import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_theme.dart';
import '../util/i18n.dart';
import '../widgets/brand.dart';

/// Shown when Acharya Ji joins the consultation while the app is open: looks and feels like a phone call
/// (vibrates every 2 seconds for up to a minute). When the app is closed the system notification rings instead.
class IncomingCallScreen extends StatefulWidget {
  const IncomingCallScreen({super.key, required this.caller, required this.onJoin});
  final String caller;
  final VoidCallback onJoin;
  @override
  State<IncomingCallScreen> createState() => _IncomingCallScreenState();
}

class _IncomingCallScreenState extends State<IncomingCallScreen> {
  Timer? _buzz;
  int _n = 0;

  @override
  void initState() {
    super.initState();
    SystemSound.play(SystemSoundType.alert);
    HapticFeedback.heavyImpact();
    _buzz = Timer.periodic(const Duration(seconds: 2), (t) {
      if (++_n > 30) {
        t.cancel();
        return;
      }
      HapticFeedback.heavyImpact();
      SystemSound.play(SystemSoundType.alert);
    });
  }

  @override
  void dispose() {
    _buzz?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: CosmicBackground(
          child: SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(children: [
                const Spacer(),
                Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: ArjColors.gold300, width: 2)),
                  child: Image.asset('assets/images/logo-mark-light.png', width: 96, height: 96, excludeFromSemantics: true),
                ),
                const SizedBox(height: 24),
                Text(tr('Incoming consultation call'), style: const TextStyle(color: ArjColors.gold200, letterSpacing: 1.2, fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                Text(tr(widget.caller), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w700)),
                const Spacer(flex: 2),
                Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                  _btn(context, Icons.call_end, tr('Later'), ArjColors.danger700, () => Navigator.of(context).pop()),
                  _btn(context, Icons.videocam, tr('Join'), ArjColors.success700, () {
                    _buzz?.cancel();
                    widget.onJoin();
                  }),
                ]),
                const SizedBox(height: 32),
              ]),
            ),
          ),
        ),
      );

  Widget _btn(BuildContext context, IconData icon, String label, Color color, VoidCallback onTap) => Column(children: [
        Semantics(
          button: true,
          label: label,
          child: Material(
            color: color,
            shape: const CircleBorder(),
            child: InkWell(customBorder: const CircleBorder(), onTap: onTap, child: SizedBox(width: 72, height: 72, child: Icon(icon, color: Colors.white, size: 32))),
          ),
        ),
        const SizedBox(height: 8),
        Text(label, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
      ]);
}
