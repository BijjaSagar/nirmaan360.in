import 'package:flutter/widgets.dart';

/// The warm palette: light warm greys, white cards, amber-to-orange accents.
///
/// The names come from the first version of the app and stay so screens keep
/// compiling; read them by role, not by the old paper metaphor. Text roles hold
/// outdoor contrast on every background including the peach foot of the page:
/// body 7:1, labels 4.5:1. Text on gold is always dark ink, because white on
/// orange fails in direct sun.
abstract final class N360Colors {
  /// Page base, the top of the warm gradient.
  static const Color paper = Color(0xFFF3F1EE);

  /// Soft tile fill on white cards.
  static const Color paper2 = Color(0xFFF0EBE4);

  /// Card white.
  static const Color sheet = Color(0xFFFFFFFF);

  static const Color ink = Color(0xFF1B1815);

  /// Body text on any surface: 7:1 or better, peach included.
  static const Color ink2 = Color(0xFF45403A);

  /// Decoration only. Fails 4.5:1 on paper, so never use it for text.
  static const Color ink3 = Color(0xFF8E887F);

  /// Hairlines on cards.
  static const Color rule = Color(0xFFEDE7DF);

  /// Deep orange for text, icons and focus rings. Passes on white, paper and peach.
  static const Color laterite = Color(0xFF9F3F08);
  static const Color lateriteDark = Color(0xFF7E3206);

  /// Gold accent. Carries dark ink, never white text.
  static const Color haldi = Color(0xFFF5A623);

  /// Slate for "in review". Used sparingly so the palette stays warm.
  static const Color neel = Color(0xFF2E4756);
  static const Color neelDeep = Color(0xFF1C2F3A);
  static const Color moss = Color(0xFF2E7D50);

  // The gold family.

  static const Color amber = Color(0xFFFFC24D);
  static const Color orange = Color(0xFFF59412);

  /// The foot of the page.
  static const Color peach = Color(0xFFF6D3B6);

  /// Pale gold wash for tinted surfaces such as active tiles.
  static const Color goldTint = Color(0xFFFFF4DF);

  // Derived text colours.

  /// Secondary text that replaces [ink3] outdoors: 4.5:1 even on peach.
  static const Color inkLabel = Color(0xFF615B52);

  /// Ink for amber statuses: [haldi] itself cannot carry text on a light surface.
  static const Color haldiInk = Color(0xFF6B4700);

  /// A deep green for text.
  static const Color mossInk = Color(0xFF1F5A36);

  /// Error and rejected red: holds 4.5:1 on every light surface.
  static const Color stampRed = Color(0xFFB3261E);

  // Surface details. Solid so they cost no blending.

  static const Color gridMinor = Color(0xFFF0EBE4);
  static const Color gridMajor = Color(0xFFE8E1D7);
  static const Color marginRed = Color(0xFFE8B78D);

  /// The soft lip under a pressable tile.
  static const Color paperEdge = Color(0xFFEDE6DB);

  /// A stronger hairline for fields, about 3:1 on white.
  static const Color ruleStrong = Color(0xFFA29A8E);

  static const Color lateriteEdge = Color(0xFF7E3206);
  static const Color inkEdge = Color(0xFF0B0A09);

  /// The thin light line around a white card.
  static const Color cardEdge = Color(0xFFF6F2EC);

  /// The warm tone of the one soft shadow a card has.
  static const Color shadow = Color(0xFFB9875B);
}

/// The static gradients. They are painted once and never animate.
abstract final class N360Gradients {
  /// Amber to orange, for primary actions and active tiles. Dark ink on top.
  static const LinearGradient gold = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: <Color>[N360Colors.amber, N360Colors.orange],
  );

  /// The page: light warm grey fading to peach at the foot.
  static const LinearGradient page = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: <Color>[
      N360Colors.paper,
      Color(0xFFF2EEE9),
      Color(0xFFF5E2D1),
      N360Colors.peach,
    ],
    stops: <double>[0, 0.58, 0.86, 1],
  );

  /// The left-to-right wash of a filled progress bar.
  static const LinearGradient goldFade = LinearGradient(
    colors: <Color>[Color(0xFFFFE6B3), N360Colors.amber, N360Colors.orange],
    stops: <double>[0, 0.62, 1],
  );
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

  /// Kept for screens built on the earlier margin. There is no margin rule now.
  static const double margin = 16;
  static const double marginText = 16;
}

/// Corners: soft rounded tiles and large cards.
abstract final class N360Radius {
  static const double small = 12;
  static const double medium = 16;
  static const double tile = 18;
  static const double card = 24;

  static const BorderRadius sm = BorderRadius.all(Radius.circular(small));
  static const BorderRadius md = BorderRadius.all(Radius.circular(medium));
  static const BorderRadius tileRadius = BorderRadius.all(Radius.circular(tile));
  static const BorderRadius cardRadius = BorderRadius.all(Radius.circular(card));
}

/// Hit target sizes, sized for gloved thumbs.
abstract final class N360Size {
  static const double tap = 56;
  static const double tapLarge = 64;

  /// Height of a numeric keypad key.
  static const double key = 64;

  /// Minimum height of one item in the bottom bar.
  static const double bar = 64;

  /// Space a screen leaves under a tile for its soft lower lip.
  static const double edge = 3;
  static const double hairline = 1;
}
