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

/// `d MMM` for a moment in time, on the phone's own calendar day.
String shortDay(BuildContext context, DateTime moment) =>
    shortDate(context, moment.toLocal().toIso8601String());

/// The pill colour that says how urgent: red for critical, amber for high,
/// slate for medium, grey for low. The word is always on the pill as well.
StampKind priorityKind(String priority) => switch (priority) {
  'critical' => StampKind.rejected,
  'high' => StampKind.returned,
  'medium' => StampKind.inReview,
  _ => StampKind.draft,
};

/// Priority as a word on a quiet pill, so it never depends on colour alone.
class PriorityPill extends StatelessWidget {
  const PriorityPill({super.key, required this.priority});

  final String priority;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return StatusPill(
      kind: priorityKind(priority),
      label: l.pictogramLabel(N360Pictograms.priority(priority)),
    );
  }
}

/// The status as a pill, in words.
class IssueStatusPill extends StatelessWidget {
  const IssueStatusPill({super.key, required this.status});

  final String status;

  static StampKind kindOf(String status) => switch (status) {
    IssueStatus.open => StampKind.returned,
    IssueStatus.inProgress => StampKind.inReview,
    IssueStatus.resolved => StampKind.approved,
    _ => StampKind.draft,
  };

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return StatusPill(
      kind: kindOf(status),
      label: l.pictogramLabel(N360Pictograms.issueStatus(status)),
    );
  }
}

/// Nothing when everything is sent; otherwise whether it is still on the phone.
class IssueSyncPill extends StatelessWidget {
  const IssueSyncPill({super.key, required this.state});

  final LocalSyncState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return switch (state) {
      LocalSyncState.synced => const SizedBox.shrink(),
      LocalSyncState.queued => StatusPill(kind: StampKind.queued, label: l.statusQueued),
      LocalSyncState.sending => StatusPill(kind: StampKind.inReview, label: l.issuesSyncSending),
      LocalSyncState.attention => StatusPill(kind: StampKind.rejected, label: l.issuesSyncAttention),
    };
  }
}

/// The pills an issue earns by being late, due today or still on the phone.
/// Empty for an ordinary, sent issue, so most rows stay two lines.
List<Widget> issueFlags(
  BuildContext context, {
  required IssueRow issue,
  required String today,
  required LocalSyncState syncState,
}) {
  final AppLocalizations l = AppLocalizations.of(context);
  final bool overdue = isOverdue(issue, today);
  final bool dueToday = !overdue && issue.dueDate == today && isOpenStatus(issue.status);
  return <Widget>[
    if (overdue)
      StatusPill(
        kind: StampKind.rejected,
        label: l.issuesOverdue(daysBetween(issue.dueDate!, today)),
      ),
    if (dueToday) StatusPill(kind: StampKind.returned, label: l.issuesDueToday),
    if (syncState != LocalSyncState.synced) IssueSyncPill(state: syncState),
  ];
}

/// When it is due, or once it is done, when it was resolved.
String issueDueText(BuildContext context, IssueRow issue) {
  final AppLocalizations l = AppLocalizations.of(context);
  final DateTime? resolvedAt = issue.resolvedAt;
  if (!isOpenStatus(issue.status) && resolvedAt != null) {
    return l.issuesResolvedOn(shortDay(context, resolvedAt));
  }
  final String? due = issue.dueDate;
  return due == null ? l.issuesNoDueDate : l.issuesDueOn(shortDate(context, due));
}

/// One issue as a row of a [LedgerList]: the title and its priority pill, one
/// caption line of where, when and who, and a chevron. A late, due-today or
/// unsent issue carries a pill for that under the caption.
///
/// This is a [LedgerListTile] with a hero title for the flight into the detail
/// page, and the extra line of pills, which the plain tile has no room for.
class IssueTile extends StatelessWidget {
  const IssueTile({
    super.key,
    required this.record,
    required this.today,
    required this.assignee,
    required this.onTap,
  });

  final SyncedRecord<IssueRow> record;
  final String today;
  final String? assignee;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final IssueRow issue = record.record;
    final String? where = issue.locationNote?.trim();
    final String who =
        assignee ?? (issue.assignedTo == null ? l.issuesUnassigned : l.issuesAssignedUnknown);
    final String meta = <String>[
      if (where != null && where.isNotEmpty) where,
      issueDueText(context, issue),
      who,
    ].join(' · ');
    final List<Widget> flags = issueFlags(
      context,
      issue: issue,
      today: today,
      syncState: record.syncState,
    );
    final String priorityWord = l.pictogramLabel(N360Pictograms.priority(issue.priority));

    return PressTarget(
      onTap: onTap,
      semanticsLabel: '$priorityWord, ${issue.title}, $meta',
      builder: (context, depth, child) =>
          Transform.scale(scale: pressedScale(depth * 0.4), child: child),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.tile),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      LedgerHero(
                        tag: 'issue-title-${issue.clientUuid}',
                        child: Text(
                          issue.title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: N360Type.fit(N360Type.bodyStrong, issue.title),
                        ),
                      ),
                      Text(
                        meta,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: N360Type.fit(N360Type.caption, meta),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                PriorityPill(priority: issue.priority),
                const SizedBox(width: N360Space.sm),
                const Icon(Icons.chevron_right, size: N360IconSize.lg, color: N360Colors.ink3),
              ],
            ),
            if (flags.isNotEmpty) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Wrap(spacing: N360Space.sm, runSpacing: N360Space.xs, children: flags),
            ],
          ],
        ),
      ),
    );
  }
}

/// One line of supporting information on the detail page: an icon, then words.
class IssueMeta extends StatelessWidget {
  const IssueMeta({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Icon(icon, size: N360IconSize.xs, color: N360Colors.ink2),
        const SizedBox(width: N360Space.xs + N360Space.xxs),
        Expanded(
          child: Text(
            text,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: N360Type.fit(N360Type.caption, text),
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
  return showLedgerSheet<AssigneeChoice>(
    context,
    isScrollControlled: true,
    builder: (sheetContext) {
      final List<UserRow?> people = <UserRow?>[null, ...users];
      return ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(sheetContext).height * 0.7),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
              child: SheetHeader(title: l.issuesAssigneeSheetTitle),
            ),
            const RuledDivider(),
            Flexible(
              child: ListView.separated(
                shrinkWrap: true,
                padding: const EdgeInsets.only(bottom: N360Space.lg),
                itemCount: people.length,
                separatorBuilder: (_, _) => const RuledDivider(indent: N360Space.card),
                itemBuilder: (context, index) {
                  final UserRow? user = people[index];
                  final bool selected = user == null ? current == null : user.id == current;
                  return ColoredBox(
                    color: selected ? N360Colors.primaryTint : N360Colors.sheet,
                    child: LedgerListTile(
                      title: user?.name ?? l.issuesUnassigned,
                      leading: Icon(
                        user == null ? Icons.person_off_outlined : Icons.person_outline,
                        size: N360IconSize.lg,
                        color: N360Colors.ink2,
                      ),
                      // A pick list has no chevron: the tick says which is chosen.
                      trailing: selected
                          ? const Icon(Icons.check, size: N360IconSize.lg, color: N360Colors.primary)
                          : const SizedBox.square(dimension: N360IconSize.lg),
                      semanticsLabel: user?.name ?? l.issuesUnassigned,
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
