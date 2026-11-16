import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../state/app_state.dart';
import '../theme/app_theme.dart';
import '../util/i18n.dart';
import '../util/validators.dart';
import '../widgets/common.dart';
import 'activity_screens.dart';

/// Puja and chadhava on request, the same form as the website's /puja: nothing is charged online; the team calls
/// back to fix the vidhi, muhurat, place and dakshina (GET /puja-requests/form, POST /puja-requests).
class PujaRequestScreen extends StatefulWidget {
  const PujaRequestScreen({super.key, required this.state, this.puja});
  final AppState state;

  /// Puja to pre-select (for example from a service link). Unknown names go to "Other".
  final String? puja;

  @override
  State<PujaRequestScreen> createState() => _PujaRequestScreenState();
}

class _PujaRequestScreenState extends State<PujaRequestScreen> {
  late Future<Map<String, dynamic>> _form = _load();
  AppState get state => widget.state;

  late final _name = TextEditingController(text: '${state.user?['name'] ?? ''}');
  late final _phone = TextEditingController(text: '${state.user?['phone'] ?? ''}');
  late final _email = TextEditingController(text: '${state.user?['email'] ?? ''}');
  final _other = TextEditingController(), _purpose = TextEditingController(), _address = TextEditingController(),
      _participants = TextEditingController(), _gotra = TextEditingController(), _birthPlace = TextEditingController(), _message = TextEditingController();
  String? _type, _time, _samagri, _language;
  String _mode = 'online';
  DateTime? _preferred, _alternate, _birthDate;
  TimeOfDay? _birthTime;
  bool _consent = false, _busy = false, _birth = false;
  Map<String, String> _errors = {};

  Future<Map<String, dynamic>> _load() async {
    final f = ((await state.api.get('/puja-requests/form'))['data'] as Map).cast<String, dynamic>();
    final wanted = widget.puja?.trim() ?? '';
    if (wanted.isNotEmpty && _type == null) {
      final known = ((f['types'] as List?) ?? const []).whereType<Map>().any((t) => t['value'] == wanted);
      _type = known ? wanted : 'other';
      if (!known) _other.text = wanted;
    }
    return f;
  }

  @override
  void dispose() {
    for (final c in [_name, _phone, _email, _other, _purpose, _address, _participants, _gotra, _birthPlace, _message]) {
      c.dispose();
    }
    super.dispose();
  }

  static String _ymd(DateTime d) => DateFormat('yyyy-MM-dd').format(d);
  static String _show(DateTime? d) => d == null ? '' : DateFormat('d MMM yyyy').format(d);

  Map<String, String> _validate() {
    final e = <String, String>{};
    if (_type == null) e['puja_type'] = tr('Choose a puja');
    if (_type == 'other' && _other.text.trim().isEmpty) e['puja_other'] = tr('Please write the name of the puja you need.');
    if (_mode == 'home' && _address.text.trim().isEmpty) e['address'] = tr('Please write the address or city for a puja at home.');
    if (_name.text.trim().length < 2) e['name'] = tr('Enter your name.');
    final digits = _phone.text.replaceAll(RegExp(r'[\s\-()]'), '');
    if (!RegExp(r'^(\+?91|0)?[6-9]\d{9}$').hasMatch(digits) && !RegExp(r'^\+[1-9]\d{7,14}$').hasMatch(digits)) {
      e['phone'] = tr('Enter a 10-digit mobile number, or include the country code.');
    }
    final email = _email.text.trim();
    if (email.isNotEmpty && !RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email)) e['email'] = tr('Enter a valid email address.');
    if (!_consent) e['consent'] = tr('Please agree to be contacted about your request.');
    return e;
  }

  String? _text(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _submit() async {
    final errs = _validate();
    if (errs.isNotEmpty) {
      setState(() => _errors = errs);
      showSnack(context, errs.values.first);
      return;
    }
    setState(() {
      _busy = true;
      _errors = {};
    });
    try {
      final r = await state.api.post('/puja-requests', {
        'puja_type': _type,
        if (_type == 'other') 'puja_other': _other.text.trim(),
        'purpose': _text(_purpose),
        'mode': _mode,
        if (_mode == 'home') 'address': _address.text.trim(),
        'preferred_date': _preferred == null ? null : _ymd(_preferred!),
        'alternate_date': _alternate == null ? null : _ymd(_alternate!),
        'preferred_time': _time,
        'samagri': _samagri,
        'participants': _text(_participants),
        'gotra': _text(_gotra),
        if (_birth && _birthDate != null) 'birth_date': _ymd(_birthDate!),
        if (_birth && _birthTime != null) 'birth_time': '${_birthTime!.hour.toString().padLeft(2, '0')}:${_birthTime!.minute.toString().padLeft(2, '0')}',
        if (_birth) 'birth_place': _text(_birthPlace),
        'name': _name.text.trim(),
        'phone': _phone.text.trim(),
        'email': _text(_email),
        'language': _language,
        'message': _text(_message),
        'consent': true,
      });
      if (!mounted) return;
      final d = (r['data'] as Map?) ?? const {};
      await Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => _PujaRequestSent(state: state, request: d)));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) '_': e.message});
      showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<DateTime?> _pickDate({required DateTime first, required DateTime last, DateTime? initial}) =>
      showDatePicker(context: context, firstDate: first, lastDate: last, initialDate: initial ?? (first.isAfter(DateTime.now()) ? first : DateTime.now()));

  Widget _heading(String text) => Padding(
        padding: const EdgeInsets.only(top: 20, bottom: 10),
        child: Text(tr(text), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
      );

  Widget _field(TextEditingController c, String label, String key,
          {String? hint, String? helper, TextInputType? type, int maxLines = 1, int? maxLength, TextCapitalization cap = TextCapitalization.sentences, List<TextInputFormatter>? fmt}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(
          controller: c,
          keyboardType: type,
          maxLines: maxLines,
          maxLength: maxLength,
          inputFormatters: fmt,
          textCapitalization: cap,
          decoration: InputDecoration(labelText: tr(label), hintText: trn(hint), helperText: trn(helper), helperMaxLines: 2, errorText: _errors[key], errorMaxLines: 3),
        ),
      );

  Widget _dropdown(String label, String key, List<Map> options, String? value, ValueChanged<String?> onChanged, {String? empty}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: DropdownButtonFormField<String>(
          initialValue: value,
          isExpanded: true,
          decoration: InputDecoration(labelText: tr(label), errorText: _errors[key]),
          items: [
            if (empty != null) DropdownMenuItem<String>(value: null, child: Text(tr(empty))),
            for (final o in options) DropdownMenuItem<String>(value: '${o['value']}', child: Text(tr('${o['label']}'), overflow: TextOverflow.ellipsis)),
          ],
          onChanged: onChanged,
        ),
      );

  Widget _dateTile(String label, DateTime? value, VoidCallback onTap, VoidCallback onClear, {String? error}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          child: InputDecorator(
            decoration: InputDecoration(
              labelText: tr(label),
              errorText: error,
              suffixIcon: value == null
                  ? const Icon(Icons.calendar_month_outlined)
                  : IconButton(tooltip: tr('Clear'), icon: const Icon(Icons.close), onPressed: onClear),
            ),
            child: Text(value == null ? tr('Choose') : _show(value)),
          ),
        ),
      );

  Widget _contact(Map support) {
    final phone = '${support['phone'] ?? ''}'.trim();
    final wa = '${support['whatsapp'] ?? ''}'.replaceAll(RegExp(r'[^0-9]'), '');
    if (phone.isEmpty && wa.isEmpty) return const SizedBox.shrink();
    return Card(
      margin: const EdgeInsets.only(top: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Prefer to talk?'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (phone.isNotEmpty)
              OutlinedButton.icon(onPressed: () => launchUrl(Uri.parse('tel:$phone')), icon: const Icon(Icons.call_outlined), label: Text(tr('Call {0}', [phone]))),
            if (wa.isNotEmpty)
              OutlinedButton.icon(
                  onPressed: () => launchUrl(Uri.parse('https://wa.me/$wa'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.chat_outlined), label: const Text('WhatsApp')),
          ]),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final tt = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Puja on request'))),
      body: SafeArea(
        child: FutureBuilder<Map<String, dynamic>>(
          future: _form,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snap.hasError) {
              final e = snap.error;
              return ErrorPanel(
                  message: e is ApiException ? e.message : 'Something went wrong.',
                  offline: e is ApiException && e.isOffline,
                  code: e is ApiException ? e.code : null,
                  onRetry: () => setState(() => _form = _load()));
            }
            final f = snap.data!;
            List<Map> list(String k) => ((f[k] as List?) ?? const []).whereType<Map>().toList();
            final steps = ((f['steps'] as List?) ?? const []).map((s) => '$s').toList();
            final today = DateTime.now();
            final day = DateTime(today.year, today.month, today.day);
            final hero = CosmicBackground(
              borderRadius: BorderRadius.circular(ArjStyle.radiusCard),
              child: Padding(
                padding: const EdgeInsets.all(18),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('Puja on request').toUpperCase(), style: tt.labelMedium?.copyWith(color: ArjColors.gold200, letterSpacing: 1.4, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 6),
                  Text(tr('Tell us the puja you need'), style: tt.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  Text(tr('${f['intro'] ?? ''}'), style: TextStyle(color: Colors.white.withValues(alpha: 0.85), height: 1.45)),
                ]),
              ),
            );
            final how = Card(
              margin: const EdgeInsets.only(top: 12),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('How it works'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  for (var i = 0; i < steps.length; i++)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        SizedBox(width: 22, child: Text('${i + 1}.', style: const TextStyle(fontWeight: FontWeight.w700, color: ArjColors.gold700))),
                        Expanded(child: Text(tr(steps[i]), style: const TextStyle(color: ArjColors.ink600, height: 1.4))),
                      ]),
                    ),
                ]),
              ),
            );
            if (f['open'] == false) {
              return ListView(padding: const EdgeInsets.all(16), children: [
                hero,
                const SizedBox(height: 16),
                Text(tr('Puja requests are paused for a few days. Please call or WhatsApp us.')),
                _contact(state.support),
              ]);
            }
            return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 32), children: [
              hero,
              how,
              _heading('1. Which puja?'),
              _dropdown('Puja', 'puja_type', [...list('types'), {'value': 'other', 'label': 'Other / not sure (write below)'}], _type,
                  (v) => setState(() => _type = v), empty: 'Choose a puja'),
              if (_type == 'other') _field(_other, 'Name of the puja, or describe what you need', 'puja_other', maxLength: 150),
              _field(_purpose, 'Purpose or problem (sankalp)', 'purpose', hint: 'For example: health of my father, delay in marriage, new home', maxLines: 3, maxLength: 2000),
              _heading('2. Where and when?'),
              Text(tr('Where should the puja be done?'), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 4),
              RadioGroup<String>(
                groupValue: _mode,
                onChanged: (v) => setState(() => _mode = v ?? _mode),
                child: Column(children: [
                  for (final m in list('modes'))
                    RadioListTile<String>(contentPadding: EdgeInsets.zero, dense: true, value: '${m['value']}', title: Text(tr('${m['label']}'))),
                ]),
              ),
              if (_mode == 'home') _field(_address, 'Address or city for the puja', 'address', maxLength: 300),
              _dateTile('Preferred date', _preferred, () async {
                final d = await _pickDate(first: day, last: day.add(const Duration(days: 365)), initial: _preferred);
                if (d != null) setState(() => _preferred = d);
              }, () => setState(() => _preferred = null), error: _errors['preferred_date']),
              _dateTile('Second choice (optional)', _alternate, () async {
                final d = await _pickDate(first: day, last: day.add(const Duration(days: 365)), initial: _alternate);
                if (d != null) setState(() => _alternate = d);
              }, () => setState(() => _alternate = null), error: _errors['alternate_date']),
              _dropdown('Time of day', 'preferred_time', list('times'), _time, (v) => setState(() => _time = v), empty: 'Choose'),
              _dropdown('Puja samagri', 'samagri', list('samagri'), _samagri, (v) => setState(() => _samagri = v), empty: 'Choose'),
              _heading('3. Sankalp details'),
              _field(_participants, 'Names to be taken in the sankalp (optional)', 'participants', helper: 'For example: Ramesh Kumar (self), Sunita Devi (wife)', maxLength: 500, cap: TextCapitalization.words),
              _field(_gotra, 'Gotra (optional)', 'gotra', maxLength: 100, cap: TextCapitalization.words),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: _birth,
                onChanged: (v) => setState(() => _birth = v),
                title: Text(tr('Add birth details for the muhurat (optional)')),
              ),
              if (_birth) ...[
                _dateTile('Date of birth', _birthDate, () async {
                  final d = await _pickDate(first: DateTime(1900), last: day, initial: _birthDate ?? DateTime(1990));
                  if (d != null) setState(() => _birthDate = d);
                }, () => setState(() => _birthDate = null), error: _errors['birth_date']),
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: () async {
                      final t = await showTimePicker(context: context, initialTime: _birthTime ?? const TimeOfDay(hour: 6, minute: 0));
                      if (t != null) setState(() => _birthTime = t);
                    },
                    child: InputDecorator(
                      decoration: InputDecoration(labelText: tr('Time of birth'), errorText: _errors['birth_time'], suffixIcon: const Icon(Icons.schedule)),
                      child: Text(_birthTime == null ? tr('Choose') : _birthTime!.format(context)),
                    ),
                  ),
                ),
                _field(_birthPlace, 'Place of birth', 'birth_place', maxLength: 200, cap: TextCapitalization.words),
              ],
              _heading('4. How do we reach you?'),
              _field(_name, 'Your name', 'name', maxLength: 120, cap: TextCapitalization.words),
              _field(_phone, 'Mobile number', 'phone', type: TextInputType.phone, helper: 'We will call or WhatsApp you on this number.',
                  fmt: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]'))], cap: TextCapitalization.none),
              _field(_email, 'Email (optional)', 'email', type: TextInputType.emailAddress, cap: TextCapitalization.none),
              _dropdown('Language for the call', 'language', list('languages'), _language, (v) => setState(() => _language = v), empty: 'Choose'),
              _field(_message, 'Anything else we should know? (optional)', 'message', maxLines: 3, maxLength: 2000),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: _consent,
                onChanged: (v) => setState(() => _consent = v ?? false),
                title: Text(tr('I agree to be contacted about this request. My details are handled as described in the Privacy Policy.')),
                subtitle: _errors['consent'] == null ? null : Text(_errors['consent']!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              ),
              FormError(_errors['_']),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: _busy ? null : _submit,
                icon: const Icon(Icons.send_outlined),
                label: Text(tr(_busy ? 'Sending…' : 'Send puja request')),
              ),
              _contact(state.support),
            ]);
          },
        ),
      ),
    );
  }
}

/// Shown after sending: the same message as the website's "request received" page.
class _PujaRequestSent extends StatelessWidget {
  const _PujaRequestSent({required this.state, required this.request});
  final AppState state;
  final Map request;

  @override
  Widget build(BuildContext context) {
    final ref = '${request['ref'] ?? ''}';
    return Scaffold(
      appBar: AppBar(title: Text(tr('Puja request received'))),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(24), children: [
          const SizedBox(height: 12),
          const Icon(Icons.check_circle_outline, size: 72, color: ArjColors.success700),
          const SizedBox(height: 16),
          Text(tr('We have received your puja request.'), textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Text('${request['puja_type'] ?? ''}', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 12),
          Text('${tr('Our team will call you to fix the date, place and dakshina.')} ${request['phone_masked'] ?? ''}',
              textAlign: TextAlign.center, style: const TextStyle(color: ArjColors.ink600, height: 1.45)),
          if (ref.length >= 8) ...[
            const SizedBox(height: 8),
            Text('${tr('Reference')}: ${ref.substring(ref.length - 8)}', textAlign: TextAlign.center, style: const TextStyle(color: ArjColors.ink600, fontSize: 13)),
          ],
          const SizedBox(height: 24),
          if (state.signedIn)
            FilledButton(
              onPressed: () => Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => PujaListScreen(state: state))),
              child: Text(tr('See my puja requests')),
            ),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: () => Navigator.of(context).maybePop(), child: Text(tr('Back to home'))),
        ]),
      ),
    );
  }
}

/// The signed-in customer's puja requests (newest first) with the date the team fixed.
class PujaRequestTile extends StatelessWidget {
  const PujaRequestTile({super.key, required this.request, this.highlight = false});
  final Map request;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final q = request;
    final link = '${q['meeting_link'] ?? ''}';
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      shape: highlight ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(ArjStyle.radiusCard), side: const BorderSide(color: ArjColors.gold500, width: 2)) : null,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: Text('${q['puja_type']}', style: const TextStyle(fontWeight: FontWeight.w700))),
            ToneChip('${q['status_label'] ?? q['status']}', switch (q['status']) {
              'scheduled' || 'completed' => ArjColors.success700,
              'cancelled' || 'spam' => ArjColors.ink600,
              _ => ArjColors.gold700,
            }),
          ]),
          const SizedBox(height: 4),
          Text(
            [
              if (q['created_at'] != null) tr('Requested {0}', [formatWhen(q['created_at'], pattern: 'd MMM yyyy')]),
              if (q['scheduled_at'] != null) tr('Fixed for {0}', [formatWhen(q['scheduled_at'], pattern: 'd MMM yyyy, h:mm a')]),
            ].join(' · '),
            style: const TextStyle(color: ArjColors.ink600, fontSize: 13),
          ),
          if (q['priest'] != null) Text(tr('Priest: {0}', [q['priest']]), style: const TextStyle(color: ArjColors.ink600, fontSize: 13)),
          if (q['quoted_minor'] != null) Text(tr('Dakshina: {0}', [formatPaise((q['quoted_minor'] as num).toInt())]), style: const TextStyle(color: ArjColors.ink600, fontSize: 13)),
          if (link.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: FilledButton.icon(
                onPressed: () => launchUrl(Uri.parse(link), mode: LaunchMode.externalApplication),
                icon: const Icon(Icons.videocam_outlined),
                label: Text(tr('Join the live puja')),
              ),
            ),
        ]),
      ),
    );
  }
}
