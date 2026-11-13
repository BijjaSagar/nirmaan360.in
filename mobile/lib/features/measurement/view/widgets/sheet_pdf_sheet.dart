import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../reports/report_share.dart';
import 'book_kit.dart';

/// The website's "Measurement sheet PDF": approved entries for a period,
/// grouped by BOQ item. Rates and amounts are on it only for people who hold
/// `money.view`; the server decides, the app only passes the dates.
Future<void> showMeasurementSheetPdf(BuildContext context, {required int projectId, required String today}) {
  return showLedgerSheet<void>(
    context,
    isScrollControlled: true,
    builder: (_) => MeasurementSheetPdfSheet(projectId: projectId, today: today),
  );
}

/// Whether the book offers the sheet: a plan with reports and `measurement.view`.
bool measurementSheetAllowed(AppServices services) =>
    ReportShare.allowed(services.session.user, 'measurement.view');

class MeasurementSheetPdfSheet extends StatefulWidget {
  const MeasurementSheetPdfSheet({super.key, required this.projectId, required this.today});

  final int projectId;

  /// `YYYY-MM-DD`, the app's today.
  final String today;

  @override
  State<MeasurementSheetPdfSheet> createState() => _MeasurementSheetPdfSheetState();
}

class _MeasurementSheetPdfSheetState extends State<MeasurementSheetPdfSheet> {
  // As on the website: the first of this month to today.
  late String _from = '${widget.today.substring(0, 8)}01';
  late String _to = widget.today;
  bool _busy = false;

  static String _iso(DateTime date) {
    String two(int value) => value.toString().padLeft(2, '0');
    return '${date.year}-${two(date.month)}-${two(date.day)}';
  }

  Future<void> _pick({required bool from}) async {
    final DateTime last = DateTime.parse(widget.today);
    final DateTime first = DateTime(last.year - 5);
    // The two ends never cross: From stops at To, To starts at From.
    final DateTime lower = from ? first : DateTime.parse(_from);
    final DateTime upper = from ? DateTime.parse(_to) : last;
    final DateTime current = DateTime.parse(from ? _from : _to);
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: current.isBefore(lower) ? lower : (current.isAfter(upper) ? upper : current),
      firstDate: lower,
      lastDate: upper,
    );
    if (picked == null || !mounted) return;
    setState(() => from ? _from = _iso(picked) : _to = _iso(picked));
  }

  Future<void> _share(BuildContext button) async {
    if (_busy) return;
    final AppLocalizations l = AppLocalizations.of(context);
    final ReportShare share = ReportShare(context.read<AppServices>().api);
    final Rect? origin = shareOriginOf(button);
    setState(() => _busy = true);
    try {
      await share.shareMeasurementSheet(
        projectId: widget.projectId,
        from: _from,
        to: _to,
        subject: l.measurementSheetTitle,
        origin: origin,
      );
      if (mounted) Navigator.of(context).pop();
    } on Object catch (error) {
      if (mounted) showPaperToast(context, _failureText(l, error), kind: PaperToastKind.warning);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  static String _failureText(AppLocalizations l, Object error) => switch (error) {
    OfflineFailure() => l.measurementSheetNeedsInternet,
    ValidationFailure() => l.measurementSheetTooLong,
    ServerFailure(statusCode: 403 || 404) => l.measurementSheetNotAllowed,
    _ => l.measurementSheetFailed,
  };

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final AppServices services = context.read<AppServices>();
    return StreamBuilder<bool>(
      stream: services.connectivity.changes,
      initialData: services.connectivity.isOnline,
      builder: (context, snapshot) {
        final bool online = snapshot.data ?? true;
        return SingleChildScrollView(
          padding: ledgerSheetPadding,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(l.measurementSheetTitle, style: N360Type.title),
              const SizedBox(height: N360Space.xs),
              Text(l.measurementSheetHint, style: N360Type.fit(N360Type.body, l.measurementSheetHint)),
              const SizedBox(height: N360Space.md),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Expanded(child: _end(context, l.measurementSheetFrom, _from, 'sheet-from', () => _pick(from: true))),
                  const SizedBox(width: N360Space.sm),
                  Expanded(child: _end(context, l.measurementSheetTo, _to, 'sheet-to', () => _pick(from: false))),
                ],
              ),
              const SizedBox(height: N360Space.lg),
              if (!online) ...<Widget>[
                Text(
                  l.measurementSheetNeedsInternet,
                  style: N360Type.fit(N360Type.body, l.measurementSheetNeedsInternet).copyWith(color: N360Colors.inkLabel),
                ),
                const SizedBox(height: N360Space.sm),
              ],
              Builder(
                builder: (button) => GloveButton(
                  key: const ValueKey<String>('sheet-share'),
                  label: l.measurementSheetShare,
                  icon: Icons.picture_as_pdf_outlined,
                  large: true,
                  fullWidth: true,
                  loading: _busy,
                  onPressed: online && !_busy ? () => _share(button) : null,
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _end(BuildContext context, String label, String date, String key, VoidCallback onTap) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SectionLabel(label),
        const SizedBox(height: N360Space.xs),
        ChoiceTile(
          key: ValueKey<String>(key),
          label: dayLabel(context, date, widget.today),
          icon: Icons.calendar_month,
          selected: false,
          onTap: _busy ? () {} : onTap,
        ),
      ],
    );
  }
}
