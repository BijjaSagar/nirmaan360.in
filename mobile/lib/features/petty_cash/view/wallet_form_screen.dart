import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show UserRow;
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/wallet_form_cubit.dart';
import 'widgets/petty_widgets.dart';

/// Creates a petty cash wallet: name and holder, then an optional opening
/// amount on the keypad. The wallet itself needs network.
class WalletFormScreen extends StatelessWidget {
  const WalletFormScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<WalletFormCubit>(
      create: (context) => WalletFormCubit(context.read<AppServices>()),
      child: const _WalletFormView(),
    );
  }
}

class _WalletFormView extends StatefulWidget {
  const _WalletFormView();

  @override
  State<_WalletFormView> createState() => _WalletFormViewState();
}

class _WalletFormViewState extends State<_WalletFormView> {
  final TextEditingController _name = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  void _onProjectKnown(BuildContext context, WalletFormState state) {
    if (_name.text.isNotEmpty) return;
    final String name = AppLocalizations.of(context).pettyCashWalletDefaultName(state.project!.code);
    _name.text = name;
    context.read<WalletFormCubit>().setName(name);
  }

  void _onDone(BuildContext context, WalletFormState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    final opening = state.openingAmount;
    final bool moneyLost = opening != null && !state.openingSaved;
    N360Haptics.success();
    showPaperToast(
      context,
      moneyLost
          ? l.pettyCashWalletMoneyFailed
          : opening == null
          ? l.pettyCashWalletCreated
          : state.savedWhileOffline
          ? l.pettyCashWalletCreatedOffline(opening.inr())
          : l.pettyCashWalletCreatedMoney(opening.inr()),
      kind: moneyLost ? PaperToastKind.warning : PaperToastKind.success,
      duration: const Duration(seconds: 4),
    );
    Navigator.of(context).maybePop();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return MultiBlocListener(
      listeners: <BlocListener<WalletFormCubit, WalletFormState>>[
        BlocListener<WalletFormCubit, WalletFormState>(
          listenWhen: (a, b) => a.project == null && b.project != null,
          listener: _onProjectKnown,
        ),
        BlocListener<WalletFormCubit, WalletFormState>(
          listenWhen: (a, b) => !a.done && b.done,
          listener: _onDone,
        ),
      ],
      child: BlocBuilder<WalletFormCubit, WalletFormState>(
        builder: (context, state) {
          final WalletFormCubit cubit = context.read<WalletFormCubit>();
          return PopScope(
            canPop: state.step == 0,
            onPopInvokedWithResult: (didPop, _) {
              if (!didPop) cubit.backToDetails();
            },
            child: Scaffold(
              body: LedgerPaper(
                child: SafeArea(
                  bottom: false,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      FieldTopBar(
                        title: l.pettyCashCreateWallet,
                        subtitle: l.pettyCashWalletFormEyebrow,
                        onBack: state.step == 0 ? null : cubit.backToDetails,
                      ),
                      if (!state.online)
                        Padding(
                          padding: const EdgeInsets.only(bottom: N360Space.sm),
                          child: OfflineBanner(
                            label: l.pettyCashWalletOffline,
                            detail: l.pettyCashWalletOfflineDetail,
                          ),
                        ),
                      Expanded(
                        child: state.step == 0
                            ? _DetailsStep(state: state, name: _name)
                            : _AmountStep(state: state),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _DetailsStep extends StatelessWidget {
  const _DetailsStep({required this.state, required this.name});

  final WalletFormState state;
  final TextEditingController name;

  Future<void> _pickHolder(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final WalletFormCubit cubit = context.read<WalletFormCubit>();
    final int? chosen = await showModalBottomSheet<int>(
      context: context,
      useSafeArea: true,
      isScrollControlled: true,
      backgroundColor: N360Colors.paper,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(N360Radius.card)),
      ),
      builder: (sheetContext) => SizedBox(
        height: MediaQuery.sizeOf(sheetContext).height * 0.6,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.lg,
                N360Space.gutter,
                N360Space.sm,
              ),
              child: SheetHeader(title: l.pettyCashWalletHolder),
            ),
            Expanded(
              child: ListView.builder(
                padding: const EdgeInsets.fromLTRB(
                  N360Space.gutter,
                  0,
                  N360Space.gutter,
                  N360Space.lg,
                ),
                itemCount: state.users.length,
                itemBuilder: (context, index) {
                  final UserRow user = state.users[index];
                  return Padding(
                    padding: const EdgeInsets.only(bottom: N360Space.sm),
                    child: FieldChoiceTile(
                      width: double.infinity,
                      icon: Icons.person,
                      label: user.name,
                      selected: user.id == state.holderId,
                      onTap: () => Navigator.of(sheetContext).pop(user.id),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
    if (chosen != null) cubit.setHolder(chosen);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final WalletFormCubit cubit = context.read<WalletFormCubit>();
    final String? nameError = state.problem == WalletFormProblem.nameRequired
        ? l.pettyCashWalletNameRequired
        : state.serverError;
    final String holder = state.holderName ?? '-';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.xs,
              N360Space.gutter,
              N360Space.sm,
            ),
            children: <Widget>[
              LedgerCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    GloveTextField(
                      label: l.pettyCashWalletName,
                      controller: name,
                      textInputAction: TextInputAction.done,
                      errorText: nameError,
                      onChanged: cubit.setName,
                    ),
                    const SizedBox(height: N360Space.md),
                    Text(l.pettyCashWalletHolder, style: N360Type.label),
                    const SizedBox(height: N360Space.xs),
                    FieldChoiceTile(
                      width: double.infinity,
                      icon: Icons.person_outline,
                      label: holder,
                      selected: false,
                      semanticsLabel: '${l.pettyCashWalletHolder}: $holder',
                      onTap: () => _pickHolder(context),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        FieldActionBar(
          child: GloveButton(
            label: l.designKeypadNext,
            icon: Icons.arrow_forward,
            large: true,
            fullWidth: true,
            onPressed: cubit.next,
          ),
        ),
      ],
    );
  }
}

class _AmountStep extends StatelessWidget {
  const _AmountStep({required this.state});

  final WalletFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final WalletFormCubit cubit = context.read<WalletFormCubit>();
    final bool failed = state.problem == WalletFormProblem.failed;
    final bool canCreate = state.online && !state.saving;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.xs,
                N360Space.gutter,
                N360Space.sm,
              ),
              child: PettyAmountCard(
                label: l.pettyCashOpeningAmount,
                typed: state.amountText,
                footer: failed
                    ? FieldInlineError(l.pettyCashWalletFailed)
                    : Text(
                        l.pettyCashOpeningHelper,
                        style: N360Type.body.copyWith(color: N360Colors.ink2),
                      ),
              ),
            ),
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              0,
              N360Space.gutter,
              N360Space.gutter,
            ),
            child: GloveKeypad(
              value: state.amountText,
              decimalPlaces: 2,
              maxIntegerDigits: 7,
              onChanged: cubit.setAmount,
              onNext: canCreate ? () => cubit.create(openingNote: l.pettyCashOpeningNote) : null,
              nextLabel: l.pettyCashWalletCreateKey,
              nextIcon: Icons.check,
              backspaceLabel: l.designKeypadDelete,
            ),
          ),
        ),
      ],
    );
  }
}
