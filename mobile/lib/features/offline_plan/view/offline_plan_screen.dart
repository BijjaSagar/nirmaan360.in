import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/widgets/back_header.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/offline/offline_plan.dart';
import '../../../l10n/generated/app_localizations.dart';
import 'widgets/offline_export_card.dart';

/// What the offline plan means for this phone: the data here, the licence,
/// and how to renew it or keep a copy.
class OfflinePlanScreen extends StatefulWidget {
  const OfflinePlanScreen({super.key});

  @override
  State<OfflinePlanScreen> createState() => _OfflinePlanScreenState();
}

class _OfflinePlanScreenState extends State<OfflinePlanScreen> {
  OfflineWork? _work;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    final OfflineWork work = await context.read<AppServices>().offlinePlan.work();
    if (mounted) setState(() => _work = work);
  }

  Future<void> _copy(String url) async {
    final AppLocalizations t = AppLocalizations.of(context);
    await Clipboard.setData(ClipboardData(text: url));
    if (mounted) showPaperToast(context, t.offlineLinkCopied, kind: PaperToastKind.success);
  }

  Future<void> _activate() async {
    setState(() => _busy = true);
    await context.read<AppServices>().offlinePlan.activateAgain();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _signInAgain() async {
    final services = context.read<AppServices>();
    await services.offlinePlan.noteSignedOut();
    await services.session.endKeepingUser();
  }

  Future<void> _freeThisPhone() async {
    final AppLocalizations t = AppLocalizations.of(context);
    final OfflinePlan plan = context.read<AppServices>().offlinePlan;
    final bool? yes = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (context) => _FreeSheet(),
    );
    if (yes != true || !mounted) return;
    try {
      await plan.release();
    } on Exception {
      if (mounted) showPaperToast(context, t.offlineFreeFailed, kind: PaperToastKind.error);
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    final OfflinePlanState? offline = context.select((AppGate gate) => gate.state.offline);
    if (offline == null) return const Scaffold(body: SizedBox.shrink());
    final OfflineWork? work = _work;

    return Scaffold(
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          PageHeader(title: t.offlinePlanTitle),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
              children: <Widget>[
                LedgerCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(t.offlineOnlyHereTitle, style: N360Type.fit(N360Type.title, t.offlineOnlyHereTitle)),
                      const SizedBox(height: N360Space.xs),
                      Text(
                        t.offlineOnlyHereBody,
                        style: N360Type.fit(N360Type.body, t.offlineOnlyHereBody).copyWith(color: N360Colors.ink2),
                      ),
                    ],
                  ),
                ),
                if (work != null) ...<Widget>[
                  const SizedBox(height: N360Space.cardGap),
                  LedgerList(
                    children: <Widget>[
                      LedgerListTile(
                        leading: const Icon(Icons.apartment_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
                        title: t.offlineHereProjects,
                        trailing: Text('${work.projects}', style: N360Type.figure),
                      ),
                      LedgerListTile(
                        leading: const Icon(Icons.description_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
                        title: t.offlineHereEntries,
                        trailing: Text('${work.entries}', style: N360Type.figure),
                      ),
                    ],
                  ),
                ],
                const SizedBox(height: N360Space.lg),
                SectionLabel(t.offlineExportHeading),
                const SizedBox(height: N360Space.xs),
                const OfflineExportCard(),
                const SizedBox(height: N360Space.lg),
                SectionLabel(t.offlineLicenceHeading),
                const SizedBox(height: N360Space.xs),
                _LicenceCard(
                  offline: offline,
                  busy: _busy,
                  onCopy: _copy,
                  onActivate: _activate,
                  onSignIn: _signInAgain,
                  onFree: _freeThisPhone,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _LicenceCard extends StatelessWidget {
  const _LicenceCard({
    required this.offline,
    required this.busy,
    required this.onCopy,
    required this.onActivate,
    required this.onSignIn,
    required this.onFree,
  });

  final OfflinePlanState offline;
  final bool busy;
  final ValueChanged<String> onCopy;
  final VoidCallback onActivate;
  final VoidCallback onSignIn;
  final VoidCallback onFree;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    final DateTime now = context.read<AppServices>().context.clock.now();
    final String locale = Localizations.localeOf(context).languageCode;
    final DateTime? end = offline.expiresAt;
    final String? date = end == null ? null : AppFormat.dayMonthYear(_day(end), locale);
    final LicenceStanding standing = offline.standing(now);

    final ({String title, String? body, bool urgent}) words = switch (offline.issue) {
      LicenceIssue.deviceLimit => (title: t.offlineDeviceLimitTitle, body: t.offlineDeviceLimitBody, urgent: true),
      LicenceIssue.revoked => (title: t.offlineRevokedTitle, body: t.offlineRevokedBody, urgent: true),
      LicenceIssue.invalid => (title: t.offlineInvalidTitle, body: t.offlineLicenceEndedBody, urgent: true),
      LicenceIssue.none => switch (standing) {
        LicenceStanding.ended => (title: t.offlineLicenceEndedTitle, body: t.offlineLicenceEndedBody, urgent: true),
        LicenceStanding.endingSoon => (title: t.offlineLicenceHeading, body: t.offlineLicenceEndingBody(date ?? ''), urgent: true),
        LicenceStanding.none => (title: t.offlineLicenceHeading, body: t.offlineLicenceNone, urgent: false),
        LicenceStanding.active => (title: t.offlineLicenceActive(date ?? ''), body: null, urgent: false),
      },
    };
    final String? manage = offline.manageUrl;
    final bool canActivate = offline.issue == LicenceIssue.revoked || offline.issue == LicenceIssue.deviceLimit;
    final Color titleColor = words.urgent ? N360Colors.stampRed : N360Colors.ink;

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(words.title, style: N360Type.fit(N360Type.bodyStrong, words.title).copyWith(color: titleColor)),
          if (words.body != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(words.body!, style: N360Type.fit(N360Type.body, words.body!).copyWith(color: N360Colors.ink2)),
          ],
          if (manage != null) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Text(t.offlineManageLink, style: N360Type.label),
            SelectableText(manage, style: N360Type.body),
            const SizedBox(height: N360Space.xs),
            GloveButton(
              label: t.offlineCopyLink,
              icon: Icons.copy,
              kind: GloveButtonKind.secondary,
              fullWidth: true,
              onPressed: () => onCopy(manage),
            ),
          ],
          if (canActivate) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            GloveButton(
              label: t.offlineActivateAgain,
              icon: Icons.phone_android,
              fullWidth: true,
              loading: busy,
              onPressed: busy ? null : onActivate,
            ),
          ],
          if (offline.needsSignIn) ...<Widget>[
            const SizedBox(height: N360Space.md),
            Text(t.offlineNeedsSignInTitle, style: N360Type.fit(N360Type.bodyStrong, t.offlineNeedsSignInTitle)),
            const SizedBox(height: N360Space.xs),
            Text(t.offlineNeedsSignInBody, style: N360Type.fit(N360Type.body, t.offlineNeedsSignInBody).copyWith(color: N360Colors.ink2)),
            const SizedBox(height: N360Space.sm),
            GloveButton(label: t.offlineSignInButton, icon: Icons.login, fullWidth: true, onPressed: onSignIn),
          ],
          if (offline.issue == LicenceIssue.none && offline.hasToken) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            GloveButton(
              key: const ValueKey<String>('offline-free-this-phone'),
              label: t.offlineFreeThisPhone,
              kind: GloveButtonKind.secondary,
              fullWidth: true,
              onPressed: onFree,
            ),
          ],
        ],
      ),
    );
  }
}

String _day(DateTime instant) {
  final DateTime ist = instant.toUtc().add(const Duration(hours: 5, minutes: 30));
  final String month = ist.month.toString().padLeft(2, '0');
  final String day = ist.day.toString().padLeft(2, '0');
  return '${ist.year}-$month-$day';
}

class _FreeSheet extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(t.offlineFreeTitle, style: N360Type.fit(N360Type.title, t.offlineFreeTitle)),
            const SizedBox(height: N360Space.xs),
            Text(t.offlineFreeBody, style: N360Type.fit(N360Type.body, t.offlineFreeBody).copyWith(color: N360Colors.ink2)),
            const SizedBox(height: N360Space.lg),
            GloveButton(
              label: t.offlineFreeConfirm,
              kind: GloveButtonKind.danger,
              fullWidth: true,
              onPressed: () => Navigator.of(context).pop(true),
            ),
            const SizedBox(height: N360Space.sm),
            GloveButton(
              label: t.commonCancel,
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
