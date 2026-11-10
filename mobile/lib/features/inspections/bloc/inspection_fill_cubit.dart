import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/sync/clock.dart';
import '../data/inspection_repository.dart';

enum FillProblem { unmarked, needRemark, saveFailed }

class FillState extends Equatable {
  const FillState({
    required this.today,
    required this.yesterday,
    this.ready = false,
    this.draft,
    this.checked = false,
    this.submitting = false,
    this.problem,
  });

  final String today;
  final String yesterday;

  /// The draft has been looked for.
  final bool ready;

  /// Null when it was finished or discarded, or never existed.
  final InspectionDraft? draft;

  /// Finishing was tried, so the items that stop it say so.
  final bool checked;
  final bool submitting;
  final FillProblem? problem;

  FillState copyWith({
    bool? ready,
    InspectionDraft? draft,
    bool? checked,
    bool? submitting,
    FillProblem? Function()? problem,
  }) {
    return FillState(
      today: today,
      yesterday: yesterday,
      ready: ready ?? this.ready,
      draft: draft ?? this.draft,
      checked: checked ?? this.checked,
      submitting: submitting ?? this.submitting,
      problem: problem == null ? this.problem : problem(),
    );
  }

  @override
  List<Object?> get props => <Object?>[today, yesterday, ready, draft, checked, submitting, problem];
}

/// Fills in a draft. Every mark is written to the phone at once, so an inspection
/// survives a call, a dead battery or the app being closed half way down the list.
class InspectionFillCubit extends Cubit<FillState> {
  InspectionFillCubit(this._services, this._uuid)
    : super(
        FillState(
          today: _services.context.today,
          yesterday: istDate(_services.context.clock.now().subtract(const Duration(days: 1))),
        ),
      ) {
    unawaited(_load());
  }

  final AppServices _services;
  final String _uuid;

  /// The latest draft, ahead of the state when someone is typing, because a
  /// keystroke in a remark should not rebuild sixty items.
  InspectionDraft? _draft;

  /// Writes happen in the order they were made.
  Future<void> _writes = Future<void>.value();

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    final InspectionDraft? draft = projectId == null ? null : await _services.inspections.readDraft(projectId, _uuid);
    if (isClosed) return;
    _draft = draft;
    emit(state.copyWith(ready: true, draft: draft));
  }

  void _persist(InspectionDraft draft) {
    _writes = _writes.then((_) => _services.inspections.saveDraft(draft)).catchError((Object _) {
      // The draft is still in memory. The next change writes it again.
    });
  }

  void _change(InspectionDraft draft, {bool show = true}) {
    _draft = draft;
    _persist(draft);
    if (show && !isClosed) emit(state.copyWith(draft: draft, problem: () => null));
  }

  void setResult(int position, ItemResult result) {
    final InspectionDraft? draft = _draft;
    if (draft == null) return;
    _change(draft.withMark(position, result: result));
  }

  /// Typing does not rebuild the page, unless a missing remark is being pointed out.
  void setRemark(int position, String text) {
    final InspectionDraft? draft = _draft;
    if (draft == null) return;
    _change(draft.withMark(position, remark: text), show: state.checked);
  }

  void setLocation(String text) {
    final InspectionDraft? draft = _draft;
    if (draft == null) return;
    _change(draft.copyWith(location: text), show: false);
  }

  void setDate(String date) {
    final InspectionDraft? draft = _draft;
    if (draft == null) return;
    _change(draft.copyWith(inspectedOn: date));
  }

  /// True when the inspection is safe on this phone and queued to be sent.
  /// Otherwise [FillState.problem] says why, and the items that stop it show it.
  Future<bool> finish() async {
    final InspectionDraft? draft = _draft;
    if (draft == null || state.submitting) return false;
    if (draft.unmarked.isNotEmpty || draft.needRemark.isNotEmpty) {
      emit(
        state.copyWith(
          draft: draft,
          checked: true,
          problem: () => draft.unmarked.isNotEmpty ? FillProblem.unmarked : FillProblem.needRemark,
        ),
      );
      return false;
    }
    emit(state.copyWith(draft: draft, submitting: true, problem: () => null));
    try {
      // The last mark must be on the phone first, or it could be written after
      // the draft has gone and bring it back.
      await _writes;
      await _services.inspections.finish(draft);
      return true;
    } on Object {
      if (!isClosed) emit(state.copyWith(submitting: false, problem: () => FillProblem.saveFailed));
      return false;
    }
  }

  Future<void> discard() async {
    final InspectionDraft? draft = _draft;
    if (draft == null) return;
    await _writes;
    await _services.inspections.discardDraft(draft.projectId, draft.uuid);
  }
}
