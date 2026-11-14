import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart' show SemanticsProperties;

import '../haptics.dart';
import '../tokens.dart';
import '../typography.dart';

enum HajiriValue {
  present('P'),
  half('H'),
  overtime('OT'),
  absent('A');

  const HajiriValue(this.letter);

  /// The mark a muster roll uses: readable without reading.
  final String letter;
}

/// Attendance for one worker: four segments, one tap, colour and letter at
/// once. Written as a single painter with no child widgets or animation, so a
/// muster roll of a hundred workers is one paint call each.
class HajiriSegment extends StatefulWidget {
  const HajiriSegment({
    super.key,
    required this.value,
    required this.onChanged,
    this.labels = HajiriLabels.english,
    this.height = N360Size.tap,
    this.semanticsLabel,
  });

  /// Null means not marked yet.
  final HajiriValue? value;
  final ValueChanged<HajiriValue> onChanged;

  /// Spoken only. Pass translated words.
  final HajiriLabels labels;

  /// Never under [N360Size.tap]: each segment is a target in its own right.
  final double height;

  /// Whose attendance this is, read before each segment.
  final String? semanticsLabel;

  @override
  State<HajiriSegment> createState() => _HajiriSegmentState();
}

@immutable
class HajiriLabels {
  const HajiriLabels({required this.present, required this.half, required this.overtime, required this.absent});

  static const HajiriLabels english = HajiriLabels(
    present: 'Present',
    half: 'Half day',
    overtime: 'Overtime',
    absent: 'Absent',
  );

  final String present;
  final String half;
  final String overtime;
  final String absent;

  String of(HajiriValue value) => switch (value) {
        HajiriValue.present => present,
        HajiriValue.half => half,
        HajiriValue.overtime => overtime,
        HajiriValue.absent => absent,
      };
}

class _HajiriSegmentState extends State<HajiriSegment> {
  HajiriValue? _value;

  @override
  void initState() {
    super.initState();
    _value = widget.value;
  }

  @override
  void didUpdateWidget(covariant HajiriSegment oldWidget) {
    super.didUpdateWidget(oldWidget);
    _value = widget.value;
  }

  void _choose(HajiriValue value) {
    N360Haptics.select();
    setState(() => _value = value);
    widget.onChanged(value);
  }

  void _handleTap(TapUpDetails details) {
    final double width = context.size!.width;
    final int index = (details.localPosition.dx / width * 4).floor().clamp(0, 3);
    _choose(HajiriValue.values[index]);
  }

  @override
  Widget build(BuildContext context) {
    final TextScaler scaler = MediaQuery.textScalerOf(context).clamp(maxScaleFactor: 1.2);
    final double height = widget.height < N360Size.tap ? N360Size.tap : widget.height;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapUp: _handleTap,
      child: RepaintBoundary(
        child: CustomPaint(
          size: Size(double.infinity, height),
          painter: _HajiriPainter(
            value: _value,
            scaler: scaler,
            labels: widget.labels,
            semanticsLabel: widget.semanticsLabel,
            onChoose: _choose,
          ),
        ),
      ),
    );
  }
}

class _HajiriPainter extends CustomPainter {
  const _HajiriPainter({
    required this.value,
    required this.scaler,
    required this.labels,
    required this.semanticsLabel,
    required this.onChoose,
  });

  final HajiriValue? value;
  final TextScaler scaler;
  final HajiriLabels labels;
  final String? semanticsLabel;
  final ValueChanged<HajiriValue> onChoose;

  static const double _gap = 6;

  static ({Color fill, Color ink}) _look(HajiriValue value) => switch (value) {
        HajiriValue.present => (fill: N360Colors.moss, ink: N360Colors.sheet),
        HajiriValue.half => (fill: N360Colors.haldi, ink: N360Colors.ink),
        HajiriValue.overtime => (fill: N360Colors.neel, ink: N360Colors.sheet),
        HajiriValue.absent => (fill: N360Colors.stampRed, ink: N360Colors.sheet),
      };

  static final Map<(String, Color, TextScaler), TextPainter> _letters = <(String, Color, TextScaler), TextPainter>{};

  static TextPainter _letter(String text, Color color, TextScaler scaler) {
    return _letters.putIfAbsent((text, color, scaler), () {
      return TextPainter(
        text: TextSpan(
          text: text,
          style: TextStyle(
            fontFamily: N360Type.mukta,
            fontSize: 16,
            fontWeight: FontWeight.w600,
            height: 1.1,
            color: color,
          ),
        ),
        textDirection: TextDirection.ltr,
        textScaler: scaler,
      )..layout();
    });
  }

  @override
  void paint(Canvas canvas, Size size) {
    final double cell = (size.width - _gap * 3) / 4;
    const radius = Radius.circular(N360Radius.medium);
    final Paint paint = Paint();
    final Paint edge = Paint()
      ..color = N360Colors.rule
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1;

    for (var i = 0; i < 4; i++) {
      final option = HajiriValue.values[i];
      final bool selected = option == value;
      final double left = i * (cell + _gap);
      final RRect tile = RRect.fromRectAndRadius(Rect.fromLTWH(left, 0, cell, size.height), radius);
      if (selected) {
        final look = _look(option);
        canvas.drawRRect(tile, paint..color = look.fill);
        final TextPainter letter = _letter(option.letter, look.ink, scaler);
        letter.paint(canvas, Offset(left + (cell - letter.width) / 2, (size.height - letter.height) / 2));
      } else {
        // Unmarked: a white tile with a hairline, so the one colour on the row
        // is the mark that has been made.
        canvas.drawRRect(tile, paint..color = N360Colors.sheet);
        canvas.drawRRect(tile.deflate(0.5), edge);
        final TextPainter letter = _letter(option.letter, N360Colors.ink2, scaler);
        letter.paint(canvas, Offset(left + (cell - letter.width) / 2, (size.height - letter.height) / 2));
      }
    }
  }

  @override
  bool shouldRepaint(_HajiriPainter oldDelegate) =>
      oldDelegate.value != value || oldDelegate.scaler != scaler;

  @override
  bool shouldRebuildSemantics(_HajiriPainter oldDelegate) =>
      oldDelegate.value != value || oldDelegate.labels != labels || oldDelegate.semanticsLabel != semanticsLabel;

  @override
  SemanticsBuilderCallback get semanticsBuilder => (Size size) {
        final double cell = (size.width - _gap * 3) / 4;
        return <CustomPainterSemantics>[
          for (var i = 0; i < 4; i++)
            CustomPainterSemantics(
              key: ValueKey<int>(i),
              rect: Rect.fromLTWH(i * (cell + _gap), 0, cell, size.height),
              properties: SemanticsProperties(
                button: true,
                selected: HajiriValue.values[i] == value,
                label: semanticsLabel == null
                    ? labels.of(HajiriValue.values[i])
                    : '$semanticsLabel, ${labels.of(HajiriValue.values[i])}',
                textDirection: TextDirection.ltr,
                onTap: () => onChoose(HajiriValue.values[i]),
              ),
            ),
        ];
      };
}
