import 'package:flutter/widgets.dart';

import 'tokens.dart';

/// Poppins for headings and big figures, Mukta for body, DM Mono only where
/// columns of figures must line up.
///
/// Line heights are set for Devanagari: matras reach well above the shirorekha
/// and below the baseline, so nothing here goes under 1.25 and body stays at
/// 1.4 or more. `TextLeadingDistribution.even` keeps the extra space balanced
/// so a line sits centred in its slot. Every style falls back to Mukta.
abstract final class N360Type {
  static const String poppins = 'Poppins';
  static const String mukta = 'Mukta';
  static const String mono = 'DMMono';

  static const List<String> _fallback = <String>[mukta];
  static const List<FontFeature> _tabular = <FontFeature>[FontFeature.tabularFigures()];

  // Poppins headings.

  static const TextStyle displayLarge = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 36,
    height: 1.3,
    fontWeight: FontWeight.w700,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle display = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 28,
    height: 1.34,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle title = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 21,
    height: 1.4,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle heading = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 17,
    height: 1.45,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Button and control labels.
  static const TextStyle button = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 16,
    height: 1.4,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  // Mukta body.

  static const TextStyle bodyLarge = TextStyle(
    fontFamily: mukta,
    fontSize: 18,
    height: 1.45,
    fontWeight: FontWeight.w400,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle body = TextStyle(
    fontFamily: mukta,
    fontSize: 16,
    height: 1.45,
    fontWeight: FontWeight.w400,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle bodyStrong = TextStyle(
    fontFamily: mukta,
    fontSize: 16,
    height: 1.42,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Field and row labels.
  static const TextStyle label = TextStyle(
    fontFamily: mukta,
    fontSize: 15,
    height: 1.4,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Captions and hints. Uses [N360Colors.inkLabel], never `ink3`.
  static const TextStyle caption = TextStyle(
    fontFamily: mukta,
    fontSize: 14,
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
    fontSize: 15,
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
    fontSize: 18,
    height: 1.35,
    fontWeight: FontWeight.w500,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Poppins, for a figure that stands on its own.
  static const TextStyle figureLarge = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 26,
    height: 1.3,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// The huge confident number at the top of a screen.
  static const TextStyle figureHero = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 44,
    height: 1.22,
    fontWeight: FontWeight.w600,
    letterSpacing: -0.5,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static final RegExp _devanagari = RegExp('[\u0900-\u097F]');

  /// True when [text] holds any Devanagari.
  static bool isDevanagari(String text) => _devanagari.hasMatch(text);

  static const TextStyle _sectionLatin = TextStyle(
    fontFamily: poppins,
    fontFamilyFallback: _fallback,
    fontSize: 14,
    height: 1.45,
    fontWeight: FontWeight.w500,
    color: N360Colors.inkLabel,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle _sectionDevanagari = TextStyle(
    fontFamily: mukta,
    fontSize: 15,
    height: 1.45,
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
