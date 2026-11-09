import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../core/app_services.dart';
import '../../core/design/design.dart';
import '../../core/sync/sync_status.dart';
import '../../l10n/generated/app_localizations.dart';
import '../app_format.dart';
import '../app_gate.dart';
import '../routes.dart';
import 'sync_status_cubit.dart';

/// Height of the strip at the top of the shell: today's date when online, the
/// hazard tape when offline. Always the same height, so going offline moves
/// nothing on the screen.
const double _railHeight = 48;
const double _settingsWidth = 64;

class _ShellTab {
  const _ShellTab(this.branch, this.icon, this.label);

  final int branch;
  final IconData icon;
  final String label;
}

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
    final List<_ShellTab> tabs = <_ShellTab>[
      _ShellTab(0, Icons.home_outlined, t.shellTabHome),
      _ShellTab(1, Icons.architecture, t.shellTabMeasure),
      if (canApprove) _ShellTab(2, Icons.approval, t.shellTabApprovals),
      _ShellTab(3, Icons.inbox_outlined, t.shellTabOutbox),
    ];
    final int current = tabs.indexWhere((tab) => tab.branch == shell.currentIndex);
    final double safeTop = MediaQuery.paddingOf(context).top;

    return PopScope(
      canPop: shell.currentIndex == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) shell.goBranch(0);
      },
      child: Scaffold(
        body: Stack(
          children: <Widget>[
            Column(
              children: <Widget>[
                SizedBox(height: safeTop),
                const SizedBox(height: _railHeight, child: _Rail()),
                Expanded(
                  child: MediaQuery.removePadding(
                    context: context,
                    removeTop: true,
                    child: RepaintBoundary(
                      child: AnimatedBuilder(
                        animation: _shift,
                        child: shell,
                        builder: (context, child) {
                          final double remaining = 1 - N360Curves.rise.transform(_shift.value);
                          return Transform.translate(offset: Offset(_from * remaining, 0), child: child);
                        },
                      ),
                    ),
                  ),
                ),
              ],
            ),
            Positioned(top: safeTop, left: 0, right: 0, child: const _SyncRule()),
            Positioned(top: safeTop, right: 0, child: const _SettingsTarget()),
          ],
        ),
        bottomNavigationBar: N360BottomBar(
          currentIndex: current < 0 ? 0 : current,
          onTap: (index) => _openTab(shell, tabs[index].branch),
          items: <N360BarItem>[
            for (final tab in tabs) N360BarItem(icon: tab.icon, label: tab.label),
          ],
        ),
      ),
    );
  }
}

/// Draws across the top of the shell while a sync runs, over the rail so the
/// layout never moves.
class _SyncRule extends StatelessWidget {
  const _SyncRule();

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
        return ProgressRule(value: value, semanticsLabel: t.shellSyncingLabel);
      },
    );
  }
}

class _Rail extends StatelessWidget {
  const _Rail();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final String date = AppFormat.weekdayDate(context.read<AppServices>().context.today, locale);

    return DecoratedBox(
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: N360Colors.rule)),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Stack(
              fit: StackFit.expand,
              children: <Widget>[
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
                  child: Align(alignment: Alignment.centerLeft, child: SectionLabel(date)),
                ),
                BlocBuilder<SyncStatusCubit, SyncStatus>(
                  buildWhen: (a, b) => a.online != b.online,
                  builder: (context, status) => _OfflineSlide(offline: !status.online),
                ),
              ],
            ),
          ),
          SizedBox(
            width: _settingsWidth,
            child: DecoratedBox(
              decoration: const BoxDecoration(
                color: N360Colors.sheet,
                border: Border(left: BorderSide(color: N360Colors.rule)),
              ),
              child: ExcludeSemantics(
                child: MediaQuery.withClampedTextScaling(
                  maxScaleFactor: 1.1,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      const Icon(Icons.settings_outlined, size: 20, color: N360Colors.ink2),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(
                          t.shellSettings,
                          maxLines: 1,
                          style: N360Type.caption.copyWith(fontSize: 12.5, height: 1.25, color: N360Colors.ink2),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Slides the hazard tape down over the date line, inside the rail, so the page
/// below is never pushed about.
class _OfflineSlide extends StatelessWidget {
  const _OfflineSlide({required this.offline});

  final bool offline;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return TweenAnimationBuilder<double>(
      tween: Tween<double>(end: offline ? 1 : 0),
      duration: context.motion.toast,
      curve: N360Curves.rise,
      child: LayoutBuilder(
        builder: (context, constraints) => OfflineBanner(
          label: t.shellOfflineLabel,
          detail: _detailFits(context, constraints.maxWidth, t) ? t.shellOfflineDetail : null,
        ),
      ),
      builder: (context, value, child) {
        if (value <= 0) return const SizedBox.shrink();
        return ClipRect(
          child: Transform.translate(offset: Offset(0, (value - 1) * _railHeight), child: child),
        );
      },
    );
  }

  /// Whether the label and detail fit on one line, since the rail cannot grow.
  bool _detailFits(BuildContext context, double width, AppLocalizations t) {
    // Hazard strip, gaps and the cloud icon take this much of the row.
    const double chrome = 22 + N360Space.md + 22 + N360Space.sm + N360Space.sm;
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

/// The tappable area for Settings: the rail cell plus the gap below it, which
/// makes the target 56dp tall without making the rail taller.
class _SettingsTarget extends StatelessWidget {
  const _SettingsTarget();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    void open() => context.push(Routes.settings);
    return Semantics(
      container: true,
      button: true,
      label: t.shellSettings,
      onTap: open,
      child: ExcludeSemantics(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapDown: (_) => N360Haptics.tap(),
          onTap: open,
          child: const SizedBox(width: _settingsWidth, height: N360Size.tap),
        ),
      ),
    );
  }
}
