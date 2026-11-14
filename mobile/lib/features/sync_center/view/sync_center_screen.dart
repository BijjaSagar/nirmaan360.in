import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_format.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/brand_mark.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/sync_center_cubit.dart';
import '../data/sync_repository.dart';
import 'sync_labels.dart';

/// Where unsent entries wait, and the quiet moment when they have all gone.
class SyncCenterScreen extends StatelessWidget {
  const SyncCenterScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<SyncCenterCubit>(
      create: (context) => SyncCenterCubit(services: context.read<AppServices>()),
      child: const _SyncCenterView(),
    );
  }
}

class _SyncCenterView extends StatefulWidget {
  const _SyncCenterView();

  @override
  State<_SyncCenterView> createState() => _SyncCenterViewState();
}

class _SyncCenterViewState extends State<_SyncCenterView> {
  /// Only the first few slips are given to the tray; the rest show as edges.
  static const int _trayWindow = 8;

  /// Slips still on the tray, including those playing their exit.
  final Set<String> _inTray = <String>{};
  String _attentionKey = '';
  int _stackGeneration = 0;
  bool _stampAllSent = false;

  /// The most that has been waiting, so the gauge can fill as slips leave.
  int _peak = 0;
  late final Timer _clock;

  @override
  void initState() {
    super.initState();
    // Keeps "3 min ago" honest while the screen stays open.
    _clock = Timer.periodic(const Duration(minutes: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _clock.cancel();
    super.dispose();
  }

  void _track(SyncCenterState state, bool reduced) {
    if (state.waiting.length > _peak) _peak = state.waiting.length;
    final Set<String> ids = <String>{for (final item in state.waiting.take(_trayWindow)) '${item.id}'};
    final String attentionKey = state.attention.map((i) => i.id).join(',');
    if (attentionKey != _attentionKey) {
      // A slip that moved to "needs a look" must not earn a success tick, so
      // the stack is rebuilt rather than told the slip has gone.
      _attentionKey = attentionKey;
      _stackGeneration++;
      _inTray
        ..clear()
        ..addAll(ids);
    } else if (reduced) {
      _inTray
        ..clear()
        ..addAll(ids);
    } else {
      _inTray.addAll(ids);
    }
  }

  void _onSlipGone(String id) {
    setState(() {
      _inTray.remove(id);
      if (_inTray.isEmpty) _stampAllSent = true;
    });
  }

  List<SlipData> _slips(SyncCenterState state, AppLocalizations t, String locale) {
    return <SlipData>[
      for (final item in state.waiting.take(_trayWindow))
        SlipData(
          id: '${item.id}',
          title: t.syncTypeLabel(item.type),
          subtitle: item.isBlocked ? t.syncBlocked : _subtitle(item, locale),
          icon: syncTypeIcon(item.type),
          status: item.status == OutboxStatus.sending ? SlipStatus.sending : SlipStatus.waiting,
          statusLabel: item.status == OutboxStatus.sending ? t.shellSlipSending : t.shellSlipWaiting,
        ),
    ];
  }

  String? _subtitle(OutboxItem item, String locale) {
    final String title = item.title;
    if (title.isEmpty) return null;
    return switch (item.type) {
      OutboxType.dpr || OutboxType.dprSubmit || OutboxType.dprPhoto =>
        AppFormat.dayMonth(title, locale),
      _ => title,
    };
  }

  Future<void> _syncNow(AppLocalizations t) async {
    final SyncRunResult result = await context.read<SyncCenterCubit>().syncNow();
    if (!mounted) return;
    final String? message = switch (result.outcome) {
      SyncOutcome.offline => t.syncOffline,
      SyncOutcome.rateLimited => t.syncRateLimited,
      SyncOutcome.serverError => t.shellOutboxServerError,
      SyncOutcome.authExpired => t.syncSignInAgain,
      _ => null,
    };
    if (message != null) showPaperToast(context, message, kind: PaperToastKind.warning);
  }

  Future<void> _discard(OutboxItem item, AppLocalizations t) async {
    final String title = t.syncTypeLabel(item.type);
    final bool? confirmed = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (context) => _ConfirmSheet(
        title: t.shellDiscardTitle,
        detail: title,
        body: t.shellDiscardBody,
        confirmLabel: t.shellDiscardConfirm,
        keepLabel: t.shellDiscardKeep,
      ),
    );
    if (confirmed == true && mounted) await context.read<SyncCenterCubit>().discard(item);
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final bool reduced = context.motion.reduced;

    return BlocBuilder<SyncCenterCubit, SyncCenterState>(
      builder: (context, state) {
        // Still paper while the first read lands: the shape of the tray card
        // and a few slips, never a spinner.
        if (!state.loaded) return _OutboxLoading(label: t.shellOutboxLoading);
        _track(state, reduced);
        final bool trayShown = state.waiting.isNotEmpty || _inTray.isNotEmpty;
        final bool allSent = !trayShown && state.attention.isEmpty;
        if (!trayShown) _peak = 0;

        final Widget tray = trayShown
            ? _TrayCard(
                waiting: state.waiting.length,
                peak: _peak,
                stack: SlipStack(
                  key: ValueKey<int>(_stackGeneration),
                  count: state.waiting.length,
                  slips: _slips(state, t, locale),
                  trayLabel: t.shellOutboxTrayLabel,
                  onSlipGone: _onSlipGone,
                ),
              )
            : allSent
                ? _AllSent(animate: _stampAllSent)
                : const SizedBox.shrink();

        final String attentionTitle = t.shellOutboxAttention(state.attention.length);
        final List<Widget> extras = <Widget>[
          if (state.attention.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.md),
            LedgerList(
              header: Text(
                attentionTitle,
                style: N360Type.fit(N360Type.heading, attentionTitle).copyWith(color: N360Colors.stampRed),
              ),
              children: <Widget>[
                for (final item in state.attention)
                  _AttentionRow(
                    item: item,
                    onRetry: () => context.read<SyncCenterCubit>().retry(item),
                    onDiscard: () => _discard(item, t),
                  ),
              ],
            ),
          ],
          if (state.stale.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.md),
            _StaleLog(items: state.stale),
          ],
        ];

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            const _OutboxBar(),
            Expanded(
              child: LayoutBuilder(
                builder: (context, constraints) => SingleChildScrollView(
                  child: ConstrainedBox(
                    constraints: BoxConstraints(minHeight: constraints.maxHeight),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.md),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        mainAxisAlignment: allSent ? MainAxisAlignment.spaceEvenly : MainAxisAlignment.start,
                        children: <Widget>[
                          if (allSent) Center(child: tray) else tray,
                          if (extras.isNotEmpty) Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: extras),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
            _SyncNowBar(onPressed: () => _syncNow(t)),
          ],
        );
      },
    );
  }
}

/// The tab's bar, with the offline banner under it when there is no network.
class _OutboxBar extends StatelessWidget {
  const _OutboxBar();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final bool online = context.select((SyncStatusCubit s) => s.state.online);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        N360TopBar(
          backLabel: t.commonBack,
          showBack: false,
          title: t.shellOutboxTitle,
          leading: const BrandMark(size: 28),
        ),
        if (!online) OfflineBanner(label: t.shellOfflineLabel, detail: t.shellOfflineDetail),
      ],
    );
  }
}

/// The page before its first read: the bar in place, then the shape of the
/// tray card and three slips with one slow sheen across them.
class _OutboxLoading extends StatelessWidget {
  const _OutboxLoading({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        const _OutboxBar(),
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              LedgerSkeleton(figure: true, lines: 1, semanticsLabel: label),
              const SizedBox(height: N360Space.cardGap),
              LedgerSkeletonList(semanticsLabel: label),
            ],
          ),
        ),
      ],
    );
  }
}

/// When things last went out, and anything stopping them now.
class _LastSynced extends StatelessWidget {
  const _LastSynced({this.centered = false});

  final bool centered;

  String? _issueText(SyncStatus status, AppLocalizations t) => switch (status.lastIssue) {
        SyncIssue.rateLimited => t.syncRateLimited,
        SyncIssue.serverError => t.shellOutboxServerError,
        SyncIssue.authExpired => t.syncSignInAgain,
        _ => null,
      };

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final status = context.watch<SyncStatusCubit>().state;
    final DateTime now = context.read<AppServices>().context.clock.now();
    final DateTime? last = status.lastSyncedAt;
    final String lastLine = last == null ? t.shellOutboxNeverSynced : t.syncLastSynced(AppFormat.ago(t, last, now));
    final String? issue = _issueText(status, t);
    return Column(
      crossAxisAlignment: centered ? CrossAxisAlignment.center : CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          mainAxisSize: centered ? MainAxisSize.min : MainAxisSize.max,
          children: <Widget>[
            const Icon(Icons.history, size: N360IconSize.sm, color: N360Colors.ink2),
            const SizedBox(width: N360Space.xs),
            Flexible(child: Text(lastLine, style: N360Type.fit(N360Type.caption, lastLine))),
          ],
        ),
        if (issue != null)
          Text(issue, style: N360Type.fit(N360Type.label, issue).copyWith(color: N360Colors.haldiInk)),
      ],
    );
  }
}

/// The live count and a gauge that fills as slips leave the tray.
class _TrayCard extends StatelessWidget {
  const _TrayCard({required this.waiting, required this.peak, required this.stack});

  final int waiting;
  final int peak;
  final Widget stack;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final double sent = peak == 0 ? 0 : (peak - waiting) / peak;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: <Widget>[
                  RollingNumber(value: '$waiting', style: N360Type.figureHero),
                  const SizedBox(width: N360Space.md),
                  Expanded(
                    child: Text(
                      t.shellOutboxWaitingLabel,
                      style: N360Type.fit(N360Type.heading, t.shellOutboxWaitingLabel),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: N360Space.xs),
              const _LastSynced(),
              const SizedBox(height: N360Space.sm),
              TickGauge(fraction: sent, ticks: 36, semanticLabel: t.syncProgress(peak - waiting, peak)),
            ],
          ),
        ),
        const SizedBox(height: N360Space.cardGap),
        stack,
      ],
    );
  }
}

/// The moment it has all reached the office: the finished building with light
/// moving through its windows, and the quiet status under it.
class _AllSent extends StatelessWidget {
  const _AllSent({required this.animate});

  final bool animate;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.lg, N360Space.lg, N360Space.xl),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SizedBox(
            width: EmptyLedger.artWidth,
            child: LoopingArt.allSent(semanticLabel: t.shellOutboxAllSentStamp),
          ),
          const SizedBox(height: N360Space.md),
          RubberStamp(
            kind: StampKind.synced,
            label: t.shellOutboxAllSentStamp,
            animate: animate,
            onLanded: N360Haptics.success,
          ),
          const SizedBox(height: N360Space.sm),
          Text(
            t.shellOutboxAllSentBody,
            textAlign: TextAlign.center,
            style: N360Type.fit(N360Type.body, t.shellOutboxAllSentBody).copyWith(color: N360Colors.ink2),
          ),
          const SizedBox(height: N360Space.xs),
          const _LastSynced(centered: true),
        ],
      ),
    );
  }
}

/// One entry the server would not take: what it is, why, and the two ways out.
class _AttentionRow extends StatelessWidget {
  const _AttentionRow({required this.item, required this.onRetry, required this.onDiscard});

  final OutboxItem item;
  final VoidCallback onRetry;
  final VoidCallback onDiscard;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final Map<String, List<String>>? errors = item.validationErrors;
    final List<String> details = <String>[
      if (errors != null)
        for (final messages in errors.values) ...messages,
    ].take(3).toList();
    final String title = t.syncTypeLabel(item.type);
    final String? subtitle = item.title.isEmpty ? null : item.title;
    final String reason = t.syncReasonFor(item);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(syncTypeIcon(item.type), size: N360IconSize.lg, color: N360Colors.ink2),
              const SizedBox(width: N360Space.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(title, style: N360Type.fit(N360Type.bodyStrong, title)),
                    if (subtitle != null) Text(subtitle, style: N360Type.fit(N360Type.caption, subtitle)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: N360Space.sm),
          Text(reason, style: N360Type.fit(N360Type.body, reason)),
          for (final detail in details)
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xxs),
              child: Text(detail, style: N360Type.fit(N360Type.caption, detail)),
            ),
          const SizedBox(height: N360Space.md),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Expanded(
                  child: GloveButton(
                    label: t.syncRetry,
                    onPressed: onRetry,
                    kind: GloveButtonKind.secondary,
                    fullWidth: true,
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: GloveButton(
                    label: t.syncDiscard,
                    onPressed: onDiscard,
                    kind: GloveButtonKind.danger,
                    fullWidth: true,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Writes the server did not apply because its copy was newer.
class _StaleLog extends StatelessWidget {
  const _StaleLog({required this.items});

  final List<OutboxItem> items;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return LedgerList(
      header: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(t.shellOutboxLogTitle, style: N360Type.fit(N360Type.heading, t.shellOutboxLogTitle)),
          Text(t.syncStaleLog, style: N360Type.fit(N360Type.caption, t.syncStaleLog)),
        ],
      ),
      children: <Widget>[
        for (final item in items)
          LedgerListTile(
            title: t.syncTypeLabel(item.type),
            trailing: Text(item.title, style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
          ),
      ],
    );
  }
}

class _SyncNowBar extends StatelessWidget {
  const _SyncNowBar({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final bool busy = context.select((SyncStatusCubit s) => s.state.isSyncing);
    return N360ActionBar(
      child: GloveButton(
        label: busy ? t.syncInProgress : t.syncNow,
        onPressed: onPressed,
        loading: busy,
        large: true,
        fullWidth: true,
        icon: Icons.sync,
      ),
    );
  }
}

/// Asks once before throwing an entry away. The safe choice sits where the
/// thumb already is.
class _ConfirmSheet extends StatelessWidget {
  const _ConfirmSheet({
    required this.title,
    required this.detail,
    required this.body,
    required this.confirmLabel,
    required this.keepLabel,
  });

  final String title;
  final String detail;
  final String body;
  final String confirmLabel;
  final String keepLabel;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(title, style: N360Type.fit(N360Type.title, title)),
            const SizedBox(height: N360Space.xs),
            Text(detail, style: N360Type.fit(N360Type.bodyStrong, detail)),
            Text(body, style: N360Type.fit(N360Type.body, body).copyWith(color: N360Colors.ink2)),
            const SizedBox(height: N360Space.lg),
            GloveButton(
              label: confirmLabel,
              kind: GloveButtonKind.danger,
              fullWidth: true,
              icon: Icons.delete_outline,
              onPressed: () => Navigator.of(context).pop(true),
            ),
            const SizedBox(height: N360Space.sm),
            GloveButton(
              label: keepLabel,
              kind: GloveButtonKind.secondary,
              large: true,
              fullWidth: true,
              onPressed: () => Navigator.of(context).pop(false),
            ),
          ],
        ),
      ),
    );
  }
}
