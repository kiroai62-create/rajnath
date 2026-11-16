import 'dart:async';

import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../util/i18n.dart';
import '../util/version.dart';

/// App-wide session state. Signed-in user is loaded from /me using the stored token.
class AppState extends ChangeNotifier {
  AppState(this.api) {
    api.onUnauthorized = () {
      if (user != null) signOutLocal();
    };
  }

  final ApiClient api;
  Map<String, dynamic>? user;
  bool ready = false;

  /// Public GET /meta (push config, assistant flag). Null until loaded.
  Map<String, dynamic>? meta;

  /// Unread in-app notifications (badge on the Account tab).
  int unread = 0;

  /// Runs before the bearer token is revoked on sign-out (e.g. DELETE /push-tokens).
  Future<void> Function()? beforeSignOut;

  bool get assistantEnabled => (meta?['assistant'] as Map?)?['enabled'] != false && featureOn('assistant');

  // ---- Admin › Mobile app (GET /meta → app). Missing values keep everything on. ----

  Map get _app => (meta?['app'] as Map?) ?? const {};

  /// Feature switched on by the owner (assistant, store, puja, palm, consultation, wallet, blog, gallery).
  bool featureOn(String key) => ((_app['features'] as Map?) ?? const {})[key] != false;

  /// Maintenance message when the owner has put the app into maintenance, else null.
  String? get maintenanceMessage {
    final m = _app['maintenance'];
    return m is Map && m['on'] == true ? '${m['message'] ?? 'The app is being updated. Please try again soon.'}' : null;
  }

  Map get updateInfo => (_app['update'] as Map?) ?? const {};

  /// This build is older than the minimum version: the user must update.
  bool get updateRequired => compareVersions(appVersion, '${updateInfo['min_version'] ?? meta?['min_app_version'] ?? '0'}') < 0;

  /// A newer version exists (shown as a dismissible note).
  bool get updateAvailable => !updateRequired && compareVersions(appVersion, '${updateInfo['latest_version'] ?? '0'}') < 0;

  /// Announcement banner `{text, cta, link, style}` or null.
  Map? get announcement => _app['announcement'] is Map ? _app['announcement'] as Map : null;

  /// Home section keys in the owner's order, without hidden ones. Unknown keys are ignored by the app.
  List<String> homeSections(List<String> defaults) {
    final home = (_app['home'] as Map?) ?? const {};
    final hidden = ((home['hidden'] as List?) ?? const []).map((e) => '$e').toSet();
    final order = ((home['order'] as List?) ?? const []).map((e) => '$e').where(defaults.contains).toList();
    for (var i = 0; i < defaults.length; i++) {
      if (!order.contains(defaults[i])) order.insert(i.clamp(0, order.length), defaults[i]);
    }
    return order.where((k) => !hidden.contains(k)).toList();
  }

  /// Support numbers for app users (Admin › Mobile app), each may be null.
  Map get support => (_app['support'] as Map?) ?? const {};

  bool get signedIn => user != null;

  /// Display names for the languages the server knows (config/locales.php).
  static const localeNames = {
    'en': 'English', 'hi': 'हिन्दी', 'bn': 'বাংলা', 'mr': 'मराठी', 'te': 'తెలుగు',
    'ta': 'தமிழ்', 'gu': 'ગુજરાતી', 'ur': 'اردو', 'kn': 'ಕನ್ನಡ',
  };

  /// App language. Null until chosen (the server then uses the account's saved language, else English).
  String? locale;

  /// Languages offered in the app: `meta.locales` when the server reports them, else English only.
  List<String> get enabledLocales {
    final l = meta?['locales'];
    final list = l is List ? l.map((e) => e is Map ? '${e['code'] ?? e['key']}' : '$e').where(localeNames.containsKey).toList() : const <String>[];
    return list.isEmpty ? const ['en'] : list;
  }

  /// True when the server offers more than English; the language choice is hidden otherwise.
  bool get multilingual => enabledLocales.length > 1;

  Future<void> bootstrap() async {
    final saved = await api.savedLocale();
    if (saved != null && localeNames.containsKey(saved)) {
      api.locale = locale = saved;
      await I18n.useCached(saved); // the last language shows at once, even offline
    }
    if (await api.token() != null) {
      try {
        user = (await api.get('/me'))['data'] as Map<String, dynamic>;
        if (locale == null && localeNames.containsKey(user?['locale'])) {
          api.locale = locale = '${user!['locale']}';
          await I18n.useCached(locale!);
        }
      } on ApiException catch (e) {
        if (e.status == 401) await api.clearToken();
      }
    }
    ready = true;
    notifyListeners();
    unawaited(_refreshStrings());
    if (signedIn) unawaited(refreshUnread());
    // Admin › Mobile app settings (maintenance, update, banner, sections) apply as soon as they arrive.
    if (meta == null) unawaited(loadMeta());
  }

  Future<Map<String, dynamic>?> loadMeta() async {
    try {
      meta = await api.get('/meta');
      // a language saved earlier that the server no longer offers falls back to English
      if (locale != null && !enabledLocales.contains(locale)) {
        api.locale = locale = null;
        await I18n.useCached('en');
        I18n.rebuildAll();
      }
      notifyListeners();
    } catch (_) {/* offline: features fall back to defaults */}
    return meta;
  }

  Future<void> refreshUnread() async {
    if (!signedIn) return;
    try {
      final r = await api.get('/notifications');
      setUnread((r['unread'] as num?)?.toInt() ?? 0);
    } catch (_) {/* badge is best-effort */}
  }

  void setUnread(int n) {
    if (n == unread) return;
    unread = n;
    notifyListeners();
  }

  Future<void> completeSignIn(String token, Map<String, dynamic> u) async {
    await api.saveToken(token);
    user = u;
    notifyListeners();
    unawaited(refreshUnread());
  }

  /// Switches the app language: saved on the device, sent as Accept-Language, and saved to the account when signed in.
  /// The app's own text (buttons, titles) switches too, on every open screen.
  Future<void> setLocale(String l) async {
    api.locale = locale = l;
    await I18n.useCached(l);
    notifyListeners();
    I18n.rebuildAll();
    await api.saveLocale(l);
    // The newest translations arrive in the background; content from the server reloads via the listeners.
    unawaited(_refreshStrings());
    if (signedIn) {
      final r = await api.patch('/me', {'locale': l});
      if (r['data'] is Map) setUser((r['data'] as Map).cast<String, dynamic>());
    }
  }

  /// Fetches the newest translations. While the server is still translating new lines (a language chosen for
  /// the first time), it asks again a few times, a few minutes apart.
  Future<void> _refreshStrings({int round = 0}) async {
    final l = locale;
    if (await I18n.refresh(api)) {
      notifyListeners();
      I18n.rebuildAll();
    }
    if (I18n.pending > 0 && round < 5 && l != null && l != 'en') {
      _stringsTimer?.cancel();
      _stringsTimer = Timer(const Duration(minutes: 3), () {
        if (locale == l) unawaited(_refreshStrings(round: round + 1));
      });
    }
  }

  Timer? _stringsTimer;

  void setUser(Map<String, dynamic> u) {
    user = u;
    notifyListeners();
  }

  Future<void> refreshUser() async {
    user = (await api.get('/me'))['data'] as Map<String, dynamic>;
    notifyListeners();
  }

  Future<void> signOut() async {
    try {
      await beforeSignOut?.call();
    } catch (_) {/* never block sign-out */}
    try {
      await api.post('/auth/logout', {});
    } catch (_) {/* token may already be revoked */}
    await signOutLocal();
  }

  Future<void> signOutLocal() async {
    await api.clearToken();
    user = null;
    unread = 0;
    notifyListeners();
  }
}
