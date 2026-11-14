import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart';
import '../../../core/sync/ids.dart';
import '../data/machine_repository.dart';

/// What stops the log, so a screen can say it under the right field.
enum MachineFormProblem {
  machineRequired,
  hoursInvalid,
  kmInvalid,
  breakdownInvalid,
  openingInvalid,
  closingInvalid,
  closingBelowOpening,
  nothingEntered,
  saveFailed,
}

/// What the person typed. Kept out of the state so typing does not rebuild.
class MachineFormText {
  const MachineFormText({
    this.hours = '',
    this.km = '',
    this.breakdown = '',
    this.opening = '',
    this.closing = '',
    this.operatorName = '',
    this.remarks = '',
  });

  final String hours;
  final String km;
  final String breakdown;
  final String opening;
  final String closing;
  final String operatorName;
  final String remarks;
}

class MachineFormState extends Equatable {
  const MachineFormState({
    required this.today,
    required this.yesterday,
    required this.date,
    this.ready = false,
    this.projectId,
    this.overview,
    this.machineId,
    this.submitting = false,
    this.problem,
  });

  final String today;
  final String yesterday;
  final String date;
  final bool ready;
  final int? projectId;
  final MachineOverview? overview;
  final int? machineId;
  final bool submitting;
  final MachineFormProblem? problem;

  Machine? get machine => machineId == null ? null : overview?.machine(machineId!);

  /// Machines of this project first, then the rest, each by code. A machine that
  /// works on another project is still offered: the server allows it.
  List<Machine> get machines {
    final List<Machine> all = <Machine>[...?overview?.machines];
    int rank(Machine row) => row.projectId == projectId ? 0 : 1;
    all.sort((a, b) => rank(a) != rank(b) ? rank(a).compareTo(rank(b)) : a.code.compareTo(b.code));
    return all;
  }

  /// Hours already logged for the chosen machine on the chosen day, as far as
  /// this phone knows. The server stops one machine at 24 hours a day.
  Hours? get loggedToday {
    final Machine? chosen = machine;
    if (chosen == null) return null;
    final Hours total = overview?.hoursOn(chosen.id, date) ?? Hours.zero;
    return total.isZero ? null : total;
  }

  MachineFormState copyWith({
    String? date,
    bool? ready,
    int? projectId,
    MachineOverview? overview,
    int? Function()? machineId,
    bool? submitting,
    MachineFormProblem? Function()? problem,
  }) {
    return MachineFormState(
      today: today,
      yesterday: yesterday,
      date: date ?? this.date,
      ready: ready ?? this.ready,
      projectId: projectId ?? this.projectId,
      overview: overview ?? this.overview,
      machineId: machineId == null ? this.machineId : machineId(),
      submitting: submitting ?? this.submitting,
      problem: problem == null ? this.problem : problem(),
    );
  }

  @override
  List<Object?> get props => <Object?>[date, ready, projectId, overview, machineId, submitting, problem];
}

/// A quick entry for one machine and one day. It works from the last copy of
/// the machines on the phone, and the log waits in the outbox until it can be sent.
class MachineLogFormCubit extends Cubit<MachineFormState> {
  MachineLogFormCubit(this._services, {int? machineId})
    : _requested = machineId,
      super(
        MachineFormState(
          today: _services.context.today,
          yesterday: istDate(_services.context.clock.now().subtract(const Duration(days: 1))),
          date: _services.context.today,
        ),
      ) {
    unawaited(_load());
  }

  final AppServices _services;
  final int? _requested;

  /// One log, one id, however many times this form tries to save it.
  final String _uuid = newUuid();

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (projectId == null) {
      if (!isClosed) emit(state.copyWith(ready: true));
      return;
    }
    final MachineOverview? overview = await _services.machines.readOverview(projectId);
    if (isClosed) return;
    final int? requested = _requested;
    emit(
      state.copyWith(
        ready: true,
        projectId: projectId,
        overview: overview,
        machineId: () => requested != null && overview?.machine(requested) != null ? requested : null,
      ),
    );
  }

  void setMachine(int id) => emit(state.copyWith(machineId: () => id, problem: () => null));

  void setDate(String date) => emit(state.copyWith(date: date));

  void clearProblem() => emit(state.copyWith(problem: () => null));

  bool _fail(MachineFormProblem problem) {
    emit(state.copyWith(problem: () => problem));
    return false;
  }

  /// Blank is nothing entered, which is not a mistake.
  static bool _blank(String text) => text.trim().isEmpty;

  /// True when the log is safe on this phone. Otherwise [MachineFormState.problem]
  /// says which field the server would have refused.
  Future<bool> submit(MachineFormText text) async {
    if (state.submitting) return false;
    final Machine? machine = state.machine;
    final int? projectId = state.projectId;
    if (machine == null || projectId == null) return _fail(MachineFormProblem.machineRequired);

    Hours? hours;
    if (!_blank(text.hours)) {
      hours = Hours.tryParse(text.hours);
      if (hours == null || Hours.hasExtraDecimals(text.hours) || hours.isNegative || hours > MachineRepository.maxHoursPerDay) {
        return _fail(MachineFormProblem.hoursInvalid);
      }
    }
    Qty? km;
    if (!_blank(text.km)) {
      km = Qty.tryParse(text.km);
      if (km == null || km.isNegative || km > MachineRepository.maxKm) return _fail(MachineFormProblem.kmInvalid);
    }
    Hours? breakdown;
    if (!_blank(text.breakdown)) {
      breakdown = Hours.tryParse(text.breakdown);
      if (breakdown == null ||
          Hours.hasExtraDecimals(text.breakdown) ||
          breakdown.isNegative ||
          breakdown > MachineRepository.maxHoursPerDay) {
        return _fail(MachineFormProblem.breakdownInvalid);
      }
    }
    Qty? opening;
    if (!_blank(text.opening)) {
      opening = Qty.tryParse(text.opening);
      if (opening == null || opening.isNegative || opening > MachineRepository.maxReading) {
        return _fail(MachineFormProblem.openingInvalid);
      }
    }
    Qty? closing;
    if (!_blank(text.closing)) {
      closing = Qty.tryParse(text.closing);
      if (closing == null || closing.isNegative || closing > MachineRepository.maxReading) {
        return _fail(MachineFormProblem.closingInvalid);
      }
    }
    if (opening != null && closing != null && closing < opening) return _fail(MachineFormProblem.closingBelowOpening);

    // The server's rule: something worked, or lost to breakdown, or both
    // readings on a machine whose meter counts hours or km.
    final bool fromMeter = opening != null && closing != null && (machine.meterIsHours || machine.meterIsKm);
    if (hours == null && km == null && breakdown == null && !fromMeter) return _fail(MachineFormProblem.nothingEntered);

    emit(state.copyWith(submitting: true, problem: () => null));
    try {
      await _services.machines.log(
        uuid: _uuid,
        projectId: projectId,
        machine: machine,
        date: state.date,
        hours: hours,
        km: km,
        breakdownHours: breakdown,
        openingReading: opening,
        closingReading: closing,
        operatorName: text.operatorName,
        remarks: text.remarks,
      );
      return true;
    } on Object {
      if (!isClosed) emit(state.copyWith(submitting: false, problem: () => MachineFormProblem.saveFailed));
      return false;
    }
  }
}
