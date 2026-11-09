import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/add_worker_cubit.dart';
import 'field_kit.dart';

/// Opens the add-worker sheet: name and trade, then wage on the keypad.
Future<void> showAddWorkerSheet(BuildContext context) {
  final AppServices services = context.read<AppServices>();
  return showLedgerSheet<void>(
    context,
    isScrollControlled: true,
    builder: (sheetContext) => BlocProvider<AddWorkerCubit>(
      create: (_) => AddWorkerCubit(services),
      child: _AddWorkerSheet(parentContext: context),
    ),
  );
}

class _AddWorkerSheet extends StatelessWidget {
  const _AddWorkerSheet({required this.parentContext});

  final BuildContext parentContext;

  void _onDone(BuildContext context, AddWorkerState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    N360Haptics.success();
    Navigator.of(context).pop();
    showPaperToast(
      parentContext,
      l.attendanceSheetDone(state.addedName),
      kind: PaperToastKind.success,
      icon: Icons.person_add_alt_1,
      duration: const Duration(seconds: 4),
    );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final AddWorkerCubit cubit = context.read<AddWorkerCubit>();
    return BlocConsumer<AddWorkerCubit, AddWorkerState>(
      listenWhen: (a, b) => !a.done && b.done,
      listener: _onDone,
      builder: (context, state) {
        final bool onWage = state.online && state.step == 1;
        return Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.viewInsetsOf(context).bottom,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  N360Space.gutter,
                  0,
                  N360Space.gutter,
                  0,
                ),
                child: SheetHeader(
                  eyebrow: l.attendanceTitle,
                  title: l.attendanceSheetTitle,
                  // Back sits in the header so the wage step keeps its room.
                  trailing: onWage
                      ? GloveButton(
                          label: l.commonBack,
                          kind: GloveButtonKind.ghost,
                          icon: Icons.arrow_back,
                          onPressed: state.submitting
                              ? null
                              : cubit.backToDetails,
                        )
                      : null,
                ),
              ),
              if (!state.online)
                _OfflinePanel(l: l)
              else if (state.step == 0)
                _DetailsStep(state: state)
              else
                _WageStep(state: state),
            ],
          ),
        );
      },
    );
  }
}

class _OfflinePanel extends StatelessWidget {
  const _OfflinePanel({required this.l});

  final AppLocalizations l;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: ledgerSheetPadding.copyWith(top: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Center(
            child: IconTile(
              icon: Icons.wifi_off,
              semanticLabel: l.attendanceSheetOfflineTitle,
            ),
          ),
          const SizedBox(height: N360Space.md),
          Text(
            l.attendanceSheetOfflineTitle,
            textAlign: TextAlign.center,
            style: N360Type.fit(
              N360Type.heading,
              l.attendanceSheetOfflineTitle,
            ),
          ),
          const SizedBox(height: N360Space.xs),
          Text(
            l.attendanceSheetOfflineMessage,
            textAlign: TextAlign.center,
            style: N360Type.fit(
              N360Type.body,
              l.attendanceSheetOfflineMessage,
            ).copyWith(color: N360Colors.ink2),
          ),
          const SizedBox(height: N360Space.lg),
          GloveButton(
            label: l.commonBack,
            fullWidth: true,
            kind: GloveButtonKind.secondary,
            icon: Icons.arrow_back,
            onPressed: () => Navigator.of(context).pop(),
          ),
        ],
      ),
    );
  }
}

class _DetailsStep extends StatefulWidget {
  const _DetailsStep({required this.state});

  final AddWorkerState state;

  @override
  State<_DetailsStep> createState() => _DetailsStepState();
}

class _DetailsStepState extends State<_DetailsStep> {
  late final TextEditingController _name = TextEditingController(
    text: widget.state.name,
  );

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final AddWorkerCubit cubit = context.read<AddWorkerCubit>();
    final AddWorkerState state = widget.state;
    final List<Pictogram> trades = N360Pictograms.trades;

    return Flexible(
      child: SingleChildScrollView(
        padding: ledgerSheetPadding.copyWith(top: N360Space.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            GloveTextField(
              label: l.attendanceSheetName,
              controller: _name,
              hint: l.attendanceSheetNameHint,
              textCapitalization: TextCapitalization.words,
              textInputAction: TextInputAction.done,
              onChanged: cubit.setName,
              errorText: state.problem == AddWorkerProblem.nameRequired
                  ? l.attendanceSheetNameRequired
                  : null,
            ),
            const SizedBox(height: N360Space.lg),
            Text(
              l.attendanceSheetTrade,
              style: N360Type.fit(N360Type.label, l.attendanceSheetTrade),
            ),
            const SizedBox(height: N360Space.xs),
            PictoChoice<String>(
              columns: 4,
              value: state.trade,
              semanticsLabel: l.attendanceSheetTrade,
              onChanged: cubit.setTrade,
              options: <PictoOption<String>>[
                for (final trade in trades)
                  PictoOption<String>(
                    value: trade.key,
                    icon: trade.icon,
                    label: l.pictogramLabel(trade),
                  ),
              ],
            ),
            if (state.problem == AddWorkerProblem.tradeRequired) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              FieldInlineError(l.attendanceSheetTradeRequired),
            ],
            const SizedBox(height: N360Space.lg),
            GloveButton(
              label: l.designKeypadNext,
              fullWidth: true,
              large: true,
              icon: Icons.arrow_forward,
              onPressed: cubit.next,
            ),
          ],
        ),
      ),
    );
  }
}

class _WageStep extends StatelessWidget {
  const _WageStep({required this.state});

  final AddWorkerState state;

  String _formatted(String text) {
    if (text.isEmpty) return '0';
    final Money? parsed = Money.tryParse(text);
    return parsed == null
        ? text
        : indianGroup(
            parsed.value,
            text.contains('.') ? text.split('.').last.length : 0,
          );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final AddWorkerCubit cubit = context.read<AddWorkerCubit>();

    String? error;
    if (state.problem == AddWorkerProblem.offline) {
      error = l.attendanceSheetOfflineError;
    } else if (state.problem == AddWorkerProblem.failed) {
      error = l.attendanceSheetFailed;
    }
    final String wageFor = l.attendanceSheetWageFor(state.name.trim());

    return Padding(
      padding: ledgerSheetPadding.copyWith(top: N360Space.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(wageFor, style: N360Type.fit(N360Type.heading, wageFor)),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: RollingMoney(
              value: _formatted(state.wageText),
              style: N360Type.figureHero,
            ),
          ),
          if (error != null)
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xs),
              child: FieldInlineError(error),
            )
          else
            Text(
              l.attendanceSheetWageHelper,
              style: N360Type.fit(
                N360Type.caption,
                l.attendanceSheetWageHelper,
              ),
            ),
          const SizedBox(height: N360Space.md),
          // The white keys need a warm tray: the sheet itself is white.
          DecoratedBox(
            decoration: const BoxDecoration(
              color: N360Colors.paper,
              borderRadius: N360Radius.cardRadius,
            ),
            child: Padding(
              padding: const EdgeInsets.all(N360Space.sm),
              child: GloveKeypad(
                value: state.wageText,
                decimalPlaces: 2,
                maxIntegerDigits: 7,
                onChanged: cubit.setWage,
                onNext: state.submitting ? null : cubit.save,
                nextLabel: l.commonSave,
                nextIcon: Icons.check,
                backspaceLabel: l.designKeypadDelete,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
