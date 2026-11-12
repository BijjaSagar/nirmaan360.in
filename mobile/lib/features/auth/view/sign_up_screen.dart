import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/widgets/back_header.dart';
import '../../../app/widgets/show_password.dart';
import '../../../app/widgets/site_link.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/sign_up_cubit.dart';

/// A new company, with the person signing up as its owner. Nothing here is
/// sold or chosen: no plan, no price. The server sets the company up.
class SignUpScreen extends StatelessWidget {
  const SignUpScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<SignUpCubit>(
      create: (context) => SignUpCubit(
        services: context.read<AppServices>(),
        gate: context.read<AppGate>(),
      ),
      child: const _SignUpView(),
    );
  }
}

class _SignUpView extends StatefulWidget {
  const _SignUpView();

  @override
  State<_SignUpView> createState() => _SignUpViewState();
}

class _SignUpViewState extends State<_SignUpView> {
  // Always empty at first, like sign-in: nothing is restored or suggested.
  final TextEditingController _company = TextEditingController();
  final TextEditingController _name = TextEditingController();
  final TextEditingController _email = TextEditingController();
  final TextEditingController _phone = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final TextEditingController _confirm = TextEditingController();
  bool _showPassword = false;

  @override
  void dispose() {
    _company.dispose();
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  void _submit() {
    FocusScope.of(context).unfocus();
    final String locale = context.read<AppGate>().state.localeCode ?? Localizations.localeOf(context).languageCode;
    context.read<SignUpCubit>().submit(
          SignUpInput(
            company: _company.text,
            name: _name.text,
            email: _email.text,
            phone: _phone.text,
            password: _password.text,
            confirm: _confirm.text,
          ),
          locale: kAppLocales.contains(locale) ? locale : 'en',
        );
  }

  void _backToSignIn() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go(Routes.signIn);
    }
  }

  String? _fieldText(SignUpState state, SignUpField field, AppLocalizations t) {
    return switch (state.fieldErrors[field]) {
      null => null,
      SignUpFieldError.missing => switch (field) {
          SignUpField.company => t.shellSignUpCompanyMissing,
          SignUpField.name => t.shellSignUpNameMissing,
          SignUpField.email => t.shellSignInEmailRequired,
          _ => t.shellSignUpFieldInvalid,
        },
      SignUpFieldError.tooLong => t.shellSignUpTooLong(
          field == SignUpField.phone ? SignUpCubit.maxPhone : SignUpCubit.maxName,
        ),
      SignUpFieldError.invalid => field == SignUpField.email ? t.shellSignUpEmailInvalid : t.shellSignUpFieldInvalid,
      SignUpFieldError.tooShort => t.shellSignUpPasswordShort,
      SignUpFieldError.mismatch => t.shellSignUpPasswordMismatch,
      SignUpFieldError.taken => t.shellSignUpPhoneTaken,
    };
  }

  Widget? _problem(SignUpState state, AppLocalizations t) {
    return switch (state.problem) {
      null => null,
      SignUpProblem.emailTaken => ErrorSlip(
          title: t.shellSignUpEmailTakenTitle,
          message: t.shellSignUpEmailTakenBody,
          actionLabel: t.shellSignInButton,
          onAction: _backToSignIn,
        ),
      SignUpProblem.noNetwork => ErrorSlip(title: t.shellSignInNetworkTitle, message: t.shellSignInNetworkBody),
      SignUpProblem.tooManyTries => ErrorSlip(title: t.shellSignInTooManyTitle, message: t.shellSignInTooManyBody),
      SignUpProblem.otherUsersWork => ErrorSlip(
          title: t.shellSignInOtherUserTitle,
          message: t.shellSignInOtherUserBody(state.otherUserCount),
        ),
      SignUpProblem.serverDown => ErrorSlip(title: t.shellSignInServerTitle, message: t.shellSignInServerBody),
    };
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return BlocConsumer<SignUpCubit, SignUpState>(
      listenWhen: (previous, next) =>
          (previous.problem != next.problem && next.problem != null) ||
          (next.fieldErrors.isNotEmpty && previous.fieldErrors != next.fieldErrors),
      listener: (context, state) => N360Haptics.warning(),
      builder: (context, state) {
        final bool busy = state.submitting;
        final Widget? problem = _problem(state, t);
        return Scaffold(
          backgroundColor: N360Colors.sheet,
          body: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              PageHeader(title: t.shellSignUpTitle),
              Expanded(
                child: SingleChildScrollView(
                  physics: const ClampingScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.md),
                  child: StaggeredRise(
                    index: 0,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        Text(
                          t.shellSignUpIntro,
                          style: N360Type.fit(N360Type.body, t.shellSignUpIntro).copyWith(color: N360Colors.ink2),
                        ),
                        const SizedBox(height: N360Space.lg),
                        GloveTextField(
                          label: t.shellSignUpCompany,
                          controller: _company,
                          textInputAction: TextInputAction.next,
                          textCapitalization: TextCapitalization.words,
                          enabled: !busy,
                          errorText: _fieldText(state, SignUpField.company, t),
                        ),
                        const SizedBox(height: N360Space.md),
                        GloveTextField(
                          label: t.shellSignUpName,
                          controller: _name,
                          textInputAction: TextInputAction.next,
                          textCapitalization: TextCapitalization.words,
                          enabled: !busy,
                          errorText: _fieldText(state, SignUpField.name, t),
                        ),
                        const SizedBox(height: N360Space.md),
                        GloveTextField(
                          label: t.shellSignInEmail,
                          controller: _email,
                          keyboardType: TextInputType.emailAddress,
                          textInputAction: TextInputAction.next,
                          textCapitalization: TextCapitalization.none,
                          enabled: !busy,
                          errorText: _fieldText(state, SignUpField.email, t),
                        ),
                        const SizedBox(height: N360Space.md),
                        GloveTextField(
                          label: t.shellSignUpPhone,
                          controller: _phone,
                          keyboardType: TextInputType.phone,
                          textInputAction: TextInputAction.next,
                          textCapitalization: TextCapitalization.none,
                          inputFormatters: <TextInputFormatter>[
                            FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]')),
                            LengthLimitingTextInputFormatter(SignUpCubit.maxPhone),
                          ],
                          enabled: !busy,
                          errorText: _fieldText(state, SignUpField.phone, t),
                        ),
                        const SizedBox(height: N360Space.md),
                        GloveTextField(
                          label: t.shellSignInPassword,
                          controller: _password,
                          obscureText: !_showPassword,
                          textInputAction: TextInputAction.next,
                          textCapitalization: TextCapitalization.none,
                          helper: t.shellSignUpPasswordHelper,
                          enabled: !busy,
                          errorText: _fieldText(state, SignUpField.password, t),
                          suffix: ShowPasswordToggle(
                            shown: _showPassword,
                            showLabel: t.shellSignInShow,
                            hideLabel: t.shellSignInHide,
                            onToggle: () => setState(() => _showPassword = !_showPassword),
                          ),
                        ),
                        const SizedBox(height: N360Space.md),
                        GloveTextField(
                          label: t.shellSignUpConfirm,
                          controller: _confirm,
                          obscureText: !_showPassword,
                          textInputAction: TextInputAction.done,
                          textCapitalization: TextCapitalization.none,
                          enabled: !busy,
                          errorText: _fieldText(state, SignUpField.confirm, t),
                          onSubmitted: (_) => _submit(),
                        ),
                        const SizedBox(height: N360Space.md),
                        GloveButton(
                          label: t.shellSignUpHaveAccount,
                          kind: GloveButtonKind.ghost,
                          fullWidth: true,
                          onPressed: busy ? null : _backToSignIn,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              N360ActionBar(
                // The consent sits with the button it applies to, never scrolled away.
                above: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    if (problem != null) ...<Widget>[problem, const SizedBox(height: N360Space.sm)],
                    const SignUpConsent(),
                  ],
                ),
                child: GloveButton(
                  label: busy ? t.shellSignUpBusy : t.shellSignUpButton,
                  onPressed: _submit,
                  loading: busy,
                  large: true,
                  fullWidth: true,
                  icon: Icons.arrow_forward,
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

/// "By creating an account you agree to the Terms and the Privacy policy",
/// with both names opening the website's pages.
class SignUpConsent extends StatefulWidget {
  const SignUpConsent({super.key});

  @override
  State<SignUpConsent> createState() => _SignUpConsentState();
}

class _SignUpConsentState extends State<SignUpConsent> {
  // Private-use characters stand in for the two links, so each language keeps
  // its own word order around them.
  static const String _termsMark = '\u{E000}';
  static const String _privacyMark = '\u{E001}';

  late final TapGestureRecognizer _terms = TapGestureRecognizer()..onTap = () => openSitePage(context, kTermsPath);
  late final TapGestureRecognizer _privacy = TapGestureRecognizer()..onTap = () => openSitePage(context, kPrivacyPath);

  @override
  void dispose() {
    _terms.dispose();
    _privacy.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String sentence = t.shellSignUpConsent(_termsMark, _privacyMark);
    final TextStyle base = N360Type.fit(N360Type.body, sentence).copyWith(color: N360Colors.ink2);
    final TextStyle link = base.copyWith(
      color: N360Colors.ink,
      fontWeight: FontWeight.w600,
      decoration: TextDecoration.underline,
    );

    final List<InlineSpan> spans = <InlineSpan>[];
    final StringBuffer plain = StringBuffer();
    void flush() {
      if (plain.isEmpty) return;
      spans.add(TextSpan(text: plain.toString()));
      plain.clear();
    }

    for (final int rune in sentence.runes) {
      final String char = String.fromCharCode(rune);
      if (char == _termsMark || char == _privacyMark) {
        flush();
        final bool terms = char == _termsMark;
        spans.add(TextSpan(
          text: terms ? t.shellSignUpTerms : t.shellSignUpPrivacy,
          style: link,
          recognizer: terms ? _terms : _privacy,
        ));
      } else {
        plain.write(char);
      }
    }
    flush();
    return Text.rich(TextSpan(style: base, children: spans));
  }
}
