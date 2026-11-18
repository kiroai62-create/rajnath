import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';

/// Screen views for Admin › Analytics › Mobile app, with the time each screen (or tab) was on screen. A view is
/// sent when the person leaves the screen or the app goes to the background. Batched and sent to /analytics/events
/// at most every 30 seconds; failures are ignored (analytics must never disturb the app).
class AppAnalytics {
  AppAnalytics._();
  static final AppAnalytics instance = AppAnalytics._();

  ApiClient? _api;
  AppLifecycleListener? _lifecycle;
  final List<Map<String, dynamic>> _queue = [];
  Timer? _timer;

  ApiClient? get api => _api;

  /// Starts sending; waiting screen views are also sent when the app goes to the background.
  set api(ApiClient? client) {
    _api = client;
    _lifecycle ??= AppLifecycleListener(onHide: _hidden, onShow: _shown, onPause: flush);
  }

  String? _current;
  DateTime? _since;

  /// What the first screen (the tabs) is called right now, for example "Home" or "Tab: Services".
  String rootName = 'Home';

  /// The person now sees [name]: the previous screen is recorded with its time on screen.
  void screen(String name) {
    _close();
    _current = name;
    _since = DateTime.now();
  }

  void _close() {
    final c = _current, since = _since;
    if (c == null) return;
    final secs = since == null ? 0 : DateTime.now().difference(since).inSeconds.clamp(0, 3600);
    _add({'type': 'screen', 'name': c, 'seconds': secs});
    _since = null;
  }

  void _hidden() {
    _close();
    flush();
  }

  void _shown() => _since ??= DateTime.now();

  void _add(Map<String, dynamic> e) {
    if (api == null || name(e).isEmpty) return;
    _queue.add(e);
    if (_queue.length >= 20) {
      flush();
    } else {
      _timer ??= Timer(const Duration(seconds: 30), flush);
    }
  }

  static String name(Map<String, dynamic> e) => '${e['name'] ?? ''}';

  Future<void> flush() async {
    _timer?.cancel();
    _timer = null;
    if (_queue.isEmpty || api == null) return;
    final batch = List<Map<String, dynamic>>.from(_queue.take(50));
    _queue.removeRange(0, batch.length);
    try {
      await api!.post('/analytics/events', {'events': batch});
    } catch (_) {/* dropped */}
  }
}

/// Records each pushed screen by its widget name (for example "NotificationsScreen").
class ScreenObserver extends NavigatorObserver {
  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPush(route, previousRoute);
    _record(route);
  }

  /// Back to the previous screen: it is on screen again.
  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPop(route, previousRoute);
    if (previousRoute != null) _record(previousRoute);
  }

  void _record(Route<dynamic> route) {
    if (route.isFirst) return AppAnalytics.instance.screen(AppAnalytics.instance.rootName);
    final name = route.settings.name ?? _widgetName(route);
    if (name != null && name.isNotEmpty) AppAnalytics.instance.screen(name.replaceAll(RegExp(r'Screen$'), ''));
  }

  String? _widgetName(Route<dynamic> route) {
    final ctx = navigator?.context;
    if (route is MaterialPageRoute && ctx != null) {
      try {
        return route.builder(ctx).runtimeType.toString();
      } catch (_) {
        return null;
      }
    }
    return null;
  }
}
