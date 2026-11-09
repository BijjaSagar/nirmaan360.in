import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/press_row.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../core/session/current_user.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../sync_center/data/sync_repository.dart';
import '../bloc/home_cubit.dart';
import '../data/home_repository.dart';

/// The site engineer's day at a glance.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final ProjectRow? project = context.select((AppGate gate) => gate.state.currentProject);
    if (project == null) return const SizedBox.shrink();
    return BlocProvider<HomeCubit>(
      key: ValueKey<int>(project.id),
      create: (context) => HomeCubit(services: context.read<AppServices>(), projectId: project.id),
      child: _HomeView(project: project),
    );
  }
}

class _HomeView extends StatelessWidget {
  const _HomeView({required this.project});

  final ProjectRow project;

  @override
  Widget build(BuildContext context) {
    final List<_TileSpec> tiles = _tilesFor(context);
    final double scale = MediaQuery.textScalerOf(context).scale(1);

    return Material(
      type: MaterialType.transparency,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final _GridLayout grid = _GridLayout.of(tiles, constraints.maxWidth - N360Space.gutter * 2, scale);
          // Pinned tiles stay in the thumb zone. If they would crush the top of
          // the page, everything scrolls together instead.
          final double panel = grid.rows(tiles.length) * 150 * scale + 32;
          final bool pinGrid = constraints.maxHeight - 90 * scale - panel >= 150;
          final Widget list = BlocBuilder<HomeCubit, HomeState>(
            builder: (context, state) => _HomeList(
              state: state,
              trailing: pinGrid ? null : _ActionGrid(tiles: tiles, layout: grid),
            ),
          );
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              _HomeHeader(project: project),
              Expanded(child: list),
              if (pinGrid) _ActionPanel(child: _ActionGrid(tiles: tiles, layout: grid)),
            ],
          );
        },
      ),
    );
  }

  List<_TileSpec> _tilesFor(BuildContext context) {
    final t = AppLocalizations.of(context);
    final CurrentUser? user = context.select((AppGate gate) => gate.state.user);
    bool can(String permission) => user?.can(permission) ?? false;
    final bool canApprove = can('measurement.approve') || can('dpr.approve') || can('ra_bill.approve');
    return <_TileSpec>[
      if (can('dpr.view'))
        _TileSpec(Icons.assignment, t.shellTileDaily, () => context.push(Routes.dprList)),
      if (can('measurement.view'))
        _TileSpec(Icons.architecture, t.shellTileMeasure, () => context.go(Routes.measurements)),
      if (can('attendance.view'))
        _TileSpec(Icons.groups, t.shellTileAttendance, () => context.push(Routes.attendance)),
      if (can('issue.view'))
        _TileSpec(Icons.report_problem_outlined, t.shellTileIssues, () => context.push(Routes.issues)),
      if (can('petty_cash.view'))
        _TileSpec(Icons.account_balance_wallet, t.shellTilePetty, () => context.push(Routes.pettyCash)),
      if (canApprove)
        _TileSpec(Icons.approval, t.shellTileApprovals, () => context.go(Routes.approvals), showsApprovals: true),
    ];
  }
}

class _HomeHeader extends StatelessWidget {
  const _HomeHeader({required this.project});

  final ProjectRow project;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final bool canSwitch = context.select((AppGate gate) => !gate.state.onlyOneProject);
    final String? location = project.location?.trim().isEmpty ?? true ? null : project.location;
    final String eyebrow = <String>[project.code, ?location].join(' · ');

    final Widget name = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SectionLabel(eyebrow),
        Row(
          children: <Widget>[
            Flexible(
              child: Text(
                project.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.title,
              ),
            ),
            if (canSwitch) const Icon(Icons.expand_more, size: 26, color: N360Colors.ink2),
          ],
        ),
      ],
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.sm, N360Space.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: canSwitch
                    ? Semantics(
                        container: true,
                        button: true,
                        label: '${project.name}. ${t.shellSwitchProject}',
                        onTap: () => context.push(Routes.projects),
                        child: ExcludeSemantics(
                          child: GestureDetector(
                            behavior: HitTestBehavior.opaque,
                            onTapDown: (_) => N360Haptics.tap(),
                            onTap: () => context.push(Routes.projects),
                            child: ConstrainedBox(
                              constraints: const BoxConstraints(minHeight: N360Size.tap),
                              child: name,
                            ),
                          ),
                        ),
                      )
                    : name,
              ),
              const _HeaderPill(),
            ],
          ),
          const SizedBox(height: N360Space.xs),
          const SizedBox(height: 1.5, child: ColoredBox(color: N360Colors.ink)),
          const SizedBox(height: 2),
          const RuledDivider(),
        ],
      ),
    );
  }
}

class _HeaderPill extends StatelessWidget {
  const _HeaderPill();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return BlocBuilder<SyncStatusCubit, SyncStatus>(
      builder: (context, status) {
        final (SyncVisualState state, int? count, String label) = switch (status) {
          SyncStatus(attentionCount: > 0) => (
              SyncVisualState.attention,
              status.attentionCount,
              t.shellPillAttention,
            ),
          SyncStatus(isSyncing: true) => (SyncVisualState.syncing, status.pendingCount, t.shellPillSending),
          SyncStatus(pendingCount: > 0) => (SyncVisualState.pending, status.pendingCount, t.shellPillWaiting),
          SyncStatus(online: false) => (SyncVisualState.offline, null, t.shellOfflineLabel),
          _ => (SyncVisualState.synced, null, t.shellPillAllSent),
        };
        return SyncPill(
          state: state,
          count: count,
          label: label,
          onTap: () => context.go(Routes.outbox),
        );
      },
    );
  }
}

/// Everything above the tile grid. Returned work always comes first.
class _HomeList extends StatelessWidget {
  const _HomeList({required this.state, this.trailing});

  final HomeState state;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final List<Widget> children = <Widget>[];
    var rise = 0;

    if (state.returned.isNotEmpty) {
      children.add(
        Padding(
          padding: const EdgeInsets.only(top: N360Space.sm),
          child: SectionLabel(t.shellReturnedTitle),
        ),
      );
      for (final item in state.returned) {
        children.add(
          Padding(
            padding: const EdgeInsets.only(top: N360Space.sm),
            child: StaggeredRise(index: rise++, child: _ReturnedSlip(item: item)),
          ),
        );
      }
    }

    children.add(
      Padding(
        padding: const EdgeInsets.only(top: N360Space.md),
        child: StaggeredRise(index: rise++, child: _TodayStrip(summary: state.summary)),
      ),
    );

    if (trailing != null) {
      children.add(
        Padding(
          padding: const EdgeInsets.only(top: N360Space.md, bottom: N360Space.md),
          child: trailing,
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.xs),
      children: children,
    );
  }
}

/// A document sent back for correction: the stamp, what it is, what the
/// checker said, and one tap straight into fixing it.
class _ReturnedSlip extends StatelessWidget {
  const _ReturnedSlip({required this.item});

  final ReturnedItem item;

  void _fix(BuildContext context) {
    final String path = item.kind == ReturnedKind.dpr ? Routes.dprForm : Routes.measurementForm;
    context.push(Uri(path: path, queryParameters: <String, String>{'uuid': item.uuid}).toString());
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final String date = AppFormat.dayMonth(item.date, locale);
    final String title = item.kind == ReturnedKind.dpr
        ? t.shellReturnedDpr(date)
        : t.shellReturnedMeasurement(item.code ?? '', item.qty ?? '', item.unit ?? '').trim();
    final String remark = (item.remark?.trim().isNotEmpty ?? false) ? item.remark!.trim() : t.shellReturnedNoRemark;

    return Semantics(
      container: true,
      button: true,
      label: '${t.statusReturned}. $title. $remark. ${t.shellReturnedFix}',
      onTap: () => _fix(context),
      child: ExcludeSemantics(
        child: CustomPaint(
          painter: const _ReturnedSlipPainter(),
          child: PressRow(
            onTap: () => _fix(context),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.md + 6, N360Space.sm + 2, N360Space.xs, N360Space.sm + 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: <Widget>[
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Row(
                          children: <Widget>[
                            RubberStamp(kind: StampKind.returned, label: t.statusReturned, size: StampSize.small),
                            const SizedBox(width: N360Space.sm),
                            Expanded(
                              child: Text(
                                title,
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: N360Type.bodyStrong,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 2),
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            const Padding(
                              padding: EdgeInsets.only(top: 3, right: N360Space.xs),
                              child: Icon(Icons.format_quote, size: 16, color: N360Colors.haldiInk),
                            ),
                            Expanded(
                              child: Text(
                                remark,
                                maxLines: 3,
                                overflow: TextOverflow.ellipsis,
                                style: N360Type.body,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: N360Space.sm),
                  SizedBox(
                    width: 64,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        const Icon(Icons.edit, size: 24, color: N360Colors.laterite),
                        Text(
                          t.shellReturnedFix,
                          textAlign: TextAlign.center,
                          maxLines: 2,
                          style: N360Type.caption.copyWith(color: N360Colors.laterite, fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ReturnedSlipPainter extends CustomPainter {
  const _ReturnedSlipPainter();

  @override
  void paint(Canvas canvas, Size size) {
    const edge = 2.0;
    final Rect face = Rect.fromLTWH(0, 0, size.width, size.height - edge);
    canvas.drawRect(Rect.fromLTWH(0, edge, size.width, size.height - edge), Paint()..color = N360Colors.paperEdge);
    canvas.drawRect(face, Paint()..color = N360Colors.sheet);
    canvas.drawRect(
      face.deflate(0.5),
      Paint()
        ..color = N360Colors.haldi
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1,
    );
    canvas.drawRect(Rect.fromLTWH(0, 0, 5, size.height - edge), Paint()..color = N360Colors.haldi);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Four figures the engineer owes the day, each a tap away from its screen.
class _TodayStrip extends StatelessWidget {
  const _TodayStrip({required this.summary});

  final HomeSummary? summary;

  void _openReport(BuildContext context) {
    final HomeSummary? data = summary;
    if (data == null) return;
    final String? uuid = data.dprClientUuid;
    switch (data.dprState) {
      case DprDayState.notStarted:
        context.push(Routes.dprForm);
      case DprDayState.draft || DprDayState.returned when uuid != null:
        context.push(Uri(path: Routes.dprForm, queryParameters: <String, String>{'uuid': uuid}).toString());
      case _ when uuid != null:
        context.push(Routes.dprDetail(uuid));
      case _:
        context.push(Routes.dprList);
    }
  }

  ({IconData icon, Color ink, String word}) _report(AppLocalizations t, DprDayState state) {
    return switch (state) {
      DprDayState.notStarted => (icon: Icons.edit_note, ink: N360Colors.laterite, word: t.shellReportNotStarted),
      DprDayState.draft => (icon: Icons.edit_note, ink: N360Colors.ink2, word: t.statusDraft),
      DprDayState.queued => (icon: Icons.schedule, ink: N360Colors.haldiInk, word: t.statusQueued),
      DprDayState.saved => (icon: Icons.cloud_done, ink: N360Colors.mossInk, word: t.shellReportSaved),
      DprDayState.inReview => (icon: Icons.hourglass_top, ink: N360Colors.neel, word: t.statusInReview),
      DprDayState.approved => (icon: Icons.task_alt, ink: N360Colors.mossInk, word: t.statusApproved),
      DprDayState.returned => (icon: Icons.undo, ink: N360Colors.haldiInk, word: t.statusReturned),
      DprDayState.rejected => (icon: Icons.block, ink: N360Colors.stampRed, word: t.statusRejected),
      DprDayState.attention => (icon: Icons.error_outline, ink: N360Colors.stampRed, word: t.shellReportAttention),
    };
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final HomeSummary? data = summary;
    final state = data?.dprState;
    final report = _report(t, state ?? DprDayState.notStarted);
    final int marked = data?.attendanceMarked ?? 0;
    final int total = data?.attendanceTotal ?? 0;
    final String attendance = total > 0 ? '$marked/$total' : '$marked';
    final String measured = '${data?.measurementsToday ?? 0}';
    final String spent = data == null ? '₹0' : AppFormat.rupees(data.expensesToday);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SectionLabel(t.commonToday),
        const SizedBox(height: N360Space.xs),
        _TodayRow(
          left: _TodayCell(
            icon: report.icon,
            iconColor: report.ink,
            caption: t.shellTodayReport,
            semantics: t.shellTodaySemantics(t.shellTodayReport, report.word),
            onTap: () => _openReport(context),
            value: Text(
              report.word,
              maxLines: 2,
              style: N360Type.heading.copyWith(color: report.ink, fontSize: 20, height: 1.2),
            ),
          ),
          right: _TodayCell(
            icon: Icons.groups,
            caption: t.shellTodayAttendance,
            semantics: t.shellTodaySemantics(t.shellTodayAttendance, attendance),
            onTap: () => context.push(Routes.attendance),
            value: RollingNumber(value: attendance, style: N360Type.figureLarge),
          ),
        ),
        const SizedBox(height: N360Space.sm),
        _TodayRow(
          left: _TodayCell(
            icon: Icons.architecture,
            caption: t.shellTodayMeasured,
            semantics: t.shellTodaySemantics(t.shellTodayMeasured, measured),
            onTap: () => context.go(Routes.measurements),
            value: RollingNumber(value: measured, style: N360Type.figureLarge),
          ),
          right: _TodayCell(
            icon: Icons.account_balance_wallet,
            caption: t.shellTodayExpenses,
            semantics: t.shellTodaySemantics(t.shellTodayExpenses, spent),
            onTap: () => context.push(Routes.pettyCash),
            value: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: RollingMoney(value: spent, style: N360Type.figureLarge),
            ),
          ),
        ),
      ],
    );
  }
}

class _TodayRow extends StatelessWidget {
  const _TodayRow({required this.left, required this.right});

  final Widget left;
  final Widget right;

  @override
  Widget build(BuildContext context) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Expanded(child: left),
          const SizedBox(width: N360Space.sm),
          Expanded(child: right),
        ],
      ),
    );
  }
}

class _TodayCell extends StatelessWidget {
  const _TodayCell({
    required this.icon,
    required this.caption,
    required this.semantics,
    required this.value,
    required this.onTap,
    this.iconColor = N360Colors.ink,
  });

  final IconData icon;
  final Color iconColor;
  final String caption;
  final String semantics;
  final Widget value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return LedgerCard(
      onTap: onTap,
      semanticsLabel: semantics,
      padding: const EdgeInsets.fromLTRB(N360Space.sm + 2, N360Space.sm, N360Space.xs, N360Space.sm),
      child: Row(
        children: <Widget>[
          Icon(icon, size: 30, color: iconColor),
          const SizedBox(width: N360Space.sm),
          Expanded(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(caption, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
                ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 38),
                  child: Align(alignment: Alignment.centerLeft, child: value),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The tiles sit on their own strip, a little darker than the page, so the
/// page above can scroll under its edge while the tiles stay put.
class _ActionPanel extends StatelessWidget {
  const _ActionPanel({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.paper2,
        border: Border(top: BorderSide(color: N360Colors.ruleStrong)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.md),
        child: child,
      ),
    );
  }
}

class _TileSpec {
  const _TileSpec(this.icon, this.label, this.onTap, {this.showsApprovals = false});

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool showsApprovals;
}

/// How many tiles across, and how far to scale their text to fit.
///
/// Three across keeps every tile in thumb reach. A word too wide for that,
/// or large system text, drops to two across, where the words have room.
@immutable
class _GridLayout {
  const _GridLayout(this.columns, this.textScale);

  factory _GridLayout.of(List<_TileSpec> tiles, double width, double scale) {
    if (tiles.length < 5 || scale > 1.15) return const _GridLayout(2, null);

    const double gap = N360Space.sm;
    final double inner = (width - gap * 2) / 3 - N360Space.sm * 2;
    final TextStyle style = N360Type.heading.copyWith(fontSize: 21 * scale, height: 1.26);
    var widest = 0.0;
    for (final tile in tiles) {
      for (final word in tile.label.split(' ')) {
        final painter = TextPainter(
          text: TextSpan(text: word, style: style),
          textDirection: TextDirection.ltr,
        )..layout();
        widest = math.max(widest, painter.width);
        painter.dispose();
      }
    }
    // A little headroom: a word that only just fits still wraps mid-word.
    final double room = inner * 0.96;
    final double fit = widest <= room ? 1 : room / widest;
    if (fit < 0.82) return const _GridLayout(2, null);
    return _GridLayout(3, fit == 1 ? null : fit * scale);
  }

  final int columns;

  /// Text scale that makes the widest word fit. Null leaves the text alone.
  final double? textScale;

  int rows(int count) => (count / columns).ceil();
}

/// The main actions, in rows the thumb can reach.
class _ActionGrid extends StatelessWidget {
  const _ActionGrid({required this.tiles, required this.layout});

  final List<_TileSpec> tiles;
  final _GridLayout layout;

  @override
  Widget build(BuildContext context) {
    const double gap = N360Space.sm;
    final int columns = layout.columns;
    final int count = context.select((HomeCubit cubit) => cubit.state.approvalsCount);
    final MediaQueryData media = MediaQuery.of(context);

    Widget tile(_TileSpec spec, int index) {
      final Widget actionTile = ActionTile(
        icon: spec.icon,
        label: spec.label,
        onTap: spec.onTap,
        badge: spec.showsApprovals && count > 0 ? '$count' : null,
      );
      return StaggeredRise(
        index: 2 + index,
        child: layout.textScale == null
            ? actionTile
            : MediaQuery(
                data: media.copyWith(textScaler: TextScaler.linear(layout.textScale!)),
                child: actionTile,
              ),
      );
    }

    final List<Widget> rows = <Widget>[];
    for (var start = 0; start < tiles.length; start += columns) {
      final List<_TileSpec> row = tiles.sublist(start, (start + columns).clamp(0, tiles.length));
      rows.add(
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              for (final (i, spec) in row.indexed) ...<Widget>[
                if (i > 0) const SizedBox(width: gap),
                Expanded(child: tile(spec, start + i)),
              ],
            ],
          ),
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        for (final (i, row) in rows.indexed) ...<Widget>[
          if (i > 0) const SizedBox(height: gap),
          row,
        ],
      ],
    );
  }
}
