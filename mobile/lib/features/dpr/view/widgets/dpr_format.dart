import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../../app/routes.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart' show BoqLine;
import '../../data/dpr_repository.dart';
import '../dpr_detail_screen.dart';
import '../dpr_form_screen.dart';

/// The hero tag shared by a list row and the detail masthead.
Object dprHeroTag(String clientUuid) => 'dpr-sheet-$clientUuid';

({int day, String month, String weekdayShort}) _dateParts(
  BuildContext context,
  String ymd,
) {
  final parts = ymd.split('-').map(int.parse).toList();
  final date = DateTime(parts[0], parts[1], parts[2]);
  final locale = Localizations.localeOf(context).toString();
  return (
    day: date.day,
    month: _name('MMM', locale, date),
    weekdayShort: _name('EEE', locale, date),
  );
}

/// "Mon, 9 Nov": names in the user's language, digits kept western to match
/// the figures everywhere else in the app.
String dprDateLabel(BuildContext context, String ymd) {
  final d = _dateParts(context, ymd);
  return '${d.weekdayShort}, ${d.day} ${d.month}';
}

/// "9 Nov".
String dprDayMonth(BuildContext context, String ymd) {
  final d = _dateParts(context, ymd);
  return '${d.day} ${d.month}';
}

/// "Today, 9 Nov" when [ymd] is [today], otherwise "Mon, 9 Nov". Says today out
/// loud, because a report against the wrong day is what a tired eye misses.
String dprDayLabel(BuildContext context, AppLocalizations l, String ymd, String today) {
  return ymd == today ? '${l.commonToday}, ${dprDayMonth(context, ymd)}' : dprDateLabel(context, ymd);
}

/// "November 2026", with western digits like the rest of the app's figures.
String dprMonthLabel(BuildContext context, String ymd) {
  final parts = ymd.split('-').map(int.parse).toList();
  final locale = Localizations.localeOf(context).toString();
  return '${_name('MMMM', locale, DateTime(parts[0], parts[1], parts[2]))} ${parts[0]}';
}

String _name(String pattern, String locale, DateTime date) {
  try {
    return DateFormat(pattern, locale).format(date);
  } on Object {
    return DateFormat(pattern, 'en').format(date);
  }
}

/// A quantity for the screen: three decimals, Indian grouping.
String dprQty(Qty qty) => qty.indian();

/// What the keypad should show for a saved quantity, without trailing zeros.
String dprKeypadText(Qty qty) => qty.value.toString();

int dprWorkerTotal(DprRow dpr) =>
    dpr.manpower.fold<int>(0, (sum, line) => sum + line.count);

/// How much of a BOQ line has been measured.
///
/// [percent] comes from exact decimals; [fraction] is only for drawing, which
/// is the one place a double is allowed near a quantity.
({double fraction, int percent}) dprBoqDone(BoqLine line) {
  final Qty total = line.item.qty;
  if (total.isZero) return (fraction: 0, percent: 0);
  final Decimal done = (total - line.previewBalance).value;
  final int percent = ((done * Decimal.fromInt(100)) / total.value)
      .round()
      .toInt()
      .clamp(0, 100);
  final double fraction = (done / total.value).toDouble().clamp(0.0, 1.0);
  return (fraction: fraction, percent: percent);
}

/// The stamp for a report, from its server status and where it is now.
({StampKind kind, String label}) dprStampFor(
  AppLocalizations l,
  SyncedRecord<DprRow> record,
) {
  final dpr = record.record;
  if (record.syncState == LocalSyncState.attention) {
    return (kind: StampKind.rejected, label: l.dprSyncAttention);
  }
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

/// Where a report is on its way to the office, in words. Never "sent" for
/// anything still on the phone.
String dprSyncText(AppLocalizations l, SyncedRecord<DprRow> record) {
  final dpr = record.record;
  return switch (record.syncState) {
    LocalSyncState.attention => l.dprSyncAttention,
    LocalSyncState.sending => l.dprSyncSending,
    LocalSyncState.queued => l.dprSyncWaiting,
    LocalSyncState.synced when dpr.localDraft || dpr.serverId == null => l.dprSyncPhoneOnly,
    LocalSyncState.synced => l.statusSynced,
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

/// Opens the Outbox, where a report that could not be sent gets fixed.
void dprGoOutbox(BuildContext context) => GoRouter.maybeOf(context)?.go(Routes.outbox);

void dprGoHome(BuildContext context) {
  final router = GoRouter.maybeOf(context);
  if (router != null) {
    router.go(Routes.home);
    return;
  }
  Navigator.of(context).popUntil((route) => route.isFirst);
}
