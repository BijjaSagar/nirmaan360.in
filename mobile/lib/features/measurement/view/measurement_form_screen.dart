import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/measurement_book.dart';
import '../bloc/measurement_form_cubit.dart';
import 'widgets/boq_picker_sheet.dart';
import 'widgets/book_kit.dart';
import 'widgets/details_sheet.dart';
import 'widgets/form_parts.dart';

/// The measurement book's entry page: dimensions on top, the live figure below
/// them, and the keypad fixed under the thumb.
class MeasurementFormScreen extends StatelessWidget {
  const MeasurementFormScreen({super.key, this.clientUuid});

  final String? clientUuid;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<MeasurementFormCubit>(
      create: (context) => MeasurementFormCubit(services: context.read<AppServices>(), clientUuid: clientUuid)..start(),
      child: const _FormView(),
    );
  }
}

class _FormView extends StatelessWidget {
  const _FormView();

  void _leave(BuildContext context) {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go(Routes.measurements);
    }
  }

  Future<void> _pickLine(BuildContext context) async {
    final MeasurementFormCubit cubit = context.read<MeasurementFormCubit>();
    final int? projectId = cubit.state.projectId;
    if (projectId == null) return;
    final line = await showBoqPicker(
      context,
      services: context.read<AppServices>(),
      projectId: projectId,
      selectedId: cubit.state.line?.item.id,
    );
    if (line != null) cubit.selectLine(line);
  }

  Future<void> _editDetails(BuildContext context) async {
    final MeasurementFormCubit cubit = context.read<MeasurementFormCubit>();
    final MeasurementFormState state = cubit.state;
    final EntryDetails? details = await showDetailsSheet(
      context,
      today: context.read<AppServices>().context.today,
      initial: EntryDetails(location: state.location, description: state.description, measuredOn: state.measuredOn),
    );
    if (details != null) {
      cubit.setDetails(location: details.location, description: details.description, measuredOn: details.measuredOn);
    }
  }

  Future<void> _save(BuildContext context) async {
    final MeasurementFormCubit cubit = context.read<MeasurementFormCubit>();
    final bool editing = cubit.state.isEditing;
    final FormResult result = await cubit.save();
    if (!context.mounted) return;
    switch (result) {
      case FormResult.saved:
        N360Haptics.success();
        if (editing) {
          showPaperToast(
            context,
            _onPhoneLine(AppLocalizations.of(context), cubit.state.online),
            kind: PaperToastKind.success,
          );
          _leave(context);
        }
      case FormResult.invalid:
        if (cubit.state.problem == FormProblem.noLine) await _pickLine(context);
      case FormResult.ignored:
      case FormResult.sent:
      case FormResult.failed:
        break;
    }
  }

  Future<void> _send(BuildContext context) async {
    final MeasurementFormCubit cubit = context.read<MeasurementFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final FormResult result = await cubit.send();
    if (!context.mounted) return;
    if (result == FormResult.sent) {
      final bool online = cubit.state.online;
      await showStampThud(
        context,
        online ? StampKind.inReview : StampKind.queued,
        online ? l.measurementStampSent : l.statusQueued,
      );
      if (!context.mounted) return;
      if (!online) {
        showPaperToast(context, l.measurementSendsWhenOnline, kind: PaperToastKind.info);
      }
      _leave(context);
    } else if (result == FormResult.invalid && cubit.state.problem == FormProblem.noLine) {
      await _pickLine(context);
    }
  }

  String _onPhoneLine(AppLocalizations l, bool online) {
    return online ? l.measurementSavedOnPhone : l.measurementSavedOffline;
  }

  String? _problemText(AppLocalizations l, FormProblem? problem) => switch (problem) {
    null => null,
    FormProblem.noLine => l.measurementProblemNoLine,
    FormProblem.nosRequired => l.measurementProblemNos,
    FormProblem.notPositive => l.measurementProblemPositive,
    FormProblem.tooLarge => l.measurementProblemTooLarge,
    FormProblem.futureDate => l.measurementProblemFuture,
    FormProblem.locked => l.measurementProblemLocked,
    FormProblem.unknown => l.measurementProblemUnknown,
  };

  /// What the details key says aloud, and shows when the date is not today.
  String _detailsSpoken(BuildContext context, AppLocalizations l, MeasurementFormState state) {
    final String day = dayLabel(context, state.measuredOn, context.read<AppServices>().context.today);
    return <String>[
      l.measurementDetailsTitle,
      day,
      if (state.location.trim().isNotEmpty) state.location.trim(),
      if (state.description.trim().isNotEmpty) state.description.trim(),
    ].join('. ');
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MediaQueryData media = MediaQuery.of(context);
    return BlocConsumer<MeasurementFormCubit, MeasurementFormState>(
      listenWhen: (previous, current) =>
          (current.problem != null && previous.problem != current.problem) ||
          (!previous.deviation && current.deviation),
      listener: (context, state) => N360Haptics.warning(),
      builder: (context, state) {
        if (state.loading) return const Scaffold(backgroundColor: Colors.transparent);
        if (state.locked) return _lockedPage(context, l);

        final MeasurementFormCubit cubit = context.read<MeasurementFormCubit>();
        return PopScope(
          canPop: !state.busy,
          child: Scaffold(
            backgroundColor: Colors.transparent,
            resizeToAvoidBottomInset: false,
            body: LedgerPaper(
              child: SafeArea(
                bottom: false,
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final bool compact = constraints.maxHeight < 640;
                    return _layout(context, l, state, cubit, compact: compact, media: media);
                  },
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _layout(
    BuildContext context,
    AppLocalizations l,
    MeasurementFormState state,
    MeasurementFormCubit cubit, {
    required bool compact,
    required MediaQueryData media,
  }) {
    final Widget header = LineHeader(
      line: state.line,
      compact: compact,
      onBack: () => _leave(context),
      onChange: () => _pickLine(context),
    );

    final Widget dims = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: DimensionRow(
        state: state,
        onTap: cubit.setActive,
        leading: compact ? SignFlipTile(deduction: state.isDeduction, onChanged: cubit.setDeduction) : null,
      ),
    );

    final String today = context.read<AppServices>().context.today;
    final String detailsLabel = state.measuredOn == today
        ? l.measurementDetailsShort
        : dayLabel(context, state.measuredOn, today);
    final Widget detailsKey = DetailsKey(
      label: detailsLabel,
      spoken: _detailsSpoken(context, l, state),
      filled: state.location.trim().isNotEmpty || state.description.trim().isNotEmpty,
      onTap: () => _editDetails(context),
    );

    final Widget toggleRow = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: Row(
        children: <Widget>[
          Expanded(
            flex: 7,
            child: SignToggle(deduction: state.isDeduction, onChanged: cubit.setDeduction),
          ),
          const SizedBox(width: N360Space.sm),
          Expanded(flex: 2, child: detailsKey),
        ],
      ),
    );

    final Widget band = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: QuantityBand(state: state, compact: compact),
    );

    final String? remark = state.returnRemark;
    final List<Widget> notes = <Widget>[
      if (remark != null && remark.isNotEmpty) ...<Widget>[
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: _ReturnedNote(remark: remark),
        ),
        const SizedBox(height: N360Space.sm),
      ],
      if (state.saved.isNotEmpty) ...<Widget>[
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: _SittingTally(state: state),
        ),
        const SizedBox(height: N360Space.sm),
      ],
    ];

    final String? problem = _problemText(l, state.problem);
    final List<Widget> alerts = <Widget>[
      if (state.deviation)
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
          child: DeviationStrip(tolerancePct: state.tolerancePct),
        ),
      if (problem != null)
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
          child: ProblemLine(message: problem),
        ),
    ];

    final Widget upper = compact
        ? ListView(
            padding: EdgeInsets.zero,
            children: <Widget>[
              if (!state.online) _offline(l),
              header,
              const _Rule(),
              const SizedBox(height: 4),
              ...notes,
              dims,
              const SizedBox(height: 4),
              band,
              ...alerts,
              const SizedBox(height: N360Space.sm),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
                child: detailsKey,
              ),
            ],
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Expanded(
                // Band and warnings stay put. Everything above gives way first.
                child: LayoutBuilder(
                  builder: (context, box) => ListView(
                    padding: EdgeInsets.zero,
                    children: <Widget>[
                      if (!state.online) _offline(l),
                      header,
                      const _Rule(),
                      const SizedBox(height: N360Space.sm),
                      dims,
                      const SizedBox(height: N360Space.sm),
                      toggleRow,
                      const SizedBox(height: N360Space.sm),
                      ...notes,
                      if (notes.isEmpty && box.maxHeight > 310)
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
                          child: Text(l.measurementPreviewNote, style: N360Type.caption),
                        ),
                    ],
                  ),
                ),
              ),
              band,
              ...alerts,
            ],
          );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(child: upper),
        const SizedBox(height: N360Space.sm),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: _ActionRow(state: state, onSave: () => _save(context), onSend: () => _send(context)),
        ),
        const SizedBox(height: N360Space.sm),
        Padding(
          padding: EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm + media.padding.bottom),
          child: GloveKeypad(
            value: state.activeText,
            onChanged: cubit.typed,
            onNext: state.active == DimField.depth ? null : cubit.next,
            nextLabel: l.measurementNext,
            backspaceLabel: l.measurementDelete,
            maxIntegerDigits: 8,
          ),
        ),
      ],
    );
  }

  Widget _offline(AppLocalizations l) {
    return OfflineBanner(label: l.measurementOffline, detail: l.measurementOfflineDetail);
  }

  Widget _lockedPage(BuildContext context, AppLocalizations l) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: LedgerPaper(
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                ErrorSlip(title: l.measurementLockedTitle, message: l.measurementLockedBody),
                const SizedBox(height: N360Space.md),
                GloveButton(
                  label: l.commonBack,
                  large: true,
                  fullWidth: true,
                  kind: GloveButtonKind.secondary,
                  onPressed: () => _leave(context),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Rule extends StatelessWidget {
  const _Rule();

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SizedBox(height: 1.5, child: ColoredBox(color: N360Colors.ink)),
          SizedBox(height: 2),
          RuledDivider(),
        ],
      ),
    );
  }
}

class _ReturnedNote extends StatelessWidget {
  const _ReturnedNote({required this.remark});

  final String remark;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Semantics(
      container: true,
      label: '${l.measurementApproverSaid}: $remark',
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.sm,
            border: Border.all(color: N360Colors.haldiInk, width: 1.5),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.sm, vertical: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Padding(
                  padding: EdgeInsets.only(top: 1),
                  child: Icon(Icons.reply, size: 20, color: N360Colors.haldiInk),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text.rich(
                    TextSpan(
                      children: <InlineSpan>[
                        TextSpan(
                          text: '${l.measurementApproverSaid}: ',
                          style: N360Type.caption.copyWith(color: N360Colors.haldiInk, height: 1.3),
                        ),
                        TextSpan(text: remark),
                      ],
                    ),
                    style: N360Type.bodyStrong.copyWith(height: 1.3),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Counts the rows saved so far, and is honest about where they are.
class _SittingTally extends StatelessWidget {
  const _SittingTally({required this.state});

  final MeasurementFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final int count = state.saved.length;
    final String net = '${formatQty(state.savedNet)} ${state.unit ?? ''}'.trim();
    return Semantics(
      container: true,
      liveRegion: true,
      label: '${l.measurementTally(count)}. ${l.measurementNet} $net',
      child: ExcludeSemantics(
        child: Row(
          children: <Widget>[
            const Icon(Icons.task_alt, size: 20, color: N360Colors.mossInk),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                '${l.measurementTally(count)} · ${l.measurementNet} $net',
                style: N360Type.bodyStrong.copyWith(color: N360Colors.mossInk, height: 1.3),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ActionRow extends StatelessWidget {
  const _ActionRow({required this.state, required this.onSave, required this.onSend});

  final MeasurementFormState state;
  final VoidCallback onSave;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final int rows = state.saved.length + (state.hasAnyDims ? 1 : 0);
    final String sendLabel = state.isEditing
        ? l.measurementSendAgain
        : (rows > 1 ? l.measurementSendCount(rows) : l.measurementSendForApproval);

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Expanded(
            flex: 3,
            child: GloveButton(
              key: const ValueKey<String>('measurement-send'),
              label: sendLabel,
              kind: GloveButtonKind.secondary,
              fullWidth: true,
              onPressed: onSend,
            ),
          ),
          const SizedBox(width: N360Space.sm),
          Expanded(
            flex: 2,
            child: GloveButton(
              key: const ValueKey<String>('measurement-save'),
              label: l.commonSave,
              fullWidth: true,
              onPressed: onSave,
            ),
          ),
        ],
      ),
    );
  }
}
