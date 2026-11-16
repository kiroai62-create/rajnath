import 'dart:async';
import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';

/// The app's own screen text in the chosen language.
///
/// Every English text the app shows passes through [tr]. The translations come from
/// GET /app-strings (the same translation memory as the website: reviewed lang files, then
/// Admin › Languages and AI). They are kept on the phone, so the last language works offline
/// and shows at once on the next start. Text with no translation yet stays in English.
class I18n {
  I18n._();

  static String _locale = 'en';
  static Map<String, String> _strings = const {};
  static const _storage = FlutterSecureStorage();
  static String _key(String l) => 'arj_strings_$l';

  static String get locale => _locale;

  /// Lines the server is still translating (after the last [refresh]).
  static int pending = 0;

  /// The current language for MaterialApp (date pickers, back-button tooltips, text direction).
  static final ValueNotifier<String> current = ValueNotifier('en');

  static String t(String en) => _locale == 'en' ? en : (_strings[en] ?? en);

  /// Switches to [l] and shows the strings saved on the phone straight away (none for English).
  static Future<void> useCached(String l) async {
    var next = const <String, String>{};
    if (l != 'en') {
      try {
        final raw = await _storage.read(key: _key(l));
        final m = raw == null ? null : jsonDecode(raw);
        if (m is Map) next = m.map((k, v) => MapEntry('$k', '$v'));
      } catch (_) {/* nothing saved yet */}
    }
    _locale = l;
    _strings = next;
    current.value = l;
  }

  /// Fetches the latest translations for the current language and saves them. Returns true when
  /// something changed (the caller then redraws the screens).
  static Future<bool> refresh(ApiClient api) async {
    final l = _locale;
    if (l == 'en') return false;
    try {
      final r = await api.get('/app-strings', query: {'locale': l});
      final s = (r['data'] as Map?)?['strings'];
      if (s is! Map || _locale != l) return false;
      pending = ((r['data'] as Map)['pending'] as num?)?.toInt() ?? 0;
      final next = s.map((k, v) => MapEntry('$k', '$v'));
      if (mapEquals(next, _strings)) return false;
      _strings = next;
      unawaited(_storage.write(key: _key(l), value: jsonEncode(next)).catchError((Object _) {}));
      return true;
    } catch (_) {
      return false; // offline or server problem: keep what we have
    }
  }

  static bool mapEquals(Map<String, String> a, Map<String, String> b) {
    if (a.length != b.length) return false;
    for (final e in a.entries) {
      if (b[e.key] != e.value) return false;
    }
    return true;
  }

  /// Redraws every screen, including const widgets and pages further back in the stack, so the
  /// new language shows everywhere without restarting the app.
  static void rebuildAll() {
    void mark(Element e) {
      e.markNeedsBuild();
      e.visitChildren(mark);
    }

    WidgetsBinding.instance.rootElement?.visitChildren(mark);
  }
}

/// Translates English app text into the chosen language. `{0}`, `{1}`… are replaced by [args].
String tr(String en, [List<Object?> args = const []]) {
  var s = I18n.t(en);
  for (var i = 0; i < args.length; i++) {
    s = s.replaceAll('{$i}', '${args[i] ?? ''}');
  }
  return s;
}

/// [tr] for optional text (tooltips, hints, errors).
String? trn(String? en) => en == null ? null : tr(en);
