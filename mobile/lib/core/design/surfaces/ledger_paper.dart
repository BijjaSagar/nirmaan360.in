import 'package:flutter/material.dart';

import '../tokens.dart';

/// Shifts every sheet of paper on screen for one frame range. The stamp moment
/// writes to it; idle screens pay nothing because nothing notifies.
final ValueNotifier<Offset> paperJolt = ValueNotifier<Offset>(Offset.zero);

/// The page itself: warm paper, a faint drafting grid, an optional red margin.
///
/// Put one at the root with [LedgerPaper.root] in `MaterialApp.builder` and
/// every route shares one sheet that stays put while content slides across it.
/// A `LedgerPaper` used under a root paints only what the root does not, so
/// screens can always wrap themselves without doubling the grid.
class LedgerPaper extends StatelessWidget {
  const LedgerPaper({super.key, this.child, this.marginRule = false}) : _root = false;

  /// The single shared sheet for the whole app.
  const LedgerPaper.root({super.key, this.child, this.marginRule = false}) : _root = true;

  final Widget? child;

  /// Draws the measurement book's double red margin at [N360Space.margin].
  /// Keep text to the right of [N360Space.marginText] on such pages.
  final bool marginRule;
  final bool _root;

  /// Whether an app-wide [LedgerPaper.root] sits above [context].
  static bool hasRoot(BuildContext context) => _LedgerPaperScope.maybeOf(context)?.root ?? false;

  static const Widget _grid = RepaintBoundary(
    child: CustomPaint(painter: _DraftingGridPainter(), isComplex: true),
  );

  @override
  Widget build(BuildContext context) {
    final bool insidePaper = _LedgerPaperScope.maybeOf(context) != null;
    Widget content = child ?? const SizedBox.expand();
    if (marginRule) {
      content = CustomPaint(painter: const _MarginRulePainter(), isComplex: true, child: content);
    }
    if (insidePaper && !_root) return content;

    if (_root) {
      // Routes stay see-through so the shared sheet shows behind every Scaffold.
      content = Theme(
        data: Theme.of(context).copyWith(scaffoldBackgroundColor: Colors.transparent),
        child: content,
      );
    }

    // A root moves only its grid. Pages jolt themselves through the route, so the
    // stamp's own overlay, which lives in the same Navigator, stays perfectly still.
    final Widget sheet = _root
        ? Stack(
            fit: StackFit.expand,
            children: <Widget>[
              const _Jolt(child: _grid),
              content,
            ],
          )
        : _Jolt(
            child: Stack(fit: StackFit.expand, children: <Widget>[_grid, content]),
          );

    return ColoredBox(
      color: N360Colors.paper,
      child: _LedgerPaperScope(root: _root, child: sheet),
    );
  }
}

class _Jolt extends StatelessWidget {
  const _Jolt({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<Offset>(
      valueListenable: paperJolt,
      builder: (context, offset, child) => Transform.translate(offset: offset, child: child),
      child: child,
    );
  }
}

class _LedgerPaperScope extends InheritedWidget {
  const _LedgerPaperScope({required this.root, required super.child});

  final bool root;

  static _LedgerPaperScope? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<_LedgerPaperScope>();

  @override
  bool updateShouldNotify(_LedgerPaperScope oldWidget) => oldWidget.root != root;
}

/// Same rhythm as the web's `.graph-paper`: a 24dp grid with 120dp majors.
class _DraftingGridPainter extends CustomPainter {
  const _DraftingGridPainter();

  static const double _cell = 24;
  static const int _majorEvery = 5;

  @override
  void paint(Canvas canvas, Size size) {
    final minor = Paint()
      ..color = N360Colors.gridMinor
      ..strokeWidth = 1;
    final major = Paint()
      ..color = N360Colors.gridMajor
      ..strokeWidth = 1;

    var i = 0;
    for (double x = 0.5; x < size.width; x += _cell, i++) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), i % _majorEvery == 0 ? major : minor);
    }
    i = 0;
    for (double y = 0.5; y < size.height; y += _cell, i++) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), i % _majorEvery == 0 ? major : minor);
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _MarginRulePainter extends CustomPainter {
  const _MarginRulePainter();

  @override
  void paint(Canvas canvas, Size size) {
    final red = Paint()
      ..color = N360Colors.marginRed
      ..strokeWidth = 1;
    const x = N360Space.margin - 0.5;
    canvas.drawLine(const Offset(x, 0), Offset(x, size.height), red);
    canvas.drawLine(const Offset(x + 3, 0), Offset(x + 3, size.height), red);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
