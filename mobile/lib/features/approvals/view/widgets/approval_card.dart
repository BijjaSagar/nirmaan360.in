import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/approvals_inbox_cubit.dart';
import '../../data/approval_repository.dart';
import 'approval_read.dart';

/// One item waiting for a decision, with its deciding figure large.
class ApprovalCard extends StatelessWidget {
  const ApprovalCard({super.key, required this.row, required this.now, required this.onTap});

  final ApprovalRow row;
  final DateTime now;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ApprovalFigure? figure = figureOf(row, l);
    final String project = <String>[?row.projectCode, ?row.projectName].join(' · ');
    final String who = <String>[
      ?row.submittedByName,
      if (row.submittedAt != null) agoText(l, row.submittedAt, now),
    ].join(' · ');
    final String type = docTypeWord(l, row.docType);
    final bool deviation = isDeviationDoc(row);
    final Color figureInk = figure?.negative == true ? N360Colors.laterite : N360Colors.ink;
    final String figureSpoken = figure == null ? '' : '${figure.value} ${figure.unit ?? ''}';

    final Widget body = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            Icon(docTypeIcon(row.docType), size: 20, color: N360Colors.ink2),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                N360Type.eyebrowText(type),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.eyebrowFor(type),
              ),
            ),
            if (deviation) RubberStamp(kind: StampKind.rejected, label: l.approvalsDeviation, size: StampSize.small),
          ],
        ),
        const SizedBox(height: 2),
        Text(
          row.title,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: N360Type.heading.copyWith(fontSize: 20, height: 1.28),
        ),
        if (project.isNotEmpty) ...<Widget>[
          const SizedBox(height: 2),
          Text(project, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.body.copyWith(height: 1.3)),
        ],
        if (figure != null) ...<Widget>[
          const SizedBox(height: 4),
          Align(
            alignment: Alignment.centerRight,
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerRight,
              child: Text.rich(
                TextSpan(
                  text: figure.value,
                  children: <InlineSpan>[
                    if (figure.unit != null)
                      TextSpan(
                        text: ' ${figure.unit}',
                        style: N360Type.figure.copyWith(color: figureInk, fontWeight: FontWeight.w400),
                      ),
                  ],
                ),
                style: N360Type.figureLarge.copyWith(color: figureInk),
              ),
            ),
          ),
        ],
        const SizedBox(height: 4),
        const RuledDivider(),
        const SizedBox(height: 4),
        Row(
          children: <Widget>[
            Expanded(
              child: Text(who, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
            ),
            if (!row.canAct)
              Flexible(
                child: Text(
                  l.approvalsWaitingOn(roleWord(l, row.currentRole, serverLabel: row.currentRoleLabel)),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.caption.copyWith(fontWeight: FontWeight.w700),
                ),
              )
            else ...<Widget>[
              Text(l.approvalsOpen, style: N360Type.label.copyWith(color: N360Colors.stampRed, height: 1.3)),
              const Icon(Icons.chevron_right, size: 20, color: N360Colors.stampRed),
            ],
          ],
        ),
      ],
    );

    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.md, N360Space.md, N360Space.sm),
      onTap: onTap,
      semanticsLabel: '$type. ${row.title}. $project. $figureSpoken. $who',
      child: body,
    );
  }
}

/// A card that has just been decided. The stamp lands on it, it holds for a
/// moment, then it slides off and the cards below rise into the gap.
class LeavingApprovalCard extends StatefulWidget {
  const LeavingApprovalCard({super.key, required this.leaving, required this.now, required this.onDone});

  final LeavingCard leaving;
  final DateTime now;
  final VoidCallback onDone;

  @override
  State<LeavingApprovalCard> createState() => _LeavingApprovalCardState();
}

class _LeavingApprovalCardState extends State<LeavingApprovalCard> with SingleTickerProviderStateMixin {
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
    final bool approved = leaving.action == ApprovalAction.approved;
    final double holdPart = _hold.inMilliseconds / (_hold + _slide).inMilliseconds;

    final Widget card = Stack(
      alignment: Alignment.center,
      children: <Widget>[
        ApprovalCard(row: leaving.row, now: widget.now, onTap: null),
        RubberStamp(
          kind: approved ? StampKind.approved : StampKind.returned,
          label: approved ? l.statusApproved : l.statusReturned,
          size: StampSize.large,
          animate: true,
        ),
      ],
    );

    return RepaintBoundary(
      child: ClipRect(
        child: AnimatedBuilder(
          animation: _exit,
          child: Padding(
            padding: const EdgeInsets.only(bottom: N360Space.md),
            child: card,
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
