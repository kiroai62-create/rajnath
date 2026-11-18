import 'dart:async';

import 'package:flutter/material.dart';
import 'package:livekit_client/livekit_client.dart' as lk;

import '../api/api_client.dart';
import '../call/booking_call.dart';
import '../call/call_platform.dart';
import '../theme/app_theme.dart';
import '../util/i18n.dart';
import '../widgets/common.dart';

enum _Phase { preparing, connecting, live, reconnecting, ended, failed }

/// Pauses before each automatic rejoin after the connection was lost (about 3 minutes in total).
const _retrySeconds = [1, 2, 4, 8, 15, 30, 30, 30, 30, 30];

/// In-app consultation call (LiveKit). Token from `POST /bookings/{ref}/call-token`.
class CallScreen extends StatefulWidget {
  const CallScreen({super.key, required this.api, required this.bookingRef, required this.title});
  final ApiClient api;
  final String bookingRef;
  final String title;

  @override
  State<CallScreen> createState() => _CallScreenState();
}

class _CallScreenState extends State<CallScreen> with WidgetsBindingObserver {
  _Phase _phase = _Phase.preparing;
  String? _message;
  bool _canRetry = true;
  bool _reconnecting = false;
  bool _audioOnly = true;
  bool _micOn = true;
  bool _camOn = false;
  bool _speakerOn = true;
  bool _busy = false;
  lk.CameraPosition _camPos = lk.CameraPosition.front;

  lk.Room? _room;
  lk.EventsListener<lk.RoomEvent>? _listener;
  lk.LocalAudioTrack? _audio;
  lk.LocalVideoTrack? _video;
  bool _leaving = false;

  /// Automatic rejoin after the connection was lost: attempt number and the countdown shown.
  int _attempt = 0;
  int _retryIn = 0;
  Timer? _retryTimer;

  /// The camera was on when the app went to the background (Android does not allow it there).
  bool _camBeforePause = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_start());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _leaving = true;
    _retryTimer?.cancel();
    unawaited(_cleanup());
    super.dispose();
  }

  /// Call log for Admin › Bookings (best effort).
  void _report(String event, [String? detail]) {
    unawaited(widget.api.post('/bookings/${widget.bookingRef}/call-event', {'event': event, 'detail': detail}).then((_) {}, onError: (Object _) {}));
  }

  /// In the background the microphone keeps working (foreground service); the camera pauses and comes back.
  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    final lp = _room?.localParticipant;
    if (_phase != _Phase.live || lp == null) return;
    if (s == AppLifecycleState.paused) _report('background');
    if (s == AppLifecycleState.resumed) _report('foreground');
    if (_audioOnly) return;
    if (s == AppLifecycleState.paused && _camOn) {
      _camBeforePause = true;
      unawaited(lp.setCameraEnabled(false).then((_) => _set(() => _camOn = false)).catchError((Object _) {}));
    } else if (s == AppLifecycleState.resumed && _camBeforePause) {
      _camBeforePause = false;
      unawaited(lp.setCameraEnabled(true, cameraCaptureOptions: lk.CameraCaptureOptions(cameraPosition: _camPos)).then((_) => _set(() => _camOn = true)).catchError((Object _) {}));
    }
  }

  void _set(VoidCallback f) {
    if (mounted) setState(f);
  }

  void _fail(String message, {bool canRetry = true}) => _set(() {
        _phase = _Phase.failed;
        _message = message;
        _canRetry = canRetry;
      });

  /// Joins the call. [rejoin] is an automatic retry after the connection was lost: errors then schedule the next try.
  Future<void> _start({bool rejoin = false}) async {
    _retryTimer?.cancel();
    if (!rejoin) _attempt = 0;
    _set(() {
      _phase = rejoin ? _Phase.reconnecting : _Phase.preparing;
      _message = null;
      _reconnecting = false;
      _retryIn = 0;
    });

    // 1. Token.
    final CallToken t;
    try {
      t = CallToken.fromJson(await widget.api.post('/bookings/${widget.bookingRef}/call-token', {}));
    } on ApiException catch (e) {
      if (rejoin && e.code != 'call_not_available' && e.status != 404) return _scheduleRejoin();
      return _fail(e.code == 'call_not_available' ? e.message : (e.isOffline ? e.message : 'Could not start the call. ${e.message}'), canRetry: true);
    } on FormatException {
      return _fail('The call is not set up yet. Please try again in a moment.');
    }
    if (_leaving) return;
    _audioOnly = t.audioOnly;
    _speakerOn = !t.audioOnly;

    // 2. Permissions + local tracks before joining (flutter_webrtc shows the system prompts).
    await CallPlatform.requestBluetooth();
    if (_leaving) return;
    try {
      _audio = await lk.LocalAudioTrack.create(const lk.AudioCaptureOptions());
    } catch (e) {
      _report('mic_error', '$e');
      return _fail(looksLikePermissionDenied(e)
          ? 'Microphone access is needed for the call. Allow it in Settings › Apps › Astro Rajnath Jha › Permissions, then try again.'
          : 'Could not start your microphone. Please close other apps using it and try again.');
    }
    if (!_audioOnly) {
      try {
        _video = await lk.LocalVideoTrack.createCameraTrack(lk.CameraCaptureOptions(cameraPosition: _camPos));
      } catch (e) {
        _video = null;
        _report('camera_error', '$e');
        if (mounted) {
          showSnack(context, looksLikePermissionDenied(e) ? 'Camera access was not allowed — continuing with audio only.' : 'Could not start the camera — continuing with audio only.');
        }
      }
    }
    if (_leaving) return _cleanup();

    // 3. Connect.
    if (!rejoin) _set(() => _phase = _Phase.connecting);
    final room = lk.Room(roomOptions: const lk.RoomOptions(adaptiveStream: true, dynacast: true));
    _room = room;
    _listener = room.createListener()
      ..on<lk.RoomReconnectingEvent>((_) {
        _report('reconnecting');
        _set(() => _reconnecting = true);
      })
      ..on<lk.RoomAttemptReconnectEvent>((_) => _set(() => _reconnecting = true))
      ..on<lk.RoomReconnectedEvent>((_) {
        _report('reconnected');
        _set(() => _reconnecting = false);
      })
      ..on<lk.RoomDisconnectedEvent>(_onDisconnected)
      ..on<lk.ParticipantConnectedEvent>((_) => _set(() {}))
      ..on<lk.ParticipantDisconnectedEvent>((_) => _set(() {}))
      ..on<lk.TrackSubscribedEvent>((_) => _set(() {}))
      ..on<lk.TrackUnsubscribedEvent>((_) => _set(() {}))
      ..on<lk.TrackMutedEvent>((_) => _set(() {}))
      ..on<lk.TrackUnmutedEvent>((_) => _set(() {}));
    try {
      await room.connect(t.url, t.token, connectOptions: const lk.ConnectOptions(autoSubscribe: true));
    } catch (_) {
      await _cleanup();
      if (rejoin) return _scheduleRejoin();
      return _fail('Could not connect to the call. Please check your internet connection and try again.');
    }
    if (_leaving) return _cleanup();

    // 4. Publish mic (+ camera).
    try {
      final lp = room.localParticipant!;
      await lp.publishAudioTrack(_audio!);
      if (_video != null) await lp.publishVideoTrack(_video!);
    } catch (_) {
      await _cleanup();
      return _fail('Could not share your microphone or camera. Please try again.');
    }
    _micOn = true;
    _camOn = _video != null;
    _report(rejoin ? 'rejoined' : 'joined', _video != null ? 'video' : 'audio');
    _attempt = 0;
    await lk.AudioManager.instance.setSpeakerOutputPreferred(_speakerOn);
    await CallPlatform.keepScreenOn(true);
    await CallPlatform.callStarted(video: _video != null, title: widget.title);
    _set(() => _phase = _Phase.live);
  }

  /// Waits a little longer each time, then joins again with a fresh token. Gives up after about 3 minutes.
  void _scheduleRejoin() {
    if (_leaving || !mounted) return;
    if (_attempt >= _retrySeconds.length) {
      _set(() {
        _phase = _Phase.ended;
        _message = 'The connection was lost and could not be restored. Check your internet, then press Rejoin.';
      });
      _report('failed', 'gave up rejoining');
      return;
    }
    var left = _retrySeconds[_attempt++];
    _set(() {
      _phase = _Phase.reconnecting;
      _retryIn = left;
    });
    _retryTimer?.cancel();
    _retryTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      left--;
      if (left <= 0) {
        timer.cancel();
        unawaited(_start(rejoin: true));
      } else {
        _set(() => _retryIn = left);
      }
    });
  }

  void _onDisconnected(lk.RoomDisconnectedEvent e) {
    if (_leaving) return;
    _report('dropped', e.reason?.name ?? 'unknown');
    unawaited(_cleanup());
    final endText = switch (e.reason) {
      lk.DisconnectReason.participantRemoved || lk.DisconnectReason.roomDeleted => 'The call has ended.',
      lk.DisconnectReason.duplicateIdentity => 'You joined this call on another device or in the website, so it left here. Press Rejoin to bring the call back to this phone.',
      lk.DisconnectReason.clientInitiated => 'The call has ended.',
      _ => null,
    };
    if (endText == null) {
      // Network change, weak signal, server restart: come back by ourselves.
      _set(() => _reconnecting = false);
      _scheduleRejoin();
      return;
    }
    _set(() {
      _phase = _Phase.ended;
      _message = endText;
      _reconnecting = false;
    });
  }

  Future<void> _cleanup() async {
    final listener = _listener, room = _room, audio = _audio, video = _video;
    _listener = null;
    _room = null;
    _audio = null;
    _video = null;
    await CallPlatform.keepScreenOn(false);
    await CallPlatform.callEnded();
    try {
      await listener?.dispose();
      if (room != null) {
        await room.disconnect();
        await room.dispose();
      }
      await audio?.stop();
      await video?.stop();
    } catch (_) {/* already gone */}
  }

  Future<void> _hangUp() async {
    if (_phase == _Phase.live || _phase == _Phase.reconnecting) _report('left');
    _leaving = true;
    _retryTimer?.cancel();
    await _cleanup();
    if (mounted) Navigator.of(context).pop();
  }

  /// The back button during a call asks first, so the call is not ended by accident.
  Future<void> _confirmLeave() async {
    if (_phase != _Phase.live && _phase != _Phase.reconnecting) {
      _leaving = true;
      _retryTimer?.cancel();
      if (mounted) Navigator.of(context).pop();
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(tr('Leave the call?')),
        content: Text(tr('The other person stays in the call. You can rejoin while the consultation time lasts.')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Stay'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Leave'))),
        ],
      ),
    );
    if (ok == true) await _hangUp();
  }

  Future<void> _guard(Future<void> Function() f) async {
    if (_busy) return;
    _set(() => _busy = true);
    try {
      await f();
    } catch (_) {
      if (mounted) showSnack(context, 'That did not work. Please try again.');
    } finally {
      _set(() => _busy = false);
    }
  }

  Future<void> _toggleMic() => _guard(() async {
        await _room?.localParticipant?.setMicrophoneEnabled(!_micOn);
        _set(() => _micOn = !_micOn);
      });

  Future<void> _toggleCam() => _guard(() async {
        final lp = _room?.localParticipant;
        if (lp == null) return;
        if (_video == null && lp.videoTrackPublications.isEmpty) {
          // Camera failed at start: try again (prompts for permission if needed).
          final v = await lk.LocalVideoTrack.createCameraTrack(lk.CameraCaptureOptions(cameraPosition: _camPos));
          _video = v;
          await lp.publishVideoTrack(v);
          _set(() => _camOn = true);
          return;
        }
        await lp.setCameraEnabled(!_camOn, cameraCaptureOptions: lk.CameraCaptureOptions(cameraPosition: _camPos));
        _set(() => _camOn = !_camOn);
      });

  Future<void> _switchCam() => _guard(() async {
        final track = _localVideo;
        if (track == null) return;
        final next = _camPos.switched();
        await track.setCameraPosition(next);
        _set(() => _camPos = next);
      });

  Future<void> _toggleSpeaker() => _guard(() async {
        await lk.AudioManager.instance.setSpeakerOutputPreferred(!_speakerOn);
        _set(() => _speakerOn = !_speakerOn);
      });

  lk.RemoteParticipant? get _remote => _room?.remoteParticipants.values.firstOrNull;

  lk.VideoTrack? get _remoteVideo => _remote?.videoTrackPublications.where((p) => p.track != null && !p.muted && p.source != lk.TrackSource.screenShareVideo).firstOrNull?.track ??
      _remote?.videoTrackPublications.where((p) => p.track != null && !p.muted).firstOrNull?.track;

  lk.LocalVideoTrack? get _localVideo => _room?.localParticipant?.videoTrackPublications.firstOrNull?.track ?? _video;

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) unawaited(_confirmLeave());
      },
      child: Scaffold(
        backgroundColor: ArjColors.ink900,
        body: SafeArea(
          child: switch (_phase) {
            _Phase.preparing || _Phase.connecting => _centered(
                const CircularProgressIndicator(color: Colors.white, semanticsLabel: 'Connecting'),
                _phase == _Phase.preparing ? 'Preparing your call…' : 'Connecting…',
              ),
            _Phase.reconnecting => _centered(
                const CircularProgressIndicator(color: ArjColors.gold300, semanticsLabel: 'Reconnecting'),
                _retryIn > 0 ? tr('Connection lost. Trying again in {0} s…', [_retryIn]) : 'Reconnecting… please keep the app open.',
                actions: [
                  OutlinedButton(
                    style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54)),
                    onPressed: _hangUp,
                    child: Text(tr('Leave call')),
                  ),
                ],
              ),
            _Phase.failed || _Phase.ended => _endedView(),
            _Phase.live => _liveView(),
          },
        ),
      ),
    );
  }

  Widget _centered(Widget top, String text, {List<Widget> actions = const []}) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            top,
            const SizedBox(height: 20),
            Text(tr(widget.title), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            Text(tr(text), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white70)),
            if (actions.isNotEmpty) ...[const SizedBox(height: 24), Wrap(spacing: 12, runSpacing: 12, alignment: WrapAlignment.center, children: actions)],
          ]),
        ),
      );

  Widget _endedView() => _centered(
        Icon(_phase == _Phase.ended ? Icons.call_end : Icons.error_outline, color: Colors.white, size: 44),
        _message ?? 'The call has ended.',
        actions: [
          if (_canRetry)
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: ArjColors.gold500, foregroundColor: ArjColors.ink900),
              onPressed: () {
                _leaving = false;
                unawaited(_start());
              },
              child: Text(tr(_phase == _Phase.ended ? 'Rejoin' : 'Try again')),
            ),
          OutlinedButton(
            style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54)),
            onPressed: () {
              _leaving = true;
              Navigator.of(context).pop();
            },
            child: Text(tr('Close')),
          ),
        ],
      );

  Widget _liveView() {
    final remote = _remote, remoteVideo = _remoteVideo, local = _camOn ? _localVideo : null;
    final waiting = remote == null;
    return Stack(children: [
      Positioned.fill(
        child: remoteVideo != null
            ? lk.VideoTrackRenderer(remoteVideo, fit: lk.VideoViewFit.cover)
            : _centered(
                const CircleAvatar(radius: 44, backgroundColor: ArjColors.gold500, child: Icon(Icons.person, size: 48, color: ArjColors.ink900)),
                waiting
                    ? 'Waiting for the astrologer…'
                    : (_audioOnly ? 'Audio call in progress' : tr('{0} is in the call with the camera off.', [remote.name.isNotEmpty ? remote.name : tr('The astrologer')])),
              ),
      ),
      if (local != null)
        Positioned(
          top: 56,
          right: 12,
          width: 110,
          height: 160,
          child: ClipRRect(borderRadius: BorderRadius.circular(12), child: lk.VideoTrackRenderer(local, fit: lk.VideoViewFit.cover)),
        ),
      Positioned(
        top: 8,
        left: 8,
        right: 8,
        child: Row(children: [
          IconButton(tooltip: tr('Leave call'), onPressed: _confirmLeave, icon: const Icon(Icons.arrow_back, color: Colors.white)),
          Expanded(child: Text(tr(remote?.name.isNotEmpty == true ? remote!.name : widget.title), overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600))),
        ]),
      ),
      if (_reconnecting)
        Positioned(
          top: 60,
          left: 0,
          right: 0,
          child: Center(
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              decoration: BoxDecoration(color: ArjColors.gold700, borderRadius: BorderRadius.circular(99)),
              child: Text(tr('Reconnecting…'), style: TextStyle(color: Colors.white)),
            ),
          ),
        ),
      Positioned(left: 0, right: 0, bottom: 24, child: _controls()),
    ]);
  }

  Widget _controls() => Wrap(alignment: WrapAlignment.center, spacing: 14, runSpacing: 12, children: [
        _round(_micOn ? Icons.mic : Icons.mic_off, _micOn ? 'Mute' : 'Unmute', _toggleMic, active: _micOn),
        if (!_audioOnly) _round(_camOn ? Icons.videocam : Icons.videocam_off, _camOn ? 'Turn camera off' : 'Turn camera on', _toggleCam, active: _camOn),
        if (!_audioOnly && _camOn) _round(Icons.cameraswitch, 'Switch camera', _switchCam),
        if (lk.AudioManager.instance.canSwitchSpeakerphone)
          _round(_speakerOn ? Icons.volume_up : Icons.hearing, _speakerOn ? 'Speaker on' : 'Speaker off', _toggleSpeaker, active: _speakerOn),
        _round(Icons.call_end, 'Hang up', _hangUp, color: ArjColors.danger700),
      ]);

  Widget _round(IconData icon, String label, VoidCallback onTap, {bool active = true, Color? color}) => Semantics(
        button: true,
        label: label,
        child: Tooltip(
          message: label,
          child: Material(
            color: color ?? (active ? Colors.white24 : Colors.white),
            shape: const CircleBorder(),
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: _busy && color == null ? null : onTap,
              child: SizedBox(width: 60, height: 60, child: Icon(icon, color: color != null ? Colors.white : (active ? Colors.white : ArjColors.ink900))),
            ),
          ),
        ),
      );
}
