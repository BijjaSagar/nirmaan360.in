import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../app/widgets/brand_mark.dart';
import '../../../app/widgets/language_switch.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/sign_in_cubit.dart';

class SignInScreen extends StatelessWidget {
  const SignInScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<SignInCubit>(
      create: (context) => SignInCubit(
        services: context.read<AppServices>(),
        gate: context.read<AppGate>(),
      ),
      child: const _SignInView(),
    );
  }
}

class _SignInView extends StatefulWidget {
  const _SignInView();

  @override
  State<_SignInView> createState() => _SignInViewState();
}

class _SignInViewState extends State<_SignInView> {
  // Always empty at first. Credentials are never restored or suggested here.
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final FocusNode _passwordFocus = FocusNode();
  bool _showPassword = false;
  int _shakeTick = 0;

  /// Height of everything except the graphic: language switch, brand row and
  /// the form card, at normal text size.
  static const double _fixedHeight = 500;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _passwordFocus.dispose();
    super.dispose();
  }

  void _submit() {
    FocusScope.of(context).unfocus();
    context.read<SignInCubit>().submit(email: _email.text, password: _password.text);
  }

  void _setLanguage(String code) {
    context.read<AppServices>().auth.setLocale(code);
  }

  ({String title, String body})? _problemText(SignInState state, AppLocalizations t) {
    return switch (state.problem) {
      null => null,
      SignInProblem.wrongCredentials => (title: t.shellSignInWrongTitle, body: t.shellSignInWrongBody),
      SignInProblem.noNetwork => (title: t.shellSignInNetworkTitle, body: t.shellSignInNetworkBody),
      SignInProblem.serverDown => (title: t.shellSignInServerTitle, body: t.shellSignInServerBody),
      SignInProblem.tooManyTries => (title: t.shellSignInTooManyTitle, body: t.shellSignInTooManyBody),
      SignInProblem.otherUsersWork => (
          title: t.shellSignInOtherUserTitle,
          body: t.shellSignInOtherUserBody(state.otherUserCount),
        ),
    };
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = context.select((AppGate gate) => gate.state.localeCode) ??
        Localizations.localeOf(context).languageCode;
    final bool expired = context.select((AppGate gate) => gate.state.isExpired);

    // The graphic's room comes from the whole screen, which the keyboard does
    // not change, so opening the keyboard reflows nothing and the fields stay
    // where they are until the focused one needs scrolling into view.
    final MediaQueryData media = MediaQuery.of(context);
    final double usable = media.size.height - media.padding.top - media.viewPadding.bottom;
    final double room = usable - _fixedHeight - (expired ? 130 : 0);

    return BlocConsumer<SignInCubit, SignInState>(
      listenWhen: (previous, next) => previous.problem != next.problem && next.problem != null,
      listener: (context, state) {
        N360Haptics.warning();
        setState(() => _shakeTick++);
      },
      builder: (context, state) {
        final problem = _problemText(state, t);
        return Scaffold(
          body: SafeArea(
            child: SingleChildScrollView(
              physics: const ClampingScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  LanguageSwitch(current: locale, onChanged: _setLanguage),
                  const SizedBox(height: N360Space.lg),
                  Row(
                    children: <Widget>[
                      const ExcludeSemantics(child: BrandMark(size: 52)),
                      const SizedBox(width: N360Space.md),
                      Expanded(child: Text(t.appName, style: N360Type.display)),
                    ],
                  ),
                  _Graphic(room: room),
                  StaggeredRise(
                    index: 1,
                    distance: 28,
                    child: LedgerCard(
                      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.lg, N360Space.lg, N360Space.lg),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: <Widget>[
                          Text(t.shellSignInWelcome, style: N360Type.display),
                          if (expired) ...<Widget>[
                            const SizedBox(height: N360Space.sm),
                            PaperToast(
                              message: t.shellSignInExpired,
                              kind: PaperToastKind.warning,
                              icon: Icons.shield_outlined,
                            ),
                          ],
                          const SizedBox(height: N360Space.sm),
                          GloveTextField(
                            label: t.shellSignInEmail,
                            controller: _email,
                            keyboardType: TextInputType.emailAddress,
                            textInputAction: TextInputAction.next,
                            textCapitalization: TextCapitalization.none,
                            enabled: !state.submitting,
                            errorText: state.emailMissing ? t.shellSignInEmailRequired : null,
                            onSubmitted: (_) => _passwordFocus.requestFocus(),
                          ),
                          const SizedBox(height: N360Space.sm),
                          GloveTextField(
                            label: t.shellSignInPassword,
                            controller: _password,
                            focusNode: _passwordFocus,
                            obscureText: !_showPassword,
                            textInputAction: TextInputAction.done,
                            textCapitalization: TextCapitalization.none,
                            enabled: !state.submitting,
                            errorText: state.passwordMissing ? t.shellSignInPasswordRequired : null,
                            onSubmitted: (_) => _submit(),
                            suffix: _ShowPassword(
                              shown: _showPassword,
                              showLabel: t.shellSignInShow,
                              hideLabel: t.shellSignInHide,
                              onToggle: () => setState(() => _showPassword = !_showPassword),
                            ),
                          ),
                          if (problem != null) ...<Widget>[
                            const SizedBox(height: N360Space.md),
                            _Shake(
                              tick: _shakeTick,
                              child: ErrorSlip(title: problem.title, message: problem.body),
                            ),
                          ],
                          const SizedBox(height: N360Space.lg),
                          GloveButton(
                            label: state.submitting ? t.shellSignInBusy : t.shellSignInButton,
                            onPressed: _submit,
                            loading: state.submitting,
                            large: true,
                            fullWidth: true,
                            icon: Icons.arrow_forward,
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

/// The building, quietly, in whatever room the screen has left. Only drawn when
/// there is real room for it, and always fixed in size once laid out.
class _Graphic extends StatelessWidget {
  const _Graphic({required this.room});

  final double room;

  static const double _minHeight = 130;
  static const double _maxHeight = 250;

  @override
  Widget build(BuildContext context) {
    if (room < _minHeight) return const SizedBox(height: N360Space.lg);
    final double height = math.min(room, _maxHeight);
    return SizedBox(
      height: room,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
        child: Center(
          child: SizedBox(
            height: height - N360Space.lg,
            child: const ExcludeSemantics(child: BuildingProgressArt(fraction: 0.62)),
          ),
        ),
      ),
    );
  }
}

/// The eye and its word, as wide as a thumb.
class _ShowPassword extends StatelessWidget {
  const _ShowPassword({
    required this.shown,
    required this.showLabel,
    required this.hideLabel,
    required this.onToggle,
  });

  final bool shown;
  final String showLabel;
  final String hideLabel;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final String label = shown ? hideLabel : showLabel;
    return Semantics(
      container: true,
      button: true,
      label: label,
      onTap: onToggle,
      child: ExcludeSemantics(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapDown: (_) => N360Haptics.select(),
          onTap: onToggle,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minWidth: 72, minHeight: N360Size.tapLarge),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: N360Space.sm),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: <Widget>[
                  Icon(shown ? Icons.visibility_off_outlined : Icons.visibility_outlined, size: 22, color: N360Colors.ink2),
                  const SizedBox(width: N360Space.xs),
                  Text(label, style: N360Type.label),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A short sideways nudge when something goes wrong. Transform only.
class _Shake extends StatefulWidget {
  const _Shake({required this.tick, required this.child});

  final int tick;
  final Widget child;

  @override
  State<_Shake> createState() => _ShakeState();
}

class _ShakeState extends State<_Shake> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _controller.duration = context.motion.of(N360Durations.stamp);
    if (!context.motion.reduced && !_controller.isAnimating && _controller.value == 0) {
      _controller.forward();
    }
  }

  @override
  void didUpdateWidget(covariant _Shake oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.tick != widget.tick && !context.motion.reduced) {
      _controller.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      child: widget.child,
      builder: (context, child) {
        final double t = _controller.value;
        final double dx = t >= 1 ? 0 : math.sin(t * math.pi * 5) * 7 * (1 - t);
        return Transform.translate(offset: Offset(dx, 0), child: child);
      },
    );
  }
}
