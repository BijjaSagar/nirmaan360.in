import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/add_worker_cubit.dart';

/// Opens the add-worker sheet: name and trade, then wage on the keypad.
Future<void> showAddWorkerSheet(BuildContext context) {
  final AppServices services = context.read<AppServices>();
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: N360Colors.paper,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(N360Radius.card)),
    ),
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
    return BlocConsumer<AddWorkerCubit, AddWorkerState>(
      listenWhen: (a, b) => !a.done && b.done,
      listener: _onDone,
      builder: (context, state) {
        return Padding(
          padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  N360Space.gutter,
                  N360Space.md,
                  N360Space.gutter,
                  0,
                ),
                child: SheetHeader(
                  eyebrow: l.attendanceTitle,
                  title: l.attendanceSheetTitle,
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
      padding: const EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.lg,
        N360Space.gutter,
        N360Space.lg,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const Icon(Icons.wifi_off, size: 40, color: N360Colors.ink2),
          const SizedBox(height: N360Space.sm),
          Text(
            l.attendanceSheetOfflineTitle,
            textAlign: TextAlign.center,
            style: N360Type.heading,
          ),
          const SizedBox(height: N360Space.xs),
          Text(
            l.attendanceSheetOfflineMessage,
            textAlign: TextAlign.center,
            style: N360Type.body,
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
  late final TextEditingController _name = TextEditingController(text: widget.state.name);

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
        padding: const EdgeInsets.fromLTRB(
          N360Space.gutter,
          N360Space.md,
          N360Space.gutter,
          N360Space.gutter,
        ),
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
            const SizedBox(height: N360Space.md),
            Text(l.attendanceSheetTrade, style: N360Type.label),
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
              const SizedBox(height: N360Space.xs),
              _InlineError(l.attendanceSheetTradeRequired),
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
    return parsed == null ? text : indianGroup(parsed.value, text.contains('.') ? text.split('.').last.length : 0);
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

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.md,
        N360Space.gutter,
        N360Space.gutter,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  l.attendanceSheetWageFor(state.name.trim()),
                  style: N360Type.label,
                ),
              ),
              GloveButton(
                label: l.commonBack,
                kind: GloveButtonKind.ghost,
                icon: Icons.arrow_back,
                onPressed: state.submitting ? null : cubit.backToDetails,
              ),
            ],
          ),
          Align(
            alignment: Alignment.centerRight,
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: RollingMoney(
                value: _formatted(state.wageText),
                style: N360Type.figureHero.copyWith(fontSize: 36),
              ),
            ),
          ),
          Text(l.attendanceSheetWageHelper, style: N360Type.caption),
          if (error != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            _InlineError(error),
          ],
          const SizedBox(height: N360Space.sm),
          GloveKeypad(
            value: state.wageText,
            decimalPlaces: 2,
            maxIntegerDigits: 7,
            onChanged: cubit.setWage,
            onNext: state.submitting ? null : cubit.save,
            nextLabel: l.commonSave,
            nextIcon: Icons.check,
            backspaceLabel: l.designKeypadDelete,
          ),
        ],
      ),
    );
  }
}

class _InlineError extends StatelessWidget {
  const _InlineError(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Padding(
          padding: EdgeInsets.only(top: 2, right: 6),
          child: Icon(Icons.error_outline, size: 18, color: N360Colors.stampRed),
        ),
        Expanded(
          child: Text(
            message,
            style: N360Type.caption.copyWith(
              color: N360Colors.stampRed,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }
}
