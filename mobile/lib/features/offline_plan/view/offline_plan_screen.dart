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
import 'widgets/licence_card.dart';
import 'widgets/licence_notice.dart';
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

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    final OfflineWork work = await context.read<AppServices>().offlinePlan.work();
    if (mounted) setState(() => _work = work);
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
                // Once the licence has ended this leads to the page that renews it.
                const LicenceNotice(endedOnly: true, padding: EdgeInsets.only(bottom: N360Space.cardGap)),
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
                if (offline.mode == OfflineMode.declined) ...<Widget>[
                  const SizedBox(height: N360Space.cardGap),
                  LedgerList(
                    children: <Widget>[
                      LedgerListTile(
                        key: const ValueKey<String>('offline-upload-later'),
                        leading: const Icon(Icons.cloud_upload_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
                        title: t.offlineUploadLaterTile,
                        subtitle: t.offlineUploadLaterHint,
                        onTap: () => context.push(Routes.offlineUpload),
                      ),
                    ],
                  ),
                ],
                const SizedBox(height: N360Space.lg),
                SectionLabel(t.offlineExportHeading),
                const SizedBox(height: N360Space.xs),
                const OfflineExportCard(),
                const SizedBox(height: N360Space.lg),
                if (offline.mode == OfflineMode.local) ...<Widget>[
                  SectionLabel(t.offlineLicenceHeading),
                  const SizedBox(height: N360Space.xs),
                  const LicenceCard(),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
