import 'package:flutter/widgets.dart';

/// Measurement-book palette. The first block is the contract, one for one with
/// the web app's `app.css`. The second block is derived for outdoor legibility:
/// body text must reach 7:1 on paper and labels 4.5:1 in direct sun.
abstract final class N360Colors {
  static const Color paper = Color(0xFFF5F1E8);
  static const Color paper2 = Color(0xFFEBE5D7);
  static const Color sheet = Color(0xFFFBF9F3);
  static const Color ink = Color(0xFF16201F);
  static const Color ink2 = Color(0xFF3C4A47);

  /// Decoration only. Fails 4.5:1 on paper, so never use it for text.
  static const Color ink3 = Color(0xFF6B7773);
  static const Color rule = Color(0xFFD9D2C1);
  static const Color laterite = Color(0xFFB4451F);
  static const Color lateriteDark = Color(0xFF8F3416);
  static const Color haldi = Color(0xFFE2A31A);
  static const Color neel = Color(0xFF1B4A66);
  static const Color neelDeep = Color(0xFF0F2C3D);
  static const Color moss = Color(0xFF3B6A4C);

  // Derived text colours.

  /// Secondary text that replaces [ink3] outdoors: 6.3:1 on paper.
  static const Color inkLabel = Color(0xFF4D5B57);

  /// Ink for amber statuses: [haldi] itself cannot carry text on paper.
  static const Color haldiInk = Color(0xFF6E4A00);

  /// A deeper moss for small text.
  static const Color mossInk = Color(0xFF2C5239);

  /// Stamp and error red: holds 4.5:1 on paper, sheet and paper2.
  static const Color stampRed = Color(0xFFA63B1A);

  // Derived drafting colours. Solid so they cost no blending.

  static const Color gridMinor = Color(0xFFEFEADE);
  static const Color gridMajor = Color(0xFFE5DFD0);
  static const Color marginRed = Color(0xFFD08A73);

  /// The underside of a sheet, seen as a thin edge below it.
  static const Color paperEdge = Color(0xFFCDC5B1);

  /// Stronger hairline for fields and control borders, 3:1 on paper.
  static const Color ruleStrong = Color(0xFF8D8672);

  /// The thickness of a laterite block, seen below a primary button.
  static const Color lateriteEdge = Color(0xFF6E2710);
  static const Color inkEdge = Color(0xFF090F0E);
}

/// Spacing scale, in logical pixels. Everything sits on a 4dp beat.
abstract final class N360Space {
  static const double xxs = 2;
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 24;
  static const double xxl = 32;
  static const double xxxl = 48;

  /// Horizontal screen gutter.
  static const double gutter = 16;

  /// Where a ledger page's red margin rule sits, and where text clears it.
  static const double margin = 40;
  static const double marginText = 52;
}

/// Corners stay square-ish: paper has corners, buttons are not pills.
abstract final class N360Radius {
  static const double small = 3;
  static const double medium = 4;

  static const BorderRadius sm = BorderRadius.all(Radius.circular(small));
  static const BorderRadius md = BorderRadius.all(Radius.circular(medium));
}

/// Hit target sizes, sized for gloved thumbs.
abstract final class N360Size {
  static const double tap = 56;
  static const double tapLarge = 64;

  /// Height of a numeric keypad key.
  static const double key = 64;

  /// Height of the bottom bar without the safe area.
  static const double bar = 64;

  /// Thickness of a sheet's visible bottom edge.
  static const double edge = 3;
  static const double hairline = 1;
}
