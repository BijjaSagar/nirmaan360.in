/// Every path in the app. Screens navigate with these, never with string literals.
abstract final class Routes {
  static const signIn = '/sign-in';
  static const projects = '/projects';
  static const home = '/home';
  static const outbox = '/outbox';
  static const settings = '/settings';

  static const dprList = '/dpr';

  /// New or draft report. Optional query: `date`, `uuid`.
  static const dprForm = '/dpr/form';
  static String dprDetail(String uuid) => '/dpr/$uuid';

  static const measurements = '/measurements';

  /// New entry, or `?uuid=` to edit a returned one.
  static const measurementForm = '/measurements/form';
  static String measurementDetail(String uuid) => '/measurements/$uuid';

  static const approvals = '/approvals';
  static String approvalDetail(int id) => '/approvals/$id';

  static const attendance = '/attendance';

  static const issues = '/issues';
  static const issueForm = '/issues/form';
  static String issueDetail(String uuid) => '/issues/$uuid';

  static const pettyCash = '/petty-cash';
  static const expenseForm = '/petty-cash/form';
}
