import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/db/status.dart';
import '../../../../core/design/design.dart';
import '../../../../core/sync/record_sync.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// The way back: an arrow with its word under it, on a soft tile, because an
/// icon alone means little to someone who reads little.
class LedgerBackKey extends StatelessWidget {
  const LedgerBackKey({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final String label = AppLocalizations.of(context).commonBack;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      semanticsLabel: label,
      builder: (context, depth, child) => Transform.scale(scale: pressedScale(depth), child: child),
      child: SizedBox.square(
        dimension: N360Size.tap,
        child: Center(
          child: SizedBox.square(
            dimension: N360Size.tile,
            child: DecoratedBox(
              decoration: const BoxDecoration(color: N360Colors.paper2, borderRadius: N360Radius.sm),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: <Widget>[
                  const Icon(Icons.arrow_back, size: N360IconSize.md, color: N360Colors.ink),
                  Text(label, maxLines: 1, style: N360Type.caption.copyWith(height: 1.15)),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The top of a page: the way back, a centred title, and room for one more
/// thing. The title stays centred whether or not there is a way back.
class BookTitleBar extends StatelessWidget {
  const BookTitleBar({super.key, required this.title, this.eyebrow, this.onBack, this.trailing});

  final String title;
  final String? eyebrow;
  final VoidCallback? onBack;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final VoidCallback? back = onBack;
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.xs),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: Row(
          children: <Widget>[
            SizedBox(
              width: N360Size.tap,
              child: back == null ? null : LedgerBackKey(onTap: back),
            ),
            const SizedBox(width: N360Space.xs),
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (eyebrow != null)
                    Text(eyebrow!, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
                  Text(
                    title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: N360Type.title,
                  ),
                ],
              ),
            ),
            const SizedBox(width: N360Space.xs),
            SizedBox(width: trailing == null ? N360Size.tap : null, child: trailing),
          ],
        ),
      ),
    );
  }
}

enum ChoiceLook { gold, deduction }

/// A rounded tile that can be chosen. Dark ink on the fill either way: the
/// primary gold when chosen, deep orange for a deduction so it cannot be missed.
class ChoiceTile extends StatelessWidget {
  const ChoiceTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.count,
    this.look = ChoiceLook.gold,
    this.centered = true,
    this.compact = false,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final IconData? icon;
  final int? count;
  final ChoiceLook look;
  final bool centered;

  /// Draws a tile of [N360Size.tile] inside the full [N360Size.tap] target, for
  /// a row of filters that should stay quiet.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final bool deep = selected && look == ChoiceLook.deduction;
    final BoxDecoration face = !selected
        ? BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.tileRadius,
            border: Border.all(color: N360Colors.rule, width: 1.25),
          )
        : (deep
              ? const BoxDecoration(color: N360Colors.orange, borderRadius: N360Radius.tileRadius)
              : const BoxDecoration(gradient: N360Gradients.gold, borderRadius: N360Radius.tileRadius));

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: count == null ? label : '$label $count',
      selected: selected,
      builder: (context, depth, child) {
        final Widget tile = DecoratedBox(decoration: face, child: child);
        return Transform.scale(
          scale: pressedScale(depth),
          child: compact ? Center(widthFactor: 1, child: tile) : tile,
        );
      },
      child: ConstrainedBox(
        constraints: BoxConstraints(minHeight: compact ? N360Size.tile : N360Size.tap),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.xs),
          child: Row(
            mainAxisSize: centered ? MainAxisSize.min : MainAxisSize.max,
            mainAxisAlignment: centered ? MainAxisAlignment.center : MainAxisAlignment.start,
            children: <Widget>[
              if (icon != null) ...<Widget>[
                Icon(icon, size: N360IconSize.button, color: N360Colors.ink),
                const SizedBox(width: 6),
              ],
              Flexible(
                child: Text(label, maxLines: centered ? 1 : 2, overflow: TextOverflow.ellipsis, style: N360Type.button),
              ),
              if (count != null) ...<Widget>[const SizedBox(width: 6), Text('$count', style: N360Type.figureSmall)],
            ],
          ),
        ),
      ),
    );
  }
}

/// A square tile holding a BOQ code, the way a list row is anchored here.
class CodeTile extends StatelessWidget {
  const CodeTile({super.key, required this.code});

  final String code;

  @override
  Widget build(BuildContext context) {
    return SizedBox.square(
      dimension: N360Size.tile,
      child: DecoratedBox(
        decoration: const BoxDecoration(color: N360Colors.paper2, borderRadius: N360Radius.md),
        child: Padding(
          padding: const EdgeInsets.all(N360Space.xs),
          child: Center(
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(code, maxLines: 1, style: N360Type.figureSmall),
            ),
          ),
        ),
      ),
    );
  }
}

/// A tinted note inside or between cards. Icon and words together, always.
class TintedNote extends StatelessWidget {
  const TintedNote({
    super.key,
    required this.icon,
    required this.ink,
    required this.fill,
    required this.body,
    this.title,
  });

  final IconData icon;
  final Color ink;
  final Color fill;
  final String? title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(color: fill, borderRadius: N360Radius.md),
      child: Padding(
        padding: const EdgeInsets.all(N360Space.md),
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
                  if (title != null) Text(title!, style: N360Type.bodyStrong.copyWith(color: ink, height: 1.3)),
                  Text(body, style: N360Type.body.copyWith(height: 1.35)),
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
