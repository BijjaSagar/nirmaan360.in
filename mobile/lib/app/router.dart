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
    redirect: (context, state) => gate.redirect(state.uri.path),
    routes: <RouteBase>[
      _route(Routes.signIn, (_) => const SignInScreen()),
      _route(Routes.projects, (_) => const ProjectPickerScreen()),
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
        routes: <RouteBase>[_route('form', (_) => const ExpenseFormScreen())],
      ),
    ],
  );
}
