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

  static const progress = '/progress';

  /// Today's work. Optional query: `floor`.
  static const progressForm = '/progress/form';
  static String progressFloor(int id) => '/progress/floor/$id';
  static String progressBeforeFinishing(int floorId) => '/progress/floor/$floorId/before-finishing';
  static String progressStage(int floorId, String trade) => '/progress/stage/$floorId/$trade';

  /// The site dashboard and open alerts. Optional query: `tab=alerts`.
  static const siteDashboard = '/site';

  static const contractors = '/contractors';

  /// Optional query: `work_order`.
  static const contractorRequest = '/contractors/request';
  static String contractorDetail(int workOrderId) => '/contractors/$workOrderId';

  /// The short list of site tools: inspections, and the machine log. Home has one
  /// row for it, so two more tiles do not crowd the page.
  static const siteTools = '/tools';

  /// Checklists filled in on site, and the inspections made from them.
  static const inspections = '/inspections';

  /// An inspection being filled in on this phone. Query: `uuid`.
  static const inspectionFill = '/inspections/fill';

  /// By the server's id, or by the uuid of one finished here and not yet sent.
  static String inspectionDetail(String key) => '/inspections/$key';

  /// The daily machine log: what each machine worked, and a quick entry.
  static const machines = '/machines';

  /// A day's log for one machine. Optional query: `asset`.
  static const machineLogForm = '/machines/form';

  /// True for the machine log pages, and no other path.
  static bool isMachines(String path) => path == machines || path.startsWith('$machines/');

  /// True for the inspection pages, and no other path.
  static bool isInspections(String path) => path == inspections || path.startsWith('$inspections/');

  static const material = '/material';

  /// Optional query: `material`.
  static const materialReceive = '/material/receive';
  static const materialUse = '/material/use';
  static const materialCount = '/material/count';

  /// Recorded material prices, each with its date. Needs `money.view`.
  static const prices = '/prices';
  static String priceMaterial(int materialId) => '/prices/$materialId';

  /// True for the price pages, and no other path.
  static bool isPrices(String path) => path == prices || path.startsWith('$prices/');

  /// The contractor portal: where a contractor login lives. A member of
  /// staff never reaches it, and a contractor reaches nothing else but settings.
  static const portal = '/portal';
  static String portalContract(int workOrderId) => '/portal/contracts/$workOrderId';
  static const portalPayments = '/portal/payments';
  static const portalBills = '/portal/bills';
  static String portalBill(int billId) => '/portal/bills/$billId';

  /// The offline plan: what is on this phone, the licence, the export.
  static const offlinePlan = '/offline-plan';

  /// Asks the owner before anything goes to the server.
  static const offlineUpload = '/offline-plan/upload';

  static bool isOfflinePlan(String path) => path == offlinePlan || path == offlineUpload;

  /// What a company on the offline plan has: site, measurement, labour and
  /// petty cash, and the screens that set a project up. Approvals, the outbox
  /// and every other module are not part of it.
  static bool isLocalFeature(String path) =>
      const <String>{home, projects, projectForm, boq, boqForm, settings, attendance}.contains(path) ||
      path == dprList ||
      path.startsWith('$dprList/') ||
      path == measurements ||
      path.startsWith('$measurements/') ||
      path == issues ||
      path.startsWith('$issues/') ||
      path == pettyCash ||
      path.startsWith('$pettyCash/');

  /// True for the portal's own pages, and no other path.
  static bool isPortal(String path) => path == portal || path.startsWith('$portal/');
}
