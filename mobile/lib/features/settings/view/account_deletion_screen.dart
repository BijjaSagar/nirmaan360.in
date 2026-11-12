import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../app/widgets/back_header.dart';
import '../../../app/widgets/show_password.dart';
import '../../../core/api/nirmaan_api.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../offline_plan/view/widgets/licence_card.dart' show licenceDate;
import '../bloc/account_deletion_cubit.dart';

/// Deleting this person's account, as the server says it would go: a member
/// goes at once, the only owner takes the whole company with them after a
/// grace period. The password, and for a company its name, confirm it, and
/// the button has to be held.
class AccountDeletionScreen extends StatelessWidget {
  const AccountDeletionScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<AccountDeletionCubit>(
      create: (context) => AccountDeletionCubit(services: context.read<AppServices>())..load(),
      child: const _DeletionView(),
    );
  }
}

class _DeletionView extends StatefulWidget {
  const _DeletionView();

  @override
  State<_DeletionView> createState() => _DeletionViewState();
}

class _DeletionViewState extends State<_DeletionView> {
  final TextEditingController _password = TextEditingController();
  final TextEditingController _name = TextEditingController();
  bool _showPassword = false;

  /// A fresh hold bar after every attempt: a full one cannot be held again.
  int _attempt = 0;

  @override
  void dispose() {
    _password.dispose();
    _name.dispose();
    super.dispose();
  }

  void _confirmed() {
    FocusScope.of(context).unfocus();
    setState(() => _attempt++);
    context.read<AccountDeletionCubit>().submit(password: _password.text, confirmName: _name.text);
  }

  void _notice(BuildContext context, AccountDeletionState state) {
    final t = AppLocalizations.of(context);
    switch (state.notice) {
      case DeletionNotice.cancelled:
        showPaperToast(context, t.accountDeleteCancelled, kind: PaperToastKind.success);
      case DeletionNotice.changed:
        _password.clear();
        showPaperToast(context, t.accountDeleteChanged, kind: PaperToastKind.warning);
      case null:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    return BlocConsumer<AccountDeletionCubit, AccountDeletionState>(
      listenWhen: (previous, next) => previous.noticeTick != next.noticeTick,
      listener: _notice,
      builder: (context, state) {
        final AccountDeletionPreview? preview = state.load == DeletionLoad.loaded ? state.preview : null;
        final bool company = preview?.mode == AccountDeletionMode.company;
        // A company already on its way out has nothing more to delete: only the way back.
        final bool showForm = preview != null && !(company && preview.pending != null);
        return Scaffold(
          backgroundColor: N360Colors.sheet,
          body: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              PageHeader(title: company && showForm ? t.accountDeleteCompanyTitle : t.accountDeleteTile),
              Expanded(
                child: SingleChildScrollView(
                  physics: const ClampingScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      ...switch (state.load) {
                        DeletionLoad.loading => <Widget>[
                            LedgerSkeletonList(lines: 3, semanticsLabel: t.accountDeleteLoading),
                          ],
                        DeletionLoad.offline => <Widget>[
                            ErrorSlip(
                              title: t.accountDeleteOfflineTitle,
                              message: t.accountDeleteOfflineBody,
                              actionLabel: t.commonRetry,
                              onAction: () => context.read<AccountDeletionCubit>().load(),
                            ),
                          ],
                        DeletionLoad.server => <Widget>[
                            ErrorSlip(
                              title: t.shellSignInServerTitle,
                              message: t.shellSignInServerBody,
                              actionLabel: t.commonRetry,
                              onAction: () => context.read<AccountDeletionCubit>().load(),
                            ),
                          ],
                        DeletionLoad.loaded => <Widget>[
                            if (preview?.pending != null) ...<Widget>[
                              _PendingCard(preview: preview!, state: state, locale: locale),
                              const SizedBox(height: N360Space.lg),
                            ],
                            if (showForm) ..._form(context, t, state, preview, locale),
                          ],
                      },
                    ],
                  ),
                ),
              ),
              if (showForm)
                N360ActionBar(
                  above: _problemSlip(state, t),
                  child: state.submitting
                      ? GloveButton(
                          label: t.accountDeleteBusy,
                          kind: GloveButtonKind.danger,
                          loading: true,
                          fullWidth: true,
                          onPressed: () {},
                        )
                      : HoldToConfirm(
                          key: ValueKey<String>('account-delete-hold-$_attempt'),
                          label: t.accountDeleteHold,
                          spokenLabel: t.accountDeleteSpoken,
                          onConfirmed: _confirmed,
                        ),
                ),
            ],
          ),
        );
      },
    );
  }

  List<Widget> _form(
    BuildContext context,
    AppLocalizations t,
    AccountDeletionState state,
    AccountDeletionPreview preview,
    String locale,
  ) {
    final bool company = preview.mode == AccountDeletionMode.company;
    final String name = preview.companyName;
    final DateTime? eraseAfter = preview.eraseAfter;
    final String date = eraseAfter == null ? '' : licenceDate(eraseAfter, locale);
    final List<String> lines = company
        ? <String>[
            t.accountDeleteCompanyErases(name),
            t.accountDeleteOthers(preview.otherPeople),
            t.accountDeleteCompanyWhen(date),
            t.accountDeletePhoneCleared,
            t.accountDeleteTaxRecords,
            t.accountDeleteExport,
            t.accountDeleteHandOver,
          ]
        : <String>[
            t.accountDeleteMemberNow,
            t.accountDeleteMemberWork(name),
            t.accountDeletePhoneCleared,
          ];
    final bool busy = state.submitting;
    final String? nameError = switch (state.problem) {
      DeletionProblem.nameMissing || DeletionProblem.wrongName => t.accountDeleteWrongName,
      _ => null,
    };
    final String? passwordError = switch (state.problem) {
      DeletionProblem.passwordMissing => t.shellSignInPasswordRequired,
      DeletionProblem.wrongPassword => t.accountDeleteWrongPassword,
      _ => null,
    };

    return <Widget>[
      for (int i = 0; i < lines.length; i++) ...<Widget>[
        if (i > 0) const SizedBox(height: N360Space.sm),
        Text(
          lines[i],
          style: (i == 0 ? N360Type.fit(N360Type.bodyStrong, lines[i]) : N360Type.fit(N360Type.body, lines[i]))
              .copyWith(color: i == 0 ? N360Colors.ink : N360Colors.ink2),
        ),
      ],
      if (state.unsent > 0) ...<Widget>[
        const SizedBox(height: N360Space.lg),
        _UnsentWarning(count: state.unsent, localOnly: state.localOnly),
      ],
      const SizedBox(height: N360Space.lg),
      if (company) ...<Widget>[
        GloveTextField(
          key: const ValueKey<String>('account-delete-name'),
          label: t.accountDeleteConfirmName(name),
          controller: _name,
          textInputAction: TextInputAction.next,
          textCapitalization: TextCapitalization.none,
          enabled: !busy,
          errorText: nameError,
        ),
        const SizedBox(height: N360Space.md),
      ],
      GloveTextField(
        key: const ValueKey<String>('account-delete-password'),
        label: t.accountDeletePassword,
        controller: _password,
        obscureText: !_showPassword,
        textInputAction: TextInputAction.done,
        textCapitalization: TextCapitalization.none,
        enabled: !busy,
        errorText: passwordError,
        suffix: ShowPasswordToggle(
          shown: _showPassword,
          showLabel: t.shellSignInShow,
          hideLabel: t.shellSignInHide,
          onToggle: () => setState(() => _showPassword = !_showPassword),
        ),
      ),
    ];
  }

  Widget? _problemSlip(AccountDeletionState state, AppLocalizations t) {
    return switch (state.problem) {
      DeletionProblem.tooManyTries => ErrorSlip(title: t.shellSignInTooManyTitle, message: t.shellSignInTooManyBody),
      DeletionProblem.offline => ErrorSlip(title: t.accountDeleteOfflineTitle, message: t.accountDeleteOfflineBody),
      DeletionProblem.server => ErrorSlip(title: t.shellSignInServerTitle, message: t.shellSignInServerBody),
      // The rest belong to a field, and are shown under it.
      _ => null,
    };
  }
}

/// The company is already set to be erased: when, and for an owner the way back.
class _PendingCard extends StatelessWidget {
  const _PendingCard({required this.preview, required this.state, required this.locale});

  final AccountDeletionPreview preview;
  final AccountDeletionState state;
  final String locale;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final PendingDeletion pending = preview.pending!;
    final String body = t.accountDeletePendingBody(preview.companyName, licenceDate(pending.eraseAfter, locale));
    return LedgerCard(
      key: const ValueKey<String>('account-delete-pending'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            t.accountDeletePendingTitle,
            style: N360Type.fit(N360Type.bodyStrong, t.accountDeletePendingTitle).copyWith(color: N360Colors.stampRed),
          ),
          const SizedBox(height: N360Space.xs),
          Text(body, style: N360Type.fit(N360Type.body, body).copyWith(color: N360Colors.ink2)),
          if (pending.canCancel) ...<Widget>[
            const SizedBox(height: N360Space.md),
            GloveButton(
              key: const ValueKey<String>('account-delete-cancel'),
              label: t.accountDeleteCancelButton,
              icon: Icons.undo,
              large: true,
              fullWidth: true,
              loading: state.cancelling,
              onPressed: () => context.read<AccountDeletionCubit>().cancelPending(),
            ),
          ],
          if (preview.mode == AccountDeletionMode.company && state.problem != null) ...<Widget>[
            const SizedBox(height: N360Space.md),
            ErrorSlip(
              title: state.problem == DeletionProblem.offline ? t.accountDeleteOfflineTitle : t.shellSignInServerTitle,
              message: state.problem == DeletionProblem.offline ? t.accountDeleteOfflineBody : t.shellSignInServerBody,
            ),
          ],
        ],
      ),
    );
  }
}

/// What would be lost with this phone's data, in red, with the way to send it first.
class _UnsentWarning extends StatelessWidget {
  const _UnsentWarning({required this.count, required this.localOnly});

  final int count;
  final bool localOnly;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String text = localOnly ? t.accountDeleteLocalOnly(count) : t.accountDeleteUnsent(count);
    return Column(
      key: const ValueKey<String>('account-delete-unsent'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Padding(
              padding: EdgeInsets.only(top: 1, right: N360Space.sm),
              child: Icon(Icons.warning_amber, size: N360IconSize.xl, color: N360Colors.stampRed),
            ),
            Expanded(
              child: Text(
                text,
                style: N360Type.fit(N360Type.bodyStrong, text).copyWith(color: N360Colors.stampRed),
              ),
            ),
          ],
        ),
        if (!localOnly) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          GloveButton(
            label: t.shellSignOutSendFirst,
            kind: GloveButtonKind.secondary,
            icon: Icons.outbox,
            fullWidth: true,
            onPressed: () => context.go(Routes.outbox),
          ),
        ],
      ],
    );
  }
}
