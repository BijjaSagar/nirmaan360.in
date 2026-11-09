import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/dpr_form_cubit.dart';
import '../data/dpr_repository.dart';
import 'dpr_detail_screen.dart';
import 'widgets/add_work_sheet.dart';
import 'widgets/dpr_format.dart';
import 'widgets/dpr_ui.dart';
import 'widgets/manpower_rows.dart';
import 'widgets/photo_strip.dart';
import 'widgets/review_sheet.dart';
import 'widgets/work_rows.dart';

/// The daily report: grouped cards, saved on every change.
///
/// With [date] and no [clientUuid] it opens the report for that date, or
/// starts one. A report that can no longer be changed opens read-only.
class DprFormScreen extends StatelessWidget {
  const DprFormScreen({super.key, this.clientUuid, this.date});

  final String? clientUuid;
  final String? date;

  @override
  Widget build(BuildContext context) {
    final services = context.read<AppServices>();
    return BlocProvider<DprFormCubit>(
      create: (_) => DprFormCubit(services: services, clientUuid: clientUuid, date: date)..load(),
      child: const _DprFormBody(),
    );
  }
}

class _DprFormBody extends StatefulWidget {
  const _DprFormBody();

  @override
  State<_DprFormBody> createState() => _DprFormBodyState();
}

class _DprFormBodyState extends State<_DprFormBody> {
  final ScrollController _scroll = ScrollController();
  final TextEditingController _hindrances = TextEditingController();
  final TextEditingController _notes = TextEditingController();
  final FocusNode _hindrancesFocus = FocusNode();
  final Set<Object> _landed = <Object>{};
  late final AppLifecycleListener _lifecycle;

  /// A work row just removed, offered back for a few seconds. A gloved thumb
  /// can land on Remove by mistake, and the row held a figure.
  ({WorkLine line, int index})? _undo;
  Timer? _undoTimer;

  @override
  void initState() {
    super.initState();
    _lifecycle = AppLifecycleListener(
      onStateChange: (state) {
        if (state != AppLifecycleState.resumed) {
          unawaited(context.read<DprFormCubit>().saveNow());
        }
      },
    );
  }

  @override
  void dispose() {
    _undoTimer?.cancel();
    _lifecycle.dispose();
    _scroll.dispose();
    _hindrances.dispose();
    _hindrancesFocus.dispose();
    _notes.dispose();
    super.dispose();
  }

  // Actions -------------------------------------------------------------------

  /// A toast that clears the floating Send footer.
  void _toast(String message, PaperToastKind kind) {
    showPaperToast(context, message, kind: kind, bottomOffset: 92);
  }

  Future<void> _copyYesterday() async {
    final cubit = context.read<DprFormCubit>();
    final l = AppLocalizations.of(context);
    final int copied = await cubit.copyYesterday();
    if (!mounted || copied == 0) return;
    _toast(l.dprFilledFromYesterday, PaperToastKind.success);
  }

  Future<void> _openWork(DprFormState state, {int? index}) async {
    final cubit = context.read<DprFormCubit>();
    final services = context.read<AppServices>();
    final WorkLine? line = await showDprAddWorkSheet(
      context,
      services: services,
      projectId: state.project!.id,
      yesterdayIds: state.yesterdayWorkIds,
      added: <int, Qty>{
        for (final row in state.work)
          if (row.boqItemId != null) row.boqItemId!: row.qty,
      },
      existing: index == null ? null : state.work[index],
    );
    if (line == null || !mounted) return;
    final int? target =
        index ??
        (line.boqItemId == null
            ? null
            : state.work.indexWhere((row) => row.boqItemId == line.boqItemId));
    cubit.putWork(line, index: (target != null && target >= 0) ? target : null);
  }

  void _removeWork(int index) {
    final cubit = context.read<DprFormCubit>();
    final WorkLine line = cubit.state.work[index];
    cubit.removeWork(index);
    _undoTimer?.cancel();
    _undoTimer = Timer(const Duration(seconds: 6), () {
      if (mounted) setState(() => _undo = null);
    });
    setState(() => _undo = (line: line, index: index));
  }

  void _undoRemove() {
    final undo = _undo;
    if (undo == null) return;
    _undoTimer?.cancel();
    context.read<DprFormCubit>().restoreWork(undo.line, undo.index);
    setState(() => _undo = null);
  }

  Future<void> _takePhoto() async {
    final cubit = context.read<DprFormCubit>();
    final l = AppLocalizations.of(context);
    final DprPhotoResult result = await cubit.addPhoto();
    if (!mounted) return;
    final String? problem = switch (result) {
      DprPhotoResult.added || DprPhotoResult.cancelled => null,
      DprPhotoResult.cameraFailed => l.dprCameraFailed,
      DprPhotoResult.failed => l.dprPhotoFailed,
      DprPhotoResult.full => l.dprPhotosFull,
      DprPhotoResult.locked => l.dprLocked,
    };
    if (problem != null) _toast(problem, PaperToastKind.error);
  }

  Future<void> _removePhoto(DprPhotoRow photo) async {
    final cubit = context.read<DprFormCubit>();
    final l = AppLocalizations.of(context);
    final bool removed = await cubit.removePhoto(photo.clientUuid);
    if (!mounted || removed) return;
    _toast(l.dprLocked, PaperToastKind.error);
  }

  /// Opens the hindrances field and takes the engineer there, from anywhere
  /// on the sheet. The card may not be built yet, so it scrolls down first.
  void _openNotes() {
    context.read<DprFormCubit>().openNotes();
    final binding = WidgetsBinding.instance;
    binding.addPostFrameCallback((_) {
      if (!mounted || !_scroll.hasClients) return;
      _scroll.jumpTo(_scroll.position.maxScrollExtent);
      binding.addPostFrameCallback((_) {
        if (!mounted || !_scroll.hasClients) return;
        _scroll.jumpTo(_scroll.position.maxScrollExtent);
        binding.addPostFrameCallback((_) {
          if (mounted) _hindrancesFocus.requestFocus();
        });
        binding.ensureVisualUpdate();
      });
      binding.ensureVisualUpdate();
    });
    binding.ensureVisualUpdate();
  }

  Future<void> _onSendPressed() async {
    final cubit = context.read<DprFormCubit>();
    final services = context.read<AppServices>();
    final l = AppLocalizations.of(context);
    FocusScope.of(context).unfocus();

    if (!cubit.validate()) {
      unawaited(N360Haptics.warning());
      if (_scroll.hasClients) {
        if (context.motion.reduced) {
          _scroll.jumpTo(0);
        } else {
          unawaited(
            _scroll.animateTo(0, duration: context.motion.rise, curve: N360Curves.rise),
          );
        }
      }
      return;
    }

    final DprSendResult? result = await showDprReviewSheet(
      context,
      cubit: cubit,
      services: services,
    );
    if (!mounted || result == null) return;

    final String? problem = switch (result) {
      DprSendResult.sent || DprSendResult.invalid => null,
      DprSendResult.futureDate => l.dprFutureDate,
      DprSendResult.locked => l.dprLocked,
      DprSendResult.failed => l.dprSendFailed,
    };
    if (problem != null) {
      _toast(problem, PaperToastKind.error);
      return;
    }
    if (result != DprSendResult.sent) return;

    // The stamp fires its own heavy haptic the moment it lands.
    await showStampThud(context, StampKind.queued, l.statusQueued);
    if (!mounted) return;
    final String honest = services.connectivity.isOnline
        ? l.dprSavedSendingNow
        : l.dprSavedWillSend;
    showPaperToast(
      context,
      honest,
      kind: PaperToastKind.success,
      duration: const Duration(milliseconds: 4200),
    );
    dprGoHome(context);
  }

  // Build ---------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<DprFormCubit, DprFormState>(
      listenWhen: (a, b) => a.phase != b.phase,
      listener: (context, state) {
        if (state.phase == DprFormPhase.editing) {
          _hindrances.text = state.hindrances;
          _notes.text = state.notes;
        }
      },
      builder: (context, state) {
        return switch (state.phase) {
          DprFormPhase.loading => const _Loading(),
          DprFormPhase.noProject => const _NoProject(),
          DprFormPhase.locked => DprDetailScreen(clientUuid: state.clientUuid!),
          DprFormPhase.editing => _screen(context, state),
        };
      },
    );
  }

  Widget _screen(BuildContext context, DprFormState state) {
    final l = AppLocalizations.of(context);
    final cubit = context.read<DprFormCubit>();
    final bool typing = MediaQuery.viewInsetsOf(context).bottom > 0;

    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          bottom: false,
          child: Column(
            children: <Widget>[
              DprTopBar(
                title: l.dprFormTitle,
                trailing: _SaveIndicator(status: state.save, tick: state.saveTick),
              ),
              Expanded(
                child: DprActionLayout(
                  body: ListView(
                    controller: _scroll,
                    keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.manual,
                    padding: EdgeInsets.only(
                      top: N360Space.xs,
                      bottom: typing ? N360Space.lg : DprActionLayout.clearance(context),
                    ),
                    children: _cards(context, state, cubit, l),
                  ),
                  action: typing
                      ? null
                      : GloveButton(
                          label: state.isReturned ? l.dprSendAgain : l.dprSendReport,
                          icon: Icons.send,
                          large: true,
                          fullWidth: true,
                          onPressed: () => unawaited(_onSendPressed()),
                        ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// A few fixed sections, each a card. Only the data lists inside them are lazy.
  List<Widget> _cards(
    BuildContext context,
    DprFormState state,
    DprFormCubit cubit,
    AppLocalizations l,
  ) {
    final services = context.read<AppServices>();
    final cards = <Widget>[];
    var rise = 0;

    void add(Widget child) {
      cards.add(
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.cardGap),
          child: StaggeredRise(index: rise++, child: child),
        ),
      );
    }

    // Which project, which day.
    add(
      Row(
        children: <Widget>[
          Expanded(
            flex: 5,
            child: DprInfoChip(icon: Icons.apartment, text: state.project?.name ?? ''),
          ),
          const SizedBox(width: N360Space.sm),
          Expanded(
            flex: 4,
            child: DprInfoChip(
              icon: Icons.calendar_month,
              // Says "Today" out loud: a report filed against the wrong day is
              // the mistake a tired eye makes.
              text: state.reportDate == services.context.today
                  ? '${l.commonToday}, ${dprDayMonth(context, state.reportDate)}'
                  : dprDateLabel(context, state.reportDate),
            ),
          ),
        ],
      ),
    );

    if (state.isReturned) {
      add(
        DprRemarkCard(
          title: l.dprApproverRemark,
          message: state.returnRemark ?? l.dprReturnedNoRemark,
        ),
      );
    }

    // Weather.
    add(
      LedgerCard(
        padding: const EdgeInsets.all(N360Space.card),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(
              icon: Icons.wb_sunny_outlined,
              title: l.dprSectionWeather,
              done: state.weather != null,
              error: state.problems.contains(DprFormProblem.weather) ? l.dprNeedWeather : null,
            ),
            const SizedBox(height: N360Space.md),
            PictoChoice<String>(
              columns: 5,
              semanticsLabel: l.dprSectionWeather,
              value: state.weather,
              onChanged: cubit.setWeather,
              options: <PictoOption<String>>[
                for (final value in state.weatherOptions)
                  PictoOption<String>(
                    value: value,
                    icon: N360Pictograms.weather(value).icon,
                    label: l.pictogramLabel(N360Pictograms.weather(value)),
                  ),
              ],
            ),
          ],
        ),
      ),
    );

    // Workers, with the figure that matters most, big.
    final List<String> visible = state.visibleTrades;
    add(
      LedgerCard(
        padding: const EdgeInsets.all(N360Space.card),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(
              icon: Icons.groups,
              title: l.dprWorkersOnSite,
              done: state.totalWorkers > 0,
              error: state.problems.contains(DprFormProblem.workers) ? l.dprNeedWorkers : null,
            ),
            if (state.problems.contains(DprFormProblem.workers)) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              GloveButton(
                label: l.dprSiteClosedWrite,
                icon: Icons.edit_note,
                kind: GloveButtonKind.ghost,
                fullWidth: true,
                onPressed: _openNotes,
              ),
            ],
            const SizedBox(height: N360Space.xs),
            DprWorkerTotal(total: state.totalWorkers, yesterday: state.yesterdayTotal),
            if (state.canCopyYesterday) ...<Widget>[
              const SizedBox(height: N360Space.md),
              DprActionTile(
                icon: Icons.history,
                label: l.dprSameAsYesterday(state.yesterdayTotal!),
                gold: true,
                onTap: () => unawaited(_copyYesterday()),
              ),
            ],
            const SizedBox(height: N360Space.md),
            for (var i = 0; i < visible.length; i++) ...<Widget>[
              if (i > 0) const SizedBox(height: N360Space.sm),
              DprTradeRow(
                key: ValueKey<String>('trade-${visible[i]}'),
                trade: visible[i],
                count: state.count(visible[i]),
                onChanged: (value) => cubit.setCount(visible[i], value),
              ),
            ],
            if (state.hiddenTradeCount > 0) ...<Widget>[
              const SizedBox(height: N360Space.md),
              GloveButton(
                label: l.dprMoreTrades(state.hiddenTradeCount),
                icon: Icons.expand_more,
                kind: GloveButtonKind.ghost,
                fullWidth: true,
                onPressed: cubit.showAllTrades,
              ),
            ],
          ],
        ),
      ),
    );

    // Work done.
    add(
      LedgerCard(
        padding: const EdgeInsets.all(N360Space.card),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(
              icon: Icons.assignment_turned_in_outlined,
              title: l.dprSectionWork,
              done: state.work.isNotEmpty,
              trailing: state.work.isEmpty
                  ? null
                  : Text(l.dprRowsCount(state.work.length), style: N360Type.label),
            ),
            for (var i = 0; i < state.work.length; i++) ...<Widget>[
              SizedBox(height: i == 0 ? N360Space.md : N360Space.lg),
              DprLandOnce(
                key: ValueKey<String>('work-${state.work[i].boqItemId ?? 'free-$i'}'),
                id: 'work-${state.work[i].boqItemId ?? 'free-$i'}',
                landed: _landed,
                child: DprWorkRow(
                  line: state.work[i],
                  services: services,
                  onChange: () => unawaited(_openWork(state, index: i)),
                  onRemove: () => _removeWork(i),
                ),
              ),
            ],
            if (_undo != null) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              _UndoRow(onUndo: _undoRemove),
            ],
            const SizedBox(height: N360Space.md),
            DprActionTile(
              icon: Icons.add,
              label: l.dprAddWork,
              onTap: () => unawaited(_openWork(state)),
            ),
          ],
        ),
      ),
    );

    // Photos.
    add(
      LedgerCard(
        padding: const EdgeInsets.all(N360Space.card),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(
              icon: Icons.photo_library_outlined,
              title: l.dprSectionPhotos,
              done: state.photos.isNotEmpty,
              trailing: state.photos.isEmpty
                  ? null
                  : Text(l.dprPhotosCount(state.photos.length), style: N360Type.label),
            ),
            const SizedBox(height: N360Space.md),
            DprActionTile(
              icon: Icons.photo_camera,
              label: l.dprTakePhoto,
              tall: true,
              // Stays tappable at the limit, so the app can say why.
              onTap: state.capturing ? null : () => unawaited(_takePhoto()),
            ),
            if (state.photos.isNotEmpty) ...<Widget>[
              const SizedBox(height: N360Space.md),
              DprPhotoStrip(
                photos: state.photos,
                repository: services.dpr,
                landed: _landed,
                onRemove: (photo) => unawaited(_removePhoto(photo)),
              ),
            ],
          ],
        ),
      ),
    );

    // Hindrances and notes.
    add(
      LedgerCard(
        padding: const EdgeInsets.all(N360Space.card),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(
              icon: Icons.edit_note,
              title: l.dprSectionNotes,
              done: state.hindrances.trim().isNotEmpty || state.notes.trim().isNotEmpty,
              trailing: state.notesOpen ? null : Text(l.dprOptional, style: N360Type.caption),
            ),
            const SizedBox(height: N360Space.md),
            if (state.notesOpen) ...<Widget>[
              GloveTextField(
                label: l.dprHindrances,
                hint: l.dprHindrancesHint,
                controller: _hindrances,
                focusNode: _hindrancesFocus,
                minLines: 3,
                maxLines: 6,
                keyboardType: TextInputType.multiline,
                textInputAction: TextInputAction.newline,
                onChanged: cubit.setHindrances,
              ),
              const SizedBox(height: N360Space.md),
              GloveTextField(
                label: l.dprNotes,
                hint: l.dprNotesHint,
                controller: _notes,
                minLines: 3,
                maxLines: 6,
                keyboardType: TextInputType.multiline,
                textInputAction: TextInputAction.newline,
                onChanged: cubit.setNotes,
              ),
            ] else
              DprActionTile(
                icon: Icons.add,
                label: l.dprAddNotes,
                onTap: _openNotes,
              ),
          ],
        ),
      ),
    );

    return cards;
  }
}

class _Loading extends StatelessWidget {
  const _Loading();

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              DprTopBar(title: l.dprFormTitle),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    LedgerSkeleton(lines: 1, semanticsLabel: l.dprLoading),
                    const SizedBox(height: N360Space.cardGap),
                    LedgerSkeleton(lines: 2, figure: true, semanticsLabel: l.dprLoading),
                    const SizedBox(height: N360Space.cardGap),
                    LedgerSkeleton(lines: 2, semanticsLabel: l.dprLoading),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Offers a just-removed work row back.
class _UndoRow extends StatelessWidget {
  const _UndoRow({required this.onUndo});

  final VoidCallback onUndo;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return DprTapPad(
      onTap: onUndo,
      semanticsLabel: l.dprUndo,
      child: Row(
        children: <Widget>[
          const Icon(Icons.undo, size: N360IconSize.md, color: N360Colors.ink2),
          const SizedBox(width: N360Space.sm),
          Expanded(child: Text(l.dprRemoved, style: N360Type.label)),
          Text(l.dprUndo, style: N360Type.bodyStrong.copyWith(color: N360Colors.laterite)),
        ],
      ),
    );
  }
}

class _NoProject extends StatelessWidget {
  const _NoProject();

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          child: Column(
            children: <Widget>[
              const DprTopBar(),
              Expanded(
                child: Center(child: EmptyLedger(title: l.dprNoProject)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Quiet proof the work is safe. The words stay put so they never flicker; only
/// the mark changes, and it lands with a tiny press each time a save completes.
class _SaveIndicator extends StatelessWidget {
  const _SaveIndicator({required this.status, required this.tick});

  final DprSaveStatus status;
  final int tick;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    if (status == DprSaveStatus.clean) return const SizedBox(width: N360Size.tap);
    final bool failed = status == DprSaveStatus.failed;
    final bool pending = status == DprSaveStatus.dirty;
    final Color ink = failed ? N360Colors.stampRed : N360Colors.mossInk;
    final String text = failed ? l.dprNotSaved : l.dprSavedOnPhone;

    return Semantics(
      container: true,
      liveRegion: failed,
      label: failed ? l.dprNotSaved : (pending ? l.dprSaving : l.dprSavedOnPhone),
      child: ExcludeSemantics(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 92),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              TweenAnimationBuilder<double>(
                key: ValueKey<int>(tick),
                tween: Tween<double>(begin: pending ? 1 : 0.55, end: 1),
                duration: context.motion.tick,
                curve: N360Curves.stamp,
                builder: (context, scale, child) => Transform.scale(scale: scale, child: child),
                child: Icon(
                  failed
                      ? Icons.error_outline
                      : (pending ? Icons.radio_button_unchecked : Icons.check_circle),
                  size: N360IconSize.sm,
                  color: pending ? N360Colors.ink3 : ink,
                ),
              ),
              const SizedBox(width: 5),
              Flexible(
                child: Text(
                  text,
                  maxLines: 2,
                  style: N360Type.caption.copyWith(
                    color: ink,
                    fontWeight: FontWeight.w600,
                    height: 1.25,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
