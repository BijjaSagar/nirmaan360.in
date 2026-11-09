/// Every path in the app. Screens navigate with these, never with string literals.
abstract final class Routes {
  static const signIn = '/sign-in';
  static const projects = '/projects';

  /// The picker, opened on purpose from Home. An owner with a single project
  /// can then still see it, to add a second one; otherwise one project skips it.
  static const projectsSwitch = '/projects?switch=1';

  /// Create a project. Needs `project.manage` and network.
  static const projectForm = '/projects/new';

  /// BOQ lines of the current project, and adding one.
  static const boq = '/boq';
  static const boqForm = '/boq/form';
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
  static const walletForm = '/petty-cash/wallet';
  static const topUp = '/petty-cash/top-up';
}
