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

/// The daily report: one continuous sheet, saved on every change.
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
    _lifecycle.dispose();
    _scroll.dispose();
    _hindrances.dispose();
    _hindrancesFocus.dispose();
    _notes.dispose();
    super.dispose();
  }

  // Actions -------------------------------------------------------------------

  /// A toast that clears the pinned Send bar.
  void _toast(String message, PaperToastKind kind) {
    showPaperToast(context, message, kind: kind, bottomOffset: 100);
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

  void _openNotes() {
    context.read<DprFormCubit>().openNotes();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _hindrancesFocus.requestFocus();
    });
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
          DprFormPhase.loading => const Scaffold(body: LedgerPaper()),
          DprFormPhase.noProject => const _NoProject(),
          DprFormPhase.locked => DprDetailScreen(clientUuid: state.clientUuid!),
          DprFormPhase.editing => _sheet(context, state),
        };
      },
    );
  }

  Widget _sheet(BuildContext context, DprFormState state) {
    final l = AppLocalizations.of(context);
    final cubit = context.read<DprFormCubit>();
    final bool typing = MediaQuery.viewInsetsOf(context).bottom > 0;
    final List<Widget> items = _items(context, state, cubit, l);

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
                child: ListView.builder(
                  controller: _scroll,
                  keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.manual,
                  padding: const EdgeInsets.only(bottom: N360Space.md),
                  itemCount: items.length,
                  itemBuilder: (context, index) => items[index],
                ),
              ),
              if (!typing)
                DprBottomAction(
                  label: state.isReturned ? l.dprSendAgain : l.dprSendReport,
                  icon: Icons.send,
                  onPressed: () => unawaited(_onSendPressed()),
                ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _items(
    BuildContext context,
    DprFormState state,
    DprFormCubit cubit,
    AppLocalizations l,
  ) {
    final services = context.read<AppServices>();
    final items = <Widget>[];
    var rise = 0;

    void slice(Widget child, {double top = 0, double bottom = 0, Key? key}) {
      items.add(
        DprSheetSlice(
          key: key,
          top: top,
          bottom: bottom,
          first: items.isEmpty,
          child: StaggeredRise(index: rise++, child: child),
        ),
      );
    }

    // Masthead.
    slice(
      SheetHeader(
        eyebrow: state.project?.name,
        title: dprDateLabel(context, state.reportDate),
        trailing: state.isReturned
            ? RubberStamp(kind: StampKind.returned, label: l.statusReturned)
            : RubberStamp(kind: StampKind.draft, label: l.statusDraft),
      ),
      top: N360Space.md,
    );

    if (state.isReturned) {
      slice(
        DprRemarkSlip(
          title: l.dprApproverRemark,
          message: state.returnRemark ?? l.dprReturnedNoRemark,
        ),
        top: N360Space.md,
      );
    }

    // 1. Weather.
    slice(
      DprSectionHead(
        number: 1,
        title: l.dprSectionWeather,
        error: state.problems.contains(DprFormProblem.weather) ? l.dprNeedWeather : null,
      ),
      top: N360Space.lg,
    );
    slice(
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
      top: N360Space.sm,
    );

    // 2. Workers.
    slice(
      DprSectionHead(
        number: 2,
        title: l.dprSectionWorkers,
        trailing: DprWorkerTotal(total: state.totalWorkers),
        error: state.problems.contains(DprFormProblem.workers) ? l.dprNeedWorkers : null,
      ),
      top: N360Space.xl,
    );
    if (state.canCopyYesterday) {
      slice(
        GloveButton(
          label: l.dprSameAsYesterday(state.yesterdayTotal!),
          icon: Icons.history,
          kind: GloveButtonKind.secondary,
          large: true,
          fullWidth: true,
          onPressed: () => unawaited(_copyYesterday()),
        ),
        top: N360Space.sm,
      );
    }
    final List<String> visible = state.visibleTrades;
    for (var i = 0; i < visible.length; i++) {
      final String trade = visible[i];
      items.add(
        DprSheetSlice(
          key: ValueKey<String>('trade-$trade'),
          top: i == 0 ? N360Space.xs : 0,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              StaggeredRise(
                index: rise++,
                child: DprTradeRow(
                  trade: trade,
                  count: state.count(trade),
                  onChanged: (value) => cubit.setCount(trade, value),
                ),
              ),
              if (i != visible.length - 1 || state.hiddenTradeCount > 0) const RuledDivider(),
            ],
          ),
        ),
      );
    }
    if (state.hiddenTradeCount > 0) {
      slice(
        GloveButton(
          label: l.dprMoreTrades(state.hiddenTradeCount),
          icon: Icons.expand_more,
          kind: GloveButtonKind.ghost,
          fullWidth: true,
          onPressed: cubit.showAllTrades,
        ),
      );
    }

    // 3. Work done.
    slice(
      DprSectionHead(
        number: 3,
        title: l.dprSectionWork,
        trailing: state.work.isEmpty
            ? null
            : Text(l.dprRowsCount(state.work.length), style: N360Type.label),
      ),
      top: N360Space.xl,
    );
    for (var i = 0; i < state.work.length; i++) {
      final WorkLine line = state.work[i];
      items.add(
        DprSheetSlice(
          key: ValueKey<String>('work-${line.boqItemId ?? 'free-$i'}'),
          child: DprLandOnce(
            id: 'work-${line.boqItemId ?? 'free-$i'}',
            landed: _landed,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                DprWorkRow(
                  line: line,
                  services: services,
                  onChange: () => unawaited(_openWork(state, index: i)),
                  onRemove: () => cubit.removeWork(i),
                ),
                const RuledDivider(),
              ],
            ),
          ),
        ),
      );
    }
    slice(
      GloveButton(
        label: l.dprAddWork,
        icon: Icons.add,
        kind: GloveButtonKind.secondary,
        large: true,
        fullWidth: true,
        onPressed: () => unawaited(_openWork(state)),
      ),
      top: N360Space.sm,
    );

    // 4. Photos.
    slice(
      DprSectionHead(
        number: 4,
        title: l.dprSectionPhotos,
        trailing: state.photos.isEmpty
            ? null
            : Text(l.dprPhotosCount(state.photos.length), style: N360Type.label),
      ),
      top: N360Space.xl,
    );
    slice(
      DprCameraButton(
        busy: state.capturing || state.photos.length >= dprMaxPhotos,
        onTap: () => unawaited(_takePhoto()),
      ),
      top: N360Space.sm,
    );
    if (state.photos.isNotEmpty) {
      slice(
        DprPhotoStrip(
          photos: state.photos,
          repository: services.dpr,
          landed: _landed,
          onRemove: (photo) => unawaited(_removePhoto(photo)),
        ),
        top: N360Space.sm,
      );
    }

    // 5. Hindrances and notes.
    slice(
      DprSectionHead(number: 5, title: l.dprSectionNotes),
      top: N360Space.xl,
    );
    if (state.notesOpen) {
      slice(
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
        top: N360Space.sm,
      );
      slice(
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
        top: N360Space.md,
      );
    } else {
      slice(
        LedgerCard(
          onTap: _openNotes,
          semanticsLabel: l.dprAddNotes,
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: 15),
          child: Row(
            children: <Widget>[
              const Icon(Icons.edit_note, size: 28, color: N360Colors.ink),
              const SizedBox(width: N360Space.sm),
              Expanded(child: Text(l.dprAddNotes, style: N360Type.bodyStrong)),
              Text(l.dprOptional, style: N360Type.caption),
            ],
          ),
        ),
        top: N360Space.sm,
      );
    }

    // The foot of the sheet.
    items.add(
      const DprSheetSlice(
        last: true,
        child: SizedBox(height: N360Space.lg),
      ),
    );
    return items;
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

/// Quiet proof the work is safe. The word stays put so it never flickers; only
/// the mark changes, and it lands with a tiny press each time a save completes.
class _SaveIndicator extends StatelessWidget {
  const _SaveIndicator({required this.status, required this.tick});

  final DprSaveStatus status;
  final int tick;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    if (status == DprSaveStatus.clean) return const SizedBox.shrink();
    final bool failed = status == DprSaveStatus.failed;
    final bool pending = status == DprSaveStatus.dirty;
    final Color ink = failed ? N360Colors.stampRed : N360Colors.mossInk;
    final String text = failed ? l.dprNotSaved : l.dprSavedOnPhone;

    return Semantics(
      container: true,
      liveRegion: failed,
      label: failed ? l.dprNotSaved : (pending ? l.dprSaving : l.dprSavedOnPhone),
      child: ExcludeSemantics(
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
                size: 20,
                color: pending ? N360Colors.ink3 : ink,
              ),
            ),
            const SizedBox(width: 5),
            Text(
              text,
              maxLines: 1,
              style: N360Type.caption.copyWith(color: ink, fontWeight: FontWeight.w600),
            ),
          ],
        ),
      ),
    );
  }
}
