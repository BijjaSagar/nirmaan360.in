import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../../app/routes.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/dpr_repository.dart';
import '../dpr_detail_screen.dart';
import '../dpr_form_screen.dart';

/// The hero tag shared by a list row and the detail masthead.
Object dprHeroTag(String clientUuid) => 'dpr-sheet-$clientUuid';

/// "Mon, 9 Nov": names in the user's language, digits kept western to match
/// the mono figures everywhere else in the app.
String dprDateLabel(BuildContext context, String ymd) {
  final parts = ymd.split('-').map(int.parse).toList();
  final date = DateTime(parts[0], parts[1], parts[2]);
  final locale = Localizations.localeOf(context).toString();
  return '${_name('EEE', locale, date)}, ${date.day} ${_name('MMM', locale, date)}';
}

String _name(String pattern, String locale, DateTime date) {
  try {
    return DateFormat(pattern, locale).format(date);
  } on Object {
    return DateFormat(pattern, 'en').format(date);
  }
}

/// A ledger figure: three decimals, Indian grouping.
String dprQty(Qty qty) => qty.indian();

/// What the keypad should show for a saved quantity, without trailing zeros.
String dprKeypadText(Qty qty) => qty.value.toString();

int dprWorkerTotal(DprRow dpr) =>
    dpr.manpower.fold<int>(0, (sum, line) => sum + line.count);

/// The stamp for a report, from its server status and where it is now.
({StampKind kind, String label}) dprStampFor(
  AppLocalizations l,
  SyncedRecord<DprRow> record,
) {
  final dpr = record.record;
  final leaving = !dpr.localDraft && record.syncState != LocalSyncState.synced;
  if (leaving && (dpr.status == DocStatus.draft || dpr.status == DocStatus.returned)) {
    return (kind: StampKind.queued, label: l.statusQueued);
  }
  return switch (dpr.status) {
    DocStatus.approved => (kind: StampKind.approved, label: l.statusApproved),
    DocStatus.inReview => (kind: StampKind.inReview, label: l.statusInReview),
    DocStatus.returned => (kind: StampKind.returned, label: l.statusReturned),
    DocStatus.rejected => (kind: StampKind.rejected, label: l.statusRejected),
    _ => (kind: StampKind.draft, label: l.statusDraft),
  };
}

/// A report that can still be changed opens the form, everything else detail.
bool dprOpensForm(SyncedRecord<DprRow> record) {
  final dpr = record.record;
  final open = dpr.status == DocStatus.draft || dpr.status == DocStatus.returned;
  final leaving = !dpr.localDraft && record.syncState != LocalSyncState.synced;
  return open && !leaving;
}

// Navigation -----------------------------------------------------------------
//
// Through go_router in the app. Without a router above (a widget test) it falls
// back to the plain Navigator, so the screens stay testable on their own.

void dprOpenForm(BuildContext context, {String? uuid, String? date}) {
  final router = GoRouter.maybeOf(context);
  if (router != null) {
    final query = <String, String>{'uuid': ?uuid, 'date': ?date};
    router.push(
      Uri(
        path: Routes.dprForm,
        queryParameters: query.isEmpty ? null : query,
      ).toString(),
    );
    return;
  }
  Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => DprFormScreen(clientUuid: uuid, date: date),
    ),
  );
}

void dprOpenDetail(BuildContext context, String uuid) {
  final router = GoRouter.maybeOf(context);
  if (router != null) {
    router.push(Routes.dprDetail(uuid));
    return;
  }
  Navigator.of(context).push(
    MaterialPageRoute<void>(builder: (_) => DprDetailScreen(clientUuid: uuid)),
  );
}

void dprGoBack(BuildContext context) {
  final navigator = Navigator.of(context);
  if (navigator.canPop()) {
    navigator.pop();
    return;
  }
  GoRouter.maybeOf(context)?.go(Routes.home);
}

void dprGoHome(BuildContext context) {
  final router = GoRouter.maybeOf(context);
  if (router != null) {
    router.go(Routes.home);
    return;
  }
  Navigator.of(context).popUntil((route) => route.isFirst);
}
