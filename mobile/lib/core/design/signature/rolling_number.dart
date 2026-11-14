import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';

import '../motion.dart';
import '../typography.dart';

/// A figure whose digits roll like an odometer. It takes the formatted string
/// and never parses a `double`.
///
/// Everything is painted by one `CustomPainter` from a shared cache of laid-out
/// digits, so a frame is a handful of glyph draws and no widget rebuilds. Digits
/// are placed outwards from the decimal point, so the units column stays the
/// units column when a digit is added or removed, and every width glides between
/// the old and new sizes instead of jumping.
///
/// With [animateEntry] the figure counts up once when it first appears, from a
/// figure of the same shape with every digit at zero, over
/// [N360Durations.count]. Use it for the one hero figure on a screen and
/// nowhere else; a stat card simply shows its value. Digits are painted from
/// frame 0 either way, and reduced motion shows the final figure at once.
///
/// Screen readers get the full value once, as a single label. There is no `Text`
/// widget inside, so a widget test should read [value] or find by semantics.
class RollingNumber extends StatefulWidget {
  const RollingNumber({
    super.key,
    required this.value,
    this.style,
    this.prefix = '',
    this.suffix = '',
    this.prefixStyle,
    this.suffixStyle,
    this.semanticsLabel,
    this.animateEntry = false,
  });

  final String value;
  final TextStyle? style;
  final String prefix;
  final String suffix;
  final TextStyle? prefixStyle;
  final TextStyle? suffixStyle;

  /// Overrides the spoken value. Defaults to prefix, value and suffix.
  final String? semanticsLabel;

  /// Count up from zero once, on first build. For hero figures only.
  final bool animateEntry;

  /// [value] with every digit set to zero: where a count-up starts.
  static String zeroed(String value) => value.replaceAll(RegExp('[0-9]'), '0');

  @override
  State<RollingNumber> createState() => _RollingNumberState();
}

class _RollingNumberState extends State<RollingNumber> with SingleTickerProviderStateMixin {
  late final AnimationController _roll = AnimationController(vsync: this);
  late _Glyphs _glyphs;
  late _Figure _figure;
  bool _ready = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _glyphs = _resolveGlyphs();
    if (!_ready) {
      _ready = true;
      final motion = context.motion;
      final String start = RollingNumber.zeroed(widget.value);
      if (widget.animateEntry && !motion.reduced && start != widget.value) {
        _figure = _Figure.still(start, _glyphs).retargeted(widget.value, 1, _glyphs);
        _roll.duration = motion.count;
        _roll.forward(from: 0);
      } else {
        _figure = _Figure.still(widget.value, _glyphs);
      }
    } else {
      _figure = _figure.remeasured(_glyphs);
    }
  }

  _Glyphs _resolveGlyphs() {
    final TextStyle style = N360Type.figure.merge(widget.style);
    return _Glyphs.of(style, MediaQuery.textScalerOf(context));
  }

  @override
  void didUpdateWidget(covariant RollingNumber oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.style != widget.style) {
      _glyphs = _resolveGlyphs();
      _figure = _figure.remeasured(_glyphs);
    }
    if (oldWidget.value == widget.value) return;

    final motion = context.motion;
    if (motion.reduced) {
      _roll.value = 1;
      _figure = _Figure.still(widget.value, _glyphs);
      return;
    }
    _figure = _figure.retargeted(widget.value, _roll.value, _glyphs);
    _roll.duration = motion.roll;
    _roll.forward(from: 0);
  }

  @override
  void dispose() {
    _roll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final _Text prefix = _glyphs.withStyle(widget.prefixStyle).text(widget.prefix);
    final _Text suffix = _glyphs.withStyle(widget.suffixStyle).text(widget.suffix);

    final double height = math.max(_glyphs.height, math.max(prefix.height, suffix.height));
    final double extras = prefix.width + suffix.width;
    final _Figure figure = _figure;

    Widget paint(double width) => SizedBox(
          width: width,
          height: height,
          child: CustomPaint(
            painter: _RollPainter(
              figure: figure,
              glyphs: _glyphs,
              roll: _roll,
              prefix: prefix,
              suffix: suffix,
              lineHeight: height,
            ),
          ),
        );

    final Widget paintable = figure.widthChanges
        ? AnimatedBuilder(
            animation: _roll,
            builder: (context, _) => paint(extras + figure.widthAt(_roll.value)),
          )
        : paint(extras + figure.widthAt(1));

    return Semantics(
      container: true,
      label: widget.semanticsLabel ?? '${widget.prefix}${widget.value}${widget.suffix}',
      child: ExcludeSemantics(child: RepaintBoundary(child: paintable)),
    );
  }
}

/// Quantity with an optional unit, for already formatted figures.
class RollingQty extends StatelessWidget {
  const RollingQty({
    super.key,
    required this.value,
    this.unit,
    this.style,
    this.unitStyle,
    this.animateEntry = false,
  });

  final String value;
  final String? unit;
  final TextStyle? style;
  final TextStyle? unitStyle;

  /// Count up from zero once, on first build. For hero figures only.
  final bool animateEntry;

  @override
  Widget build(BuildContext context) {
    final TextStyle resolved = N360Type.figure.merge(style);
    return RollingNumber(
      value: value,
      style: style,
      animateEntry: animateEntry,
      suffix: unit == null ? '' : ' $unit',
      suffixStyle: unitStyle ??
          resolved.copyWith(
            fontSize: (resolved.fontSize ?? 16) * 0.62,
            fontWeight: FontWeight.w400,
            letterSpacing: 0,
          ),
    );
  }
}

/// Rupees, for already formatted figures such as `1,23,456.00`. A leading rupee
/// sign in [value] is moved into the static prefix so it does not roll.
class RollingMoney extends StatelessWidget {
  const RollingMoney({
    super.key,
    required this.value,
    this.style,
    this.symbol = '₹',
    this.animateEntry = false,
  });

  final String value;
  final TextStyle? style;
  final String symbol;

  /// Count up from zero once, on first build. For hero figures only.
  final bool animateEntry;

  @override
  Widget build(BuildContext context) {
    final String figure = value.startsWith(symbol) ? value.substring(symbol.length).trimLeft() : value;
    return RollingNumber(value: figure, style: style, prefix: symbol, animateEntry: animateEntry);
  }
}

@immutable
class _Text {
  const _Text(this.painter, this.width, this.height);

  final TextPainter? painter;
  final double width;
  final double height;
}

/// Laid-out digits and punctuation for one style. Shared across every rolling
/// number on screen so a dozen figures lay out ten digits, not hundreds.
class _Glyphs {
  _Glyphs._(this.style, this.scaler) {
    var widest = 0.0;
    var tallest = 0.0;
    for (var i = 0; i < 10; i++) {
      final painter = _layout('$i');
      digits.add(painter);
      widest = math.max(widest, painter.width);
      tallest = math.max(tallest, painter.height);
    }
    digitWidth = widest;
    height = tallest;
  }

  static const int _capacity = 24;
  static final Map<(TextStyle, TextScaler), _Glyphs> _cache = <(TextStyle, TextScaler), _Glyphs>{};

  static _Glyphs of(TextStyle style, TextScaler scaler) {
    final key = (style, scaler);
    final hit = _cache.remove(key);
    if (hit != null) {
      _cache[key] = hit;
      return hit;
    }
    if (_cache.length >= _capacity) _cache.remove(_cache.keys.first);
    return _cache[key] = _Glyphs._(style, scaler);
  }

  final TextStyle style;
  final TextScaler scaler;
  final List<TextPainter> digits = <TextPainter>[];
  final Map<String, TextPainter> _others = <String, TextPainter>{};
  final Map<String, _Text> _texts = <String, _Text>{};
  late final double digitWidth;
  late final double height;

  TextPainter _layout(String text) => TextPainter(
        text: TextSpan(text: text, style: style),
        textDirection: TextDirection.ltr,
        textScaler: scaler,
      )..layout();

  TextPainter glyph(String char) {
    final digit = _digitOf(char);
    if (digit != null) return digits[digit];
    return _others[char] ??= _layout(char);
  }

  /// A prefix or suffix, cached so a rebuild does not lay it out again.
  _Text text(String value) {
    if (value.isEmpty) return const _Text(null, 0, 0);
    return _texts.putIfAbsent(value, () {
      final painter = _layout(value);
      return _Text(painter, painter.width, painter.height);
    });
  }

  /// The glyph set for this style merged with [other], sharing the cache.
  _Glyphs withStyle(TextStyle? other) => other == null ? this : _Glyphs.of(style.merge(other), scaler);

  /// A digit's own width. In DM Mono these are all equal, so columns stay
  /// tabular; Poppins figures keep their natural rhythm instead of a loose "1".
  double widthOf(String? char) => char == null ? 0 : glyph(char).width;
}

int? _digitOf(String? char) {
  if (char == null || char.length != 1) return null;
  final code = char.codeUnitAt(0) - 48;
  return code >= 0 && code <= 9 ? code : null;
}

/// One character slot, named by its distance from the decimal point: 0 is the
/// point, negative slots are the integer digits, positive are the decimals.
class _Slot {
  const _Slot({
    required this.key,
    required this.fromChar,
    required this.toChar,
    required this.fromWidth,
    required this.toWidth,
    required this.start,
    required this.end,
    required this.rolls,
    required this.delay,
  });

  final int key;
  final String? fromChar;
  final String? toChar;
  final double fromWidth;
  final double toWidth;

  /// Wheel positions: whole numbers are digits, fractions are between digits.
  final double start;
  final double end;
  final bool rolls;
  final double delay;

  bool get blankAtStart => fromChar == null;
  bool get blankAtEnd => toChar == null;

  double progress(double t) {
    final double local = ((t - delay) / (1 - delay)).clamp(0.0, 1.0);
    return N360Curves.roll.transform(local);
  }

  double wheelAt(double t) => lerpDouble(start, end, progress(t))!;
  double widthAt(double t) => lerpDouble(fromWidth, toWidth, progress(t))!;
}

class _Figure {
  _Figure(this.value, this.slots);

  /// A figure at rest.
  factory _Figure.still(String value, _Glyphs glyphs) {
    final chars = _charsByKey(value);
    final keys = chars.keys.toList()..sort();
    return _Figure(value, <_Slot>[
      for (final key in keys)
        _Slot(
          key: key,
          fromChar: chars[key],
          toChar: chars[key],
          fromWidth: glyphs.widthOf(chars[key]),
          toWidth: glyphs.widthOf(chars[key]),
          start: _digitOf(chars[key])?.toDouble() ?? 0,
          end: _digitOf(chars[key])?.toDouble() ?? 0,
          rolls: false,
          delay: 0,
        ),
    ]);
  }

  final String value;
  final List<_Slot> slots;

  bool get widthChanges => slots.any((s) => s.fromWidth != s.toWidth);

  double widthAt(double t) => slots.fold(0, (sum, slot) => sum + slot.widthAt(t));

  /// Same layout, new font metrics, for a text scale or style change.
  _Figure remeasured(_Glyphs glyphs) => _Figure(value, <_Slot>[
        for (final s in slots)
          _Slot(
            key: s.key,
            fromChar: s.fromChar,
            toChar: s.toChar,
            fromWidth: glyphs.widthOf(s.fromChar),
            toWidth: glyphs.widthOf(s.toChar),
            start: s.start,
            end: s.end,
            rolls: s.rolls,
            delay: s.delay,
          ),
      ]);

  /// Rolls from wherever the wheels are right now to [next], so typing quickly
  /// never makes a digit snap back.
  _Figure retargeted(String next, double t, _Glyphs glyphs) {
    final Map<int, _Slot> old = <int, _Slot>{for (final s in slots) s.key: s};
    final Map<int, String> target = _charsByKey(next);
    final List<int> keys = <int>{...old.keys, ...target.keys}.toList()..sort();
    final bool up = _compareMagnitude(value, next) <= 0;

    final List<_Slot> built = <_Slot>[];
    var column = 0;
    for (final key in keys.reversed) {
      final _Slot? previous = old[key];
      final String? to = target[key];
      final String? from = previous == null
          ? null
          : (previous.progress(t) >= 0.5 ? previous.toChar : previous.fromChar);
      final double fromWidth = previous == null ? 0 : previous.widthAt(t);
      final double toWidth = glyphs.widthOf(to);
      final int? toDigit = _digitOf(to);
      final int? fromDigit = _digitOf(from);

      if (toDigit == null && fromDigit == null) {
        built.add(_Slot(
          key: key,
          fromChar: from,
          toChar: to,
          fromWidth: fromWidth,
          toWidth: toWidth,
          start: 0,
          end: 0,
          rolls: false,
          delay: 0,
        ));
      } else {
        final double delay = math.min(0.28, column * 0.045);
        final double wheel;
        if (previous != null && (previous.rolls || fromDigit != null)) {
          wheel = previous.wheelAt(t);
        } else {
          wheel = ((toDigit ?? 0) - (up ? 1 : -1)).toDouble();
        }
        built.add(_Slot(
          key: key,
          fromChar: fromDigit == null ? null : from,
          toChar: toDigit == null ? null : to,
          fromWidth: fromWidth,
          toWidth: toWidth,
          start: wheel,
          end: _wheelTarget(wheel, toDigit, up),
          rolls: true,
          delay: delay,
        ));
        column++;
      }
    }
    return _Figure(next, built.reversed.toList());
  }

  static double _wheelTarget(double wheel, int? digit, bool up) {
    if (digit == null) return wheel + (up ? 1 : -1);
    final double from = wheel % 10;
    if (up) {
      var distance = (digit - from) % 10;
      if (distance < 0.001) distance = 0;
      return wheel + distance;
    }
    var distance = (from - digit) % 10;
    if (distance < 0.001) distance = 0;
    return wheel - distance;
  }

  static Map<int, String> _charsByKey(String value) {
    final Map<int, String> chars = <int, String>{};
    final int point = value.indexOf('.');
    final int integerEnd = point == -1 ? value.length : point;
    for (var i = 0; i < integerEnd; i++) {
      chars[i - integerEnd] = value[i];
    }
    if (point != -1) {
      for (var i = point; i < value.length; i++) {
        chars[i - point] = value[i];
      }
    }
    return chars;
  }

  /// Compares two formatted figures by size without parsing to `double`.
  static int _compareMagnitude(String a, String b) {
    String clean(String s) => s.replaceAll(RegExp(r'[^0-9.]'), '');
    final List<String> left = clean(a).split('.');
    final List<String> right = clean(b).split('.');
    final String li = left.first.replaceFirst(RegExp(r'^0+'), '');
    final String ri = right.first.replaceFirst(RegExp(r'^0+'), '');
    if (li.length != ri.length) return li.length.compareTo(ri.length);
    final int integers = li.compareTo(ri);
    if (integers != 0) return integers;
    final String lf = left.length > 1 ? left[1] : '';
    final String rf = right.length > 1 ? right[1] : '';
    return lf.padRight(rf.length, '0').compareTo(rf.padRight(lf.length, '0'));
  }
}

class _RollPainter extends CustomPainter {
  _RollPainter({
    required this.figure,
    required this.glyphs,
    required this.roll,
    required this.prefix,
    required this.suffix,
    required this.lineHeight,
  }) : super(repaint: roll);

  final _Figure figure;
  final _Glyphs glyphs;
  final AnimationController roll;
  final _Text prefix;
  final _Text suffix;
  final double lineHeight;

  @override
  void paint(Canvas canvas, Size size) {
    final double t = roll.value;
    canvas.clipRect(Offset.zero & size);

    final double natural = prefix.width + figure.widthAt(t) + suffix.width;
    final double overflow = natural > size.width && natural > 0 ? size.width / natural : 1;
    if (overflow != 1) canvas.scale(overflow);

    var x = 0.0;
    final double mid = (lineHeight - glyphs.height) / 2;
    prefix.painter?.paint(canvas, Offset(x, (lineHeight - prefix.height) / 2));
    x += prefix.width;

    for (final slot in figure.slots) {
      final double w = slot.widthAt(t);
      if (w > 0.01) _paintSlot(canvas, slot, t, x, w, mid);
      x += w;
    }

    suffix.painter?.paint(canvas, Offset(x, (lineHeight - suffix.height) / 2));
  }

  void _paintSlot(Canvas canvas, _Slot slot, double t, double x, double w, double mid) {
    if (!slot.rolls) {
      final String? char = slot.toChar ?? slot.fromChar;
      if (char == null) return;
      final painter = glyphs.glyph(char);
      painter.paint(canvas, Offset(x + (w - painter.width) / 2, mid));
      return;
    }

    final double wheel = slot.wheelAt(t);
    final int below = wheel.floor();
    final double fraction = wheel - below;
    final double slotHeight = glyphs.height;

    // Two wheel faces can show at once; a larger number sits lower on the wheel.
    void face(int position, double y) {
      if (y >= lineHeight || y <= -slotHeight) return;
      if (slot.blankAtStart && position == slot.start.round()) return;
      if (slot.blankAtEnd && position == slot.end.round()) return;
      final painter = glyphs.digits[position % 10];
      painter.paint(canvas, Offset(x + (w - painter.width) / 2, y + mid));
    }

    face(below, -fraction * slotHeight);
    if (fraction > 0.001) face(below + 1, (1 - fraction) * slotHeight);
  }

  @override
  bool shouldRepaint(_RollPainter oldDelegate) =>
      oldDelegate.figure != figure ||
      oldDelegate.glyphs != glyphs ||
      oldDelegate.prefix.painter != prefix.painter ||
      oldDelegate.suffix.painter != suffix.painter;
}
