import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/app_info.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/back_header.dart';
import '../../../app/widgets/language_switch.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/session/current_user.dart';
import '../../../core/sync/failures.dart';
import '../../../l10n/generated/app_localizations.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  Future<void> _signOut(BuildContext context) async {
    final auth = context.read<AppServices>().auth;
    final bool online = context.read<SyncStatusCubit>().state.online;
    final int unsent = await auth.unsentWorkCount();
    if (!context.mounted) return;

    if (unsent > 0) {
      await _warnUnsent(context, unsent);
      return;
    }

    final bool? confirmed = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (context) => _SignOutSheet(online: online),
    );
    if (confirmed != true) return;
    try {
      await auth.signOut();
    } on UnsentWorkFailure catch (failure) {
      // Something was queued between the check and the tap.
      if (context.mounted) await _warnUnsent(context, failure.count);
    }
  }

  Future<void> _warnUnsent(BuildContext context, int count) async {
    final auth = context.read<AppServices>().auth;
    final _UnsentChoice? choice = await showLedgerSheet<_UnsentChoice>(
      context,
      isScrollControlled: true,
      builder: (context) => _UnsentSheet(count: count),
    );
    if (!context.mounted) return;
    switch (choice) {
      case _UnsentChoice.sendFirst:
        context.go(Routes.outbox);
      case _UnsentChoice.loseThem:
        await auth.signOut(discardUnsent: true);
      case null:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final CurrentUser? user = context.select((AppGate gate) => gate.state.user);
    final String locale = context.select((AppGate gate) => gate.state.localeCode) ??
        Localizations.localeOf(context).languageCode;

    return Scaffold(
      body: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            PageHeader(title: t.shellSettings),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                children: <Widget>[
                  StaggeredRise(
                    index: 0,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        if (user != null) ...<Widget>[
                          _WhoList(user: user, eyebrow: t.shellSettingsSignedIn),
                          const SizedBox(height: N360Space.lg),
                        ],
                        SectionLabel(t.shellSettingsLanguage),
                        const SizedBox(height: N360Space.xs),
                        LanguageSwitch(
                          current: locale,
                          onChanged: (code) => context.read<AppServices>().auth.setLocale(code),
                        ),
                        const SizedBox(height: N360Space.lg),
                        LedgerList(
                          children: <Widget>[
                            LedgerListTile(
                              leading: const Icon(Icons.info_outline, size: N360IconSize.lg, color: N360Colors.ink2),
                              title: t.shellSettingsVersion,
                              trailing: Text(AppInfo.label, style: N360Type.figure),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            N360ActionBar(
              child: GloveButton(
                label: t.shellSignOut,
                kind: GloveButtonKind.danger,
                icon: Icons.logout,
                fullWidth: true,
                onPressed: () => _signOut(context),
              ),
            ),
          ],
        ),
    );
  }
}

/// Who is signed in: name and role, the firm, the email, one row each.
class _WhoList extends StatelessWidget {
  const _WhoList({required this.user, required this.eyebrow});

  final CurrentUser user;
  final String eyebrow;

  @override
  Widget build(BuildContext context) {
    return LedgerList(
      header: SectionLabel(eyebrow),
      children: <Widget>[
        LedgerListTile(
          leading: const Icon(Icons.person_outline, size: N360IconSize.lg, color: N360Colors.ink2),
          title: user.name,
          subtitle: user.roleLabel.isEmpty ? null : user.roleLabel,
        ),
        if (user.organizationName.isNotEmpty)
          LedgerListTile(
            leading: const Icon(Icons.business_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
            title: user.organizationName,
          ),
        if (user.email.isNotEmpty)
          LedgerListTile(
            leading: const Icon(Icons.mail_outline, size: N360IconSize.lg, color: N360Colors.ink2),
            title: user.email,
          ),
      ],
    );
  }
}

class _SignOutSheet extends StatelessWidget {
  const _SignOutSheet({required this.online});

  final bool online;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(t.shellSignOutConfirmTitle, style: N360Type.fit(N360Type.title, t.shellSignOutConfirmTitle)),
            const SizedBox(height: N360Space.xs),
            Text(
              t.shellSignOutConfirmBody,
              style: N360Type.fit(N360Type.body, t.shellSignOutConfirmBody).copyWith(color: N360Colors.ink2),
            ),
            const SizedBox(height: N360Space.lg),
            GloveButton(
              label: t.shellSignOut,
              kind: GloveButtonKind.danger,
              fullWidth: true,
              icon: Icons.logout,
              onPressed: () => Navigator.of(context).pop(true),
            ),
            const SizedBox(height: N360Space.sm),
            GloveButton(
              label: t.commonCancel,
              kind: GloveButtonKind.secondary,
              large: true,
              fullWidth: true,
              onPressed: () => Navigator.of(context).pop(false),
            ),
          ],
        ),
      ),
    );
  }
}

enum _UnsentChoice { sendFirst, loseThem }

/// Warns with the count and asks for a deliberate second step: the destructive
/// control must be held down, so it can never happen by a stray tap.
class _UnsentSheet extends StatelessWidget {
  const _UnsentSheet({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String title = t.shellSignOutUnsentTitle(count);
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Padding(
                  padding: EdgeInsets.only(top: 1, right: N360Space.sm),
                  child: Icon(Icons.warning_amber, size: N360IconSize.xxl, color: N360Colors.stampRed),
                ),
                Expanded(
                  child: Text(title, style: N360Type.fit(N360Type.title, title).copyWith(color: N360Colors.stampRed)),
                ),
              ],
            ),
            const SizedBox(height: N360Space.xs),
            Text(t.shellSignOutUnsentBody, style: N360Type.fit(N360Type.bodyLarge, t.shellSignOutUnsentBody)),
            const SizedBox(height: N360Space.lg),
            _HoldToConfirm(
              label: t.shellSignOutHold,
              spokenLabel: t.shellSignOutDiscard,
              onConfirmed: () => Navigator.of(context).pop(_UnsentChoice.loseThem),
            ),
            const SizedBox(height: N360Space.sm),
            GloveButton(
              label: t.shellSignOutSendFirst,
              large: true,
              fullWidth: true,
              icon: Icons.outbox,
              onPressed: () => Navigator.of(context).pop(_UnsentChoice.sendFirst),
            ),
          ],
        ),
      ),
    );
  }
}

/// A red-edged bar that fills while pressed and fires only when full. Letting
/// go early empties it again. With animations off it is a plain button,
/// because the warning sheet is already a second step.
class _HoldToConfirm extends StatefulWidget {
  const _HoldToConfirm({required this.label, required this.spokenLabel, required this.onConfirmed});

  final String label;
  final String spokenLabel;
  final VoidCallback onConfirmed;

  @override
  State<_HoldToConfirm> createState() => _HoldToConfirmState();
}

class _HoldToConfirmState extends State<_HoldToConfirm> with SingleTickerProviderStateMixin {
  static const Duration _hold = Duration(milliseconds: 1400);

  late final AnimationController _fill;

  @override
  void initState() {
    super.initState();
    _fill = AnimationController(vsync: this, duration: _hold)
      ..addStatusListener((status) {
        if (status == AnimationStatus.completed) {
          N360Haptics.thud();
          widget.onConfirmed();
        }
      });
  }

  @override
  void dispose() {
    _fill.dispose();
    super.dispose();
  }

  void _start() {
    N360Haptics.tap();
    _fill.forward();
  }

  void _stop() {
    if (_fill.status != AnimationStatus.completed) _fill.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final bool reduced = context.motion.reduced;
    final Widget label = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.lg, vertical: N360Space.sm),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          const Icon(Icons.touch_app_outlined, size: N360IconSize.button, color: N360Colors.stampRed),
          const SizedBox(width: N360Space.sm),
          Flexible(
            child: Text(
              widget.label,
              textAlign: TextAlign.center,
              style: N360Type.fit(N360Type.button, widget.label).copyWith(color: N360Colors.stampRed),
            ),
          ),
        ],
      ),
    );

    final Widget face = ConstrainedBox(
      constraints: const BoxConstraints(minHeight: N360Size.tap),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: N360Colors.sheet,
          borderRadius: N360Radius.tileRadius,
          border: Border.all(color: N360Colors.stampRed, width: 1.25),
        ),
        child: ClipRRect(
          borderRadius: N360Radius.tileRadius,
          child: Stack(
            alignment: Alignment.center,
            children: <Widget>[
              if (!reduced)
                Positioned.fill(
                  child: AnimatedBuilder(
                    animation: _fill,
                    builder: (context, _) => FractionallySizedBox(
                      alignment: Alignment.centerLeft,
                      widthFactor: _fill.value,
                      heightFactor: 1,
                      child: ColoredBox(color: N360Colors.stampRed.withValues(alpha: 0.16)),
                    ),
                  ),
                ),
              label,
            ],
          ),
        ),
      ),
    );

    return Semantics(
      container: true,
      button: true,
      label: widget.spokenLabel,
      onTap: widget.onConfirmed,
      child: ExcludeSemantics(
        child: reduced
            ? GestureDetector(behavior: HitTestBehavior.opaque, onTap: widget.onConfirmed, child: face)
            : Listener(
                behavior: HitTestBehavior.opaque,
                onPointerDown: (_) => _start(),
                onPointerUp: (_) => _stop(),
                onPointerCancel: (_) => _stop(),
                child: face,
              ),
      ),
    );
  }
}
