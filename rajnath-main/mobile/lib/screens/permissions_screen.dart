import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:permission_handler/permission_handler.dart';

import '../theme/app_theme.dart';
import '../util/i18n.dart';
import '../widgets/common.dart';

/// The permissions the app uses, why, and their state. Asked once after install ([AppPermissions.askOnce]);
/// anything refused can be allowed later from More › Permissions (or the phone's settings).
class AppPermissions {
  static const _asked = 'arj_permissions_asked';

  /// (permission, title, why)
  static final list = <(Permission, String, String)>[
    (Permission.notification, 'Notifications', 'Booking reminders, report ready, and the ring when Acharya Ji joins your call.'),
    (Permission.camera, 'Camera', 'Video consultations, and palm photos for palm reading.'),
    (Permission.microphone, 'Microphone', 'Audio and video consultations with Acharya Ji.'),
    (Permission.bluetoothConnect, 'Nearby devices (Bluetooth)', 'Call sound in Bluetooth earphones.'),
  ];

  /// First start after install: explain, then show the system prompts one after another. Never again after that.
  static Future<void> askOnce(BuildContext context) async {
    const storage = FlutterSecureStorage();
    try {
      if (await storage.read(key: _asked) != null) return;
      await storage.write(key: _asked, value: '1');
    } catch (_) {
      return;
    }
    if (!context.mounted) return;
    final go = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (c) => AlertDialog(
        title: Text(tr('Allow the app to work fully')),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            for (final (_, title, why) in list)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Text.rich(TextSpan(children: [TextSpan(text: '${tr(title)}: ', style: const TextStyle(fontWeight: FontWeight.w700)), TextSpan(text: tr(why))])),
              ),
            Text(tr('You can change these any time in More › Permissions.'), style: const TextStyle(color: ArjColors.ink600, fontSize: 13)),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Later'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Allow'))),
        ],
      ),
    );
    if (go == true) await requestAll();
  }

  static Future<void> requestAll() async {
    try {
      await list.map((p) => p.$1).toList().request();
    } catch (_) {/* not available on this phone */}
  }
}

class PermissionsScreen extends StatefulWidget {
  const PermissionsScreen({super.key});
  @override
  State<PermissionsScreen> createState() => _PermissionsScreenState();
}

class _PermissionsScreenState extends State<PermissionsScreen> with WidgetsBindingObserver {
  Map<Permission, PermissionStatus> _status = {};

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Back from the phone's settings: show the new state.
  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    if (s == AppLifecycleState.resumed) _load();
  }

  Future<void> _load() async {
    final m = <Permission, PermissionStatus>{};
    for (final (p, _, _) in AppPermissions.list) {
      try {
        m[p] = await p.status;
      } catch (_) {/* not on this phone */}
    }
    if (mounted) setState(() => _status = m);
  }

  Future<void> _ask(Permission p) async {
    final s = await p.request();
    if (s.isPermanentlyDenied && mounted) {
      showSnack(context, 'Turn it on in the phone settings that open now.');
      await openAppSettings();
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Permissions'))),
        body: SafeArea(
          child: ListView(padding: const EdgeInsets.all(16), children: [
            Text(tr('Allow these so calls, reminders and photos work without problems.'), style: const TextStyle(color: ArjColors.ink600)),
            const SizedBox(height: 12),
            for (final (p, title, why) in AppPermissions.list)
              Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  title: Text(tr(title)),
                  subtitle: Text(tr(why)),
                  trailing: (_status[p]?.isGranted ?? false)
                      ? Icon(Icons.check_circle, color: ArjColors.success700, semanticLabel: tr('Allowed'))
                      : FilledButton(onPressed: () => _ask(p), child: Text(tr('Allow'))),
                ),
              ),
            const SizedBox(height: 8),
            OutlinedButton.icon(onPressed: openAppSettings, icon: const Icon(Icons.settings_outlined), label: Text(tr('Open phone settings'))),
          ]),
        ),
      );
}
