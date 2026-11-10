import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/widgets/back_header.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/offline/offline_plan.dart';
import '../../../l10n/generated/app_localizations.dart';
import 'widgets/licence_card.dart';
import 'widgets/offline_export_card.dart';

/// Where a phone lands when its offline licence has ended and something tried
/// to add or change a record. It says why, how to renew, and offers a button
/// that asks the server now. Everything already on the phone stays readable and
/// can be exported from here; nothing is deleted.
///
/// A fresh licence lifts the block the moment it arrives, so the same page then
/// says so and lets the person carry on, with no restart.
class LicenceEndedScreen extends StatefulWidget {
  const LicenceEndedScreen({super.key});

  @override
  State<LicenceEndedScreen> createState() => _LicenceEndedScreenState();
}

class _LicenceEndedScreenState extends State<LicenceEndedScreen> {
  bool _busy = false;
  LicenceRenewal? _result;

  Future<void> _tryNow() async {
    final OfflinePlan plan = context.read<AppServices>().offlinePlan;
    setState(() {
      _busy = true;
      _result = null;
    });
    final LicenceRenewal result = await plan.renewNow();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _result = result;
    });
  }

  void _leave() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go(Routes.home);
    }
  }

  void _lookAtData() {
    final bool hasProject = context.read<AppGate>().state.hasProject;
    context.go(hasProject ? Routes.home : Routes.projects);
  }

  /// The words for a try that did not renew. The cases that have a card of their
  /// own (a freed phone, a full plan, a lapsed sign-in) are said there instead.
  String? _words(AppLocalizations t) => switch (_result) {
    LicenceRenewal.offline => t.licenceResultOffline,
    LicenceRenewal.noAnswer => t.licenceResultNoAnswer,
    LicenceRenewal.stillEnded => t.licenceResultStillEnded,
    _ => null,
  };

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    final OfflinePlanState? offline = context.select((AppGate gate) => gate.state.offline);
    final LicenceStatus licence = context.select((AppGate gate) => gate.state.licence);
    if (offline == null) return const Scaffold(body: SizedBox.shrink());

    final bool ended = licence.entriesBlocked;
    final String locale = Localizations.localeOf(context).languageCode;
    final DateTime? end = licence.endsAt;
    final String? words = _words(t);
    final bool hasProblem = offline.issue == LicenceIssue.revoked || offline.issue == LicenceIssue.deviceLimit || offline.needsSignIn;

    return Scaffold(
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          PageHeader(title: t.licenceScreenTitle),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
              children: <Widget>[
                if (ended)
                  LedgerCard(
                    key: const ValueKey<String>('licence-ended-card'),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            const Icon(Icons.lock_outline, size: N360IconSize.lg, color: N360Colors.stampRed),
                            const SizedBox(width: N360Space.sm),
                            Expanded(
                              child: Text(
                                t.offlineLicenceEndedTitle,
                                style: N360Type.fit(N360Type.title, t.offlineLicenceEndedTitle).copyWith(color: N360Colors.stampRed),
                              ),
                            ),
                          ],
                        ),
                        if (end != null) ...<Widget>[
                          const SizedBox(height: N360Space.xs),
                          Text(t.licenceEndedOn(licenceDate(end, locale)), style: N360Type.label),
                        ],
                        const SizedBox(height: N360Space.xs),
                        Text(
                          t.offlineLicenceEndedBody,
                          style: N360Type.fit(N360Type.body, t.offlineLicenceEndedBody).copyWith(color: N360Colors.ink2),
                        ),
                        const SizedBox(height: N360Space.md),
                        GloveButton(
                          key: const ValueKey<String>('licence-try-now'),
                          label: t.licenceTryNow,
                          icon: Icons.refresh,
                          fullWidth: true,
                          large: true,
                          loading: _busy,
                          onPressed: _busy ? null : _tryNow,
                        ),
                        if (_busy || words != null) ...<Widget>[
                          const SizedBox(height: N360Space.sm),
                          Semantics(
                            liveRegion: true,
                            child: Text(
                              _busy ? t.licenceTrying : words!,
                              key: const ValueKey<String>('licence-result'),
                              style: N360Type.fit(N360Type.body, _busy ? t.licenceTrying : words!).copyWith(color: N360Colors.ink2),
                            ),
                          ),
                        ],
                      ],
                    ),
                  )
                else
                  LedgerCard(
                    key: const ValueKey<String>('licence-renewed-card'),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            const Icon(Icons.check_circle_outline, size: N360IconSize.lg, color: N360Colors.ink2),
                            const SizedBox(width: N360Space.sm),
                            Expanded(child: Text(t.licenceRenewedTitle, style: N360Type.fit(N360Type.title, t.licenceRenewedTitle))),
                          ],
                        ),
                        const SizedBox(height: N360Space.xs),
                        Text(t.licenceRenewedBody, style: N360Type.fit(N360Type.body, t.licenceRenewedBody).copyWith(color: N360Colors.ink2)),
                        const SizedBox(height: N360Space.md),
                        GloveButton(
                          key: const ValueKey<String>('licence-continue'),
                          label: t.licenceContinue,
                          fullWidth: true,
                          large: true,
                          onPressed: _leave,
                        ),
                      ],
                    ),
                  ),
                if (hasProblem) ...<Widget>[
                  const SizedBox(height: N360Space.cardGap),
                  const LicenceCard(onlyProblems: true),
                ],
                const SizedBox(height: N360Space.lg),
                SectionLabel(t.offlineExportHeading),
                const SizedBox(height: N360Space.xs),
                const OfflineExportCard(),
                if (ended) ...<Widget>[
                  const SizedBox(height: N360Space.cardGap),
                  GloveButton(
                    key: const ValueKey<String>('licence-look-at-data'),
                    label: t.licenceViewData,
                    icon: Icons.visibility_outlined,
                    kind: GloveButtonKind.secondary,
                    fullWidth: true,
                    onPressed: _lookAtData,
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
