// The shell on its own: the real gate, frame, bottom bar and the screens the
// shell owns, with a placeholder where another feature's page would be.
//
// It compiles even while another feature is mid-change, so the shell screens
// can be rendered and judged at any time. Behaviour that crosses into other
// features (opening a report, a measurement) belongs in the tests that pump
// `Nirmaan360App`.
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/app_frame.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/app/shell/home_shell.dart';
import 'package:nirmaan360/app/shell/sync_status_cubit.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/projects/view/project_form_screen.dart';
import 'package:nirmaan360/features/projects/view/project_picker_screen.dart';
import 'package:nirmaan360/features/settings/view/settings_screen.dart';
import 'package:nirmaan360/features/sync_center/view/sync_center_screen.dart';

import '../support/test_app.dart';

Page<void> _page(GoRouterState state, Widget child) => N360Page<void>(key: state.pageKey, child: child);

GoRoute _route(String path, Widget child, {List<RouteBase> routes = const <RouteBase>[]}) {
  return GoRoute(path: path, pageBuilder: (context, state) => _page(state, child), routes: routes);
}

/// Stands in for a page another feature owns.
class ElsewherePage extends StatelessWidget {
  const ElsewherePage(this.name, {super.key});

  final String name;

  @override
  Widget build(BuildContext context) => Scaffold(body: Center(child: Text(name)));
}

/// The shell's own routes, with [ElsewherePage] for the rest.
GoRouter buildPolishRouter(AppGate gate, {String initialLocation = Routes.home}) {
  return GoRouter(
    initialLocation: initialLocation,
    refreshListenable: gate.refreshListenable,
    redirect: (context, state) => gate.redirect(state.uri),
    errorPageBuilder: (context, state) => _page(state, ElsewherePage(state.uri.path)),
    routes: <RouteBase>[
      _route(Routes.signIn, const SignInScreen()),
      _route(
        Routes.projects,
        const ProjectPickerScreen(),
        routes: <RouteBase>[_route('new', const ProjectFormScreen())],
      ),
      _route(Routes.settings, const SettingsScreen()),
      StatefulShellRoute.indexedStack(
        pageBuilder: (context, state, shell) => _page(state, HomeShell(navigationShell: shell)),
        branches: <StatefulShellBranch>[
          StatefulShellBranch(routes: <RouteBase>[_route(Routes.home, const HomeScreen())]),
          StatefulShellBranch(routes: <RouteBase>[_route(Routes.measurements, const ElsewherePage('measure'))]),
          StatefulShellBranch(routes: <RouteBase>[_route(Routes.approvals, const ElsewherePage('approvals'))]),
          StatefulShellBranch(routes: <RouteBase>[_route(Routes.outbox, const SyncCenterScreen())]),
        ],
      ),
    ],
  );
}

/// `Nirmaan360App` with the shell-only router.
class PolishApp extends StatefulWidget {
  const PolishApp({super.key, required this.services, this.initialLocation = Routes.home});

  final AppServices services;
  final String initialLocation;

  @override
  State<PolishApp> createState() => _PolishAppState();
}

class _PolishAppState extends State<PolishApp> {
  late final AppGate _gate = AppGate(widget.services);
  late final SyncStatusCubit _syncStatus = SyncStatusCubit(widget.services.syncCenter);
  GoRouter? _router;

  @override
  void dispose() {
    _router?.dispose();
    _gate.close();
    _syncStatus.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return RepositoryProvider<AppServices>.value(
      value: widget.services,
      child: MultiBlocProvider(
        providers: <BlocProvider<dynamic>>[
          BlocProvider<AppGate>.value(value: _gate),
          BlocProvider<SyncStatusCubit>.value(value: _syncStatus),
        ],
        child: BlocBuilder<AppGate, GateState>(
          buildWhen: (previous, next) => previous.ready != next.ready || previous.localeCode != next.localeCode,
          builder: (context, state) {
            if (!state.ready) return const ColoredBox(color: N360Colors.paper);
            final GoRouter router = _router ??= buildPolishRouter(_gate, initialLocation: widget.initialLocation);
            return N360AppFrame(router: router, locale: state.locale);
          },
        ),
      ),
    );
  }
}

/// A phone to render on: its logical size and the system bars it keeps.
class Phone {
  const Phone(this.name, this.size, {required this.safeTop, required this.safeBottom});

  /// A compact Android phone with three-button navigation.
  static const Phone android = Phone('360', Size(360, 800), safeTop: 24, safeBottom: 0);

  /// A six-inch iPhone with the island and the home indicator.
  static const Phone iphone = Phone('390', Size(390, 844), safeTop: 59, safeBottom: 34);

  final String name;
  final Size size;
  final double safeTop;
  final double safeBottom;
}

/// Pumps the shell-only app on [app]'s repositories, on [phone].
Future<void> pumpPolishApp(
  WidgetTester tester,
  TestApp app, {
  String location = Routes.home,
  String? locale,
  bool disableAnimations = true,
  Phone phone = Phone.android,
  double pixelRatio = 1,
  double textScale = 1,
}) async {
  if (app.services.session.current.status == SessionStatus.restoring) {
    await app.services.auth.restoreSession();
  }
  if (locale != null) await app.services.auth.setLocale(locale);
  tester.view.devicePixelRatio = pixelRatio;
  tester.view.physicalSize = phone.size * pixelRatio;
  tester.view.padding = FakeViewPadding(top: phone.safeTop * pixelRatio, bottom: phone.safeBottom * pixelRatio);
  tester.view.viewPadding = FakeViewPadding(top: phone.safeTop * pixelRatio, bottom: phone.safeBottom * pixelRatio);
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  addTearDown(tester.view.resetPadding);
  addTearDown(tester.view.resetViewPadding);
  tester.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
  if (disableAnimations) {
    tester.platformDispatcher.accessibilityFeaturesTestValue =
        const FakeAccessibilityFeatures(disableAnimations: true);
    addTearDown(tester.platformDispatcher.clearAccessibilityFeaturesTestValue);
  }

  await tester.pumpWidget(PolishApp(services: app.services, initialLocation: location));
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 800));
  await pumpDrift(tester, 5);
}
