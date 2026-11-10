import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/notification_settings_cubit.dart';
import '../data/notification_preferences.dart';

/// The person's own switches, kept on the server: which kinds of push reach
/// their phones, quiet hours, and email or WhatsApp where the office has them.
/// Online only. A change shows at once and is undone if it does not save.
class NotificationSettingsScreen extends StatelessWidget {
  const NotificationSettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final AppServices services = context.read<AppServices>();
    final AppGate gate = context.read<AppGate>();
    return BlocProvider<NotificationSettingsCubit>(
      create: (_) => NotificationSettingsCubit(
        NotificationPreferencesRepository(services.context.api, portal: () => gate.state.isContractor),
      )..load(),
      child: const _NotificationSettingsView(),
    );
  }
}

class _NotificationSettingsView extends StatelessWidget {
  const _NotificationSettingsView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocListener<NotificationSettingsCubit, NotificationSettingsState>(
      listenWhen: (before, now) => now.saveFailures > before.saveFailures,
      listener: (context, state) => showPaperToast(context, l.pushPrefsSaveFailed, kind: PaperToastKind.info),
      child: Scaffold(
        body: LedgerPaper(
          child: SafeArea(
            top: false,
            bottom: false,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                FieldTopBar(title: l.pushPrefsTitle),
                Expanded(
                  child: BlocBuilder<NotificationSettingsCubit, NotificationSettingsState>(
                    builder: (context, state) => ListView(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                      children: _children(context, l, state),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _children(BuildContext context, AppLocalizations l, NotificationSettingsState state) {
    final NotificationPreferences? p = state.preferences;
    if (state.load == NotificationSettingsLoad.loading || (p == null && state.load != NotificationSettingsLoad.failed)) {
      return <Widget>[LedgerSkeletonList(lines: 4, semanticsLabel: l.pushPrefsLoading)];
    }
    if (p == null) {
      final bool offline = state.failure is OfflineFailure;
      return <Widget>[
        EmptyLedger(
          title: offline ? l.pushPrefsOfflineTitle : l.pushPrefsFailedTitle,
          message: offline ? l.pushPrefsOfflineMessage : l.pushPrefsFailedMessage,
        ),
        const SizedBox(height: N360Space.md),
        GloveButton(
          key: const ValueKey<String>('push-prefs-retry'),
          label: l.pushPrefsRetry,
          kind: GloveButtonKind.secondary,
          icon: Icons.refresh,
          fullWidth: true,
          onPressed: () => context.read<NotificationSettingsCubit>().load(),
        ),
      ];
    }

    final NotificationSettingsCubit cubit = context.read<NotificationSettingsCubit>();
    final bool idle = !state.saving;
    final QuietHoursSetting quiet = p.quietHours;
    final List<({String key, ChannelSwitch value, String title, String hint})> others =
        <({String key, ChannelSwitch value, String title, String hint})>[
          if (p.email case final ChannelSwitch email when email.connected)
            (key: 'email', value: email, title: l.pushPrefsEmail, hint: l.pushPrefsEmailHint),
          if (p.whatsapp case final ChannelSwitch whatsapp when whatsapp.connected)
            (key: 'whatsapp', value: whatsapp, title: l.pushPrefsWhatsapp, hint: l.pushPrefsWhatsappHint),
        ];

    return <Widget>[
      if (!p.pushConnected) ...<Widget>[
        Text(l.pushPrefsNotConnected, style: N360Type.fit(N360Type.caption, l.pushPrefsNotConnected).copyWith(color: N360Colors.ink2)),
        const SizedBox(height: N360Space.md),
      ],
      LedgerList(
        header: SectionLabel(l.pushPrefsSection),
        children: <Widget>[
          _SwitchRow(
            key: const ValueKey<String>('push-prefs-push'),
            title: l.pushPrefsPush,
            hint: l.pushPrefsPushHint,
            value: p.pushEnabled,
            onChanged: idle ? cubit.setPush : null,
          ),
          for (final PushCategorySwitch category in p.categories)
            _SwitchRow(
              key: ValueKey<String>('push-prefs-${category.key}'),
              title: _label(l, category),
              hint: _hint(l, category),
              value: category.enabled,
              onChanged: idle && p.pushEnabled ? (on) => cubit.setCategory(category.key, on) : null,
            ),
        ],
      ),
      const SizedBox(height: N360Space.lg),
      LedgerList(
        header: SectionLabel(l.pushPrefsQuietSection),
        children: <Widget>[
          _SwitchRow(
            key: const ValueKey<String>('push-prefs-quiet'),
            title: l.pushPrefsQuiet,
            hint: l.pushPrefsQuietHint,
            value: quiet.enabled,
            onChanged: idle ? cubit.setQuietHours : null,
          ),
          if (quiet.enabled) ...<Widget>[
            _TimeRow(
              key: const ValueKey<String>('push-prefs-quiet-from'),
              title: l.pushPrefsQuietFrom,
              time: quiet.from,
              onPicked: idle ? cubit.setQuietFrom : null,
            ),
            _TimeRow(
              key: const ValueKey<String>('push-prefs-quiet-until'),
              title: l.pushPrefsQuietUntil,
              time: quiet.until,
              onPicked: idle ? cubit.setQuietUntil : null,
            ),
          ],
        ],
      ),
      if (others.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.lg),
        LedgerList(
          header: SectionLabel(l.pushPrefsOtherSection),
          children: <Widget>[
            for (final other in others)
              _SwitchRow(
                key: ValueKey<String>('push-prefs-${other.key}'),
                title: other.title,
                hint: other.hint,
                value: other.value.enabled,
                onChanged: idle ? (on) => cubit.setChannel(other.key, on) : null,
              ),
          ],
        ),
      ],
    ];
  }

  /// The app's own words for the kinds it knows; the server's for a new one.
  static String _label(AppLocalizations l, PushCategorySwitch category) => switch (category.key) {
    'alerts' => l.pushPrefsAlerts,
    'approvals' => l.pushPrefsApprovals,
    'payments' => l.pushPrefsPayments,
    'material' => l.pushPrefsMaterial,
    'issues' => l.pushPrefsIssues,
    'contractor' => l.pushPrefsContractor,
    'announcements' => l.pushPrefsAnnouncements,
    _ => category.label,
  };

  static String _hint(AppLocalizations l, PushCategorySwitch category) => switch (category.key) {
    'alerts' => l.pushPrefsAlertsHint,
    'approvals' => l.pushPrefsApprovalsHint,
    'payments' => l.pushPrefsPaymentsHint,
    'material' => l.pushPrefsMaterialHint,
    'issues' => l.pushPrefsIssuesHint,
    'contractor' => l.pushPrefsContractorHint,
    'announcements' => l.pushPrefsAnnouncementsHint,
    _ => category.hint,
  };
}

/// A switch with its whole explanation, never cut short. The whole row turns it.
class _SwitchRow extends StatelessWidget {
  const _SwitchRow({super.key, required this.title, required this.hint, required this.value, required this.onChanged});

  final String title;
  final String hint;
  final bool value;

  /// Null shows the switch as unavailable.
  final ValueChanged<bool>? onChanged;

  @override
  Widget build(BuildContext context) {
    final ValueChanged<bool>? change = onChanged;
    return MergeSemantics(
      child: InkWell(
        onTap: change == null ? null : () => change(!value),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: N360Size.tap),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.tile),
            child: Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(title, style: N360Type.fit(N360Type.bodyStrong, title)),
                      if (hint.isNotEmpty)
                        Text(hint, style: N360Type.fit(N360Type.caption, hint).copyWith(color: N360Colors.ink2)),
                    ],
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                Switch(value: value, onChanged: change),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A time of day, `HH:MM` on the company's clock, changed with the phone's own clock picker.
class _TimeRow extends StatelessWidget {
  const _TimeRow({super.key, required this.title, required this.time, required this.onPicked});

  final String title;
  final String time;
  final ValueChanged<String>? onPicked;

  static TimeOfDay _parse(String time) {
    final List<String> parts = time.split(':');
    return TimeOfDay(hour: int.tryParse(parts.first) ?? 0, minute: parts.length > 1 ? int.tryParse(parts[1]) ?? 0 : 0);
  }

  static String _text(TimeOfDay time) =>
      '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}';

  Future<void> _pick(BuildContext context, ValueChanged<String> picked) async {
    final TimeOfDay? chosen = await showTimePicker(context: context, initialTime: _parse(time));
    if (chosen == null) return;
    final String text = _text(chosen);
    if (text != time) picked(text);
  }

  @override
  Widget build(BuildContext context) {
    final ValueChanged<String>? picked = onPicked;
    final String shown = MaterialLocalizations.of(context).formatTimeOfDay(_parse(time));
    return LedgerListTile(
      leading: const Icon(Icons.schedule, size: N360IconSize.lg, color: N360Colors.ink2),
      title: title,
      trailing: Text(shown, style: N360Type.figure),
      semanticsLabel: '$title, $shown',
      onTap: picked == null ? null : () => _pick(context, picked),
    );
  }
}
