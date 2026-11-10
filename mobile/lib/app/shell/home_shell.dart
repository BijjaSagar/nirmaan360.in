import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../core/app_services.dart';
import '../../core/design/design.dart';
import '../../core/sync/sync_status.dart';
import '../../l10n/generated/app_localizations.dart';
import '../app_gate.dart';
import 'sync_status_cubit.dart';

class _ShellTab {
  const _ShellTab(this.branch, this.icon, this.label, {this.badge});

  final int branch;
  final IconData icon;
  final String label;
  final String? badge;
}

const int _approvalsBranch = 2;

/// The four-tab shell around Home, Measure, Approvals and Outbox.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key, required this.navigationShell});

  final StatefulNavigationShell navigationShell;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> with SingleTickerProviderStateMixin {
  late final AnimationController _shift = AnimationController(vsync: this, value: 1);
  double _from = 0;

  /// Approvals waiting on this person, for the badge on the Approvals tab.
  StreamSubscription<int>? _approvals;
  int _waitingApprovals = 0;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _approvals ??= context.read<AppServices>().approvals.watchCount().listen((count) {
      if (mounted && count != _waitingApprovals) setState(() => _waitingApprovals = count);
    });
  }

  @override
  void didUpdateWidget(covariant HomeShell oldWidget) {
    super.didUpdateWidget(oldWidget);
    final int before = oldWidget.navigationShell.currentIndex;
    final int now = widget.navigationShell.currentIndex;
    if (before != now) {
      _from = now > before ? 28 : -28;
      _shift.duration = context.motion.of(N360Durations.rise);
      _shift.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _approvals?.cancel();
    _shift.dispose();
    super.dispose();
  }

  void _openTab(StatefulNavigationShell shell, int branch) {
    shell.goBranch(branch, initialLocation: branch == shell.currentIndex);
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final shell = widget.navigationShell;
    final bool canApprove = context.select((AppGate gate) => gate.state.canApprove);
    // Nothing waits to be sent on the offline plan, so there is no outbox.
    final bool localOnly = context.select((AppGate gate) => gate.state.isLocalOnly);
    final int unsent = context.select((SyncStatusCubit s) => s.state.pendingCount + s.state.attentionCount);
    final List<_ShellTab> tabs = <_ShellTab>[
      _ShellTab(0, Icons.home_outlined, t.shellTabHome),
      _ShellTab(1, Icons.architecture, t.shellTabMeasure),
      if (canApprove)
        _ShellTab(
          _approvalsBranch,
          Icons.approval_outlined,
          t.shellTabApprovals,
          badge: _waitingApprovals > 0 ? '$_waitingApprovals' : null,
        ),
      if (!localOnly) _ShellTab(3, Icons.inbox_outlined, t.shellTabOutbox, badge: unsent > 0 ? '$unsent' : null),
    ];
    final int current = tabs.indexWhere((tab) => tab.branch == shell.currentIndex);
    // Each tab paints its own top bar, status-bar area included, so the shell
    // passes the inset straight through. The sync rule draws along the bar's
    // lower edge, where the eye already rests.
    final double barBottom = MediaQuery.paddingOf(context).top + N360TopBar.height;

    return PopScope(
      canPop: shell.currentIndex == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) shell.goBranch(0);
      },
      child: Scaffold(
        body: Stack(
          children: <Widget>[
            RepaintBoundary(
              child: AnimatedBuilder(
                animation: _shift,
                child: shell,
                builder: (context, child) {
                  final double remaining = 1 - N360Curves.rise.transform(_shift.value);
                  return Transform.translate(offset: Offset(_from * remaining, 0), child: child);
                },
              ),
            ),
            Positioned(top: barBottom - _SyncRule.height, left: 0, right: 0, child: const _SyncRule()),
          ],
        ),
        bottomNavigationBar: N360BottomBar(
          currentIndex: current < 0 ? 0 : current,
          onTap: (index) => _openTab(shell, tabs[index].branch),
          items: <N360BarItem>[
            for (final tab in tabs) N360BarItem(icon: tab.icon, label: tab.label, badge: tab.badge),
          ],
        ),
      ),
    );
  }
}

/// Draws across the top of the shell while a sync runs, so nothing moves.
class _SyncRule extends StatelessWidget {
  const _SyncRule();

  /// The rule's thickness, so it can sit on the bar's lower edge.
  static const double height = 4;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return BlocBuilder<SyncStatusCubit, SyncStatus>(
      buildWhen: (a, b) => a.isSyncing != b.isSyncing || a.progress != b.progress,
      builder: (context, status) {
        if (!status.isSyncing) return const SizedBox.shrink();
        final progress = status.progress;
        final double? value =
            progress != null && progress.total > 0 ? progress.done / progress.total : null;
        return ProgressRule(value: value, height: height, semanticsLabel: t.shellSyncingLabel);
      },
    );
  }
}

