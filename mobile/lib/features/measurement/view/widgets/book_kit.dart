import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/db/status.dart';
import '../../../../core/design/design.dart';
import '../../../../core/design/internal/press_target.dart';
import '../../../../core/sync/record_sync.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// The way back: an arrow with its word under it, because an icon alone means
/// little to someone who reads little.
class LedgerBackKey extends StatelessWidget {
  const LedgerBackKey({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final String label = AppLocalizations.of(context).commonBack;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      minWidth: 56,
      minHeight: 56,
      semanticsLabel: label,
      child: SizedBox(
        width: 56,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            const Icon(Icons.arrow_back, size: 26, color: N360Colors.ink),
            Text(
              label,
              maxLines: 1,
              style: N360Type.caption.copyWith(color: N360Colors.ink2, height: 1.2, fontSize: 13),
            ),
          ],
        ),
      ),
      builder: (context, depth, child) => Transform.translate(offset: Offset(0, depth * 2), child: child),
    );
  }
}

/// A ledger line whose value can never push it wider than the sheet. Figures
/// shrink to fit, because a figure must stay whole; words wrap.
class BookRow extends StatelessWidget {
  const BookRow({
    super.key,
    required this.label,
    required this.value,
    this.hint,
    this.ruled = true,
    this.total = false,
    this.valueStyle,
  }) : _words = false;

  const BookRow.words({super.key, required this.label, required this.value, this.hint, this.ruled = true})
    : total = false,
      valueStyle = null,
      _words = true;

  final String label;
  final String value;
  final String? hint;
  final bool ruled;
  final bool total;
  final TextStyle? valueStyle;
  final bool _words;

  @override
  Widget build(BuildContext context) {
    final TextStyle figure = valueStyle ?? (total ? N360Type.figure.copyWith(fontSize: 20) : N360Type.figure);
    return LedgerRow(
      label: label,
      hint: hint,
      ruled: ruled,
      total: total,
      valueWidget: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 200),
        child: _words
            ? Text(
                value,
                textAlign: TextAlign.right,
                maxLines: 4,
                overflow: TextOverflow.ellipsis,
                style: N360Type.body,
              )
            : FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerRight,
                child: Text(value, maxLines: 1, style: figure),
              ),
      ),
    );
  }
}

/// Which stamp a server status gets.
StampKind stampKindFor(String status) => switch (status) {
  DocStatus.inReview => StampKind.inReview,
  DocStatus.approved => StampKind.approved,
  DocStatus.returned => StampKind.returned,
  DocStatus.rejected => StampKind.rejected,
  _ => StampKind.draft,
};

String statusWord(AppLocalizations l, String status) => switch (status) {
  DocStatus.inReview => l.statusInReview,
  DocStatus.approved => l.statusApproved,
  DocStatus.returned => l.statusReturned,
  DocStatus.rejected => l.statusRejected,
  _ => l.statusDraft,
};

/// A short word for a record's trip to the server, or null when it has arrived.
String? syncWord(AppLocalizations l, LocalSyncState state) => switch (state) {
  LocalSyncState.synced => null,
  LocalSyncState.queued => l.statusQueued,
  LocalSyncState.sending => l.measurementSyncSending,
  LocalSyncState.attention => l.measurementSyncAttention,
};

/// Icon and word for a record that is not on the server yet.
class SyncMark extends StatelessWidget {
  const SyncMark({super.key, required this.state});

  final LocalSyncState state;

  @override
  Widget build(BuildContext context) {
    final String? word = syncWord(AppLocalizations.of(context), state);
    if (word == null) return const SizedBox.shrink();
    final ({IconData icon, Color ink}) look = switch (state) {
      LocalSyncState.queued => (icon: Icons.phone_android, ink: N360Colors.inkLabel),
      LocalSyncState.sending => (icon: Icons.sync, ink: N360Colors.neel),
      LocalSyncState.attention => (icon: Icons.error_outline, ink: N360Colors.stampRed),
      LocalSyncState.synced => (icon: Icons.cloud_done, ink: N360Colors.mossInk),
    };
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(look.icon, size: 16, color: look.ink),
        const SizedBox(width: 4),
        Flexible(
          child: Text(
            word,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: N360Type.caption.copyWith(color: look.ink, fontWeight: FontWeight.w600),
          ),
        ),
      ],
    );
  }
}

/// `2026-11-09` as `Today`, `Yesterday` or `9 Nov`, in the app's language but
/// always with the digits people use on site.
String dayLabel(BuildContext context, String date, String today) {
  final AppLocalizations l = AppLocalizations.of(context);
  if (date == today) return l.commonToday;
  final DateTime? parsed = DateTime.tryParse(date);
  if (parsed == null) return date;
  final DateTime? now = DateTime.tryParse(today);
  if (now != null && now.difference(parsed).inDays == 1) return l.measurementYesterday;
  final String locale = Localizations.localeOf(context).languageCode;
  final DateFormat format = DateFormat('d MMM', locale)..useNativeDigits = false;
  if (now != null && now.year != parsed.year) {
    return (DateFormat('d MMM yyyy', locale)..useNativeDigits = false).format(parsed);
  }
  return format.format(parsed);
}

/// Today's date as the app knows it, minus [days].
String daysBefore(String today, int days) {
  final DateTime parsed = DateTime.parse(today);
  final DateTime earlier = DateTime(parsed.year, parsed.month, parsed.day - days);
  String two(int value) => value.toString().padLeft(2, '0');
  return '${earlier.year}-${two(earlier.month)}-${two(earlier.day)}';
}
