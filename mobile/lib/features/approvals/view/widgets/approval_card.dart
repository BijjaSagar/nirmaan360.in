import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/approvals_inbox_cubit.dart';
import '../../data/approval_repository.dart';
import 'approval_read.dart';

/// One item waiting for a decision: its deciding figure first, then what it
/// is, then who sent it and when, on one line.
class ApprovalTile extends StatelessWidget {
  const ApprovalTile({super.key, required this.row, required this.now, required this.onTap, this.status});

  final ApprovalRow row;
  final DateTime now;
  final VoidCallback? onTap;

  /// Overrides the row's own pill, for a row that has just been decided.
  final Widget? status;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ApprovalFigure? figure = figureOf(row, l);
    final String headline = headlineOf(row);
    final String amount = figure == null ? '' : '${figure.value} ${figure.unit ?? ''}'.trim();
    final String meta = <String>[
      ?row.projectCode,
      ?row.submittedByName,
      if (row.submittedAt != null) agoText(l, row.submittedAt, now),
    ].join(' · ');
    final Widget? pill = status ?? _ownStatus(l);

    return LedgerListTile(
      title: headline,
      subtitle: meta,
      semanticsLabel: <String>[
        docTypeWord(l, row.docType),
        headline,
        if (amount.isNotEmpty) amount,
        if (isDeviationDoc(row)) l.approvalsDeviation,
        meta,
      ].join(', '),
      leading: Icon(docTypeIcon(row.docType), size: N360IconSize.lg, color: N360Colors.ink2),
      status: amount.isEmpty && pill == null
          ? null
          : Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (amount.isNotEmpty) Text(amount, maxLines: 1, style: N360Type.figure),
                if (amount.isNotEmpty && pill != null) const SizedBox(height: N360Space.xxs),
                ?pill,
              ],
            ),
      onTap: onTap,
    );
  }

  Widget? _ownStatus(AppLocalizations l) {
    if (isDeviationDoc(row)) return StatusPill(kind: StampKind.rejected, label: l.approvalsDeviation);
    if (!row.canAct) {
      return StatusPill(
        kind: StampKind.inReview,
        label: roleWord(l, row.currentRole, serverLabel: row.currentRoleLabel),
      );
    }
    return null;
  }
}

/// A row that has just been decided. It shows the decision, holds for a moment,
/// then slides off and the rows below rise into the gap.
class LeavingApprovalTile extends StatefulWidget {
  const LeavingApprovalTile({super.key, required this.leaving, required this.now, required this.onDone});

  final LeavingCard leaving;
  final DateTime now;
  final VoidCallback onDone;

  @override
  State<LeavingApprovalTile> createState() => _LeavingApprovalTileState();
}

class _LeavingApprovalTileState extends State<LeavingApprovalTile> with SingleTickerProviderStateMixin {
  late final AnimationController _exit = AnimationController(vsync: this);
  bool _started = false;

  static const Duration _hold = Duration(milliseconds: 520);
  static const Duration _slide = Duration(milliseconds: 320);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    final N360Motion motion = context.motion;
    if (motion.reduced) {
      WidgetsBinding.instance.addPostFrameCallback((_) => widget.onDone());
      return;
    }
    _exit.duration = _hold + _slide;
    _exit.forward().whenComplete(() {
      if (mounted) widget.onDone();
    });
  }

  @override
  void dispose() {
    _exit.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final LeavingCard leaving = widget.leaving;
    final (StampKind kind, String word) = switch (leaving.action) {
      ApprovalAction.approved => (StampKind.approved, l.statusApproved),
      ApprovalAction.sentBack => (StampKind.returned, l.statusReturned),
      ApprovalAction.rejected => (StampKind.rejected, l.statusRejected),
    };
    final double holdPart = _hold.inMilliseconds / (_hold + _slide).inMilliseconds;

    return RepaintBoundary(
      child: ClipRect(
        child: AnimatedBuilder(
          animation: _exit,
          child: ApprovalTile(
            row: leaving.row,
            now: widget.now,
            onTap: null,
            status: StatusPill(kind: kind, label: word),
          ),
          builder: (context, child) {
            final double raw = ((_exit.value - holdPart) / (1 - holdPart)).clamp(0.0, 1.0);
            final double p = N360Curves.rise.transform(raw);
            return Align(
              alignment: Alignment.bottomCenter,
              heightFactor: lerpDouble(1, 0, p),
              child: Transform.translate(offset: Offset(lerpDouble(0, 420, p)!, 0), child: child),
            );
          },
        ),
      ),
    );
  }
}
