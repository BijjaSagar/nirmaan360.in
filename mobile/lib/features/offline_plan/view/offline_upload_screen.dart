import 'dart:async';

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
import '../bloc/offline_upload_cubit.dart';
import '../data/offline_upload.dart';
import 'widgets/offline_export_card.dart';

/// Asks the owner before any of their data goes to the server.
class OfflineUploadScreen extends StatelessWidget {
  const OfflineUploadScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<OfflineUploadCubit>(
      create: (context) => OfflineUploadCubit(context.read<AppServices>()),
      child: const _UploadView(),
    );
  }
}

class _UploadView extends StatelessWidget {
  const _UploadView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    final OfflineMode? mode = context.select((AppGate gate) => gate.state.offline?.mode);
    final bool interrupted = mode == OfflineMode.uploading;

    return BlocConsumer<OfflineUploadCubit, OfflineUploadState>(
      listenWhen: (a, b) => !a.finished && b.finished,
      listener: (context, state) {
        showPaperToast(context, t.offlineUploadDone, kind: PaperToastKind.success, duration: const Duration(seconds: 5));
      },
      builder: (context, state) {
        final OfflineUploadCubit cubit = context.read<OfflineUploadCubit>();
        return PopScope(
          // Answering is the only way past this screen.
          canPop: mode == OfflineMode.declined,
          child: Scaffold(
            body: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                PageHeader(title: t.offlineUploadTitle, showBack: mode == OfflineMode.declined),
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                    children: <Widget>[
                      _Headline(interrupted: interrupted, state: state),
                      if (state.work != null) ...<Widget>[
                        const SizedBox(height: N360Space.cardGap),
                        _WhatGoes(work: state.work!),
                      ],
                      const SizedBox(height: N360Space.lg),
                      SectionLabel(t.offlineExportHeading),
                      const SizedBox(height: N360Space.xs),
                      const OfflineExportCard(),
                    ],
                  ),
                ),
                N360ActionBar(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      GloveButton(
                        key: const ValueKey<String>('offline-upload-yes'),
                        label: interrupted ? t.offlineUploadContinue : t.offlineUploadYes,
                        icon: Icons.cloud_upload_outlined,
                        large: true,
                        fullWidth: true,
                        loading: state.running,
                        onPressed: state.running ? null : () => unawaited(cubit.accept()),
                      ),
                      const SizedBox(height: N360Space.sm),
                      GloveButton(
                        key: const ValueKey<String>('offline-upload-no'),
                        label: t.offlineUploadNo,
                        kind: GloveButtonKind.secondary,
                        large: true,
                        fullWidth: true,
                        onPressed: state.running
                            ? null
                            : () async {
                                await cubit.decline();
                                if (context.mounted) context.go(Routes.home);
                              },
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _Headline extends StatelessWidget {
  const _Headline({required this.interrupted, required this.state});

  final bool interrupted;
  final OfflineUploadState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    final String? problem = switch (state.stop) {
      null => null,
      UploadStop.noNetwork => t.offlineUploadNoNetwork,
      UploadStop.signIn => t.offlineUploadSignIn,
      UploadStop.busy => t.offlineUploadBusy,
      UploadStop.stillOffline => t.offlineUploadStillOffline,
      UploadStop.planLimit => t.offlineUploadPlanLimit(state.detail ?? ''),
      UploadStop.refused => t.offlineUploadRefused(state.detail ?? ''),
    };
    final String heading = state.running
        ? t.offlineUploadRunningTitle
        : interrupted
        ? t.offlineUploadInterruptedTitle
        : t.offlineUploadAskTitle;
    final String body = state.running
        ? t.offlineUploadRunningBody
        : interrupted
        ? t.offlineUploadInterruptedBody
        : t.offlineUploadAskBody;

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(heading, style: N360Type.fit(N360Type.title, heading)),
          const SizedBox(height: N360Space.xs),
          Text(body, style: N360Type.fit(N360Type.body, body).copyWith(color: N360Colors.ink2)),
          if (state.running && state.total > 0) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Text(t.offlineUploadProgress(state.done, state.total), style: N360Type.bodyStrong),
          ],
          if (problem != null) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Text(problem, style: N360Type.fit(N360Type.body, problem).copyWith(color: N360Colors.stampRed)),
          ],
        ],
      ),
    );
  }
}

class _WhatGoes extends StatelessWidget {
  const _WhatGoes({required this.work});

  final OfflineWork work;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    Widget row(IconData icon, String label, int count) => LedgerListTile(
      leading: Icon(icon, size: N360IconSize.lg, color: N360Colors.ink2),
      title: label,
      trailing: Text('$count', style: N360Type.figure),
    );
    return LedgerList(
      children: <Widget>[
        row(Icons.apartment_outlined, t.offlineHereProjects, work.projects),
        row(Icons.format_list_numbered, t.offlineHereBoq, work.boqLines),
        row(Icons.groups_outlined, t.offlineHereWorkers, work.workers),
        row(Icons.account_balance_wallet_outlined, t.offlineHereWallets, work.wallets),
        row(Icons.description_outlined, t.offlineHereEntries, work.entries),
      ],
    );
  }
}
