import 'package:flutter/material.dart';

import '../state/app_state.dart';
import '../theme/app_theme.dart';
import '../util/i18n.dart';
import '../widgets/common.dart';
import 'services_screens.dart';

/// "Get a consultation with Acharya Ji" after a free result (Kundli, matching, calculators, Rashifal, gochar,
/// muhurat, Panchang), like the website's "Book a reading". With [printed] it also offers the Printed Lifetime Kundli.
class ConsultCta extends StatelessWidget {
  const ConsultCta({super.key, required this.state, this.text, this.printed = false});
  final AppState state;
  final String? text;
  final bool printed;

  static const consultSlug = 'consultation-with-acharya-ji';
  static const printedSlug = 'printed-lifetime-kundli';

  void _open(BuildContext context, String slug) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => ServiceDetailScreen(state: state, slug: slug)));

  @override
  Widget build(BuildContext context) {
    if (!state.featureOn('consultation')) return const SizedBox.shrink();
    return Container(
      margin: const EdgeInsets.only(top: 20),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(colors: [ArjColors.ink900, ArjColors.ink800, ArjColors.maroon700]),
        borderRadius: BorderRadius.circular(ArjStyle.radiusCard),
        border: Border.all(color: ArjColors.gold300.withValues(alpha: 0.5)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr(text ?? 'Want Acharya Ji to read this in detail?'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 16)),
        const SizedBox(height: 4),
        Text(tr('Personal guidance on career, marriage, health and remedies, by video, audio or in person.'), style: TextStyle(color: Colors.white.withValues(alpha: 0.8), height: 1.4)),
        const SizedBox(height: 12),
        Wrap(spacing: 10, runSpacing: 10, children: [
          GoldButton(label: tr('Get a consultation with Acharya Ji'), icon: Icons.videocam_outlined, onPressed: () => _open(context, consultSlug)),
          if (printed) GlassButton(label: tr('Printed lifetime Kundli'), icon: Icons.menu_book_outlined, onPressed: () => _open(context, printedSlug)),
        ]),
      ]),
    );
  }
}
