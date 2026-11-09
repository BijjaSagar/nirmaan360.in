import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/db/app_database.dart' show UserRow;
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/issue_helpers.dart';
import '../../data/issue_repository.dart';

/// `d MMM`, in the app's language.
String shortDate(BuildContext context, String isoDate) {
  final String locale = Localizations.localeOf(context).toString();
  return DateFormat('d MMM', locale).format(DateTime.parse(isoDate));
}

({Color ink, Color? fill}) _priorityLook(String priority) => switch (priority) {
  'critical' => (ink: N360Colors.sheet, fill: N360Colors.stampRed),
  'high' => (ink: N360Colors.laterite, fill: null),
  'medium' => (ink: N360Colors.neel, fill: null),
  _ => (ink: N360Colors.ink2, fill: null),
};

/// Priority as a picture and a word, so it never depends on colour alone.
class PriorityMarker extends StatelessWidget {
  const PriorityMarker({super.key, required this.priority, this.compact = false});

  final String priority;

  /// A single line, for detail rows.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Pictogram picture = N360Pictograms.priority(priority);
    final look = _priorityLook(priority);
    final String label = l.pictogramLabel(picture);
    final bool critical = priority == 'critical';

    final Widget icon = Icon(
      picture.icon,
      size: compact ? 20 : 24,
      color: look.fill == null ? look.ink : N360Colors.sheet,
    );

    if (compact) {
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (look.fill == null)
            icon
          else
            DecoratedBox(
              decoration: BoxDecoration(color: look.fill, borderRadius: N360Radius.sm),
              child: Padding(padding: const EdgeInsets.all(2), child: icon),
            ),
          const SizedBox(width: 6),
          Text(
            label,
            style: N360Type.bodyStrong.copyWith(
              color: critical ? N360Colors.stampRed : look.ink,
            ),
          ),
        ],
      );
    }

    return Semantics(
      label: label,
      child: ExcludeSemantics(
        child: SizedBox(
          width: 60,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              DecoratedBox(
                decoration: BoxDecoration(
                  color: look.fill,
                  borderRadius: N360Radius.sm,
                  border: look.fill == null
                      ? Border.all(color: look.ink.withValues(alpha: 0.8), width: 1.5)
                      : null,
                ),
                child: SizedBox.square(dimension: 36, child: Center(child: icon)),
              ),
              const SizedBox(height: 3),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  label,
                  maxLines: 1,
                  style: N360Type.caption.copyWith(
                    fontSize: 12.5,
                    color: critical ? N360Colors.stampRed : look.ink,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The status as a rubber stamp, in words.
class IssueStatusStamp extends StatelessWidget {
  const IssueStatusStamp({
    super.key,
    required this.status,
    this.size = StampSize.small,
    this.animate = false,
  });

  final String status;
  final StampSize size;
  final bool animate;

  static StampKind kindOf(String status) => switch (status) {
    IssueStatus.open => StampKind.returned,
    IssueStatus.inProgress => StampKind.inReview,
    IssueStatus.resolved => StampKind.approved,
    _ => StampKind.draft,
  };

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String label = l.pictogramLabel(N360Pictograms.issueStatus(status));
    return RubberStamp(kind: kindOf(status), label: label, size: size, animate: animate);
  }
}

/// Nothing when everything is sent; otherwise whether it is still on the phone.
class IssueSyncStamp extends StatelessWidget {
  const IssueSyncStamp({super.key, required this.state});

  final LocalSyncState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return switch (state) {
      LocalSyncState.synced => const SizedBox.shrink(),
      LocalSyncState.queued => RubberStamp(
        kind: StampKind.queued,
        label: l.statusQueued,
        size: StampSize.small,
      ),
      LocalSyncState.sending => RubberStamp(
        kind: StampKind.inReview,
        label: l.issuesSyncSending,
        size: StampSize.small,
      ),
      LocalSyncState.attention => RubberStamp(
        kind: StampKind.rejected,
        label: l.issuesSyncAttention,
        size: StampSize.small,
      ),
    };
  }
}

/// Due date, with overdue spelled out in words and an icon.
class DueLine extends StatelessWidget {
  const DueLine({super.key, required this.issue, required this.today});

  final IssueRow issue;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? due = issue.dueDate;
    if (due == null) {
      return _MetaLine(icon: Icons.event_busy, text: l.issuesNoDueDate);
    }
    if (isOverdue(issue, today)) {
      return _MetaLine(
        icon: Icons.warning_amber,
        text: l.issuesOverdue(daysBetween(due, today)),
        color: N360Colors.stampRed,
        strong: true,
      );
    }
    if (due == today && isOpenStatus(issue.status)) {
      return _MetaLine(
        icon: Icons.schedule,
        text: l.issuesDueToday,
        color: N360Colors.haldiInk,
        strong: true,
      );
    }
    return _MetaLine(icon: Icons.event, text: l.issuesDueOn(shortDate(context, due)));
  }
}

class _MetaLine extends StatelessWidget {
  const _MetaLine({
    required this.icon,
    required this.text,
    this.color = N360Colors.ink2,
    this.strong = false,
  });

  final IconData icon;
  final String text;
  final Color color;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Icon(icon, size: 17, color: color),
        const SizedBox(width: 5),
        Expanded(
          child: Text(
            text,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: (strong ? N360Type.bodyStrong : N360Type.body).copyWith(
              fontSize: 15,
              height: 1.3,
              color: color,
            ),
          ),
        ),
      ],
    );
  }
}

/// One line of supporting information under an issue title.
class IssueMeta extends StatelessWidget {
  const IssueMeta({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => _MetaLine(icon: icon, text: text);
}

/// A selectable chip: the same pressable sheet as everything else, filled with
/// ink when chosen. At least 56dp high for a gloved thumb.
class SelectTile extends StatelessWidget {
  const SelectTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.trailing,
    this.semanticsLabel,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final IconData? icon;

  /// A count or short note after the label.
  final String? trailing;
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) {
    final Color ink = selected ? N360Colors.sheet : N360Colors.ink;
    return LedgerCard(
      padding: EdgeInsets.zero,
      onTap: onTap,
      semanticsLabel: semanticsLabel ?? (trailing == null ? label : '$label, $trailing'),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: selected ? N360Colors.ink : null,
          borderRadius: N360Radius.sm,
        ),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: N360Size.tap - N360Size.edge),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (icon != null) ...<Widget>[
                  Icon(icon, size: 20, color: ink),
                  const SizedBox(width: 6),
                ],
                Text(
                  label,
                  maxLines: 1,
                  style: N360Type.bodyStrong.copyWith(color: ink),
                ),
                if (trailing != null) ...<Widget>[
                  const SizedBox(width: 6),
                  Text(
                    trailing!,
                    style: N360Type.figureSmall.copyWith(
                      color: selected ? N360Colors.sheet : N360Colors.inkLabel,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The result of the assignee sheet. A null [userId] means "not assigned".
class AssigneeChoice {
  const AssigneeChoice(this.userId);

  final int? userId;
}

/// Lets the user pick who an issue goes to. Returns null if dismissed.
Future<AssigneeChoice?> showAssigneeSheet(
  BuildContext context, {
  required List<UserRow> users,
  required int? current,
}) {
  final AppLocalizations l = AppLocalizations.of(context);
  return showModalBottomSheet<AssigneeChoice>(
    context: context,
    useSafeArea: true,
    isScrollControlled: true,
    backgroundColor: N360Colors.paper,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(N360Radius.medium)),
    ),
    builder: (sheetContext) {
      final int itemCount = users.length + 1;
      return SizedBox(
        height: MediaQuery.sizeOf(sheetContext).height * 0.6,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.md,
                N360Space.gutter,
                N360Space.sm,
              ),
              child: SheetHeader(title: l.issuesAssigneeSheetTitle),
            ),
            Expanded(
              child: ListView.builder(
                padding: const EdgeInsets.fromLTRB(
                  N360Space.gutter,
                  0,
                  N360Space.gutter,
                  N360Space.lg,
                ),
                itemCount: itemCount,
                itemBuilder: (context, index) {
                  final UserRow? user = index == 0 ? null : users[index - 1];
                  final bool selected = user == null ? current == null : user.id == current;
                  final String label = user?.name ?? l.issuesUnassigned;
                  return Padding(
                    padding: const EdgeInsets.only(bottom: N360Space.sm),
                    child: SelectTile(
                      icon: user == null ? Icons.person_off_outlined : Icons.person,
                      label: label,
                      selected: selected,
                      onTap: () => Navigator.of(sheetContext).pop(AssigneeChoice(user?.id)),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      );
    },
  );
}
