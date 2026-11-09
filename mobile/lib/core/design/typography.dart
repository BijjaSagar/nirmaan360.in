import 'package:flutter/widgets.dart';

import 'tokens.dart';

/// Poppins for headings and big figures, Mukta for body, DM Mono only where
/// columns of figures must line up.
///
/// The scale is set against the reference design: small, quiet type with air
/// around it. Body is 14, labels 13, captions 12, a section heading 15, a
/// screen title 18, a stat figure 22 and the one hero figure on a screen 34.
/// Nothing on screen is set under 12.
///
/// Devanagari reads a touch smaller than Latin at the same size and its matras
/// reach well above and below the line, so [fit] gives small Devanagari text one
/// extra point and a 1.4 line. Line heights here never go under 1.3, body stays
/// at 1.4 or more, and `TextLeadingDistribution.even` keeps a line centred in
/// its slot. Every style falls back to Mukta.
abstract final class N360Type {
  static const String poppins = 'Poppins';
  static const String mukta = 'Mukta';
  static const String mono = 'DMMono';

  static const List<String> _fallback = <String>[mukta];
  static const List<FontFeature> _tabular = <FontFeature>[FontFeature.tabularFigures()];

  /// The smallest size any text is set at, in either script.
  static const double minimumSize = 12;

  // Poppins headings.

  /// A welcome or a first-run headline. One per screen at most.
  static const TextStyle displayLarge = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 30,
    height: 1.3,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.3,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// A big heading on a detail page.
  static const TextStyle display = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 24,
    height: 1.3,
    fontWeight: FontWeight.w600,
    letterSpacing: -0.2,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// The screen title.
  static const TextStyle title = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 18,
    height: 1.35,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// A card or section heading.
  static const TextStyle heading = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 15,
    height: 1.4,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Button and control labels.
  static const TextStyle button = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 15,
    height: 1.35,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  // Mukta body.

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
    fontWeight: FontWeight.w600,
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

  // Figures.

  /// DM Mono, for figures stacked in a column.
  static const TextStyle figureSmall = TextStyle(
    fontFamily: mono,
    fontFamilyFallback: _fallback,
    fontSize: 13,
    height: 1.35,
    fontWeight: FontWeight.w400,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// DM Mono, for figures stacked in a column.
  static const TextStyle figure = TextStyle(
    fontFamily: mono,
    fontFamilyFallback: _fallback,
    fontSize: 16,
    height: 1.35,
    fontWeight: FontWeight.w500,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Poppins, for a figure that stands on its own: a stat card value.
  static const TextStyle figureLarge = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
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
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 34,
    height: 1.2,
    fontWeight: FontWeight.w600,
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
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
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
    fontWeight: FontWeight.w600,
    color: N360Colors.inkLabel,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// The small section label style for [text]. Devanagari sets better in Mukta.
  static TextStyle eyebrowFor(String text) =>
      isDevanagari(text) ? _sectionDevanagari : _sectionLatin;

  /// Section labels are no longer upper-cased, so this returns [text] as given.
  static String eyebrowText(String text) => text;

  /// Stamp lettering: Latin in capitals. Devanagari has no case.
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
/// sun; above [max] a 34 hero figure no longer fits a 360dp card beside its
/// icon. The app shell wraps the whole app once:
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
