import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/push/push_service.dart';
import '../../../l10n/generated/app_localizations.dart';

/// Explains push in plain words, once, and lets the person say yes or not now.
/// Answers true for yes. Closing it any other way counts as not now.
Future<bool?> showPushPrompt(BuildContext context) {
  return showLedgerSheet<bool>(context, isScrollControlled: true, builder: (context) => const PushPromptSheet());
}

class PushPromptSheet extends StatelessWidget {
  const PushPromptSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String body = context.select((AppGate gate) => gate.state.isContractor) ? t.pushPromptBodyContractor : t.pushPromptBody;
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(t.pushPromptTitle, style: N360Type.fit(N360Type.title, t.pushPromptTitle)),
              const SizedBox(height: N360Space.xs),
              Text(
                body,
                style: N360Type.fit(N360Type.body, body).copyWith(color: N360Colors.ink2),
              ),
              const SizedBox(height: N360Space.lg),
              GloveButton(
                label: t.pushPromptTurnOn,
                fullWidth: true,
                icon: Icons.notifications_none,
                onPressed: () => Navigator.of(context).pop(true),
              ),
              const SizedBox(height: N360Space.sm),
              GloveButton(
                label: t.pushPromptNotNow,
                kind: GloveButtonKind.secondary,
                large: true,
                fullWidth: true,
                onPressed: () => Navigator.of(context).pop(false),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Shows [PushPromptSheet] on the page it sits in, the first time push is
/// offered, a moment after the person arrives. It draws nothing itself.
///
/// It waits until push says the person has not been asked, and it never shows
/// on the sign-in screen, because push is not offered there. It sits in Home's shell and on the contractor portal's home. Asking is recorded as soon as the sheet answers,
/// so the sheet appears once.
class PushPromptHost extends StatefulWidget {
  const PushPromptHost({super.key, this.delay = const Duration(milliseconds: 1500)});

  final Duration delay;

  @override
  State<PushPromptHost> createState() => _PushPromptHostState();
}

class _PushPromptHostState extends State<PushPromptHost> {
  StreamSubscription<PushState>? _states;
  Timer? _timer;
  bool _shown = false;

  PushService get _push => context.read<AppServices>().push;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _states ??= _push.states.listen((_) => _arm());
    _arm();
  }

  @override
  void dispose() {
    _timer?.cancel();
    unawaited(_states?.cancel());
    super.dispose();
  }

  void _arm() {
    if (_shown || _push.state != PushState.askFirst || (_timer?.isActive ?? false)) return;
    _timer = Timer(widget.delay, _show);
  }

  Future<void> _show() async {
    if (!mounted || _shown || _push.state != PushState.askFirst) return;
    final ModalRoute<Object?>? route = ModalRoute.of(context);
    if (route != null && !route.isCurrent) {
      // Something else is on top. Look again shortly rather than cover it.
      _arm();
      return;
    }
    _shown = true;
    final PushService push = _push;
    final bool? yes = await showPushPrompt(context);
    if (yes == true) {
      await push.turnOn();
    } else {
      await push.declineForNow();
    }
  }

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
