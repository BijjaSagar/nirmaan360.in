import 'package:flutter/widgets.dart';

/// The product owner's palette, applied with restraint.
///
/// The page is the warm grey, every surface is white, and structure comes from
/// hairlines in the light surface tone rather than shadows or tints. Colour is
/// spent in three places only: the one primary action, data (progress, status),
/// and the urgent red-orange. Text on the primary is white; small orange text
/// and icons use [laterite], a darker cut that holds 4.5:1 on light surfaces.
///
/// The names come from the first version of the app and stay so screens keep
/// compiling; read them by role. Outdoor contrast holds on every background:
/// body 7:1, labels 4.5:1.
abstract final class N360Colors {
  /// Page background.
  static const Color paper = Color(0xFFEFECEB);

  /// The light surface tone: hairlines, dividers, tracks, disabled fills.
  static const Color paper2 = Color(0xFFE3D6CF);

  /// Every card and control face.
  static const Color sheet = Color(0xFFFFFFFF);

  static const Color ink = Color(0xFF070706);

  /// Charcoal for secondary text and labels. Long body text uses [ink].
  static const Color ink2 = Color(0xFF575250);

  /// Decoration only: chevrons, placeholders. Fails 4.5:1 on paper, so never
  /// use it for text.
  static const Color ink3 = Color(0xFF9FA2A7);

  /// Hairlines between rows and around cards.
  static const Color rule = Color(0xFFE3D6CF);

  /// The one primary action: a very slightly deeper cut of [orange] so that
  /// white text on it passes 4.5:1 (5.0:1). Visually the same colour.
  static const Color primary = Color(0xFFB0552A);

  /// Text and icons on [primary].
  static const Color onPrimary = Color(0xFFFFFFFF);

  /// The wash behind a selected tile or an active input, with a [primary] edge.
  static const Color primaryTint = Color(0xFFE1C9BB);

  /// A darker cut of [orange] for small text, icons and focus rings. The palette's
  /// own deep oranges stay below 4.5:1 on light surfaces, so they are fills only.
  static const Color laterite = Color(0xFF8F4622);
  static const Color lateriteDark = Color(0xFF7A3A1B);

  /// Gold accent, as a data fill (half day, progress). Carries dark ink.
  static const Color haldi = Color(0xFFE1985E);

  /// Slate for "in review". Used sparingly so the palette stays warm.
  static const Color neel = Color(0xFF2E4756);
  static const Color neelDeep = Color(0xFF1C2F3A);
  static const Color moss = Color(0xFF2E7D50);

  // The gold family, exactly as the owner gave it.

  static const Color amber = Color(0xFFE1985E);

  /// Progress fills and highlights. Carries dark ink when it carries text.
  static const Color orange = Color(0xFFBC5E2F);

  /// Warm beige, for tinted panels.
  static const Color beige = Color(0xFFDCB8A4);

  /// Delayed and urgent states only. As a fill it carries dark ink; as a dot
  /// it stands beside dark text.
  static const Color accent = Color(0xFFE63E06);

  /// The second muted grey, for disabled marks only.
  static const Color muted = Color(0xFFAAAAAA);

  /// Light surface. The same tone as [paper2].
  static const Color peach = Color(0xFFE3D6CF);

  /// Card tint: the wash on a selected tile.
  static const Color goldTint = Color(0xFFE1C9BB);

  // Derived text colours.

  /// Secondary text that replaces [ink3] outdoors: 4.5:1 even on peach.
  static const Color inkLabel = Color(0xFF575250);

  /// Ink for amber statuses: [haldi] itself cannot carry text on a light surface.
  static const Color haldiInk = Color(0xFF6B4700);

  /// A deep green for text.
  static const Color mossInk = Color(0xFF1F5A36);

  /// Error and rejected red: holds 4.5:1 on every light surface.
  static const Color stampRed = Color(0xFFB3261E);

  // Surface details. Solid so they cost no blending.

  static const Color gridMinor = Color(0xFFE3D6CF);
  static const Color gridMajor = Color(0xFFDCB8A4);
  static const Color marginRed = Color(0xFFE1985E);

  /// The soft lip under a pressable tile. Kept for screens that still draw it.
  static const Color paperEdge = Color(0xFFE3D6CF);

  /// A stronger hairline for fields and secondary buttons, about 3:1 on white.
  static const Color ruleStrong = Color(0xFF8F8B88);

  static const Color lateriteEdge = Color(0xFF7A3A1B);
  static const Color inkEdge = Color(0xFF070706);

  /// The hairline around a white card. The same tone as [rule].
  static const Color cardEdge = Color(0xFFE3D6CF);

  /// Kept for screens that still paint a shadow. The system itself paints none.
  static const Color shadow = Color(0xFFB9875B);
}

/// The two ways the top bar can be painted. The owner chooses one; the app
/// reads the choice from [N360Chrome.topBar].
enum N360BarTone {
  /// White with a hairline under it. Ink text, white tiles with hairlines,
  /// dark status-bar icons.
  paper(
    fill: N360Colors.sheet,
    edge: N360Colors.rule,
    ink: N360Colors.ink,
    inkSoft: N360Colors.inkLabel,
    tileFill: N360Colors.sheet,
    tileEdge: N360Colors.rule,
    tileInk: N360Colors.ink,
    lightStatusIcons: false,
  ),

  /// The brand colour, with white text and icons. Tiles are a darker cut of
  /// the same orange so they read as part of the bar. Light status-bar icons.
  brand(
    fill: N360Colors.primary,
    edge: N360Colors.primary,
    ink: N360Colors.onPrimary,
    inkSoft: N360Colors.onPrimary,
    tileFill: N360Colors.lateriteDark,
    tileEdge: N360Colors.lateriteDark,
    tileInk: N360Colors.onPrimary,
    lightStatusIcons: true,
  );

  const N360BarTone({
    required this.fill,
    required this.edge,
    required this.ink,
    required this.inkSoft,
    required this.tileFill,
    required this.tileEdge,
    required this.tileInk,
    required this.lightStatusIcons,
  });

  final Color fill;

  /// The hairline along the bar's lower edge.
  final Color edge;

  /// Title text and icons on the bar.
  final Color ink;

  /// The line under the title. On the brand bar it is white too: a tint
  /// would fall under 4.5:1.
  final Color inkSoft;
  final Color tileFill;
  final Color tileEdge;
  final Color tileInk;

  /// Whether the status bar above the bar needs light icons.
  final bool lightStatusIcons;
}

/// The one switch for the app's chrome. Every top bar, every tile on it and
/// the status-bar icon brightness read [topBar]; change it here and the whole
/// app follows. It is a plain field so the owner's two options can be rendered
/// side by side from one build.
abstract final class N360Chrome {
  static N360BarTone topBar = N360BarTone.paper;
}

/// Static "gradients". The design is flat; these exist so screens that painted
/// a gradient still compile and now get the flat primary and the flat page.
abstract final class N360Gradients {
  /// Flat primary. Text on it is [N360Colors.onPrimary].
  static const LinearGradient gold = LinearGradient(
    colors: <Color>[N360Colors.primary, N360Colors.primary],
  );

  /// The page: one flat warm grey.
  static const LinearGradient page = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: <Color>[N360Colors.paper, N360Colors.paper],
  );

  /// Flat progress fill.
  static const LinearGradient goldFade = LinearGradient(
    colors: <Color>[N360Colors.orange, N360Colors.orange],
  );
}

/// Spacing scale, in logical pixels. Everything sits on a 4dp beat.
///
/// Inside a card use [card] padding and [sm] between lines; between cards use
/// [cardGap]; between sections use [lg].
abstract final class N360Space {
  static const double xxs = 2;
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 24;
  static const double xxl = 32;
  static const double xxxl = 48;

  /// Padding inside a white card.
  static const double card = 14;

  /// Padding inside a small tile or chip.
  static const double tile = 10;

  /// The gap between two cards stacked or side by side.
  static const double cardGap = 10;

  /// Horizontal screen gutter.
  static const double gutter = 16;

  /// Kept for screens built on the earlier margin. There is no margin rule now.
  static const double margin = 16;
  static const double marginText = 16;
}

/// Corners. Small, so surfaces read as documents rather than pills.
abstract final class N360Radius {
  static const double small = 6;
  static const double medium = 8;
  static const double tile = 10;
  static const double card = 12;

  static const BorderRadius sm = BorderRadius.all(Radius.circular(small));
  static const BorderRadius md = BorderRadius.all(Radius.circular(medium));
  static const BorderRadius tileRadius = BorderRadius.all(Radius.circular(tile));
  static const BorderRadius cardRadius = BorderRadius.all(Radius.circular(card));
}

/// Sizes. Hit areas are sized for gloved thumbs; visible things are sized for
/// the eye. The two are deliberately separate: a 44dp tile sits inside a 56dp
/// target, so a control can look light and still be easy to hit.
abstract final class N360Size {
  /// The least any tappable thing accepts, on both axes. Never go under this.
  static const double tap = 56;

  /// For the one main action on a screen.
  static const double tapLarge = 64;

  /// Height of a numeric keypad key. A key is pressed hundreds of times a day,
  /// so it stays a little taller than the minimum.
  static const double key = 60;

  /// Height of one item in the bottom bar. The painted tile is [barTile].
  static const double bar = 56;

  /// The painted tile behind the active bottom-bar item.
  static const double barTile = 44;

  /// The visible side of a square icon tile.
  static const double tile = 40;

  /// A smaller icon tile, for the corner of a stat card or a list row.
  static const double tileSmall = 32;

  /// The visible height of a compact control that still carries a [tap] target
  /// around it, such as a sync tag or a filter chip.
  static const double control = 36;

  /// A status pill.
  static const double pill = 22;

  /// Space a screen leaves under a tile for its soft lower lip.
  static const double edge = 3;
  static const double hairline = 1;
}

/// Icon sizes. Icons are quiet in this design: a glyph sits beside its word at
/// text height, and only the keypad and the hero moments go above 22.
abstract final class N360IconSize {
  /// Inline with a caption.
  static const double xs = 14;

  /// Inline with a label, or a status mark on a slip.
  static const double sm = 16;

  /// Inline with body text, and the default for a button.
  static const double md = 18;

  /// In a [N360Size.tile] tile, and in the bottom bar.
  static const double lg = 20;

  /// Bottom bar and stepper keys.
  static const double xl = 22;

  /// Keypad action keys and the few hero marks.
  static const double xxl = 24;

  /// Inside an icon tile.
  static const double tile = lg;

  /// In a button beside its label.
  static const double button = md;

  /// In the bottom bar, above its word.
  static const double bar = xl;
}
