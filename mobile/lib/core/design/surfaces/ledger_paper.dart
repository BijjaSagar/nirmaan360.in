import 'package:flutter/material.dart';

import '../tokens.dart';

/// Shifts the page for a few frames. The stamp moment writes to it; idle screens
/// pay nothing because nothing notifies.
final ValueNotifier<Offset> paperJolt = ValueNotifier<Offset>(Offset.zero);

/// The page background: a warm light grey fading to peach at the foot.
///
/// Put one at the root with [LedgerPaper.root] in `MaterialApp.builder` and
/// every route shares one background that stays put while content slides across
/// it. A `LedgerPaper` used under a root passes through, so screens can always
/// wrap themselves without doubling the page.
class LedgerPaper extends StatelessWidget {
  const LedgerPaper({super.key, this.child, this.marginRule = false}) : _root = false;

  /// The single shared page for the whole app.
  const LedgerPaper.root({super.key, this.child, this.marginRule = false}) : _root = true;

  final Widget? child;

  /// Kept so existing screens compile. The new look has no margin rule.
  final bool marginRule;
  final bool _root;

  /// Whether an app-wide [LedgerPaper.root] sits above [context].
  static bool hasRoot(BuildContext context) => _LedgerPaperScope.maybeOf(context)?.root ?? false;

  static const Widget _wash = RepaintBoundary(
    child: DecoratedBox(
      decoration: BoxDecoration(gradient: N360Gradients.page),
      child: SizedBox.expand(),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final bool insidePaper = _LedgerPaperScope.maybeOf(context) != null;
    Widget content = child ?? const SizedBox.expand();
    if (insidePaper && !_root) return content;

    if (_root) {
      // Routes stay see-through so the shared page shows behind every Scaffold.
      content = Theme(
        data: Theme.of(context).copyWith(scaffoldBackgroundColor: Colors.transparent),
        child: content,
      );
    }

    // A root moves only its wash. Pages jolt themselves through the route, so the
    // stamp's own overlay, which lives in the same Navigator, stays perfectly still.
    final Widget page = _root
        ? Stack(
            fit: StackFit.expand,
            children: <Widget>[
              const _Jolt(child: _wash),
              content,
            ],
          )
        : _Jolt(
            child: Stack(fit: StackFit.expand, children: <Widget>[_wash, content]),
          );

    return ColoredBox(
      color: N360Colors.paper,
      child: _LedgerPaperScope(root: _root, child: page),
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
