import 'package:flutter/widgets.dart';

import 'tokens.dart';

/// One type voice: Mukta, for Latin and Devanagari alike, with tabular figures.
///
/// Mukta's Latin and Devanagari were drawn together, so a Marathi label and the
/// English one beside it share a rhythm, and its `tnum` feature gives every
/// figure the same width, so columns of quantities and rupees line up without a
/// second family. Weight does the work: 400 for body, 500 for labels and
/// captions, 600 for headings and buttons, 700 for the one hero figure.
///
/// The scale is small and quiet: body 14, labels 13, captions 12, a section
/// heading 15, a screen title 18, a stat figure 22 and the hero figure 32.
/// Nothing on screen is set under 12.
///
/// Devanagari reads a touch smaller than Latin at the same size and its matras
/// reach well above and below the line, so [fit] gives small Devanagari text one
/// extra point and a 1.4 line. Line heights never go under 1.3; body stays at
/// 1.4 or more.
abstract final class N360Type {
  /// The family for everything.
  static const String mukta = 'Mukta';

  /// Kept so screens that name them still compile. The design no longer sets
  /// anything in Poppins or DM Mono; both resolve to Mukta through fallback.
  static const String poppins = 'Mukta';
  static const String mono = 'Mukta';

  static const List<FontFeature> _tabular = <FontFeature>[FontFeature.tabularFigures()];

  /// The smallest size any text is set at, in either script.
  static const double minimumSize = 12;

  // Headings.

  /// A welcome or a first-run headline. One per screen at most.
  static const TextStyle displayLarge = TextStyle(
    fontFamily: mukta,
    fontSize: 28,
    height: 1.3,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.3,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// A big heading on a detail page.
  static const TextStyle display = TextStyle(
    fontFamily: mukta,
    fontSize: 22,
    height: 1.3,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.2,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// The screen title.
  static const TextStyle title = TextStyle(
    fontFamily: mukta,
    fontSize: 18,
    height: 1.35,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// A card or section heading.
  static const TextStyle heading = TextStyle(
    fontFamily: mukta,
    fontSize: 15,
    height: 1.4,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Button and control labels.
  static const TextStyle button = TextStyle(
    fontFamily: mukta,
    fontSize: 15,
    height: 1.35,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  // Body.

  /// Lead text and field input.
  static const TextStyle bodyLarge = TextStyle(
    fontFamily: mukta,
    fontSize: 16,
    height: 1.45,
    fontWeight: FontWeight.w400,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle body = TextStyle(
    fontFamily: mukta,
    fontSize: 14,
    height: 1.45,
    fontWeight: FontWeight.w400,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle bodyStrong = TextStyle(
    fontFamily: mukta,
    fontSize: 14,
    height: 1.42,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Field and row labels.
  static const TextStyle label = TextStyle(
    fontFamily: mukta,
    fontSize: 13,
    height: 1.4,
    fontWeight: FontWeight.w500,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Captions and hints. Uses [N360Colors.inkLabel], never `ink3`.
  static const TextStyle caption = TextStyle(
    fontFamily: mukta,
    fontSize: 12,
    height: 1.4,
    fontWeight: FontWeight.w500,
    color: N360Colors.inkLabel,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  // Figures. All tabular, so columns line up.

  /// A small figure beside a caption, or in a dense row.
  static const TextStyle figureSmall = TextStyle(
    fontFamily: mukta,
    fontSize: 13,
    height: 1.35,
    fontWeight: FontWeight.w500,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// A figure in a ledger row.
  static const TextStyle figure = TextStyle(
    fontFamily: mukta,
    fontSize: 15,
    height: 1.35,
    fontWeight: FontWeight.w500,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// A figure that stands on its own: a stat card value.
  static const TextStyle figureLarge = TextStyle(
    fontFamily: mukta,
    fontSize: 22,
    height: 1.3,
    fontWeight: FontWeight.w600,
    letterSpacing: -0.2,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// The one confident number at the top of a screen. One per screen.
  static const TextStyle figureHero = TextStyle(
    fontFamily: mukta,
    fontSize: 32,
    height: 1.2,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.6,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static final RegExp _devanagari = RegExp('[ऀ-ॿ]');

  /// True when [text] holds any Devanagari.
  static bool isDevanagari(String text) => _devanagari.hasMatch(text);

  /// [style] sized for the script of [text].
  ///
  /// Devanagari at 15 or under gets one more point and a line of at least 1.4,
  /// so its matras stay clear and a Marathi label reads as large as the English
  /// one beside it. Larger sizes are left alone. Latin text comes back as is.
  static TextStyle fit(TextStyle style, String text) =>
      isDevanagari(text) ? forDevanagari(style) : style;

  /// [style] adjusted for Devanagari regardless of content. The theme uses this
  /// when the whole app is in Marathi or Hindi.
  static TextStyle forDevanagari(TextStyle style) {
    final double? size = style.fontSize;
    final double? height = style.height;
    final bool small = size != null && size <= 15;
    final bool lowLine = height != null && height < 1.4;
    if (!small && !lowLine) return style;
    return style.copyWith(
      fontSize: small ? size + 1 : size,
      height: lowLine ? 1.4 : height,
    );
  }

  static const TextStyle _sectionLatin = TextStyle(
    fontFamily: mukta,
    fontSize: 12,
    height: 1.4,
    fontWeight: FontWeight.w500,
    color: N360Colors.inkLabel,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle _sectionDevanagari = TextStyle(
    fontFamily: mukta,
    fontSize: 13,
    height: 1.4,
    fontWeight: FontWeight.w500,
    color: N360Colors.inkLabel,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// The small section label style for [text].
  static TextStyle eyebrowFor(String text) =>
      isDevanagari(text) ? _sectionDevanagari : _sectionLatin;

  /// Section labels are not upper-cased, so this returns [text] as given.
  static String eyebrowText(String text) => text;

  /// Lettering for the one real stamp: Latin in capitals. Devanagari has no
  /// case. Status pills show their label as given.
  static String stampText(String text) =>
      isDevanagari(text) ? text : text.toUpperCase();

  /// Tracking for capitals, which is applied only to Latin text, because
  /// tracking breaks the headline that joins Devanagari letters into a word.
  static double trackingFor(String text, double latin) =>
      isDevanagari(text) ? 0 : latin;
}

/// How far the system text size setting may push the app.
///
/// Below [min] a 12 caption would fall under what a site engineer can read in
/// sun; above [max] a 32 hero figure no longer fits a 360dp card beside its
/// meta. The app shell wraps the whole app once:
///
/// ```dart
/// builder: (context, child) => N360TextScaling(child: LedgerPaper.root(child: child)),
/// ```
///
/// Widgets that paint their own text clamp again through [of], so a stray
/// unclamped scaler can never break a painted figure.
abstract final class N360TextScale {
  static const double min = 0.9;
  static const double max = 1.3;

  /// The clamped scaler for [context].
  static TextScaler of(BuildContext context) =>
      MediaQuery.textScalerOf(context).clamp(minScaleFactor: min, maxScaleFactor: max);
}

/// Clamps system text scaling for everything beneath it. Put one at the root.
class N360TextScaling extends StatelessWidget {
  const N360TextScaling({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return MediaQuery.withClampedTextScaling(
      minScaleFactor: N360TextScale.min,
      maxScaleFactor: N360TextScale.max,
      child: child,
    );
  }
}
