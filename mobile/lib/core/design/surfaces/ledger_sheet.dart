import 'package:flutter/material.dart';

import '../motion.dart';
import '../tokens.dart';

/// Shows a bottom sheet the Nirmaan360 way: it rises and settles
/// ([N360Curves.settle], [N360Durations.sheet]), leaves quickly, carries the
/// theme's drag handle and white card shape, and keeps clear of the system bar.
/// With reduced motion it is simply there.
///
/// Use it in place of `showModalBottomSheet` so every sheet in the app moves
/// the same way:
///
/// ```dart
/// final choice = await showLedgerSheet<int>(context, builder: (context) => ...);
/// ```
Future<T?> showLedgerSheet<T>(
  BuildContext context, {
  required WidgetBuilder builder,
  bool isScrollControlled = false,
  bool isDismissible = true,
  bool enableDrag = true,
  bool useSafeArea = true,
  bool showDragHandle = true,
  BoxConstraints? constraints,
}) {
  final motion = context.motion;
  return showModalBottomSheet<T>(
    context: context,
    builder: builder,
    isScrollControlled: isScrollControlled,
    isDismissible: isDismissible,
    enableDrag: enableDrag,
    useSafeArea: useSafeArea,
    showDragHandle: showDragHandle,
    constraints: constraints,
    barrierColor: N360Colors.ink.withValues(alpha: 0.32),
    sheetAnimationStyle: AnimationStyle(
      duration: motion.sheet,
      reverseDuration: motion.quick + motion.quick,
      curve: N360Curves.settle,
      reverseCurve: N360Curves.exit,
    ),
  );
}

/// Standard padding for the body of a sheet shown with [showLedgerSheet]: a
/// gutter on each side and room at the foot for the thumb.
const EdgeInsets ledgerSheetPadding = EdgeInsets.fromLTRB(
  N360Space.gutter,
  N360Space.xs,
  N360Space.gutter,
  N360Space.lg,
);
