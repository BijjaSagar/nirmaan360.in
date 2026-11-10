import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart';
import '../../site_progress/data/progress_models.dart' show percentText;
import '../../site_progress/view/widgets/progress_widgets.dart' show percentFraction;
import '../bloc/site_alerts_cubit.dart';
import '../data/site_alerts_repository.dart';

/// What the server says about this site today, then what needs attention.
class SiteDashboardScreen extends StatelessWidget {
  const SiteDashboardScreen({super.key, this.tab = SiteTab.dashboard});

  final SiteTab tab;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<SiteAlertsCubit>(
      create: (context) => SiteAlertsCubit(context.read<AppServices>(), tab: tab),
      child: const _SiteView(),
    );
  }
}

class _SiteView extends StatelessWidget {
  const _SiteView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              BlocBuilder<SiteAlertsCubit, SiteAlertsState>(
                buildWhen: (a, b) => a.project?.id != b.project?.id,
                builder: (context, state) => FieldTopBar(title: l.alertsTitle, subtitle: state.project?.code),
              ),
              const Expanded(child: _SiteScroll()),
            ],
          ),
        ),
      ),
    );
  }
}

class _SiteScroll extends StatelessWidget {
  const _SiteScroll();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<SiteAlertsCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.alertsLoading,
      child: BlocBuilder<SiteAlertsCubit, SiteAlertsState>(
        builder: (context, state) => CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: _slivers(l, state),
        ),
      ),
    );
  }

  List<Widget> _slivers(AppLocalizations l, SiteAlertsState state) {
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));
    Widget skeleton() => SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(lines: 3, semanticsLabel: l.alertsLoading),
      ),
    );

    if (state.load == SiteLoad.loading) return <Widget>[skeleton()];
    if (state.load == SiteLoad.noProject) {
      return <Widget>[empty(l.progressNoProjectTitle, l.progressNoProjectMessage)];
    }
    final SiteOverview? overview = state.overview;
    if (overview == null) {
      return <Widget>[if (state.refreshing) skeleton() else empty(l.progressNoDataTitle, l.alertsNoDataMessage)];
    }
    if (overview.refused) return <Widget>[empty(l.alertsRefusedTitle, l.alertsRefusedMessage)];

    return <Widget>[
      // A person who may read only one of the two is not asked to choose.
      if (state.hasTabs) SliverToBoxAdapter(child: _Tabs(state: state, overview: overview)),
      ...switch (state.shownTab) {
        SiteTab.dashboard => _dashboard(l, overview, empty),
        SiteTab.alerts => _alerts(l, state, overview, empty),
      },
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.lg)),
    ];
  }

  List<Widget> _dashboard(
    AppLocalizations l,
    SiteOverview overview,
    SliverToBoxAdapter Function(String, String) empty,
  ) {
    if (overview.dashboardRefused) return <Widget>[empty(l.alertsRefusedTitle, l.alertsRefusedMessage)];
    final List<SiteCard> cards = overview.dashboardCards;
    if (cards.isEmpty) return <Widget>[empty(l.progressNoDataTitle, l.alertsDashboardEmpty)];
    return <Widget>[
      SliverPadding(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
        sliver: SliverList.separated(
          itemCount: cards.length,
          separatorBuilder: (context, index) => const SizedBox(height: N360Space.cardGap),
          itemBuilder: (context, index) => _DashboardCard(card: cards[index]),
        ),
      ),
    ];
  }

  List<Widget> _alerts(
    AppLocalizations l,
    SiteAlertsState state,
    SiteOverview overview,
    SliverToBoxAdapter Function(String, String) empty,
  ) {
    if (overview.alertsRefused) return <Widget>[empty(l.alertsRefusedTitle, l.alertsListRefusedMessage)];
    // Only someone with `alerts.manage` is offered a scan.
    final Widget? scan = state.canScan ? SliverToBoxAdapter(child: _ScanBar(scan: state.scan)) : null;
    if (overview.alerts.isEmpty) return <Widget>[?scan, empty(l.alertsNoneTitle, l.alertsNoneMessage)];
    return <Widget>[
      ?scan,
      FieldGroupSliver(
        padding: EdgeInsets.zero,
        sliver: SliverList.builder(
          itemCount: overview.alerts.length,
          itemBuilder: (context, index) => Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              if (index > 0) const RuledDivider(indent: N360Space.card),
              _AlertRow(alert: overview.alerts[index]),
            ],
          ),
        ),
      ),
      if (overview.alertsTotal > overview.alerts.length)
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
            child: Builder(
              builder: (context) {
                final String note = l.alertsShowingSome(overview.alerts.length, overview.alertsTotal);
                return Text(note, style: N360Type.fit(N360Type.caption, note));
              },
            ),
          ),
        ),
    ];
  }
}

/// "Scan now" and what came of it. A refusal is shown in the server's own
/// words, so the rate limit reads as the server said it.
class _ScanBar extends StatelessWidget {
  const _ScanBar({required this.scan});

  final ScanNotice scan;

  List<String> _lines(AppLocalizations l) {
    final String? said = scan.serverMessage;
    final int? seconds = scan.wait == null ? null : (scan.wait!.inMilliseconds / 1000).ceil();
    return switch (scan.status) {
      ScanStatus.idle => const <String>[],
      ScanStatus.running => <String>[l.alertsScanning],
      ScanStatus.done => <String>[l.alertsScanDone(scan.result?.added ?? 0, scan.result?.open ?? 0, scan.result?.resolved ?? 0)],
      ScanStatus.offline => <String>[l.alertsScanOffline],
      ScanStatus.failed => <String>[l.alertsScanFailed],
      ScanStatus.refused => <String>[said == null ? l.alertsScanFailed : l.alertsScanRefused(said)],
      ScanStatus.rateLimited => <String>[
        if (said != null) l.alertsScanRefused(said),
        if (seconds != null && seconds > 0) l.alertsScanWait(seconds),
        if (said == null && (seconds == null || seconds <= 0)) l.alertsScanFailed,
      ],
    };
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final SiteAlertsCubit cubit = context.read<SiteAlertsCubit>();
    final List<String> lines = _lines(l);
    final bool problem = const <ScanStatus>{
      ScanStatus.offline,
      ScanStatus.failed,
      ScanStatus.refused,
      ScanStatus.rateLimited,
    }.contains(scan.status);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          GloveButton(
            key: const ValueKey<String>('site-scan-now'),
            label: l.alertsScanNow,
            icon: Icons.radar,
            kind: GloveButtonKind.secondary,
            fullWidth: true,
            loading: scan.running,
            onPressed: scan.running ? null : cubit.scan,
          ),
          if (lines.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xs),
              child: Semantics(
                liveRegion: true,
                child: Row(
                  key: const ValueKey<String>('site-scan-result'),
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Icon(
                      problem
                          ? Icons.error_outline
                          : scan.running
                          ? Icons.hourglass_empty
                          : Icons.check_circle_outline,
                      size: N360IconSize.md,
                      color: problem ? N360Colors.stampRed : N360Colors.ink2,
                    ),
                    const SizedBox(width: N360Space.xs),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          for (final String line in lines) Text(line, style: N360Type.fit(N360Type.body, line)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _Tabs extends StatelessWidget {
  const _Tabs({required this.state, required this.overview});

  final SiteAlertsState state;
  final SiteOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final SiteAlertsCubit cubit = context.read<SiteAlertsCubit>();
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.sm),
      child: Wrap(
        spacing: N360Space.sm,
        runSpacing: N360Space.sm,
        children: <Widget>[
          IntrinsicWidth(
            child: N360Chip(
              key: const ValueKey<String>('site-tab-dashboard'),
              label: l.alertsTabDashboard,
              selected: state.tab == SiteTab.dashboard,
              onTap: () => cubit.setTab(SiteTab.dashboard),
            ),
          ),
          IntrinsicWidth(
            child: N360Chip(
              key: const ValueKey<String>('site-tab-alerts'),
              label: l.alertsTabAlerts,
              count: overview.alertsRefused || overview.alertsTotal == 0 ? null : '${overview.alertsTotal}',
              selected: state.tab == SiteTab.alerts,
              onTap: () => cubit.setTab(SiteTab.alerts),
            ),
          ),
        ],
      ),
    );
  }
}

Color _toneColor(CardTone tone) => switch (tone) {
  CardTone.warn => N360Colors.laterite,
  CardTone.bad => N360Colors.stampRed,
  _ => N360Colors.ink,
};

class _DashboardCard extends StatelessWidget {
  const _DashboardCard({required this.card});

  final SiteCard card;

  @override
  Widget build(BuildContext context) {
    final String? note = card.note?.trim().isEmpty ?? true ? null : card.note;
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(card.title, style: N360Type.fit(N360Type.label, card.title)),
          const SizedBox(height: N360Space.xxs),
          Text(
            card.value,
            style: N360Type.fit(N360Type.title, card.value).copyWith(color: _toneColor(card.tone)),
          ),
          if (note != null) Text(note, style: N360Type.fit(N360Type.caption, note)),
          for (final CardMeter meter in card.meters) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            _Meter(meter: meter),
          ],
          if (card.lines.isNotEmpty) const SizedBox(height: N360Space.xs),
          for (final (int index, CardLine line) in card.lines.indexed) ...<Widget>[
            if (index > 0) const RuledDivider(),
            _Line(line: line),
          ],
        ],
      ),
    );
  }
}

class _Meter extends StatelessWidget {
  const _Meter({required this.meter});

  final CardMeter meter;

  @override
  Widget build(BuildContext context) {
    final String? percent = meter.percent;
    final String shown = percentText(percent);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(child: Text(meter.label, style: N360Type.fit(N360Type.caption, meter.label))),
            const SizedBox(width: N360Space.sm),
            Text(shown, style: N360Type.figureSmall),
          ],
        ),
        const SizedBox(height: N360Space.xxs),
        HatchedProgressBar(fraction: percentFraction(percent), height: 6, semanticLabel: '${meter.label} $shown'),
      ],
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.line});

  final CardLine line;

  @override
  Widget build(BuildContext context) {
    final String? sub = line.sub?.trim().isEmpty ?? true ? null : line.sub;
    final String? value = line.value?.trim().isEmpty ?? true ? null : line.value;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: N360Space.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(line.label, style: N360Type.fit(N360Type.body, line.label)),
                if (sub != null) Text(sub, style: N360Type.fit(N360Type.caption, sub)),
              ],
            ),
          ),
          if (value != null) ...<Widget>[
            const SizedBox(width: N360Space.sm),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 150),
              child: Text(
                value,
                textAlign: TextAlign.right,
                style: N360Type.fit(N360Type.body, value).copyWith(color: _toneColor(line.tone)),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// The word carries the severity. The colour only repeats it.
StampKind alertStampKind(AlertSeverity severity) => switch (severity) {
  AlertSeverity.critical => StampKind.rejected,
  AlertSeverity.warning => StampKind.returned,
  AlertSeverity.info => StampKind.draft,
};

extension AlertSeverityWords on AppLocalizations {
  String alertSeverityWord(AlertSeverity severity) => switch (severity) {
    AlertSeverity.critical => alertsSeverityCritical,
    AlertSeverity.warning => alertsSeverityWarning,
    AlertSeverity.info => alertsSeverityInfo,
  };
}

class _AlertRow extends StatelessWidget {
  const _AlertRow({required this.alert});

  final SiteAlert alert;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String word = l.alertSeverityWord(alert.severity);
    final String? first = alert.firstSeen;
    final String? since = first == null ? null : l.alertsSince(dayLabel(context, first, context.read<AppServices>().context.today));
    final String spoken = <String>[word, alert.title, alert.message, ?since].join('. ');
    return Semantics(
      key: ValueKey<String>('site-alert-${alert.id}'),
      container: true,
      label: spoken,
      child: ExcludeSemantics(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                children: <Widget>[
                  StatusPill(kind: alertStampKind(alert.severity), label: word),
                  if (since != null) ...<Widget>[
                    const SizedBox(width: N360Space.sm),
                    Expanded(
                      child: Text(
                        since,
                        textAlign: TextAlign.right,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: N360Type.fit(N360Type.caption, since),
                      ),
                    ),
                  ],
                ],
              ),
              const SizedBox(height: N360Space.xs),
              Text(alert.title, style: N360Type.fit(N360Type.bodyStrong, alert.title)),
              const SizedBox(height: N360Space.xxs),
              Text(alert.message, style: N360Type.fit(N360Type.body, alert.message).copyWith(color: N360Colors.ink2)),
            ],
          ),
        ),
      ),
    );
  }
}
