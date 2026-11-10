import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/scan_attendance_cubit.dart';
import '../data/attendance_repository.dart';
import 'qr_camera.dart';
import 'widgets/field_kit.dart';

/// Hold a worker's card up to the camera and they are marked present.
class ScanAttendanceScreen extends StatelessWidget {
  const ScanAttendanceScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ScanAttendanceCubit>(
      create: (context) => ScanAttendanceCubit(context.read<AppServices>()),
      child: const _ScanView(),
    );
  }
}

class _ScanView extends StatelessWidget {
  const _ScanView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      backgroundColor: N360Colors.sheet,
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocListener<ScanAttendanceCubit, ScanAttendanceState>(
            listenWhen: (a, b) => a.last?.number != b.last?.number && b.last != null,
            listener: (context, state) {
              switch (state.last!.outcome.result) {
                case ScanResult.marked:
                case ScanResult.queued:
                  N360Haptics.success();
                case ScanResult.alreadyMarked:
                  N360Haptics.select();
                case ScanResult.refused:
                case ScanResult.notACard:
                  N360Haptics.warning();
              }
            },
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                BlocBuilder<ScanAttendanceCubit, ScanAttendanceState>(
                  buildWhen: (a, b) => a.projectCode != b.projectCode,
                  builder: (context, state) => FieldTopBar(title: l.scanTitle, subtitle: state.projectCode),
                ),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.sm),
                    child: ClipRRect(
                      borderRadius: N360Radius.cardRadius,
                      child: QrCameraView(onCode: (code) => context.read<ScanAttendanceCubit>().onCode(code)),
                    ),
                  ),
                ),
                const _Result(),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// What the last card did, in words, and what this sitting has done so far.
class _Result extends StatelessWidget {
  const _Result();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return FieldActionBar(
      child: BlocBuilder<ScanAttendanceCubit, ScanAttendanceState>(
        builder: (context, state) {
          final ScanEntry? last = state.last;
          final (IconData icon, String text) = last == null ? (Icons.qr_code_scanner, l.scanHint) : _describe(l, last.outcome);
          return Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Semantics(
                liveRegion: true,
                container: true,
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Icon(icon, size: N360IconSize.lg, color: N360Colors.ink2),
                    const SizedBox(width: N360Space.sm),
                    Expanded(
                      child: Text(
                        text,
                        key: const ValueKey<String>('scan-result'),
                        style: N360Type.fit(N360Type.body, text),
                      ),
                    ),
                  ],
                ),
              ),
              if (state.counted > 0) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Text(l.scanCount(state.counted), style: N360Type.fit(N360Type.caption, l.scanCount(state.counted))),
              ],
              if (last?.outcome.result == ScanResult.queued) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Text(l.scanQueuedNote, style: N360Type.fit(N360Type.caption, l.scanQueuedNote)),
              ],
            ],
          );
        },
      ),
    );
  }

  static (IconData, String) _describe(AppLocalizations l, ScanOutcome o) {
    final String? name = o.workerName;
    return switch (o.result) {
      ScanResult.marked => (Icons.check_circle_outline, name == null ? l.scanMarkedUnknown : l.scanMarked(name)),
      ScanResult.alreadyMarked => (
        Icons.info_outline,
        name == null ? l.scanAlreadyUnknown : l.scanAlready(name, _statusWord(l, o.status)),
      ),
      ScanResult.queued => (Icons.cloud_upload_outlined, name == null ? l.scanQueuedUnknown : l.scanQueued(name)),
      ScanResult.refused => (Icons.block, <String>[?name, o.message ?? l.scanFailed].join(' · ')),
      ScanResult.notACard => (Icons.block, l.scanNotACard),
    };
  }

  static String _statusWord(AppLocalizations l, String? status) => switch (status) {
    AttendanceStatus.half => l.scanStatusHalf,
    AttendanceStatus.overtime => l.scanStatusOvertime,
    AttendanceStatus.absent => l.scanStatusAbsent,
    _ => l.scanStatusPresent,
  };
}
