import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../core/app_services.dart';
import 'app_frame.dart';
import 'app_gate.dart';
import 'push_navigator.dart';
import 'router.dart';
import 'routes.dart';
import 'shell/sync_status_cubit.dart';
import 'widgets/boot_paper.dart';

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

class _Nirmaan360AppState extends State<Nirmaan360App> with WidgetsBindingObserver {
  late final AppGate _gate = widget.gate ?? AppGate(widget.services);
  late final SyncStatusCubit _syncStatus = SyncStatusCubit(widget.services.syncCenter);
  GoRouter? _router;
  PushNavigator? _pushNavigator;
  StreamSubscription<Object>? _refusals;
  bool _renewalOpen = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // A write turned away because the licence has ended opens the page that says
    // why, whichever screen it came from. The forms already redirect there; this
    // covers a button that writes in place, and a licence that ends under an open form.
    _refusals = widget.services.offlinePlan.refusals.listen((_) => _openRenewal());
  }

  void _openRenewal() {
    final GoRouter? router = _router;
    if (router == null || _renewalOpen) return;
    if (router.routeInformationProvider.value.uri.path == Routes.licenceEnded) return;
    _renewalOpen = true;
    unawaited(router.push<void>(Routes.licenceEnded).whenComplete(() => _renewalOpen = false));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // The person may have changed the phone's notification setting while away.
    if (state == AppLifecycleState.resumed) unawaited(widget.services.push.refresh());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_refusals?.cancel());
    _pushNavigator?.dispose();
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
            _pushNavigator ??= PushNavigator(services: widget.services, gate: _gate, router: router)..start();
            return N360AppFrame(router: router, locale: state.locale);
          },
        ),
      ),
    );
  }
}
