import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../core/app_services.dart';
import '../core/design/design.dart';
import '../l10n/generated/app_localizations.dart';
import 'app_gate.dart';
import 'router.dart';
import 'routes.dart';
import 'shell/sync_status_cubit.dart';
import 'widgets/boot_paper.dart';

const SystemUiOverlayStyle _chrome = SystemUiOverlayStyle(
  statusBarColor: Color(0x00000000),
  statusBarIconBrightness: Brightness.dark,
  statusBarBrightness: Brightness.light,
  systemNavigationBarColor: N360Colors.sheet,
  systemNavigationBarIconBrightness: Brightness.dark,
);

/// The root of the app. Shows plain paper until the local session is known,
/// then the real app, so nothing ever flashes the wrong screen.
class Nirmaan360App extends StatefulWidget {
  const Nirmaan360App({
    super.key,
    required this.services,
    this.gate,
    this.initialLocation = Routes.home,
  });

  final AppServices services;

  /// Supply the gate from `main` when boot errors have to reach it.
  final AppGate? gate;
  final String initialLocation;

  @override
  State<Nirmaan360App> createState() => _Nirmaan360AppState();
}

class _Nirmaan360AppState extends State<Nirmaan360App> {
  late final AppGate _gate = widget.gate ?? AppGate(widget.services);
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
          buildWhen: (previous, next) =>
              previous.ready != next.ready || previous.localeCode != next.localeCode,
          builder: (context, state) {
            if (!state.ready) return const BootPaper();
            final GoRouter router = _router ??= buildRouter(_gate, initialLocation: widget.initialLocation);
            return MaterialApp.router(
              debugShowCheckedModeBanner: false,
              onGenerateTitle: (context) => AppLocalizations.of(context).appName,
              theme: buildN360Theme(),
              routerConfig: router,
              locale: state.locale,
              localizationsDelegates: AppLocalizations.localizationsDelegates,
              supportedLocales: AppLocalizations.supportedLocales,
              builder: (context, child) => AnnotatedRegion<SystemUiOverlayStyle>(
                value: _chrome,
                child: LedgerPaper.root(child: child!),
              ),
            );
          },
        ),
      ),
    );
  }
}
