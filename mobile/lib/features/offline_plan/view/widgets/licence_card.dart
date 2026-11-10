import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../app/app_format.dart';
import '../../../../app/app_gate.dart';
import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/offline/offline_plan.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// The calendar date in India on which [instant] falls, `YYYY-MM-DD`.
String licenceDay(DateTime instant) {
  final DateTime ist = instant.toUtc().add(const Duration(hours: 5, minutes: 30));
  final String month = ist.month.toString().padLeft(2, '0');
  final String day = ist.day.toString().padLeft(2, '0');
  return '${ist.year}-$month-$day';
}

/// [instant] as "12 Nov 2026" in the reader's language.
String licenceDate(DateTime instant, String locale) => AppFormat.dayMonthYear(licenceDay(instant), locale);

/// How the licence stands, and what to do about a freed phone, a full plan or
/// a lapsed sign-in. It does its own work, so the plan page and the renewal
/// page show the same card and need no handlers of their own.
///
/// With [onlyProblems] the card is drawn only when something other than the end
/// date needs doing: the renewal page already says the licence has ended.
class LicenceCard extends StatefulWidget {
  const LicenceCard({super.key, this.onlyProblems = false});

  final bool onlyProblems;

  @override
  State<LicenceCard> createState() => _LicenceCardState();
}

class _LicenceCardState extends State<LicenceCard> {
  bool _busy = false;

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
      builder: (context) => const _FreeSheet(),
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
    final LicenceStatus licence = context.select((AppGate gate) => gate.state.licence);
    if (offline == null) return const SizedBox.shrink();
    if (widget.onlyProblems && offline.issue == LicenceIssue.none && !offline.needsSignIn) {
      return const SizedBox.shrink();
    }

    final String locale = Localizations.localeOf(context).languageCode;
    final DateTime? end = offline.expiresAt;
    final String? date = end == null ? null : licenceDate(end, locale);
    final LicenceStanding standing = licence.standing;

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
              onPressed: () => _copy(manage),
            ),
          ],
          if (canActivate) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            GloveButton(
              label: t.offlineActivateAgain,
              icon: Icons.phone_android,
              fullWidth: true,
              loading: _busy,
              onPressed: _busy ? null : _activate,
            ),
          ],
          if (offline.needsSignIn) ...<Widget>[
            const SizedBox(height: N360Space.md),
            Text(t.offlineNeedsSignInTitle, style: N360Type.fit(N360Type.bodyStrong, t.offlineNeedsSignInTitle)),
            const SizedBox(height: N360Space.xs),
            Text(t.offlineNeedsSignInBody, style: N360Type.fit(N360Type.body, t.offlineNeedsSignInBody).copyWith(color: N360Colors.ink2)),
            const SizedBox(height: N360Space.sm),
            GloveButton(label: t.offlineSignInButton, icon: Icons.login, fullWidth: true, onPressed: _signInAgain),
          ],
          if (!widget.onlyProblems && offline.issue == LicenceIssue.none && offline.hasToken) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            GloveButton(
              key: const ValueKey<String>('offline-free-this-phone'),
              label: t.offlineFreeThisPhone,
              kind: GloveButtonKind.secondary,
              fullWidth: true,
              onPressed: _freeThisPhone,
            ),
          ],
        ],
      ),
    );
  }
}

class _FreeSheet extends StatelessWidget {
  const _FreeSheet();

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
