import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/db/app_database.dart' show UserRow;
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../attendance/view/widgets/field_kit.dart';
import '../../bloc/issue_helpers.dart';
import '../../data/issue_repository.dart';

/// `d MMM`, in the app's language.
String shortDate(BuildContext context, String isoDate) {
  final String locale = Localizations.localeOf(context).toString();
  return DateFormat('d MMM', locale).format(DateTime.parse(isoDate));
}

({Color tile, Color icon, Color word}) _priorityLook(String priority) => switch (priority) {
  'critical' => (tile: N360Colors.accent, icon: N360Colors.ink, word: N360Colors.stampRed),
  'high' => (tile: N360Colors.goldTint, icon: N360Colors.ink, word: N360Colors.laterite),
  'medium' => (tile: N360Colors.paper2, icon: N360Colors.neel, word: N360Colors.neel),
  _ => (tile: N360Colors.paper2, icon: N360Colors.ink2, word: N360Colors.ink2),
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

    Widget tile(double size, double iconSize) => DecoratedBox(
      decoration: BoxDecoration(
        color: look.tile,
        borderRadius: compact ? N360Radius.sm : N360Radius.md,
      ),
      child: SizedBox.square(
        dimension: size,
        child: Icon(picture.icon, size: iconSize, color: look.icon),
      ),
    );

    if (compact) {
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          tile(32, 20),
          const SizedBox(width: 8),
          Text(label, style: N360Type.bodyStrong.copyWith(color: look.word)),
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
              tile(52, 28),
              const SizedBox(height: 4),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  label,
                  maxLines: 1,
                  style: N360Type.caption.copyWith(
                    fontSize: 13,
                    height: 1.2,
                    color: look.word,
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

/// The status as a stamp, in words.
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

/// Overdue as a filled chip that reads from arm's length, in the palette's
/// urgent colour with dark ink on it.
class OverdueChip extends StatelessWidget {
  const OverdueChip({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(color: N360Colors.accent, borderRadius: N360Radius.sm),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Icon(Icons.warning_amber, size: 17, color: N360Colors.ink),
            const SizedBox(width: 5),
            Flexible(
              child: Text(
                text,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.bodyStrong.copyWith(fontSize: 15, height: 1.25, color: N360Colors.ink),
              ),
            ),
          ],
        ),
      ),
    );
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
      return IssueMeta(icon: Icons.event_busy, text: l.issuesNoDueDate);
    }
    if (isOverdue(issue, today)) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Align(
          alignment: Alignment.centerLeft,
          child: OverdueChip(text: l.issuesOverdue(daysBetween(due, today))),
        ),
      );
    }
    if (due == today && isOpenStatus(issue.status)) {
      return IssueMeta(
        icon: Icons.schedule,
        text: l.issuesDueToday,
        color: N360Colors.laterite,
        strong: true,
      );
    }
    return IssueMeta(icon: Icons.event, text: l.issuesDueOn(shortDate(context, due)));
  }
}

/// One line of supporting information under an issue title.
class IssueMeta extends StatelessWidget {
  const IssueMeta({
    super.key,
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
      borderRadius: BorderRadius.vertical(top: Radius.circular(N360Radius.card)),
    ),
    builder: (sheetContext) {
      return SizedBox(
        height: MediaQuery.sizeOf(sheetContext).height * 0.6,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.lg,
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
                itemCount: users.length + 1,
                itemBuilder: (context, index) {
                  final UserRow? user = index == 0 ? null : users[index - 1];
                  return Padding(
                    padding: const EdgeInsets.only(bottom: N360Space.sm),
                    child: FieldChoiceTile(
                      width: double.infinity,
                      icon: user == null ? Icons.person_off_outlined : Icons.person,
                      label: user?.name ?? l.issuesUnassigned,
                      selected: user == null ? current == null : user.id == current,
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
