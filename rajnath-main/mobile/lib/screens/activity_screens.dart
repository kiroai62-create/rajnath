import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../call/booking_call.dart';
import '../state/app_state.dart';
import '../theme/app_theme.dart';
import '../util/i18n.dart';
import '../util/validators.dart';
import '../widgets/common.dart';
import 'account_screens.dart';
import 'call_screen.dart';
import 'puja_request_screens.dart';
import 'purchase_screens.dart';
import 'review_screens.dart';
import 'app_control.dart';
import 'routing.dart';

Future<void> openExternal(BuildContext context, String? url) async {
  final u = url == null ? null : Uri.tryParse(url);
  if (u == null || !await launchUrl(u, mode: LaunchMode.externalApplication)) {
    if (context.mounted) showSnack(context, 'Could not open the link.');
  }
}

String _date(Object? iso) => formatWhen(iso, pattern: 'd MMM yyyy');

/// PDF and photo links are signed and expire after 15 minutes, so fetch the record again
/// and open the fresh link in the browser / PDF viewer.
Future<void> openFreshLink(BuildContext context, Future<String?> Function() fresh) async {
  String? url;
  try {
    url = await fresh();
  } on ApiException catch (e) {
    if (context.mounted) showSnack(context, e.message);
    return;
  }
  if (!context.mounted) return;
  if (url == null || url.isEmpty) return showSnack(context, 'This file is not available yet.');
  await openExternal(context, url);
}

/// Fresh `pdf_url` of a report or palm reading (`/reports/{ref}`, `/palmistry/{ref}`).
Future<String?> freshPdfUrl(AppState state, String path) async {
  final u = unwrap(await state.api.get(path))['pdf_url'];
  return u == null ? null : '$u';
}

Widget pdfButton(BuildContext context, AppState state, String path) => OutlinedButton.icon(
      onPressed: () => openFreshLink(context, () => freshPdfUrl(state, path)),
      icon: const Icon(Icons.picture_as_pdf_outlined),
      label: Text(tr('Download PDF')),
    );

// ───────────────────────────── Orders ─────────────────────────────

class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key, required this.state});
  final AppState state;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Orders'))),
        body: SafeArea(
          child: AsyncView<List<Map<String, dynamic>>>(
            load: () async => unwrapList(await state.api.get('/orders')),
            isEmpty: (l) => l.isEmpty,
            emptyText: 'No orders yet.',
            builder: (context, list, reload) => ListView(padding: const EdgeInsets.all(16), children: [
              for (final o in list)
                Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    title: Text(tr('${o['label'] ?? humanize(o['kind'])}')),
                    subtitle: Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [StatusChip(o['status']), Text(tr(_date(o['created_at'])))]),
                    ),
                    trailing: Text(tr(formatPaise((o['subtotal_minor'] as num?)?.toInt() ?? 0)), style: const TextStyle(fontWeight: FontWeight.w600)),
                    onTap: () async {
                      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderDetailScreen(state: state, ref: '${o['ref']}')));
                      await reload();
                    },
                  ),
                ),
            ]),
          ),
        ),
      );
}

class OrderDetailScreen extends StatefulWidget {
  const OrderDetailScreen({super.key, required this.state, required this.ref});
  final AppState state;
  final String ref;
  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  int _v = 0;

  Future<void> _cancel() async {
    final ok = await confirm(context, 'Cancel this order?', 'It has not been paid. You can place a new order any time.', 'Cancel order');
    if (!ok) return;
    try {
      await widget.state.api.post('/orders/${widget.ref}/cancel', {});
      if (mounted) setState(() => _v++);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Order'))),
        body: SafeArea(
          child: AsyncView<Map<String, dynamic>>(
            key: ValueKey(_v),
            load: () async => unwrap(await widget.state.api.get('/orders/${widget.ref}')),
            builder: (context, o, _) {
              int? m(String k) => (o[k] as num?)?.toInt();
              final next = screenForNext(widget.state, o);
              return ListView(padding: const EdgeInsets.all(16), children: [
                Text(tr('${o['label'] ?? humanize(o['kind'])}'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                Row(children: [StatusChip(o['status'])]),
                const SizedBox(height: 16),
                _kv('Placed', formatWhen(o['created_at'])),
                if (o['paid_at'] != null) _kv('Paid', formatWhen(o['paid_at'])),
                const Divider(height: 24),
                if ((m('discount_minor') ?? 0) > 0) ...[
                  if (m('list_minor') != null) _kv('Price', formatPaise(m('list_minor'))),
                  _kv(o['coupon'] != null ? 'Coupon ${o['coupon']}' : 'Coupon discount', '− ${formatPaise(m('discount_minor'))}'),
                ],
                _kv('Subtotal', formatPaise(m('subtotal_minor') ?? 0)),
                if ((m('wallet_applied_minor') ?? 0) > 0) _kv('From wallet', '− ${formatPaise(m('wallet_applied_minor'))}'),
                _kv('Paid online', formatPaise(m('payable_minor') ?? 0), bold: true),
                if ((m('refunded_minor') ?? 0) > 0) _kv('Refunded', formatPaise(m('refunded_minor'))),
                if ((m('tax_minor') ?? 0) > 0)
                  Text(tr('Includes GST {0}', [formatPaise(m('tax_minor'))]), style: const TextStyle(fontSize: 12, color: ArjColors.ink600)),
                if (o['invoices'] is List && (o['invoices'] as List).isNotEmpty) ...[
                  const SectionTitle('Invoices'),
                  for (final inv in (o['invoices'] as List).whereType<Map>())
                    Row(children: [
                      Expanded(child: _kv('${inv['type'] == 'credit_note' ? 'Credit note' : 'Invoice'} ${inv['number'] ?? ''}'.trim(), formatPaise((inv['total_minor'] as num?)?.toInt() ?? 0))),
                      if (inv['ref'] != null)
                        IconButton(
                          tooltip: tr('Download PDF'),
                          icon: const Icon(Icons.picture_as_pdf_outlined),
                          onPressed: () => openFreshLink(context, () async {
                            final fresh = unwrap(await widget.state.api.get('/orders/${widget.ref}'));
                            final match = ((fresh['invoices'] as List?) ?? const []).whereType<Map>().where((i) => i['ref'] == inv['ref']);
                            return match.isEmpty || match.first['pdf_url'] == null ? null : '${match.first['pdf_url']}';
                          }),
                        ),
                    ]),
                ],
                const SizedBox(height: 8),
                Text(tr('Reference {0}', [o['ref']]), style: const TextStyle(fontSize: 12, color: ArjColors.ink600)),
                const SizedBox(height: 20),
                if (next != null && o['status'] == 'paid' && (o['next'] as Map?)?['type'] != 'shipment')
                  FilledButton(onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => next)), child: Text(tr('Continue'))),
                if (o['status'] == 'pending_payment') OutlinedButton(onPressed: _cancel, child: Text(tr('Cancel order'))),
                if (o['review'] is Map) ...[
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => MyReviewsScreen(state: widget.state))),
                    icon: const Icon(Icons.reviews_outlined),
                    label: Text(tr('Your review')),
                  ),
                ] else if (const ['paid', 'partially_refunded'].contains(o['status']) && const ['service', 'product'].contains(o['kind'])) ...[
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => WriteReviewScreen(state: widget.state, orderRef: widget.ref, orderLabel: o['label'] == null ? null : '${o['label']}')),
                    ),
                    icon: const Icon(Icons.rate_review_outlined),
                    label: Text(tr('Write a review')),
                  ),
                ],
              ]);
            },
          ),
        ),
      );
}

Widget _kv(String k, String v, {bool bold = false}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(children: [Expanded(child: Text(tr(k), style: const TextStyle(color: ArjColors.ink600))), Text(tr(v), style: TextStyle(fontWeight: bold ? FontWeight.w600 : FontWeight.normal))]),
    );

Future<bool> confirm(BuildContext context, String title, String body, String action) async =>
    await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(title: Text(tr(title)), content: Text(tr(body)), actions: [
        TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Keep it'))),
        FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr(action))),
      ]),
    ) ==
    true;

// ───────────────────────────── Bookings ─────────────────────────────

class BookingsScreen extends StatefulWidget {
  const BookingsScreen({super.key, required this.state, this.highlight});
  final AppState state;
  final String? highlight;
  @override
  State<BookingsScreen> createState() => _BookingsScreenState();
}

class _BookingsScreenState extends State<BookingsScreen> {
  int _v = 0;

  Future<void> _cancel(Map b) async {
    final ok = await confirm(context, 'Cancel this booking?', 'The full amount will be refunded to your wallet.', 'Cancel booking');
    if (!ok) return;
    try {
      await widget.state.api.post('/bookings/${b['ref']}/cancel', {});
      if (!mounted) return;
      showSnack(context, 'Booking cancelled. The amount is back in your wallet.');
      setState(() => _v++);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  Future<void> _reschedule(Map b) async {
    final done = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => SlotBookingScreen(
        state: widget.state,
        variantRef: '${b['variant']}',
        title: '${b['title']}',
        rescheduleRef: '${b['ref']}',
        practitioner: b['practitioner_ref'] != null ? {'ref': b['practitioner_ref'], 'display_name': b['practitioner']} : null,
      ),
    ));
    if (done == true && mounted) setState(() => _v++);
  }

  Future<void> _joinCall(Map b) async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => CallScreen(api: widget.state.api, bookingRef: '${b['ref']}', title: '${b['title'] ?? 'Consultation'}'),
    ));
    if (mounted) setState(() => _v++);
  }

  List<Widget> _callSection(Map b) {
    final spec = callButtonFor(BookingCall.fromJson(b));
    final hint = spec.hint == null ? null : Text(tr(spec.hint!), style: const TextStyle(color: ArjColors.ink600, fontSize: 13));
    return switch (spec.kind) {
      CallButtonKind.hidden => const [],
      CallButtonKind.inApp => [
          FilledButton.icon(
            key: ValueKey('join-call-${b['ref']}'),
            onPressed: spec.enabled ? () => _joinCall(b) : null,
            icon: Icon(b['mode'] == 'phone' || b['mode'] == 'audio' ? Icons.call_outlined : Icons.videocam_outlined),
            label: Text(tr(spec.label!)),
          ),
          if (hint != null) Padding(padding: const EdgeInsets.only(top: 6), child: hint),
          const SizedBox(height: 8),
        ],
      CallButtonKind.external => [
          FilledButton.icon(
            key: ValueKey('join-link-${b['ref']}'),
            onPressed: () => openExternal(context, spec.link),
            icon: const Icon(Icons.open_in_new),
            label: Text(tr(spec.label!)),
          ),
          const SizedBox(height: 8),
        ],
      CallButtonKind.pending => [?hint, const SizedBox(height: 8)],
    };
  }

  /// The team moved this booking (Admin › Bookings › Move / serve today): say so, with the earlier time and their note.
  Widget _movedNote(Map b) {
    final start = DateTime.tryParse('${b['start_utc']}')?.toLocal();
    final now = DateTime.now();
    final today = start != null && start.year == now.year && start.month == now.month && start.day == now.day;
    final note = '${b['moved_note'] ?? ''}'.trim();
    return Container(
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: ArjColors.gold50, borderRadius: BorderRadius.circular(12), border: Border.all(color: ArjColors.gold200)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Icon(Icons.update, size: 20, color: ArjColors.gold700),
        const SizedBox(width: 8),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(today ? 'Moved to today by our team' : 'Time changed by our team'), style: const TextStyle(fontWeight: FontWeight.w700, color: ArjColors.gold700)),
            Text(tr('Earlier time: {0}', [formatWhen(b['moved_from_utc'], pattern: 'EEE, d MMM · h:mm a')]), style: const TextStyle(fontSize: 13, color: ArjColors.ink600)),
            if (note.isNotEmpty) Text(note, style: const TextStyle(fontSize: 13)),
          ]),
        ),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Bookings'))),
        body: SafeArea(
          child: AsyncView<List<Map<String, dynamic>>>(
            key: ValueKey(_v),
            load: () async => unwrapList(await widget.state.api.get('/bookings')),
            isEmpty: (l) => l.isEmpty,
            emptyText: 'No bookings yet.',
            builder: (context, list, _) => ListView(padding: const EdgeInsets.all(16), children: [
              for (final b in list)
                Card(
                  margin: const EdgeInsets.only(bottom: 12),
                  shape: b['ref'] == widget.highlight
                      ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: const BorderSide(color: ArjColors.gold500, width: 2))
                      : null,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Expanded(child: Text(tr('${b['title']}'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600))),
                        StatusChip(b['status']),
                      ]),
                      const SizedBox(height: 6),
                      Text(tr(formatWhen(b['start_utc'], pattern: 'EEE, d MMM yyyy · h:mm a')), style: const TextStyle(fontWeight: FontWeight.w600)),
                      if (b['moved_from_utc'] != null) _movedNote(b),
                      Text(tr([humanize(b['mode']), if (b['practitioner'] != null) 'with ${b['practitioner']}'].where((s) => s.isNotEmpty).join(' · ')), style: const TextStyle(color: ArjColors.ink600)),
                      const SizedBox(height: 8),
                      ..._callSection(b),
                      Wrap(spacing: 8, runSpacing: 8, children: [
                        if (b['can_reschedule'] == true) OutlinedButton(onPressed: () => _reschedule(b), child: Text(tr('Reschedule'))),
                        if (b['can_cancel'] == true) TextButton(onPressed: () => _cancel(b), child: Text(tr('Cancel'))),
                      ]),
                    ]),
                  ),
                ),
            ]),
          ),
        ),
      );
}

// ───────────────────────────── Reports ─────────────────────────────

class ReportsScreen extends StatelessWidget {
  const ReportsScreen({super.key, required this.state});
  final AppState state;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Reports'))),
        body: SafeArea(
          child: AsyncView<List<Map<String, dynamic>>>(
            load: () async => unwrapList(await state.api.get('/reports')),
            isEmpty: (l) => l.isEmpty,
            emptyText: 'No reports yet.',
            builder: (context, list, reload) => ListView(padding: const EdgeInsets.all(16), children: [
              for (final r in list)
                Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    title: Text(tr('${r['title']}')),
                    subtitle: Padding(padding: const EdgeInsets.only(top: 4), child: Row(children: [StatusChip(r['status'])])),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      if (r['pdf_url'] != null)
                        IconButton(
                          tooltip: tr('Download PDF'),
                          icon: const Icon(Icons.picture_as_pdf_outlined),
                          onPressed: () => openFreshLink(context, () => freshPdfUrl(state, '/reports/${r['ref']}')),
                        ),
                      Icon(Icons.chevron_right, semanticLabel: tr('Open')),
                    ]),
                    onTap: () async {
                      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ReportDetailScreen(state: state, ref: '${r['ref']}')));
                      await reload();
                    },
                  ),
                ),
            ]),
          ),
        ),
      );
}

class ReportDetailScreen extends StatefulWidget {
  const ReportDetailScreen({super.key, required this.state, required this.ref});
  final AppState state;
  final String ref;
  @override
  State<ReportDetailScreen> createState() => _ReportDetailScreenState();
}

class _ReportDetailScreenState extends State<ReportDetailScreen> {
  int _v = 0;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Report'))),
        body: SafeArea(
          child: AsyncView<Map<String, dynamic>>(
            key: ValueKey(_v),
            load: () async => unwrap(await widget.state.api.get('/reports/${widget.ref}')),
            builder: (context, r, _) => ListView(padding: const EdgeInsets.all(16), children: [
              Text(tr('${r['title']}'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              Row(children: [StatusChip(r['status'])]),
              if (r['status'] == 'awaiting_details')
                ReportDetailsForm(state: widget.state, ref: widget.ref, twoProfiles: r['needs_two_profiles'] == true, needsAddress: r['needs_address'] == true, onDone: () => setState(() => _v++))
              else if (r['status'] == 'in_preparation')
                Padding(padding: EdgeInsets.only(top: 16), child: Text(tr('Your report is being prepared. We will notify you when it is ready.')))
              else ...[
                if (r['completed_at'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Completed {0}', [_date(r['completed_at'])]), style: const TextStyle(color: ArjColors.ink600))),
                if (r['pdf_url'] != null) Padding(padding: const EdgeInsets.only(top: 12), child: Align(alignment: Alignment.centerLeft, child: pdfButton(context, widget.state, '/reports/${widget.ref}'))),
                SectionsView((r['sections'] as List?) ?? const []),
              ],
            ]),
          ),
        ),
      );
}

class ReportDetailsForm extends StatefulWidget {
  const ReportDetailsForm({super.key, required this.state, required this.ref, required this.twoProfiles, this.needsAddress = false, required this.onDone});
  final AppState state;
  final String ref;
  final bool twoProfiles;

  /// Printed report: ask where to post it.
  final bool needsAddress;
  final VoidCallback onDone;
  @override
  State<ReportDetailsForm> createState() => _ReportDetailsFormState();
}

class _ReportDetailsFormState extends State<ReportDetailsForm> {
  final _question = TextEditingController();
  late final _dPhone = TextEditingController(text: '${widget.state.user?['phone'] ?? ''}'.replaceAll('null', ''));
  final _dAddress = TextEditingController(), _dCity = TextEditingController(), _dPin = TextEditingController();
  String? _a, _b;
  String _lang = 'en';
  bool _busy = false;
  Map<String, String> _errors = {};
  int _v = 0;

  @override
  void dispose() {
    for (final c in [_question, _dPhone, _dAddress, _dCity, _dPin]) {
      c.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _delivery => widget.needsAddress
      ? {'delivery_name': '${widget.state.user?['name'] ?? 'Customer'}', 'delivery_phone': normalizeIndianMobile(_dPhone.text), 'delivery_address': _dAddress.text.trim(),
          'delivery_city': _dCity.text.trim(), 'delivery_pincode': _dPin.text.trim()}
      : const {};

  Future<void> _submit() async {
    if (_a == null || (widget.twoProfiles && _b == null)) {
      return setState(() => _errors = {'_': widget.twoProfiles ? 'Please choose both birth profiles.' : 'Please choose a birth profile.'});
    }
    if (widget.twoProfiles && _a == _b) return setState(() => _errors = {'_': 'Please choose two different profiles.'});
    setState(() { _busy = true; _errors = {}; });
    try {
      final q = _question.text.trim();
      await widget.state.api.post('/reports/${widget.ref}/details', {'profile_a': _a, if (_b != null) 'profile_b': _b, 'language': _lang, if (q.isNotEmpty) 'question': q, ..._delivery});
      if (!mounted) return;
      showSnack(context, 'Thank you. We have started preparing your report.');
      widget.onDone();
    } on ApiException catch (e) {
      setState(() => _errors = e.fieldErrors.isEmpty ? {'_': e.message} : e.fieldErrors);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AsyncView<List<Map<String, dynamic>>>(
        key: ValueKey(_v),
        load: () async => unwrapList(await widget.state.api.get('/birth-profiles')),
        builder: (context, profiles, _) {
          final items = [for (final p in profiles) DropdownMenuItem<String>(value: '${p['ref']}', child: Text(tr('${p['name']}')))];
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const SectionTitle('Details for your report'),
            if (profiles.isEmpty) Text(tr('Add a birth profile first, then come back here.')),
            if (profiles.isNotEmpty) ...[
              DropdownButtonFormField<String>(initialValue: _a, items: items, onChanged: (v) => setState(() => _a = v),
                  decoration: InputDecoration(labelText: trn(widget.twoProfiles ? 'First person' : 'Birth profile'), errorText: trn(_errors['profile_a']))),
              if (widget.twoProfiles) ...[
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(initialValue: _b, items: items, onChanged: (v) => setState(() => _b = v),
                    decoration: InputDecoration(labelText: tr('Second person'), errorText: trn(_errors['profile_b']))),
              ],
            ],
            TextButton.icon(
              onPressed: () async {
                final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => AddBirthProfileScreen(state: widget.state)));
                if (saved == true) setState(() => _v++);
              },
              icon: const Icon(Icons.add),
              label: Text(tr('Add a birth profile')),
            ),
            const SizedBox(height: 8),
            _LanguagePicker(value: _lang, onChanged: (v) => setState(() => _lang = v)),
            const SizedBox(height: 12),
            TextField(controller: _question, maxLines: 3, maxLength: 500, decoration: InputDecoration(labelText: tr('Anything specific to look at? (optional)'), errorText: trn(_errors['question']))),
            if (widget.needsAddress) ...[
              const SectionTitle('Where should we post your printed Kundli?'),
              TextField(controller: _dPhone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Mobile number'), errorText: trn(_errors['delivery_phone']))),
              const SizedBox(height: 12),
              TextField(controller: _dAddress, maxLines: 2, maxLength: 400, decoration: InputDecoration(labelText: tr('House, street and area'), errorText: trn(_errors['delivery_address']))),
              const SizedBox(height: 12),
              TextField(controller: _dCity, decoration: InputDecoration(labelText: tr('City and state'), errorText: trn(_errors['delivery_city']))),
              const SizedBox(height: 12),
              TextField(controller: _dPin, keyboardType: TextInputType.number, maxLength: 6, decoration: InputDecoration(labelText: tr('PIN code'), errorText: trn(_errors['delivery_pincode']))),
            ],
            FormError(_errors['_'] ?? _errors['language']),
            const SizedBox(height: 12),
            FilledButton(onPressed: _busy || profiles.isEmpty ? null : _submit, child: Text(tr(_busy ? 'Sending…' : 'Submit details'))),
          ]);
        },
      );
}

class _LanguagePicker extends StatelessWidget {
  const _LanguagePicker({required this.value, required this.onChanged});
  final String value;
  final ValueChanged<String> onChanged;
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr('Language')),
        const SizedBox(height: 6),
        SegmentedButton<String>(
          segments: [ButtonSegment(value: 'en', label: Text(tr('English'))), ButtonSegment(value: 'hi', label: Text(tr('हिन्दी')))],
          selected: {value},
          onSelectionChanged: (s) => onChanged(s.first),
        ),
      ]);
}

// ───────────────────────────── Puja ─────────────────────────────

/// Puja requests (puja on request, as on the website) and earlier online puja bookings.
class PujaListScreen extends StatelessWidget {
  const PujaListScreen({super.key, required this.state, this.highlight});
  final AppState state;

  /// Ref of a request or booking to outline (from a notification).
  final String? highlight;

  Future<(List<Map<String, dynamic>>, List<Map<String, dynamic>>)> _load() async {
    final results = await Future.wait([state.api.get('/me/puja-requests'), state.api.get('/puja')]);
    return (unwrapList(results[0]), unwrapList(results[1]));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Puja requests'))),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => PujaRequestScreen(state: state))),
          icon: const Icon(Icons.temple_hindu_outlined),
          label: Text(tr('Request a puja')),
        ),
        body: SafeArea(
          child: AsyncView<(List<Map<String, dynamic>>, List<Map<String, dynamic>>)>(
            load: _load,
            isEmpty: (l) => l.$1.isEmpty && l.$2.isEmpty,
            emptyText: 'No puja requested yet.',
            builder: (context, data, reload) => ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 88), children: [
              Text(tr('Tell us the puja you need; our team calls you to fix the date, place and dakshina.'), style: const TextStyle(color: ArjColors.ink600)),
              const SizedBox(height: 12),
              for (final q in data.$1) PujaRequestTile(request: q, highlight: q['ref'] == highlight),
              if (data.$2.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 16, bottom: 8), child: Text(tr('Earlier puja bookings'), style: const TextStyle(fontWeight: FontWeight.w700))),
              for (final p in data.$2)
                Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    title: Text(tr('${p['title']}')),
                    subtitle: Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [StatusChip(p['status']), if (p['scheduled_at'] != null) Text(tr(formatWhen(p['scheduled_at'])))]),
                    ),
                    trailing: Icon(Icons.chevron_right, semanticLabel: tr('Open')),
                    onTap: () async {
                      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PujaDetailScreen(state: state, ref: '${p['ref']}')));
                      await reload();
                    },
                  ),
                ),
            ]),
          ),
        ),
      );
}

class PujaDetailScreen extends StatefulWidget {
  const PujaDetailScreen({super.key, required this.state, required this.ref});
  final AppState state;
  final String ref;
  @override
  State<PujaDetailScreen> createState() => _PujaDetailScreenState();
}

class _PujaDetailScreenState extends State<PujaDetailScreen> {
  int _v = 0;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Puja'))),
        body: SafeArea(
          child: AsyncView<Map<String, dynamic>>(
            key: ValueKey(_v),
            load: () async => unwrap(await widget.state.api.get('/puja/${widget.ref}')),
            builder: (context, p, _) => ListView(padding: const EdgeInsets.all(16), children: [
              Text(tr('${p['title']}'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              Row(children: [StatusChip(p['status'])]),
              if (p['scheduled_at'] != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr('Scheduled for {0}', [formatWhen(p['scheduled_at'], pattern: 'EEE, d MMM yyyy · h:mm a')]))),
              if (p['customer_note'] != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr('${p['customer_note']}'))),
              if ((p['photo_urls'] as List? ?? const []).isNotEmpty) ...[
                const SectionTitle('Photos from the puja'),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (var i = 0; i < (p['photo_urls'] as List).length; i++)
                    OutlinedButton.icon(
                      onPressed: () => openFreshLink(context, () async {
                        final urls = (unwrap(await widget.state.api.get('/puja/${widget.ref}'))['photo_urls'] as List?) ?? const [];
                        return i < urls.length ? '${urls[i]}' : null;
                      }),
                      icon: const Icon(Icons.photo_outlined),
                      label: Text(tr((p['photo_urls'] as List).length == 1 ? 'View photo' : 'View photo ${i + 1}')),
                    ),
                ]),
              ] else if ((p['photo_count'] as num? ?? 0) > 0)
                Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('{0} photos shared.', [p['photo_count']]), style: const TextStyle(color: ArjColors.ink600))),
              const SizedBox(height: 12),
              Wrap(spacing: 8, runSpacing: 8, children: [
                if (p['live_link'] != null) FilledButton.icon(onPressed: () => openExternal(context, '${p['live_link']}'), icon: const Icon(Icons.live_tv_outlined), label: Text(tr('Watch live'))),
                if (p['video_url'] != null) OutlinedButton.icon(onPressed: () => openExternal(context, '${p['video_url']}'), icon: const Icon(Icons.play_circle_outline), label: Text(tr('Watch recording'))),
              ]),
              if (p['status'] == 'awaiting_details') SankalpForm(state: widget.state, ref: widget.ref, onDone: () => setState(() => _v++)),
            ]),
          ),
        ),
      );
}

class _Devotee {
  final name = TextEditingController();
  final relation = TextEditingController();
  void dispose() {
    name.dispose();
    relation.dispose();
  }
}

class SankalpForm extends StatefulWidget {
  const SankalpForm({super.key, required this.state, required this.ref, required this.onDone});
  final AppState state;
  final String ref;
  final VoidCallback onDone;
  static const maxDevotees = 8;
  @override
  State<SankalpForm> createState() => _SankalpFormState();
}

class _SankalpFormState extends State<SankalpForm> {
  late final List<_Devotee> _devotees = [_Devotee()..name.text = '${widget.state.user?['name'] ?? ''}'];
  final _gotra = TextEditingController();
  final _wish = TextEditingController();
  final _address = TextEditingController();
  late final _phone = TextEditingController(text: '${widget.state.user?['phone'] ?? ''}');
  String _location = 'online';
  DateTime? _date;
  bool _busy = false;
  Map<String, String> _errors = {};

  @override
  void dispose() {
    for (final d in _devotees) {
      d.dispose();
    }
    for (final c in [_gotra, _wish, _address, _phone]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final devotees = [
      for (final d in _devotees)
        if (d.name.text.trim().isNotEmpty) {'name': d.name.text.trim(), if (d.relation.text.trim().isNotEmpty) 'relation': d.relation.text.trim()},
    ];
    final errs = <String, String>{};
    if (devotees.isEmpty) errs['devotees'] = 'Add at least one devotee name.';
    if (_location == 'home' && _address.text.trim().isEmpty) errs['address'] = 'Please enter the address for the puja.';
    final pe = mobileError(_phone.text);
    if (pe != null) errs['phone'] = pe;
    if (errs.isNotEmpty) return setState(() => _errors = errs);
    setState(() { _busy = true; _errors = {}; });
    try {
      String? opt(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
      await widget.state.api.post('/puja/${widget.ref}/details', {
        'devotees': devotees,
        if (opt(_gotra) != null) 'gotra': opt(_gotra),
        if (opt(_wish) != null) 'wish': opt(_wish),
        'location': _location,
        if (_date != null) 'preferred_date': DateFormat('yyyy-MM-dd').format(_date!),
        if (_location == 'home') 'address': _address.text.trim(),
        'phone': normalizeIndianMobile(_phone.text),
      });
      if (!mounted) return;
      showSnack(context, 'Thank you. Our team will confirm the date and time.');
      widget.onDone();
    } on ApiException catch (e) {
      setState(() => _errors = e.fieldErrors.isEmpty ? {'_': e.message} : e.fieldErrors);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const SectionTitle('Sankalp details'),
        Text(tr('Names are recited in the sankalp. Add up to 8 devotees.'), style: TextStyle(color: ArjColors.ink600)),
        const SizedBox(height: 8),
        for (var i = 0; i < _devotees.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(flex: 3, child: TextField(controller: _devotees[i].name, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: tr('Devotee {0} name', [i + 1])))),
              const SizedBox(width: 8),
              Expanded(flex: 2, child: TextField(controller: _devotees[i].relation, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: tr('Relation')))),
              IconButton(
                tooltip: tr('Remove devotee {0}', [i + 1]),
                onPressed: _devotees.length == 1 ? null : () => setState(() => _devotees.removeAt(i).dispose()),
                icon: const Icon(Icons.remove_circle_outline),
              ),
            ]),
          ),
        if (_errors['devotees'] != null) FormError(_errors['devotees']),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: _devotees.length >= SankalpForm.maxDevotees ? null : () => setState(() => _devotees.add(_Devotee())),
            icon: const Icon(Icons.person_add_alt),
            label: Text(tr(_devotees.length >= SankalpForm.maxDevotees ? 'Maximum 8 devotees' : 'Add devotee')),
          ),
        ),
        TextField(controller: _gotra, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: tr('Gotra (optional)'), errorText: trn(_errors['gotra']))),
        const SizedBox(height: 12),
        TextField(controller: _wish, maxLines: 2, maxLength: 300, decoration: InputDecoration(labelText: tr('Your wish / sankalp (optional)'), errorText: trn(_errors['wish']))),
        Text(tr('Where should the puja be performed?')),
        const SizedBox(height: 6),
        SegmentedButton<String>(
          segments: [ButtonSegment(value: 'online', label: Text(tr('Online'))), ButtonSegment(value: 'temple', label: Text(tr('Temple'))), ButtonSegment(value: 'home', label: Text(tr('At home')))],
          selected: {_location},
          onSelectionChanged: (s) => setState(() => _location = s.first),
        ),
        if (_location == 'home') ...[
          const SizedBox(height: 12),
          TextField(controller: _address, maxLines: 3, decoration: InputDecoration(labelText: tr('Address for the puja'), errorText: trn(_errors['address']))),
        ],
        const SizedBox(height: 12),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.event_outlined),
          title: Text(tr(_date == null ? 'Preferred date (optional)' : DateFormat('EEE, d MMM yyyy').format(_date!))),
          subtitle: _errors['preferred_date'] != null ? Text(tr(_errors['preferred_date']!), style: TextStyle(color: Theme.of(context).colorScheme.error)) : null,
          trailing: _date == null ? null : IconButton(tooltip: tr('Clear date'), onPressed: () => setState(() => _date = null), icon: const Icon(Icons.clear)),
          onTap: () async {
            final now = DateTime.now();
            final d = await showDatePicker(context: context, firstDate: now, lastDate: now.add(const Duration(days: 365)), initialDate: _date ?? now.add(const Duration(days: 3)));
            if (d != null) setState(() => _date = d);
          },
        ),
        TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Mobile number'), errorText: trn(_errors['phone']))),
        FormError(_errors['_'] ?? _errors['location']),
        const SizedBox(height: 16),
        FilledButton(onPressed: _busy ? null : _submit, child: Text(tr(_busy ? 'Sending…' : 'Submit details'))),
      ]);
}

// ───────────────────────────── Notifications ─────────────────────────────

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.state});
  final AppState state;
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  int _v = 0;

  Future<List<Map<String, dynamic>>> _load() async {
    final r = await widget.state.api.get('/notifications');
    widget.state.setUnread((r['unread'] as num?)?.toInt() ?? 0);
    return unwrapList(r);
  }

  Future<void> _open(Map<String, dynamic> n) async {
    if (n['read'] != true) {
      try {
        await widget.state.api.post('/notifications/${n['id']}/read', {});
        n['read'] = true;
        widget.state.setUnread(widget.state.unread > 0 ? widget.state.unread - 1 : 0);
        if (mounted) setState(() {});
      } on ApiException catch (_) {/* still open it */}
    }
    if (!mounted) return;
    final kind = n['kind'] as String?, ref = n['ref'] as String?;
    if (ref != null && const {'booking', 'report', 'puja', 'palm', 'order'}.contains(kind)) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screenForTarget(widget.state, kind, ref)));
    } else if (n['link'] != null) {
      // Admin › Push messages may point to an app screen (app:puja, app:product/…).
      await openAppLink(context, widget.state, '${n['link']}');
    }
  }

  Future<void> _readAll() async {
    try {
      await widget.state.api.post('/notifications/read-all', {});
      widget.state.setUnread(0);
      if (mounted) setState(() => _v++);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Notifications')), actions: [
          IconButton(tooltip: tr('Mark all as read'), onPressed: _readAll, icon: const Icon(Icons.done_all)),
          IconButton(
            tooltip: tr('Notification settings'),
            onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => NotificationPrefsScreen(state: widget.state))),
            icon: const Icon(Icons.tune),
          ),
        ]),
        body: SafeArea(
          child: AsyncView<List<Map<String, dynamic>>>(
            key: ValueKey(_v),
            load: _load,
            isEmpty: (l) => l.isEmpty,
            emptyText: 'No notifications yet.',
            builder: (context, list, _) => ListView.separated(
              itemCount: list.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, i) {
                final n = list[i];
                final unread = n['read'] != true;
                return ListTile(
                  tileColor: unread ? ArjColors.gold50 : null,
                  leading: Icon(unread ? Icons.circle : Icons.circle_outlined, size: 12, color: unread ? ArjColors.gold700 : ArjColors.ink200, semanticLabel: unread ? 'Unread' : 'Read'),
                  title: Text(tr('${n['title']}'), style: TextStyle(fontWeight: unread ? FontWeight.w600 : FontWeight.normal)),
                  subtitle: Text(tr('${n['body'] ?? ''}\n${formatWhen(n['created_at'])}')),
                  isThreeLine: true,
                  onTap: () => _open(n),
                );
              },
            ),
          ),
        ),
      );
}
