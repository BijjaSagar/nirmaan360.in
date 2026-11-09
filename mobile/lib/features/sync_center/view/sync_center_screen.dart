import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_format.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/sync_center_cubit.dart';
import '../data/sync_repository.dart';
import 'sync_labels.dart';

/// Where unsent entries wait, and the satisfying moment when they leave.
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

  /// Header at the top, the stamp in the middle of the free paper, and any
  /// log at the foot.
  List<Widget> _allSentChildren(List<Widget> items) {
    final List<Widget> rest = items.sublist(3);
    return <Widget>[
      items.first,
      Center(child: items[2]),
      if (rest.isEmpty) const SizedBox(height: N360Space.xxl) else Column(children: rest),
    ];
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
    final bool? confirmed = await showModalBottomSheet<bool>(
      context: context,
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
        if (!state.loaded) return const SizedBox.shrink();
        _track(state, reduced);
        final bool trayShown = state.waiting.isNotEmpty || _inTray.isNotEmpty;
        final bool allSent = !trayShown && state.attention.isEmpty;

        final List<Widget> items = <Widget>[
          _OutboxHeader(waiting: state.waiting.length),
          const SizedBox(height: N360Space.md),
          if (trayShown)
            SlipStack(
              key: ValueKey<int>(_stackGeneration),
              count: state.waiting.length,
              slips: _slips(state, t, locale),
              trayLabel: t.shellOutboxTrayLabel,
              onSlipGone: _onSlipGone,
            )
          else if (allSent)
            _AllSent(animate: _stampAllSent),
          if (state.attention.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.xl),
            Text(t.shellOutboxAttention(state.attention.length), style: N360Type.heading.copyWith(color: N360Colors.stampRed)),
            const SizedBox(height: N360Space.xs),
            for (final item in state.attention)
              Padding(
                padding: const EdgeInsets.only(bottom: N360Space.sm),
                child: _AttentionSlip(
                  item: item,
                  onRetry: () => context.read<SyncCenterCubit>().retry(item),
                  onDiscard: () => _discard(item, t),
                ),
              ),
          ],
          if (state.stale.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.xl),
            _StaleLog(items: state.stale),
          ],
        ];

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Expanded(
              child: LayoutBuilder(
                builder: (context, constraints) => SingleChildScrollView(
                  child: ConstrainedBox(
                    constraints: BoxConstraints(minHeight: constraints.maxHeight),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        mainAxisAlignment: allSent ? MainAxisAlignment.spaceBetween : MainAxisAlignment.start,
                        children: allSent ? _allSentChildren(items) : items,
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

class _OutboxHeader extends StatelessWidget {
  const _OutboxHeader({required this.waiting});

  final int waiting;

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
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SectionLabel(t.shellOutboxTitle),
        if (waiting > 0)
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              RollingNumber(value: '$waiting', style: N360Type.figureHero),
              const SizedBox(width: N360Space.md),
              Flexible(child: Text(t.shellOutboxWaitingLabel, style: N360Type.title)),
            ],
          ),
        Row(
          children: <Widget>[
            const Icon(Icons.history, size: 18, color: N360Colors.inkLabel),
            const SizedBox(width: N360Space.xs),
            Flexible(child: Text(lastLine, style: N360Type.body.copyWith(color: N360Colors.ink2))),
          ],
        ),
        if (issue != null)
          Padding(
            padding: const EdgeInsets.only(top: N360Space.xs),
            child: Text(issue, style: N360Type.bodyStrong.copyWith(color: N360Colors.haldiInk)),
          ),
      ],
    );
  }
}

/// The day's closing stamp, pressed straight onto the empty page.
class _AllSent extends StatelessWidget {
  const _AllSent({required this.animate});

  final bool animate;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        RubberStamp(
          kind: StampKind.synced,
          label: t.shellOutboxAllSentStamp,
          size: StampSize.hero,
          animate: animate,
          onLanded: N360Haptics.success,
        ),
        const SizedBox(height: N360Space.lg),
        Text(
          t.shellOutboxAllSentBody,
          textAlign: TextAlign.center,
          style: N360Type.bodyLarge.copyWith(color: N360Colors.ink2),
        ),
      ],
    );
  }
}

class _AttentionSlip extends StatelessWidget {
  const _AttentionSlip({required this.item, required this.onRetry, required this.onDiscard});

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

    return CustomPaint(
      painter: const _AttentionPainter(),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.md + 6, N360Space.md, N360Space.md, N360Space.md + 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Icon(syncTypeIcon(item.type), size: 26, color: N360Colors.ink2),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(title, style: N360Type.bodyStrong),
                      if (subtitle != null) Text(subtitle, style: N360Type.caption),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: N360Space.xs),
            Text(t.syncReasonFor(item), style: N360Type.body),
            for (final detail in details)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(detail, style: N360Type.caption),
              ),
            const SizedBox(height: N360Space.sm),
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
      ),
    );
  }
}

class _AttentionPainter extends CustomPainter {
  const _AttentionPainter();

  @override
  void paint(Canvas canvas, Size size) {
    const edge = 2.0;
    final Rect face = Rect.fromLTWH(0, 0, size.width, size.height - edge);
    canvas.drawRect(Rect.fromLTWH(0, edge, size.width, size.height - edge), Paint()..color = N360Colors.paperEdge);
    canvas.drawRect(face, Paint()..color = N360Colors.sheet);
    canvas.drawRect(
      face.deflate(0.5),
      Paint()
        ..color = N360Colors.rule
        ..style = PaintingStyle.stroke,
    );
    canvas.drawRect(Rect.fromLTWH(0, 0, 5, size.height - edge), Paint()..color = N360Colors.stampRed);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Writes the server did not apply because its copy was newer.
class _StaleLog extends StatelessWidget {
  const _StaleLog({required this.items});

  final List<OutboxItem> items;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SectionLabel(t.shellOutboxLogTitle),
        const SizedBox(height: N360Space.xs),
        Text(t.syncStaleLog, style: N360Type.body.copyWith(color: N360Colors.ink2)),
        const SizedBox(height: N360Space.xs),
        LedgerCard(
          padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.xs, N360Space.md, N360Space.xs),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              for (final (index, item) in items.indexed)
                LedgerRow(
                  ruled: index < items.length - 1,
                  label: t.syncTypeLabel(item.type),
                  value: item.title,
                  valueStyle: N360Type.figureSmall,
                ),
            ],
          ),
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
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
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
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.lg, N360Space.lg, N360Space.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(title, style: N360Type.title),
            const SizedBox(height: N360Space.xs),
            Text(detail, style: N360Type.bodyStrong),
            Text(body, style: N360Type.body.copyWith(color: N360Colors.ink2)),
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
