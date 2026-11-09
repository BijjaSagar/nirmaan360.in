// Reads an approval's document safely. The server shapes it by `doc_type`, and
// a field the app did not expect must never crash an approver's screen.
import 'package:flutter/material.dart';

import '../../../../core/db/app_database.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../measurement/bloc/measurement_book.dart';
import '../../bloc/boq_index.dart';

Map<String, dynamic> readMap(Object? value) => value is Map ? value.cast<String, dynamic>() : const <String, dynamic>{};

List<Map<String, dynamic>> readList(Object? value) => value is List
    ? value.whereType<Map>().map((row) => row.cast<String, dynamic>()).toList()
    : const <Map<String, dynamic>>[];

String? readText(Object? value) {
  if (value == null) return null;
  final String text = value.toString().trim();
  return text.isEmpty ? null : text;
}

Qty? readQty(Object? value) => Qty.tryParse(readText(value));

Money? readMoney(Object? value) => Money.tryParse(readText(value));

/// The one figure an approver looks at first.
class ApprovalFigure {
  const ApprovalFigure(this.value, {this.unit, this.negative = false});

  final String value;
  final String? unit;
  final bool negative;
}

ApprovalFigure? figureOf(ApprovalRow row, AppLocalizations l) {
  final Map<String, dynamic> doc = row.document;
  switch (row.docType) {
    case 'measurement':
      final Qty? qty = readQty(doc['qty']);
      if (qty == null) return null;
      return ApprovalFigure(formatQty(qty), unit: readText(readMap(doc['boq_item'])['unit']), negative: qty.isNegative);
    case 'ra_bill':
      final Money? net = readMoney(doc['net_payable']);
      return net == null ? null : ApprovalFigure(net.inr());
    case 'bill_note':
      final Money? total = readMoney(doc['total_amount']);
      return total == null ? null : ApprovalFigure(total.inr());
    case 'dpr':
      final String? total = readText(doc['manpower_total']);
      return total == null ? null : ApprovalFigure(total, unit: l.approvalsWorkers);
  }
  return null;
}

bool isMoneyDoc(String docType) => docType == 'ra_bill' || docType == 'bill_note';

/// A measurement's size against its BOQ line, for a progress mark.
class ApprovalShare {
  const ApprovalShare(this.fraction, this.label);

  final double fraction;
  final String label;
}

/// Null for anything but a measurement, and when the line is not on this phone.
ApprovalShare? shareOf(ApprovalRow row, BoqIndex boq) {
  if (row.docType != 'measurement') return null;
  final int? projectId = row.projectId;
  final String? code = readText(readMap(row.document['boq_item'])['code']);
  final Qty? qty = readQty(row.document['qty']);
  if (projectId == null || code == null || qty == null) return null;
  final BoqItemRow? line = boq[(projectId, code)];
  if (line == null) return null;
  final double? fraction = shareFraction(qty, line.qty);
  final String? label = shareLabel(qty, line.qty);
  return fraction == null || label == null ? null : ApprovalShare(fraction, label);
}

/// What an approver calls this document. The server's own title repeats the
/// type and figure shown beside it, and is English in every language.
String headlineOf(ApprovalRow row) {
  final Map<String, dynamic> doc = row.document;
  switch (row.docType) {
    case 'measurement':
      final Map<String, dynamic> boq = readMap(doc['boq_item']);
      final String? code = readText(boq['code']);
      final String? name = readText(doc['location']) ?? readText(doc['description']) ?? readText(boq['description']);
      if (code != null && name != null) return '$code · $name';
    case 'ra_bill' || 'bill_note':
      final String? number = readText(doc['number']);
      final String? party = readText(doc['party_name']);
      if (number != null && party != null) return '$number · $party';
  }
  return row.title;
}

/// The measurement as the book writes it, or null for other documents.
String? formulaOf(ApprovalRow row) {
  if (row.docType != 'measurement') return null;
  final Map<String, dynamic> doc = row.document;
  final Qty? qty = readQty(doc['qty']);
  if (qty == null) return null;
  return bookFormula(
    nos: readQty(doc['nos']),
    length: readQty(doc['length']),
    breadth: readQty(doc['breadth']),
    depth: readQty(doc['depth']),
    deduction: doc['is_deduction'] == true,
    result: qty,
    unit: readText(readMap(doc['boq_item'])['unit']),
  );
}

bool isDeviationDoc(ApprovalRow row) => row.docType == 'measurement' && row.document['deviation'] == true;

String docTypeWord(AppLocalizations l, String docType) => switch (docType) {
  'measurement' => l.approvalsDocMeasurement,
  'dpr' => l.approvalsDocDpr,
  'ra_bill' => l.approvalsDocBill,
  'bill_note' => l.approvalsDocBillNote,
  'vendor_bill' => l.approvalsDocVendorBill,
  'indent' => l.approvalsDocIndent,
  'rfi' => l.approvalsDocRfi,
  _ => l.approvalsDocOther,
};

IconData docTypeIcon(String docType) => switch (docType) {
  'measurement' => Icons.architecture,
  'dpr' => Icons.assignment,
  'ra_bill' => Icons.request_quote,
  'bill_note' => Icons.receipt_long,
  'vendor_bill' => Icons.receipt,
  'indent' => Icons.inventory_2_outlined,
  'rfi' => Icons.help_outline,
  _ => Icons.description,
};

/// A role's name in the app's language. A role the app does not know shows as
/// the server wrote it.
String roleWord(AppLocalizations l, String? role, {String? serverLabel}) {
  return switch (role) {
    'engineer' => l.approvalsRoleEngineer,
    'qs' => l.approvalsRoleQs,
    'pm' => l.approvalsRolePm,
    'accounts' => l.approvalsRoleAccounts,
    'owner' => l.approvalsRoleOwner,
    'admin' => l.approvalsRoleAdmin,
    'client' => l.approvalsRoleClient,
    _ => serverLabel ?? role ?? '',
  };
}

/// "2 hours ago", from whole units only. Never negative, even if the phone's
/// clock has drifted behind the server's.
String agoText(AppLocalizations l, DateTime? at, DateTime now) {
  if (at == null) return '';
  final Duration age = now.difference(at);
  if (age.inMinutes < 1) return l.approvalsJustNow;
  if (age.inMinutes < 60) return l.approvalsMinutesAgo(age.inMinutes);
  if (age.inHours < 24) return l.approvalsHoursAgo(age.inHours);
  return l.approvalsDaysAgo(age.inDays);
}
