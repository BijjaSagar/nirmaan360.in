import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/offline_export.dart';

/// Makes one file of everything and offers it to the system share sheet.
class OfflineExportCard extends StatefulWidget {
  const OfflineExportCard({super.key});

  @override
  State<OfflineExportCard> createState() => _OfflineExportCardState();
}

class _OfflineExportCardState extends State<OfflineExportCard> {
  final GlobalKey _button = GlobalKey();
  bool _busy = false;

  Future<void> _export() async {
    final AppLocalizations t = AppLocalizations.of(context);
    final AppServices services = context.read<AppServices>();
    final RenderBox? box = _button.currentContext?.findRenderObject() as RenderBox?;
    final Rect? origin = box == null ? null : box.localToGlobal(Offset.zero) & box.size;

    setState(() => _busy = true);
    try {
      final OfflineExport export = await services.exportBackup();
      await services.shareFile(export.path, t.offlineShareSubject, origin);
      if (!mounted) return;
      final String done = t.offlineExportDone(export.photos);
      showPaperToast(
        context,
        export.missingPhotos > 0 ? '$done ${t.offlineExportMissing(export.missingPhotos)}' : done,
        kind: PaperToastKind.success,
        duration: const Duration(seconds: 5),
      );
    } on Exception {
      if (mounted) showPaperToast(context, t.offlineExportFailed, kind: PaperToastKind.error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(t.offlineExportHint, style: N360Type.fit(N360Type.body, t.offlineExportHint).copyWith(color: N360Colors.ink2)),
          const SizedBox(height: N360Space.sm),
          GloveButton(
            key: _button,
            label: t.offlineExportButton,
            icon: Icons.ios_share,
            fullWidth: true,
            loading: _busy,
            onPressed: _busy ? null : _export,
          ),
        ],
      ),
    );
  }
}
