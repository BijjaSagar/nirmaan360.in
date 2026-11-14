import '../data/issue_repository.dart';

/// Server issue statuses, in the order the list shows them.
abstract final class IssueStatus {
  static const open = 'open';
  static const inProgress = 'in_progress';
  static const resolved = 'resolved';
  static const closed = 'closed';
}

bool isOpenStatus(String status) =>
    status == IssueStatus.open || status == IssueStatus.inProgress;

int _statusRank(String status) => switch (status) {
  IssueStatus.open => 0,
  IssueStatus.inProgress => 1,
  IssueStatus.resolved => 2,
  IssueStatus.closed => 3,
  _ => 4,
};

int _priorityRank(String priority) => switch (priority) {
  'critical' => 0,
  'high' => 1,
  'medium' => 2,
  'low' => 3,
  _ => 4,
};

/// Whole days from [from] to [to], both `YYYY-MM-DD`.
int daysBetween(String from, String to) =>
    DateTime.parse(to).difference(DateTime.parse(from)).inDays;

/// An unfinished issue past its due date.
bool isOverdue(IssueRow issue, String today) {
  final due = issue.dueDate;
  return due != null && isOpenStatus(issue.status) && due.compareTo(today) < 0;
}

/// Open and in-progress work first; within that, overdue, then most urgent,
/// then earliest due.
int compareIssues(IssueRow a, IssueRow b, String today) {
  final byStatus = _statusRank(a.status).compareTo(_statusRank(b.status));
  if (byStatus != 0) return byStatus;

  final overdueA = isOverdue(a, today);
  final overdueB = isOverdue(b, today);
  if (overdueA != overdueB) return overdueA ? -1 : 1;

  final byPriority = _priorityRank(a.priority).compareTo(_priorityRank(b.priority));
  if (byPriority != 0) return byPriority;

  final dueA = a.dueDate ?? '9999-99-99';
  final dueB = b.dueDate ?? '9999-99-99';
  final byDue = dueA.compareTo(dueB);
  if (byDue != 0) return byDue;

  final stampA = a.clientUpdatedAt ?? a.updatedAt;
  final stampB = b.clientUpdatedAt ?? b.updatedAt;
  if (stampA == null || stampB == null) return 0;
  return stampB.compareTo(stampA);
}
