import 'package:flutter/material.dart';

import '../signature/progress_rule.dart';
import '../tokens.dart';

/// Pull to refresh without a spinner. The list stretches under the thumb as
/// Android lists do; once the pull is far enough a gold rule appears along the
/// top edge, and while the refresh runs the rule sweeps, the same sweep the
/// sync centre shows. It leaves when the work is done.
///
/// ```dart
/// LedgerRefresh(onRefresh: cubit.refresh, child: ListView(...))
/// ```
class LedgerRefresh extends StatefulWidget {
  const LedgerRefresh({
    super.key,
    required this.onRefresh,
    required this.child,
    this.semanticsLabel,
    this.notificationPredicate = defaultScrollNotificationPredicate,
  });

  final RefreshCallback onRefresh;
  final Widget child;

  /// Spoken while refreshing. Pass the translated word.
  final String? semanticsLabel;
  final ScrollNotificationPredicate notificationPredicate;

  @override
  State<LedgerRefresh> createState() => _LedgerRefreshState();
}

class _LedgerRefreshState extends State<LedgerRefresh> {
  RefreshIndicatorStatus? _status;

  void _onStatus(RefreshIndicatorStatus? status) {
    if (status == _status) return;
    setState(() => _status = status);
  }

  @override
  Widget build(BuildContext context) {
    final bool armed = _status == RefreshIndicatorStatus.armed || _status == RefreshIndicatorStatus.snap;
    final bool running = _status == RefreshIndicatorStatus.refresh;
    return Stack(
      children: <Widget>[
        RefreshIndicator.noSpinner(
          onRefresh: widget.onRefresh,
          onStatusChange: _onStatus,
          notificationPredicate: widget.notificationPredicate,
          semanticsLabel: widget.semanticsLabel,
          child: widget.child,
        ),
        if (armed || running)
          Positioned(
            left: N360Space.gutter,
            right: N360Space.gutter,
            top: 0,
            child: IgnorePointer(
              child: ProgressRule(
                value: armed ? 1 : null,
                height: 3,
                semanticsLabel: running ? widget.semanticsLabel : null,
              ),
            ),
          ),
      ],
    );
  }
}
