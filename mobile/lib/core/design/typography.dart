import 'package:flutter/widgets.dart';

import 'tokens.dart';

/// Khand for headings, Mukta for body, DM Mono for figures.
///
/// Line heights are set for Devanagari: matras reach well above the shirorekha
/// and below the baseline, so nothing here goes under 1.25 and body stays at
/// 1.4 or more. `TextLeadingDistribution.even` keeps the extra space balanced
/// so a line sits centred in its slot.
///
/// DM Mono has no Devanagari and no rupee sign, so every style falls back to
/// Mukta.
abstract final class N360Type {
  static const String khand = 'Khand';
  static const String mukta = 'Mukta';
  static const String mono = 'DMMono';

  static const List<String> _fallback = <String>[mukta];
  static const List<FontFeature> _tabular = <FontFeature>[FontFeature.tabularFigures()];

  // Khand display.

  static const TextStyle displayLarge = TextStyle(
    fontFamily: khand,
    fontFamilyFallback: _fallback,
    fontSize: 40,
    height: 1.28,
    fontWeight: FontWeight.w700,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle display = TextStyle(
    fontFamily: khand,
    fontFamilyFallback: _fallback,
    fontSize: 32,
    height: 1.3,
    fontWeight: FontWeight.w700,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle title = TextStyle(
    fontFamily: khand,
    fontFamilyFallback: _fallback,
    fontSize: 26,
    height: 1.32,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle heading = TextStyle(
    fontFamily: khand,
    fontFamilyFallback: _fallback,
    fontSize: 22,
    height: 1.34,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  /// Button and control labels.
  static const TextStyle button = TextStyle(
    fontFamily: khand,
    fontFamilyFallback: _fallback,
    fontSize: 22,
    height: 1.3,
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

  /// Field and row labels: 15dp semi-bold in the strong secondary ink.
  static const TextStyle label = TextStyle(
    fontFamily: mukta,
    fontSize: 15,
    height: 1.4,
    fontWeight: FontWeight.w600,
    color: N360Colors.ink2,
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

  // DM Mono figures. Tabular so a column of figures lines up like a ledger.

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

  static const TextStyle figureLarge = TextStyle(
    fontFamily: mono,
    fontFamilyFallback: _fallback,
    fontSize: 28,
    height: 1.3,
    fontWeight: FontWeight.w500,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle figureHero = TextStyle(
    fontFamily: mono,
    fontFamilyFallback: _fallback,
    fontSize: 40,
    height: 1.25,
    fontWeight: FontWeight.w500,
    color: N360Colors.ink,
    fontFeatures: _tabular,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  // The web app's `.eyebrow`.

  static const TextStyle _eyebrowLatin = TextStyle(
    fontFamily: mono,
    fontFamilyFallback: _fallback,
    fontSize: 12.5,
    height: 1.4,
    fontWeight: FontWeight.w500,
    letterSpacing: 1.75,
    color: N360Colors.inkLabel,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static const TextStyle _eyebrowDevanagari = TextStyle(
    fontFamily: mukta,
    fontSize: 15,
    height: 1.4,
    fontWeight: FontWeight.w600,
    color: N360Colors.inkLabel,
    leadingDistribution: TextLeadingDistribution.even,
    decoration: TextDecoration.none,
  );

  static final RegExp _devanagari = RegExp('[\u0900-\u097F]');

  /// True when [text] holds any Devanagari.
  static bool isDevanagari(String text) => _devanagari.hasMatch(text);

  /// The eyebrow style for [text].
  ///
  /// Tracking breaks the shirorekha that joins Devanagari letters into a word,
  /// so Marathi and Hindi get Mukta with no tracking instead.
  static TextStyle eyebrowFor(String text) =>
      isDevanagari(text) ? _eyebrowDevanagari : _eyebrowLatin;

  /// Uppercases Latin text. Devanagari has no case, so it passes through.
  static String eyebrowText(String text) =>
      isDevanagari(text) ? text : text.toUpperCase();

  /// Tracking for condensed caps, which is applied only to Latin text.
  static double trackingFor(String text, double latin) =>
      isDevanagari(text) ? 0 : latin;
}
