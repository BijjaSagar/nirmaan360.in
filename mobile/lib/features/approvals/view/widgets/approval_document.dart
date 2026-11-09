import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../core/sync/clock.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../measurement/bloc/measurement_book.dart';
import '../../../measurement/view/widgets/book_kit.dart';
import '../../data/approval_repository.dart';
import 'approval_read.dart';

/// The full document behind an approval, read straight from the server's copy.
class ApprovalDocument extends StatelessWidget {
  const ApprovalDocument({super.key, required this.row, required this.today});

  final ApprovalRow row;
  final String today;

  @override
  Widget build(BuildContext context) {
    return switch (row.docType) {
      'measurement' => _MeasurementDoc(doc: row.document, today: today),
      'dpr' => _DprDoc(doc: row.document, today: today),
      'ra_bill' => _BillDoc(doc: row.document, today: today),
      _ => LedgerCard(child: Text(row.title, style: N360Type.bodyLarge)),
    };
  }
}

Widget _sheet(List<Widget> rows) {
  return LedgerCard(
    padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.sm, N360Space.lg, N360Space.md),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: rows),
  );
}

class _MeasurementDoc extends StatelessWidget {
  const _MeasurementDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Map<String, dynamic> boq = readMap(doc['boq_item']);
    final String unit = readText(boq['unit']) ?? '';
    final Qty? qty = readQty(doc['qty']);
    final bool deduction = doc['is_deduction'] == true;
    final Qty? nos = readQty(doc['nos']);
    final Qty? length = readQty(doc['length']);
    final Qty? breadth = readQty(doc['breadth']);
    final Qty? depth = readQty(doc['depth']);
    final Qty? balance = readQty(doc['balance_qty']);
    final String? measuredOn = readText(doc['measured_on']);
    final String? location = readText(doc['location']);
    final String? description = readText(doc['description']);
    final String? remarks = readText(doc['remarks']);
    final Color qtyInk = qty?.isNegative == true ? N360Colors.laterite : N360Colors.ink;
    String dim(Qty? value) => value == null ? '—' : bookFigure(value);

    final String? formula = qty == null
        ? null
        : bookFormula(
            nos: nos,
            length: length,
            breadth: breadth,
            depth: depth,
            deduction: deduction,
            result: qty,
            unit: unit,
          );

    return _sheet(<Widget>[
      if (readText(boq['code']) != null)
        BookRow(label: l.measurementItem, value: readText(boq['code'])!, hint: readText(boq['description'])),
      if (measuredOn != null) BookRow.words(label: l.measurementDateLabel, value: dayLabel(context, measuredOn, today)),
      if (location != null) BookRow.words(label: l.measurementLocationLabel, value: location),
      if (description != null) BookRow.words(label: l.measurementDescriptionLabel, value: description),
      BookRow(label: l.measurementNos, value: dim(nos)),
      BookRow(label: l.measurementLength, value: dim(length)),
      BookRow(label: l.measurementBreadth, value: dim(breadth)),
      BookRow(label: l.measurementDepth, value: dim(depth)),
      if (formula != null)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
          child: FittedBox(
            alignment: Alignment.centerLeft,
            fit: BoxFit.scaleDown,
            child: Text(
              formula,
              style: N360Type.figureSmall.copyWith(color: deduction ? N360Colors.laterite : N360Colors.ink2),
            ),
          ),
        ),
      if (qty != null)
        BookRow(
          label: l.measurementQuantity,
          total: true,
          value: '${formatQty(qty)}${unit.isEmpty ? '' : ' $unit'}',
          valueStyle: N360Type.figure.copyWith(fontSize: 20, color: qtyInk),
        ),
      if (balance != null)
        BookRow(label: l.measurementBalance, value: '${formatQty(balance)} $unit'.trim(), ruled: false),
      if (remarks != null) BookRow.words(label: l.approvalsRemarks, value: remarks, ruled: false),
    ]);
  }
}

class _DprDoc extends StatelessWidget {
  const _DprDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? date = readText(doc['report_date']);
    final String? weather = readText(doc['weather']);
    final String? summary = readText(doc['summary']);
    final String? hindrances = readText(doc['hindrances']);
    final String? total = readText(doc['manpower_total']);
    final List<Map<String, dynamic>> manpower = readList(doc['manpower']);
    final List<Map<String, dynamic>> work = readList(doc['work_items']);
    final List<Map<String, dynamic>> photos = readList(doc['photos']);

    return _sheet(<Widget>[
      if (date != null) BookRow.words(label: l.measurementDateLabel, value: dayLabel(context, date, today)),
      if (weather != null) BookRow.words(label: l.approvalsWeather, value: weather),
      if (summary != null) ...<Widget>[
        _Heading(l.approvalsSummary),
        Text(summary, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (hindrances != null) ...<Widget>[
        _Heading(l.approvalsHindrances),
        Text(hindrances, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (manpower.isNotEmpty || total != null) _Heading(l.approvalsManpower),
      for (final Map<String, dynamic> row in manpower)
        BookRow(label: readText(row['trade']) ?? '', value: readText(row['count']) ?? '—'),
      if (total != null) BookRow(label: l.approvalsTotal, value: total, total: true),
      if (work.isNotEmpty) _Heading(l.approvalsWorkDone),
      for (final Map<String, dynamic> row in work)
        BookRow(
          label: '${readText(row['code']) ?? ''} ${readText(row['description']) ?? ''}'.trim(),
          value: '${_qty(row['qty'])} ${readText(row['unit']) ?? ''}'.trim(),
        ),
      if (photos.isNotEmpty) BookRow(label: l.approvalsPhotos, value: '${photos.length}', ruled: false),
    ]);
  }

  String _qty(Object? raw) {
    final Qty? qty = readQty(raw);
    return qty == null ? '—' : formatQty(qty);
  }
}

class _BillDoc extends StatelessWidget {
  const _BillDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? number = readText(doc['number']);
    final String? party = readText(doc['party_name']);
    final String? type = readText(doc['type']);
    final String? from = readText(doc['period_from']);
    final String? to = readText(doc['period_to']);
    Money? money(String key) => readMoney(doc[key]);
    final Money? gross = money('gross_amount');
    final Money? deductions = money('deduction_total');
    final Money? gst = money('gst_amount');
    final Money? net = money('net_payable');

    String period() =>
        '${from == null ? '—' : dayLabel(context, from, today)} – ${to == null ? '—' : dayLabel(context, to, today)}';

    return _sheet(<Widget>[
      if (number != null) BookRow(label: l.approvalsBillNumber, value: number),
      if (party != null) BookRow.words(label: l.approvalsParty, value: party),
      if (type != null) BookRow.words(label: l.approvalsBillType, value: type),
      if (from != null || to != null) BookRow.words(label: l.approvalsPeriod, value: period()),
      if (gross != null) BookRow(label: l.approvalsGross, value: gross.inr()),
      if (deductions != null) BookRow(label: l.approvalsDeductions, value: deductions.inr()),
      if (gst != null) BookRow(label: l.approvalsGst, value: gst.inr()),
      if (net != null)
        BookRow(
          label: l.approvalsNetPayable,
          total: true,
          value: net.inr(),
          valueStyle: N360Type.figure.copyWith(fontSize: 20),
        ),
    ]);
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: N360Space.sm, bottom: N360Space.xxs),
      child: SectionLabel(text),
    );
  }
}

/// The trail of a document: who sent it, who has acted, who is next.
class ApprovalChain extends StatelessWidget {
  const ApprovalChain({super.key, required this.row, required this.now});

  final ApprovalRow row;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final List<Map<String, dynamic>> steps = readList(row.steps);
    final int current = (row.currentLevel ?? 1).clamp(1, row.levels.length + 1);
    final List<String> pending = current <= row.levels.length ? row.levels.sublist(current - 1) : const <String>[];

    final List<Widget> rows = <Widget>[
      for (final Map<String, dynamic> step in steps)
        _ChainRow(
          state: _StationState.done,
          title: _stepTitle(l, step),
          line: _stepLine(l, step),
          remark: readText(step['remark']),
          last: pending.isEmpty && identical(step, steps.last),
        ),
      for (final (index, role) in pending.indexed)
        _ChainRow(
          state: index == 0 ? _StationState.current : _StationState.upcoming,
          title: roleWord(l, role, serverLabel: index == 0 ? row.currentRoleLabel : null),
          line: index == 0 ? l.approvalsChainWaiting : l.approvalsChainNext,
          last: index == pending.length - 1,
        ),
    ];

    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.md, N360Space.lg, N360Space.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SectionLabel(l.approvalsChainTitle),
          const SizedBox(height: N360Space.sm),
          ...rows,
        ],
      ),
    );
  }

  String _stepTitle(AppLocalizations l, Map<String, dynamic> step) {
    final String action = switch (readText(step['action'])) {
      'submit' => l.approvalsActionSubmitted,
      'approve' => l.approvalsActionApproved,
      'return' => l.approvalsActionReturned,
      'reject' => l.approvalsActionRejected,
      final other => other ?? '',
    };
    final String actor = readText(step['actor']) ?? roleWord(l, readText(step['role']));
    return '$actor · $action';
  }

  String _stepLine(AppLocalizations l, Map<String, dynamic> step) {
    final String role = roleWord(l, readText(step['role']));
    final DateTime? at = parseServerTime(step['acted_at']);
    return <String>[if (role.isNotEmpty) role, if (at != null) agoText(l, at, now)].join(' · ');
  }
}

enum _StationState { done, current, upcoming }

class _ChainRow extends StatelessWidget {
  const _ChainRow({required this.state, required this.title, required this.line, required this.last, this.remark});

  final _StationState state;
  final String title;
  final String line;
  final String? remark;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final bool current = state == _StationState.current;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SizedBox(
            width: 28,
            child: CustomPaint(
              painter: _RailPainter(state: state, last: last),
            ),
          ),
          const SizedBox(width: N360Space.sm),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: N360Space.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    title,
                    style: N360Type.bodyStrong.copyWith(color: current ? N360Colors.stampRed : N360Colors.ink),
                  ),
                  if (line.isNotEmpty) Text(line, style: N360Type.caption),
                  if (remark != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text('“$remark”', style: N360Type.body),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A survey line down the page, with a station for each person in the chain.
class _RailPainter extends CustomPainter {
  const _RailPainter({required this.state, required this.last});

  final _StationState state;
  final bool last;

  @override
  void paint(Canvas canvas, Size size) {
    const y = 12.0;
    final double x = size.width / 2;
    final Paint rail = Paint()
      ..color = N360Colors.ruleStrong
      ..strokeWidth = 1.5;
    if (!last) canvas.drawLine(Offset(x, y), Offset(x, size.height), rail);

    switch (state) {
      case _StationState.done:
        canvas.drawCircle(Offset(x, y), 9, Paint()..color = N360Colors.moss);
        final Path check = Path()
          ..moveTo(x - 4.2, y + 0.2)
          ..lineTo(x - 1, y + 3.4)
          ..lineTo(x + 4.6, y - 3);
        canvas.drawPath(
          check,
          Paint()
            ..color = N360Colors.sheet
            ..style = PaintingStyle.stroke
            ..strokeWidth = 2.2
            ..strokeCap = StrokeCap.round
            ..strokeJoin = StrokeJoin.round,
        );
      case _StationState.current:
        canvas.drawCircle(Offset(x, y), 9, Paint()..color = N360Colors.sheet);
        canvas.drawCircle(
          Offset(x, y),
          9,
          Paint()
            ..color = N360Colors.laterite
            ..style = PaintingStyle.stroke
            ..strokeWidth = 2.5,
        );
        canvas.drawCircle(Offset(x, y), 3.5, Paint()..color = N360Colors.laterite);
      case _StationState.upcoming:
        canvas.drawCircle(Offset(x, y), 8, Paint()..color = N360Colors.sheet);
        canvas.drawCircle(
          Offset(x, y),
          8,
          Paint()
            ..color = N360Colors.ruleStrong
            ..style = PaintingStyle.stroke
            ..strokeWidth = 1.5,
        );
    }
  }

  @override
  bool shouldRepaint(_RailPainter oldDelegate) => oldDelegate.state != state || oldDelegate.last != last;
}
