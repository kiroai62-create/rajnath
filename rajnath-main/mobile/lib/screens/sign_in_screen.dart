import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api/api_client.dart';
import '../state/app_state.dart';
import '../util/i18n.dart';

/// Email / mobile OTP sign-in and sign-up, matching the website flow.
class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key, required this.state});
  final AppState state;
  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  final _id = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  Map<String, dynamic>? _challenge;
  bool _terms = false, _busy = false;
  String? _error;
  int _resendIn = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _id.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  void _startTimer(int s) {
    _timer?.cancel();
    setState(() => _resendIn = s);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();
      setState(() => _resendIn = _resendIn > 0 ? _resendIn - 1 : 0);
      if (_resendIn == 0) t.cancel();
    });
  }

  Future<void> _run(Future<void> Function() f) async {
    setState(() { _busy = true; _error = null; });
    try {
      await f();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send() => _run(() async {
        final r = await widget.state.api.post('/auth/otp/start', {'identifier': _id.text.trim()});
        setState(() => _challenge = r);
        _startTimer((r['resend_in'] as num?)?.toInt() ?? 30);
      });

  Future<void> _resend() => _run(() async {
        await widget.state.api.post('/auth/otp/resend', {'challenge': _challenge!['challenge']});
        _startTimer(30);
      });

  Future<void> _verify() => _run(() async {
        final isNew = _challenge!['is_new_account'] == true;
        if (isNew && !_terms) throw ApiException('Please accept the Terms and Privacy Policy.');
        final r = await widget.state.api.post('/auth/otp/verify', {
          'challenge': _challenge!['challenge'], 'code': _code.text.trim(), 'device_name': 'Android app', 'platform': 'android',
          if (isNew) 'name': _name.text.trim(), if (isNew) 'accept_terms': true,
        });
        await widget.state.completeSignIn(r['token'] as String, (r['user'] as Map).cast<String, dynamic>());
        if (mounted) Navigator.of(context).pop(true);
      });

  @override
  Widget build(BuildContext context) {
    final c = _challenge;
    return Scaffold(
      appBar: AppBar(title: Text(tr(c == null ? 'Sign in' : 'Enter code'))),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(20), children: [
          Center(child: Image.asset('assets/images/logo-full.png', width: 260, semanticLabel: tr('Acharya Rajnath Jha'))),
          const SizedBox(height: 20),
          if (c == null) ...[
            Text(tr('Enter your email or mobile number. We will send you a 6-digit code — no password needed.')),
            const SizedBox(height: 20),
            TextField(controller: _id, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.username, AutofillHints.telephoneNumber],
                decoration: InputDecoration(labelText: tr('Email or mobile number'), hintText: tr('98xxxxxxxx or you@example.com')), onSubmitted: (_) => _send()),
            const SizedBox(height: 20),
            FilledButton(onPressed: _busy ? null : _send, child: Text(tr(_busy ? 'Sending…' : 'Send code'))),
          ] else ...[
            Text(tr('We sent a code to {0}. It is valid for 10 minutes.', [c['sent_to']])),
            const SizedBox(height: 20),
            TextField(controller: _code, keyboardType: TextInputType.number, maxLength: 6, autofillHints: const [AutofillHints.oneTimeCode],
                inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: InputDecoration(labelText: tr('6-digit code'), counterText: tr(''))),
            if (c['is_new_account'] == true) ...[
              const SizedBox(height: 16),
              TextField(controller: _name, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: tr('Your name'))),
              CheckboxListTile(contentPadding: EdgeInsets.zero, value: _terms, onChanged: (v) => setState(() => _terms = v ?? false),
                  title: Text(tr('I accept the Terms of use and Privacy policy')), controlAffinity: ListTileControlAffinity.leading),
            ],
            const SizedBox(height: 16),
            FilledButton(onPressed: _busy ? null : _verify, child: Text(tr(c['is_new_account'] == true ? 'Create account' : 'Sign in'))),
            const SizedBox(height: 8),
            TextButton(onPressed: _busy || _resendIn > 0 ? null : _resend, child: Text(tr(_resendIn > 0 ? 'Resend code in ${_resendIn}s' : 'Resend code'))),
            TextButton(onPressed: () => setState(() { _challenge = null; _code.clear(); }), child: Text(tr('Use a different email or number'))),
          ],
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr(_error!), style: TextStyle(color: Theme.of(context).colorScheme.error), semanticsLabel: 'Error: $_error')),
        ]),
      ),
    );
  }
}
