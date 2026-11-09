import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../core/design/design.dart';
import '../../core/sync/sync_status.dart';
import '../../l10n/generated/app_localizations.dart';
import 'sync_status_cubit.dart';

/// A place of fixed height that shows [online] normally and the offline banner
/// when there is no network. The two slide past each other inside the slot, so
/// losing the network never moves anything else on the screen.
class OfflineSlot extends StatelessWidget {
  const OfflineSlot({super.key, required this.online});

  final Widget online;

  /// Fits the banner: its 34dp badge plus its own padding.
  static const double height = 56;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return SizedBox(
      height: height,
      child: BlocBuilder<SyncStatusCubit, SyncStatus>(
        buildWhen: (a, b) => a.online != b.online,
        builder: (context, status) => TweenAnimationBuilder<double>(
          tween: Tween<double>(end: status.online ? 0 : 1),
          duration: context.motion.toast,
          curve: N360Curves.rise,
          builder: (context, value, _) => ClipRect(
            child: Stack(
              fit: StackFit.expand,
              children: <Widget>[
                if (value < 1)
                  Transform.translate(offset: Offset(0, -value * height), child: online),
                if (value > 0)
                  Transform.translate(
                    offset: Offset(0, (1 - value) * height),
                    child: LayoutBuilder(
                      builder: (context, constraints) => OfflineBanner(
                        label: t.shellOfflineLabel,
                        detail: _detailFits(context, constraints.maxWidth, t) ? t.shellOfflineDetail : null,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Whether the label and detail fit on one line, since the slot cannot grow.
  bool _detailFits(BuildContext context, double width, AppLocalizations t) {
    // The banner's padding, its badge and the gap beside it.
    const double chrome = 88;
    final painter = TextPainter(
      textDirection: TextDirection.ltr,
      textScaler: MediaQuery.textScalerOf(context),
      maxLines: 1,
      text: TextSpan(
        text: t.shellOfflineLabel,
        style: N360Type.bodyStrong,
        children: <InlineSpan>[TextSpan(text: '  ${t.shellOfflineDetail}', style: N360Type.body)],
      ),
    )..layout();
    final bool fits = painter.width <= width - chrome;
    painter.dispose();
    return fits;
  }
}
