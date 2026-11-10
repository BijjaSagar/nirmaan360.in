import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/expense_form_cubit.dart';
import '../data/petty_cash_repository.dart';
import 'widgets/petty_widgets.dart';
import '../../offline_plan/view/widgets/licence_notice.dart';

/// Amount on the keypad, then what it was for. An expense over the balance
/// stops at the first step, in plain words.
class ExpenseFormScreen extends StatelessWidget {
  const ExpenseFormScreen({super.key, @visibleForTesting this.captureBill});

  /// Stands in for the camera in tests.
  final Future<String?> Function()? captureBill;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ExpenseFormCubit>(
      create: (context) => ExpenseFormCubit(
        context.read<AppServices>(),
        captureBill: captureBill,
      ),
      child: const _ExpenseFormView(),
    );
  }
}

class _ExpenseFormView extends StatelessWidget {
  const _ExpenseFormView();

  void _onSaved(BuildContext context, ExpenseFormState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    N360Haptics.success();
    showPaperToast(
      context,
      context.read<AppServices>().offlinePlan.isLocalOnly
          ? l.offlineSavedOnPhone
          : state.savedWhileOffline
          ? l.pettyCashSavedOffline
          : l.pettyCashSavedOnline,
      kind: PaperToastKind.success,
      icon: state.savedWhileOffline ? Icons.phone_android : Icons.task_alt,
      duration: const Duration(milliseconds: 3200),
    );
    // A plain pop: `maybePop` would meet the PopScope below, which turns a
    // pop on the second step into "back to the amount".
    final NavigatorState navigator = Navigator.of(context);
    if (navigator.canPop()) navigator.pop();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocConsumer<ExpenseFormCubit, ExpenseFormState>(
      listenWhen: (a, b) => !a.saved && b.saved,
      listener: _onSaved,
      builder: (context, state) {
        final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
        // The back gesture and the Back tile do the same thing: on the second
        // step they return to the amount, and only on the first do they leave.
        return PopScope(
          canPop: state.step == 0,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop && !state.submitting) cubit.backToAmount();
          },
          child: Scaffold(
            body: LedgerPaper(
              child: SafeArea(
                top: false,
                bottom: false,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    FieldTopBar(
                      title: l.pettyCashFormTitle,
                      subtitle: l.pettyCashFormEyebrow,
                      onBack: state.step == 0
                          ? () => Navigator.of(context).maybePop()
                          : cubit.backToAmount,
                    ),
                    const LicenceNotice(),
                    Expanded(
                      child: state.step == 0
                          ? _AmountStep(state: state)
                          : _DetailsStep(state: state),
                    ),
                    if (state.step == 1) _SaveBar(state: state),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _AmountStep extends StatelessWidget {
  const _AmountStep({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    final Money? limit = state.available;
    final bool over = state.isOverBalance;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (state.wallets.length > 1) _WalletChoice(state: state),
        Expanded(
          // The figure sits in the middle of the room above the keypad, as an
          // amount does on a payment app, so the page has no empty end.
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.xs,
                N360Space.gutter,
                N360Space.md,
              ),
              child: PettyAmountCard(
                label: l.pettyCashAmount,
                typed: state.amountText,
                color: over ? N360Colors.stampRed : N360Colors.ink,
                footer: over
                    ? _OverBalanceNote(balance: limit!)
                    : state.problem == ExpenseProblem.amountRequired
                    ? _Warn(text: l.pettyCashAmountRequired)
                    : state.balanceAfter != null
                    ? Text(
                        l.pettyCashBalanceAfter(state.balanceAfter!.inr()),
                        style: N360Type.fit(
                          N360Type.body,
                          l.pettyCashBalanceAfter(state.balanceAfter!.inr()),
                        ).copyWith(color: N360Colors.ink2),
                      )
                    : limit != null
                    ? Text(
                        '${l.pettyCashBalance}: ${limit.inr()}',
                        style: N360Type.fit(
                          N360Type.body,
                          l.pettyCashBalance,
                        ).copyWith(color: N360Colors.ink2),
                      )
                    : null,
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(
            N360Space.gutter,
            0,
            N360Space.gutter,
            N360Space.gutter,
          ),
          child: SafeArea(
            top: false,
            child: GloveKeypad(
              value: state.amountText,
              decimalPlaces: 2,
              maxIntegerDigits: 7,
              onChanged: cubit.setAmount,
              onNext: state.amountReady ? cubit.continueFromAmount : null,
              nextLabel: l.designKeypadNext,
              backspaceLabel: l.designKeypadDelete,
            ),
          ),
        ),
      ],
    );
  }
}

class _OverBalanceNote extends StatelessWidget {
  const _OverBalanceNote({required this.balance});

  final Money balance;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return _Warn(text: l.pettyCashOverBalance(balance.inr()));
  }
}

class _Warn extends StatelessWidget {
  const _Warn({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      liveRegion: true,
      label: text,
      child: ExcludeSemantics(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Padding(
              padding: EdgeInsets.only(top: N360Space.xxs, right: N360Space.sm),
              child: Icon(
                Icons.warning_amber,
                size: N360IconSize.md,
                color: N360Colors.stampRed,
              ),
            ),
            Expanded(
              child: Text(
                text,
                style: N360Type.fit(
                  N360Type.bodyStrong,
                  text,
                ).copyWith(color: N360Colors.stampRed),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Which wallet the money comes out of, when the project has more than one.
class _WalletChoice extends StatelessWidget {
  const _WalletChoice({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(
            N360Space.gutter,
            N360Space.xs,
            N360Space.gutter,
            0,
          ),
          child: SectionLabel(l.pettyCashWallet),
        ),
        FieldChoiceStrip(
          itemCount: state.wallets.length,
          itemBuilder: (context, index) {
            final WalletLine line = state.wallets[index];
            return N360Chip(
              label: line.wallet.name,
              count: line.previewBalance.inr(),
              selected: line.wallet.id == state.walletId,
              onTap: () => cubit.setWallet(line.wallet.id),
            );
          },
        ),
      ],
    );
  }
}

class _DetailsStep extends StatelessWidget {
  const _DetailsStep({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    final Money? amount = state.amount;

    final WalletLine? wallet = state.wallet;

    return ListView(
      padding: const EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.xs,
        N360Space.gutter,
        N360Space.gutter,
      ),
      children: <Widget>[
        PettyAmountSummary(
          amount: amount?.indian(),
          onTap: cubit.backToAmount,
          walletName: state.wallets.length > 1 ? wallet?.wallet.name : null,
        ),
        const SizedBox(height: N360Space.lg),
        Text(
          l.pettyCashCategory,
          style: N360Type.fit(N360Type.label, l.pettyCashCategory),
        ),
        const SizedBox(height: N360Space.xs),
        PictoChoice<String>(
          columns: 4,
          value: state.category,
          semanticsLabel: l.pettyCashCategory,
          onChanged: cubit.setCategory,
          options: <PictoOption<String>>[
            for (final p in N360Pictograms.expenseCategories)
              PictoOption<String>(
                value: p.key,
                icon: p.icon,
                label: l.pictogramLabel(p),
              ),
          ],
        ),
        if (state.problem == ExpenseProblem.categoryRequired) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          _Warn(text: l.pettyCashCategoryRequired),
        ],
        const SizedBox(height: N360Space.lg),
        if (state.billPath == null)
          GloveButton(
            label: l.pettyCashPhotoBill,
            icon: Icons.photo_camera,
            kind: GloveButtonKind.secondary,
            fullWidth: true,
            onPressed: cubit.takeBillPhoto,
          )
        else
          _BillAdded(path: state.billPath!, onRetake: cubit.takeBillPhoto),
        if (state.problem == ExpenseProblem.billFailed) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          _Warn(text: l.pettyCashBillFailed),
        ],
        const SizedBox(height: N360Space.lg),
        GloveTextField(
          label: l.pettyCashNote,
          hint: l.pettyCashNoteHint,
          onChanged: cubit.setNote,
          textInputAction: TextInputAction.done,
        ),
        const SizedBox(height: N360Space.lg),
        Text(
          l.pettyCashDate,
          style: N360Type.fit(N360Type.label, l.pettyCashDate),
        ),
        const SizedBox(height: N360Space.xs),
        PettyDateChoice(
          today: state.today,
          yesterday: state.yesterday,
          value: state.entryDate ?? state.today,
          onChanged: cubit.setDate,
        ),
      ],
    );
  }
}

/// The bill once it is photographed: the picture itself, so a blurred or
/// dark photo is seen before the expense is saved, and the way to take it again.
class _BillAdded extends StatefulWidget {
  const _BillAdded({required this.path, required this.onRetake});

  final String path;
  final VoidCallback onRetake;

  @override
  State<_BillAdded> createState() => _BillAddedState();
}

class _BillAddedState extends State<_BillAdded> {
  late Future<Uint8List> _bytes = File(widget.path).readAsBytes();

  @override
  void didUpdateWidget(covariant _BillAdded oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.path != widget.path) _bytes = File(widget.path).readAsBytes();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerCard(
      flat: true,
      onTap: widget.onRetake,
      semanticsLabel: '${l.pettyCashBillAdded}. ${l.pettyCashBillRetake}',
      padding: const EdgeInsets.all(N360Space.tile),
      child: Row(
        children: <Widget>[
          BillThumbnail(load: _bytes, size: N360Size.tap),
          const SizedBox(width: N360Space.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  l.pettyCashBillAdded,
                  style: N360Type.fit(
                    N360Type.bodyStrong,
                    l.pettyCashBillAdded,
                  ),
                ),
                Row(
                  children: <Widget>[
                    const Icon(
                      Icons.photo_camera,
                      size: N360IconSize.sm,
                      color: N360Colors.ink2,
                    ),
                    const SizedBox(width: N360Space.xs),
                    Flexible(
                      child: Text(
                        l.pettyCashBillRetake,
                        style: N360Type.fit(
                          N360Type.caption,
                          l.pettyCashBillRetake,
                        ).copyWith(color: N360Colors.ink2),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SaveBar extends StatelessWidget {
  const _SaveBar({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    return FieldActionBar(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          // Beside the button that failed, not at the foot of a long form.
          if (state.problem == ExpenseProblem.saveFailed)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                N360Space.xs,
                N360Space.xs,
                N360Space.xs,
                N360Space.sm,
              ),
              child: FieldInlineError(l.pettyCashSaveFailed),
            ),
          GloveButton(
            label: l.pettyCashSave,
            icon: Icons.check,
            large: true,
            fullWidth: true,
            loading: state.submitting,
            onPressed: state.submitting ? null : () => _save(cubit),
          ),
        ],
      ),
    );
  }

  Future<void> _save(ExpenseFormCubit cubit) async {
    final bool saved = await cubit.submit();
    if (!saved) N360Haptics.warning();
  }
}
