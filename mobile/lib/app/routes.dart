/// Every path in the app. Screens navigate with these, never with string literals.
abstract final class Routes {
  static const signIn = '/sign-in';

  /// A new company with this person as its owner. Open only while signed out.
  static const signUp = '/sign-up';

  /// True for the two pages a signed-out person may be on.
  static bool isAuth(String path) => path == signIn || path == signUp;
  static const projects = '/projects';

  /// The picker, opened on purpose from Home. An owner with a single project
  /// can then still see it, to add a second one; otherwise one project skips it.
  static const projectsSwitch = '/projects?switch=1';

  /// Create a project. Needs `project.manage` and network.
  static const projectForm = '/projects/new';

  /// BOQ lines of the current project, and adding one.
  static const boq = '/boq';
  static const boqForm = '/boq/form';

  /// A BOQ line's tenders, a quote for one of them, and choosing a quote.
  static String boqTenders(int boqItemId) => '/boq/$boqItemId/tenders';
  static String tenderQuote(int boqItemId, int tenderId) => '/boq/$boqItemId/tenders/$tenderId/quote';
  static String tenderAward(int boqItemId, int tenderId, int bidId) =>
      '/boq/$boqItemId/tenders/$tenderId/award?bid=$bidId';
  static const home = '/home';
  static const outbox = '/outbox';
  static const settings = '/settings';

  /// The person's own switches: which kinds of push, quiet hours, email and WhatsApp.
  static const notificationSettings = '/settings/notifications';

  /// News and reminders sent to this person. Optional query: `id`, the one a
  /// tapped notification was about. A contractor login reads it too.
  static const announcements = '/settings/announcements';
  static String announcementsAt(int id) => '$announcements?id=$id';

  /// Deleting this person's account. Open to everyone who can open Settings,
  /// contractor logins included.
  static const deleteAccount = '/settings/delete-account';

  /// Settings and its sub-pages, open whenever Settings is.
  static bool isSettings(String path) =>
      path == settings || path == notificationSettings || path == announcements || path == deleteAccount;

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

  /// Marking present by scanning a worker's QR card. Needs `attendance.manage` and `labour`.
  static const attendanceScan = '/attendance/scan';

  /// True for the two ways to mark attendance with the camera, and no other path.
  static bool isAttendanceCapture(String path) => path == attendanceScan || path == attendanceSelfie;

  /// Marking present with a photo taken at the site. Same rule as the scan.
  static const attendanceSelfie = '/attendance/selfie';

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

  /// Goods received against a purchase order. Optional query: `order`.
  static const materialReceiveOrder = '/material/receive-order';

  /// Material issued from a store.
  static const materialIssue = '/material/issue';

  /// True for the two entries that need `procurement.stock`, and no other path.
  static bool isStockEntry(String path) => path == materialReceiveOrder || path == materialIssue;

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

  /// True for the portal's own pages, and no other path.
  static bool isPortal(String path) => path == portal || path.startsWith('$portal/');
}
