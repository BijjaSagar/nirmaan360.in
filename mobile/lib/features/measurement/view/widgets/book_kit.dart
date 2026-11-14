import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/db/status.dart';
import '../../../../core/design/design.dart';
import '../../../../core/sync/record_sync.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// The way back: the design system's [N360BackTile], an arrow with its word
/// under it, because an icon alone means little to someone who reads little.
class LedgerBackKey extends StatelessWidget {
  const LedgerBackKey({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) =>
      N360BackTile(label: AppLocalizations.of(context).commonBack, onTap: onTap);
}

/// The top of a page: the app's one [N360TopBar]. Back shows only when the
/// screen gives it somewhere to go, so a root tab keeps a plain title.
class BookTitleBar extends StatelessWidget {
  const BookTitleBar({super.key, required this.title, this.eyebrow, this.onBack, this.leading, this.trailing});

  final String title;
  final String? eyebrow;
  final VoidCallback? onBack;

  /// What sits on the left of a root tab, where there is no way back.
  final Widget? leading;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return N360TopBar(
      backLabel: AppLocalizations.of(context).commonBack,
      title: title,
      subtitle: eyebrow,
      onBack: onBack,
      showBack: onBack != null,
      leading: leading,
      trailing: trailing,
    );
  }
}

enum ChoiceLook { tint, solid, deduction }

/// A rounded tile that can be chosen. A solid fill is for the one choice a
/// screen is about; everything else tints with a thin primary edge.
class ChoiceTile extends StatelessWidget {
  const ChoiceTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.look = ChoiceLook.tint,
    this.centered = true,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final IconData? icon;
  final ChoiceLook look;
  final bool centered;

  @override
  Widget build(BuildContext context) {
    final bool filled = selected && look != ChoiceLook.tint;
    final Color ink = filled ? N360Colors.onPrimary : N360Colors.ink;
    final BoxDecoration face;
    if (!selected) {
      face = BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.tileRadius,
        border: Border.all(color: look == ChoiceLook.tint ? N360Colors.rule : N360Colors.ruleStrong),
      );
    } else if (look == ChoiceLook.tint) {
      face = BoxDecoration(
        color: N360Colors.primaryTint,
        borderRadius: N360Radius.tileRadius,
        border: Border.all(color: N360Colors.primary),
      );
    } else {
      face = BoxDecoration(
        color: look == ChoiceLook.deduction ? N360Colors.laterite : N360Colors.primary,
        borderRadius: N360Radius.tileRadius,
      );
    }

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: label,
      selected: selected,
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth),
        child: DecoratedBox(decoration: face, child: child),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.xs),
          child: Row(
            mainAxisSize: centered ? MainAxisSize.min : MainAxisSize.max,
            mainAxisAlignment: centered ? MainAxisAlignment.center : MainAxisAlignment.start,
            children: <Widget>[
              if (icon != null) ...<Widget>[
                Icon(icon, size: N360IconSize.button, color: ink),
                const SizedBox(width: 6),
              ],
              Flexible(
                child: Text(
                  label,
                  maxLines: centered ? 1 : 2,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.button.copyWith(color: ink),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A plain note between cards. Icon and words together, always.
class TintedNote extends StatelessWidget {
  const TintedNote({
    super.key,
    required this.icon,
    required this.ink,
    required this.body,
    this.title,
    this.edge = N360Colors.rule,
  });

  final IconData icon;
  final Color ink;
  final Color edge;
  final String? title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.cardRadius,
        border: Border.all(color: edge),
      ),
      child: Padding(
        padding: const EdgeInsets.all(N360Space.card),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.only(top: 1),
              child: Icon(icon, size: N360IconSize.lg, color: ink),
            ),
            const SizedBox(width: N360Space.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (title != null) Text(title!, style: N360Type.bodyStrong.copyWith(color: ink)),
                  Text(body, style: N360Type.body),
                ],
              ),
            ),
          ],
        ),
      ),
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
    final TextStyle figure = valueStyle ?? (total ? N360Type.figure : N360Type.figure);
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
        Icon(look.icon, size: N360IconSize.sm, color: look.ink),
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
