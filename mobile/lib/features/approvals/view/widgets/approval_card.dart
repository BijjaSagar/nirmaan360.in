import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/approvals_inbox_cubit.dart';
import '../../data/approval_repository.dart';
import 'approval_read.dart';

/// One item waiting for a decision, with its deciding figure large.
class ApprovalCard extends StatelessWidget {
  const ApprovalCard({super.key, required this.row, required this.now, required this.onTap, this.share});

  final ApprovalRow row;
  final DateTime now;
  final VoidCallback? onTap;
  final ApprovalShare? share;

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
    final ApprovalShare? portion = share;

    final Widget body = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            IconTile(icon: docTypeIcon(row.docType), semanticLabel: type),
            const SizedBox(width: N360Space.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(type, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
                  Text(
                    row.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.heading.copyWith(fontSize: 16, height: 1.3),
                  ),
                ],
              ),
            ),
          ],
        ),
        if (figure != null) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          Row(
            children: <Widget>[
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text.rich(
                    TextSpan(
                      text: figure.value,
                      children: <InlineSpan>[
                        if (figure.unit != null)
                          TextSpan(
                            text: ' ${figure.unit}',
                            style: N360Type.heading.copyWith(color: figureInk, fontWeight: FontWeight.w500),
                          ),
                      ],
                    ),
                    style: N360Type.figureHero.copyWith(color: figureInk, fontSize: 32),
                  ),
                ),
              ),
              if (portion != null) ...<Widget>[
                const SizedBox(width: N360Space.sm),
                Semantics(
                  label: '${portion.label} ${l.measurementShareOfBoq}',
                  child: ExcludeSemantics(
                    child: SquarePercent(fraction: portion.fraction, label: portion.label, size: 56),
                  ),
                ),
              ],
            ],
          ),
        ],
        if (deviation) ...<Widget>[const SizedBox(height: N360Space.sm), _DeviationFlag(label: l.approvalsDeviation)],
        if (project.isNotEmpty) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          Text(project, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
        ],
        const SizedBox(height: 2),
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
              Text(l.approvalsOpen, style: N360Type.label.copyWith(color: N360Colors.laterite, height: 1.3)),
              const Icon(Icons.north_east, size: 18, color: N360Colors.laterite),
            ],
          ],
        ),
      ],
    );

    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.md, N360Space.md, N360Space.sm + 2),
      onTap: onTap,
      semanticsLabel: '$type. ${row.title}. $project. $figureSpoken. $who',
      child: body,
    );
  }
}

/// The urgent fill with dark ink, so a deviation reads from across the site.
class _DeviationFlag extends StatelessWidget {
  const _DeviationFlag({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: DecoratedBox(
        decoration: const BoxDecoration(color: N360Colors.accent, borderRadius: N360Radius.sm),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.sm, vertical: 3),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const Icon(Icons.warning_amber, size: 18, color: N360Colors.ink),
              const SizedBox(width: 4),
              Text(label, style: N360Type.bodyStrong.copyWith(height: 1.3, fontWeight: FontWeight.w700)),
            ],
          ),
        ),
      ),
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
