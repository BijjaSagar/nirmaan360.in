// Shared harness for the attendance, issues and petty cash widget tests.
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

export '../../../support/test_app.dart';

/// A phone-sized screen with everything the feature screens need above it.
Widget fieldApp(
  AppServices services,
  Widget home, {
  Locale locale = const Locale('en'),
  bool disableAnimations = true,
  double textScale = 1,
}) {
  return RepositoryProvider<AppServices>.value(
    value: services,
    child: MaterialApp(
      theme: buildN360Theme(),
      debugShowCheckedModeBanner: false,
      locale: locale,
      localizationsDelegates: const <LocalizationsDelegate<Object?>>[
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: AppLocalizations.supportedLocales,
      builder: (context, navigator) => MediaQuery(
        data: MediaQuery.of(context).copyWith(
          disableAnimations: disableAnimations,
          textScaler: TextScaler.linear(textScale),
        ),
        child: LedgerPaper.root(child: navigator),
      ),
      home: home,
    ),
  );
}

/// Like [fieldApp], for screens that navigate with `context.push`.
///
/// [routes] maps paths to screens. Tests can pass a [Routes] path as [initial].
Widget fieldRouterApp(
  AppServices services, {
  required Map<String, Widget Function(GoRouterState state)> routes,
  required String initial,
  Locale locale = const Locale('en'),
  bool disableAnimations = true,
}) {
  final router = GoRouter(
    initialLocation: initial,
    routes: <RouteBase>[
      for (final entry in routes.entries)
        GoRoute(
          path: entry.key,
          builder: (context, state) => entry.value(state),
        ),
    ],
  );
  return RepositoryProvider<AppServices>.value(
    value: services,
    child: MaterialApp.router(
      theme: buildN360Theme(),
      debugShowCheckedModeBanner: false,
      locale: locale,
      localizationsDelegates: const <LocalizationsDelegate<Object?>>[
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: AppLocalizations.supportedLocales,
      routerConfig: router,
      builder: (context, navigator) => MediaQuery(
        data: MediaQuery.of(context).copyWith(disableAnimations: disableAnimations),
        child: LedgerPaper.root(child: navigator!),
      ),
    ),
  );
}

/// Sizes the test view like a cheap Android phone and restores it afterwards.
void setPhone(WidgetTester tester, {double width = 360, double height = 800, double ratio = 3}) {
  tester.view.devicePixelRatio = ratio;
  tester.view.physicalSize = Size(width, height) * ratio;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

/// Waits out `showPaperToast`'s timer so a test can end cleanly.
Future<void> settleToasts(WidgetTester tester) async {
  await tester.pump(const Duration(seconds: 5));
  await tester.pump(const Duration(milliseconds: 400));
}
