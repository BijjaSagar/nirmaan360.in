import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../reports/report_share.dart';
import '../../data/dpr_repository.dart';
import 'dpr_ui.dart';

/// The website's two buttons for a report: its PDF, and the WhatsApp message
/// with a signed link to that PDF. Both come from the server, so they work only
/// online and only once the report is there as it is shown here.
class DprShareCard extends StatefulWidget {
  const DprShareCard({super.key, required this.record, required this.online});

  final SyncedRecord<DprRow> record;
  final bool online;

  /// The website shows these buttons on a plan with reports, to whoever can
  /// see DPRs.
  static bool shows(AppServices services) => ReportShare.allowed(services.session.user, 'dpr.view');

  @override
  State<DprShareCard> createState() => _DprShareCardState();
}

enum _Busy { none, pdf, link }

class _DprShareCardState extends State<DprShareCard> {
  _Busy _busy = _Busy.none;

  /// The link's life in days, once the server has said it.
  int _linkDays = 7;

  Future<void> _share(BuildContext button, _Busy which) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final int? serverId = widget.record.record.serverId;
    if (serverId == null || _busy != _Busy.none) return;
    final ReportShare share = ReportShare(context.read<AppServices>().api);
    final Rect? origin = shareOriginOf(button);
    final String locale = Localizations.localeOf(context).languageCode;
    setState(() => _busy = which);
    try {
      if (which == _Busy.pdf) {
        await share.shareDprPdf(
          dprId: serverId,
          reportDate: widget.record.record.reportDate,
          subject: l.dprFormTitle,
          origin: origin,
        );
      } else {
        final DprShareLink link = await share.shareDprLink(
          dprId: serverId,
          locale: locale,
          subject: l.dprFormTitle,
          origin: origin,
        );
        if (mounted) _linkDays = link.linkDays;
      }
    } on Object catch (error) {
      if (mounted) showPaperToast(context, _failureText(l, error), kind: PaperToastKind.warning);
    } finally {
      if (mounted) setState(() => _busy = _Busy.none);
    }
  }

  static String _failureText(AppLocalizations l, Object error) => switch (error) {
    OfflineFailure() => l.dprShareNeedsInternet,
    ServerFailure(statusCode: 403 || 404) => l.dprShareNotAllowed,
    _ => l.dprShareFailed,
  };

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool onServer = widget.record.record.serverId != null && !widget.record.isUnsent;
    final bool ready = onServer && widget.online;
    final String? note = !onServer
        ? l.dprShareAfterSend
        : !widget.online
        ? l.dprShareNeedsInternet
        : null;

    Widget button(_Busy which, String label, IconData icon, String key) => Builder(
      builder: (button) => GloveButton(
        key: ValueKey<String>(key),
        label: label,
        icon: icon,
        kind: GloveButtonKind.secondary,
        fullWidth: true,
        loading: _busy == which,
        onPressed: ready && _busy == _Busy.none ? () => _share(button, which) : null,
      ),
    );

    return DprSectionCard(
      icon: Icons.ios_share,
      title: l.dprShareTitle,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (note != null) ...<Widget>[
            Text(note, style: N360Type.fit(N360Type.body, note).copyWith(color: N360Colors.inkLabel)),
            const SizedBox(height: N360Space.sm),
          ],
          button(_Busy.pdf, l.dprSharePdf, Icons.picture_as_pdf_outlined, 'dpr-share-pdf'),
          const SizedBox(height: N360Space.sm),
          button(_Busy.link, l.dprShareWhatsApp, Icons.chat_outlined, 'dpr-share-whatsapp'),
          const SizedBox(height: N360Space.xs),
          Text(l.dprShareLinkNote(_linkDays), style: N360Type.caption),
        ],
      ),
    );
  }
}
