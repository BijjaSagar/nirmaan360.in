import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/app_services.dart';
import '../core/design/design.dart';
import '../core/push/local_lookup.dart';
import '../core/push/push_message.dart';
import '../core/push/push_target.dart';
import 'app_gate.dart';
import 'routes.dart';

/// Carries what push brings into the app: a tap opens the screen it points at,
/// and a message that arrives while the app is open shows as a short note and
/// starts a sync, so the figures behind it are fresh.
///
/// A tap waits until the app knows who is signed in. It is dropped if nobody is:
/// a notification is never a way past the sign-in screen. Where it leads is
/// worked out by [PushRouter] against the same gate the router uses, so someone
/// who cannot open a screen lands on Home.
class PushNavigator {
  PushNavigator({
    required this.services,
    required this.gate,
    required this.router,
    this.toastTime = const Duration(seconds: 5),
  });

  final AppServices services;
  final AppGate gate;
  final GoRouter router;
  final Duration toastTime;

  final List<StreamSubscription<Object?>> _subscriptions = <StreamSubscription<Object?>>[];
  PushMessage? _pending;
  bool _disposed = false;

  void start() {
    _subscriptions
      ..add(services.push.taps.listen(_onTap))
      ..add(services.push.foreground.listen(_onForeground))
      ..add(gate.stream.listen((_) => _flush()));
  }

  void dispose() {
    _disposed = true;
    for (final StreamSubscription<Object?> subscription in _subscriptions) {
      unawaited(subscription.cancel());
    }
  }

  void _onTap(PushMessage message) {
    _pending = message;
    _flush();
  }

  void _flush() {
    final PushMessage? message = _pending;
    if (message == null || _disposed) return;
    final GateState state = gate.state;
    if (!state.ready) return;
    _pending = null;
    if (!state.isSignedIn || state.signingIn) return;
    unawaited(_open(message));
  }

  Future<void> _open(PushMessage message) async {
    final PushTarget? target = PushTarget.fromMessage(message);
    await _showProjectOf(target);
    // A contractor login has no Home: its portal is where it starts.
    final String home = gate.state.isContractor ? Routes.portal : Routes.home;
    final PushRouter pushRouter = PushRouter(
      allows: (location) => gate.redirect(location) == null,
      lookup: (kind, id) => localUuidFor(services.db, kind, id),
      home: home,
    );
    final String path = await pushRouter.pathFor(target);
    if (_disposed) return;
    // From Home, so Back leads somewhere sensible whatever the app was doing.
    router.go(home);
    if (path != home) unawaited(router.push<void>(path));
    _syncSoon();
  }

  /// A message about another project of this company shows that project.
  Future<void> _showProjectOf(PushTarget? target) async {
    final int? projectId = target?.projectId;
    if (projectId == null || gate.state.selectedProjectId == projectId) return;
    final bool known = gate.state.projects?.any((project) => project.id == projectId) ?? false;
    if (known) await gate.selectProject(projectId);
  }

  void _onForeground(PushMessage message) {
    final GateState state = gate.state;
    if (_disposed || !state.ready || !state.isSignedIn) return;
    _syncSoon();
    final OverlayState? overlay = router.routerDelegate.navigatorKey.currentState?.overlay;
    if (overlay == null || !overlay.mounted) return;
    final String text = <String?>[message.title, message.body]
        .where((part) => part != null && part.isNotEmpty)
        .join(' · ');
    if (text.isEmpty) return;
    insertPaperToast(overlay, text, icon: Icons.notifications_none, duration: toastTime);
  }

  void _syncSoon() {
    unawaited(services.sync.syncNow().then<void>((_) {}).catchError((Object _) {}));
  }
}
