import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/push/push_service.dart';
import '../../../l10n/generated/app_localizations.dart';
import 'push_prompt.dart';

/// The row in Settings that says whether alerts reach this phone, and turns
/// them on. This is the only way back after "Not now". Once on, it opens the
/// person's own switches: which kinds, quiet hours, email and WhatsApp.
class PushSettingsTile extends StatelessWidget {
  const PushSettingsTile({super.key, required this.state});

  final PushState state;

  Future<void> _turnOn(BuildContext context) async {
    final PushService push = context.read<AppServices>().push;
    if (state == PushState.askFirst) {
      final bool? yes = await showPushPrompt(context);
      if (yes == true) {
        await push.turnOn();
      } else {
        await push.declineForNow();
      }
      return;
    }
    await push.turnOn();
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final bool on = state == PushState.on;
    final bool blocked = state == PushState.blocked;
    final bool contractor = context.select((AppGate gate) => gate.state.isContractor);
    final String subtitle = on
        ? (contractor ? t.pushSettingsOnContractor : t.pushSettingsOn)
        : blocked
        ? t.pushSettingsBlockedShort
        : t.pushSettingsOff;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        LedgerListTile(
          key: const ValueKey<String>('settings-push'),
          leading: Icon(
            on ? Icons.notifications_active_outlined : Icons.notifications_off_outlined,
            size: N360IconSize.lg,
            color: N360Colors.ink2,
          ),
          title: t.pushSettingsTitle,
          subtitle: subtitle,
          trailing: Text(on ? t.pushSettingsIsOn : t.pushSettingsTurnOn, style: N360Type.bodyStrong),
          onTap: on ? () => context.push(Routes.notificationSettings) : () => _turnOn(context),
        ),
        if (blocked)
          Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.card, 0, N360Space.card, N360Space.tile),
            child: Text(
              t.pushSettingsBlocked,
              style: N360Type.fit(N360Type.caption, t.pushSettingsBlocked).copyWith(color: N360Colors.ink2),
            ),
          ),
      ],
    );
  }
}
