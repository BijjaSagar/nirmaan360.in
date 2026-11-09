import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

import '../core/design/design.dart';
import '../features/approvals/view/approval_detail_screen.dart';
import '../features/approvals/view/approvals_inbox_screen.dart';
import '../features/attendance/view/attendance_screen.dart';
import '../features/auth/view/sign_in_screen.dart';
import '../features/dpr/view/dpr_detail_screen.dart';
import '../features/dpr/view/dpr_form_screen.dart';
import '../features/dpr/view/dpr_list_screen.dart';
import '../features/home/view/home_screen.dart';
import '../features/issues/view/issue_detail_screen.dart';
import '../features/issues/view/issue_form_screen.dart';
import '../features/issues/view/issue_list_screen.dart';
import '../features/measurement/view/measurement_detail_screen.dart';
import '../features/measurement/view/measurement_form_screen.dart';
import '../features/measurement/view/measurement_list_screen.dart';
import '../features/petty_cash/view/expense_form_screen.dart';
import '../features/petty_cash/view/petty_cash_screen.dart';
import '../features/petty_cash/view/top_up_screen.dart';
import '../features/petty_cash/view/wallet_form_screen.dart';
import '../features/site_alerts/bloc/site_alerts_cubit.dart';
import '../features/site_alerts/view/site_dashboard_screen.dart';
import '../features/site_progress/view/floor_progress_screen.dart';
import '../features/site_progress/view/progress_form_screen.dart';
import '../features/site_progress/view/progress_screen.dart';
import '../features/site_progress/view/stage_row_screen.dart';
import '../features/material_control/bloc/material_form_cubit.dart';
import '../features/material_control/view/material_form_screen.dart';
import '../features/material_control/view/material_screen.dart';
import '../features/projects/view/boq_form_screen.dart';
import '../features/projects/view/boq_screen.dart';
import '../features/projects/view/project_form_screen.dart';
import '../features/projects/view/project_picker_screen.dart';
import '../features/settings/view/settings_screen.dart';
import '../features/sync_center/view/sync_center_screen.dart';
import 'app_gate.dart';
import 'routes.dart';
import 'shell/home_shell.dart';

Page<void> _page(GoRouterState state, Widget child) => N360Page<void>(key: state.pageKey, child: child);

GoRoute _route(String path, Widget Function(GoRouterState state) screen, {GlobalKey<NavigatorState>? parent, List<RouteBase> routes = const <RouteBase>[]}) {
  return GoRoute(
    path: path,
    parentNavigatorKey: parent,
    pageBuilder: (context, state) => _page(state, screen(state)),
    routes: routes,
  );
}

/// Every path in [Routes]. `form` routes come before `:uuid` routes, or the
/// `:uuid` route would swallow them.
GoRouter buildRouter(AppGate gate, {String initialLocation = Routes.home}) {
  final root = GlobalKey<NavigatorState>(debugLabel: 'root');

  return GoRouter(
    navigatorKey: root,
    initialLocation: initialLocation,
    refreshListenable: gate.refreshListenable,
    redirect: (context, state) => gate.redirect(state.uri),
    routes: <RouteBase>[
      _route(Routes.signIn, (_) => const SignInScreen()),
      _route(
        Routes.projects,
        (_) => const ProjectPickerScreen(),
        routes: <RouteBase>[_route('new', (_) => const ProjectFormScreen())],
      ),
      _route(
        Routes.boq,
        (_) => const BoqScreen(),
        routes: <RouteBase>[_route('form', (_) => const BoqFormScreen())],
      ),
      _route(Routes.settings, (_) => const SettingsScreen()),
      StatefulShellRoute.indexedStack(
        pageBuilder: (context, state, shell) => _page(state, HomeShell(navigationShell: shell)),
        branches: <StatefulShellBranch>[
          StatefulShellBranch(routes: <RouteBase>[_route(Routes.home, (_) => const HomeScreen())]),
          StatefulShellBranch(
            routes: <RouteBase>[
              _route(
                Routes.measurements,
                (_) => const MeasurementListScreen(),
                routes: <RouteBase>[
                  _route(
                    'form',
                    (s) => MeasurementFormScreen(clientUuid: s.uri.queryParameters['uuid']),
                    parent: root,
                  ),
                  _route(
                    ':uuid',
                    (s) => MeasurementDetailScreen(clientUuid: s.pathParameters['uuid']!),
                    parent: root,
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              _route(
                Routes.approvals,
                (_) => const ApprovalsInboxScreen(),
                routes: <RouteBase>[
                  _route(
                    ':id',
                    (s) => ApprovalDetailScreen(approvalId: int.parse(s.pathParameters['id']!)),
                    parent: root,
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(routes: <RouteBase>[_route(Routes.outbox, (_) => const SyncCenterScreen())]),
        ],
      ),
      _route(
        Routes.dprList,
        (_) => const DprListScreen(),
        routes: <RouteBase>[
          _route(
            'form',
            (s) => DprFormScreen(
              clientUuid: s.uri.queryParameters['uuid'],
              date: s.uri.queryParameters['date'],
            ),
          ),
          _route(':uuid', (s) => DprDetailScreen(clientUuid: s.pathParameters['uuid']!)),
        ],
      ),
      _route(Routes.attendance, (_) => const AttendanceScreen()),
      _route(
        Routes.siteDashboard,
        (s) => SiteDashboardScreen(tab: s.uri.queryParameters['tab'] == 'alerts' ? SiteTab.alerts : SiteTab.dashboard),
      ),
      _route(
        Routes.material,
        (_) => const MaterialScreen(),
        routes: <RouteBase>[
          for (final (path, kind) in <(String, MaterialFormKind)>[
            ('receive', MaterialFormKind.receive),
            ('use', MaterialFormKind.use),
            ('count', MaterialFormKind.count),
          ])
            _route(
              path,
              (s) => MaterialFormScreen(kind: kind, materialId: int.tryParse(s.uri.queryParameters['material'] ?? '')),
            ),
        ],
      ),
      _route(
        Routes.progress,
        (_) => const ProgressScreen(),
        routes: <RouteBase>[
          _route('form', (s) => ProgressFormScreen(floorId: int.tryParse(s.uri.queryParameters['floor'] ?? ''))),
          _route(
            'floor/:id',
            (s) => FloorProgressScreen(floorId: int.parse(s.pathParameters['id']!)),
            routes: <RouteBase>[
              _route('before-finishing', (s) => BeforeFinishingScreen(floorId: int.parse(s.pathParameters['id']!))),
            ],
          ),
          _route(
            'stage/:id/:trade',
            (s) => StageRowScreen(floorId: int.parse(s.pathParameters['id']!), tradeKey: s.pathParameters['trade']!),
          ),
        ],
      ),
      _route(
        Routes.issues,
        (_) => const IssueListScreen(),
        routes: <RouteBase>[
          _route('form', (_) => const IssueFormScreen()),
          _route(':uuid', (s) => IssueDetailScreen(clientUuid: s.pathParameters['uuid']!)),
        ],
      ),
      _route(
        Routes.pettyCash,
        (_) => const PettyCashScreen(),
        routes: <RouteBase>[
          _route('form', (_) => const ExpenseFormScreen()),
          _route('wallet', (_) => const WalletFormScreen()),
          _route('top-up', (_) => const TopUpScreen()),
        ],
      ),
    ],
  );
}
