import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';
import 'rolling_number.dart';

enum SlipStatus { waiting, sending, sent, attention }

/// What one outbox slip says. Status text is translated by the caller.
@immutable
class SlipData {
  const SlipData({
    required this.id,
    required this.title,
    required this.status,
    required this.statusLabel,
    this.subtitle,
    this.icon,
  });

  final String id;
  final String title;
  final String? subtitle;
  final SlipStatus status;
  final String statusLabel;
  final IconData? icon;

  SlipData sent(String label) => SlipData(
        id: id,
        title: title,
        subtitle: subtitle,
        icon: icon,
        status: SlipStatus.sent,
        statusLabel: label,
      );
}

/// One queued entry, as a paper slip torn from a pad. When [status] becomes
/// [SlipStatus.sent], a small tick is stamped onto it.
class OutboxSlip extends StatefulWidget {
  const OutboxSlip({
    super.key,
    required this.title,
    required this.status,
    required this.statusLabel,
    this.subtitle,
    this.icon,
    this.onTap,
    this.onSettled,
  });

  final String title;
  final String? subtitle;
  final SlipStatus status;
  final String statusLabel;
  final IconData? icon;
  final VoidCallback? onTap;

  /// Called once after the tick has landed and held long enough to be enjoyed.
  final VoidCallback? onSettled;

  @override
  State<OutboxSlip> createState() => _OutboxSlipState();
}

class _OutboxSlipState extends State<OutboxSlip> with SingleTickerProviderStateMixin {
  late final AnimationController _tick = AnimationController(vsync: this);
  bool _initialised = false;
  bool _feltImpact = false;

  // The stamp takes the first part of the timeline, the rest is the hold.
  double get _landAt =>
      N360Durations.tick.inMilliseconds / (N360Durations.tick + N360Durations.slipHold).inMilliseconds;

  @override
  void initState() {
    super.initState();
    _tick.addListener(_watchImpact);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_initialised) return;
    _initialised = true;
    if (widget.status == SlipStatus.sent) _playTick();
  }

  @override
  void didUpdateWidget(covariant OutboxSlip oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.status != SlipStatus.sent && widget.status == SlipStatus.sent) _playTick();
  }

  void _playTick() {
    final motion = context.motion;
    _feltImpact = false;
    _tick.duration = motion.reduced ? Duration.zero : motion.tick + motion.slipHold;
    _tick.forward(from: 0).whenComplete(() {
      if (!mounted) return;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) widget.onSettled?.call();
      });
    });
  }

  void _watchImpact() {
    // Contact is where the stamp curve first reaches rest, about 34% in.
    if (!_feltImpact && _tick.value >= _landAt * 0.34) {
      _feltImpact = true;
      N360Haptics.success();
    }
  }

  @override
  void dispose() {
    _tick.dispose();
    super.dispose();
  }

  Color get _band => switch (widget.status) {
        SlipStatus.waiting => N360Colors.ink3,
        SlipStatus.sending => N360Colors.orange,
        SlipStatus.sent => N360Colors.moss,
        SlipStatus.attention => N360Colors.stampRed,
      };

  @override
  Widget build(BuildContext context) {
    final Widget body = Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: <Widget>[
        if (widget.icon != null) ...<Widget>[
          SizedBox.square(
            dimension: N360Size.tileSmall,
            child: CustomPaint(
              painter: const SoftSurfacePainter(radius: N360Radius.small + 2),
              child: Center(child: Icon(widget.icon, size: N360IconSize.md, color: N360Colors.ink2)),
            ),
          ),
          const SizedBox(width: N360Space.tile),
        ],
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                widget.title,
                style: N360Type.fit(N360Type.bodyStrong, widget.title),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              if (widget.subtitle != null)
                Text(
                  widget.subtitle!,
                  style: N360Type.fit(N360Type.caption, widget.subtitle!),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
            ],
          ),
        ),
        const SizedBox(width: N360Space.sm),
        _StatusMark(
          status: widget.status,
          label: widget.statusLabel,
          tick: _tick,
          landAt: _landAt,
        ),
      ],
    );

    final String spoken = '${widget.title}. ${widget.statusLabel}';
    final bool tappable = widget.onTap != null;
    final Widget slip = PressTarget(
      onTap: widget.onTap,
      semanticsLabel: tappable ? spoken : null,
      haptic: tappable ? N360Haptics.tap : null,
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth * 0.6),
        child: CustomPaint(
          painter: _SlipPainter(band: _band),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.md,
              N360Space.sm,
              N360Space.md,
              N360Space.sm,
            ),
            child: child,
          ),
        ),
      ),
      child: ConstrainedBox(constraints: const BoxConstraints(minHeight: 44), child: body),
    );
    if (tappable) return slip;
    return Semantics(container: true, label: spoken, child: ExcludeSemantics(child: slip));
  }
}

/// A soft rounded slip with a short status band on its left edge.
class _SlipPainter extends CustomPainter {
  const _SlipPainter({required this.band});

  final Color band;

  @override
  void paint(Canvas canvas, Size size) {
    final RRect face = RRect.fromRectAndRadius(
      Offset.zero & size,
      const Radius.circular(N360Radius.medium),
    );
    canvas.drawRRect(face, Paint()..color = N360Colors.paper);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(0, 12, 3, size.height - 24),
        const Radius.circular(2),
      ),
      Paint()..color = band,
    );
  }

  @override
  bool shouldRepaint(_SlipPainter oldDelegate) => oldDelegate.band != band;
}

class _StatusMark extends StatelessWidget {
  const _StatusMark({required this.status, required this.label, required this.tick, required this.landAt});

  final SlipStatus status;
  final String label;
  final Animation<double> tick;
  final double landAt;

  @override
  Widget build(BuildContext context) {
    final TextStyle text = N360Type.fit(N360Type.caption, label).copyWith(fontWeight: FontWeight.w600);
    return switch (status) {
      SlipStatus.waiting => Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Icon(Icons.schedule, size: N360IconSize.sm, color: N360Colors.inkLabel),
            const SizedBox(width: 4),
            Text(label, style: text),
          ],
        ),
      SlipStatus.sending => Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const _SlipSpinner(),
            const SizedBox(width: 4),
            Text(label, style: text.copyWith(color: N360Colors.laterite)),
          ],
        ),
      SlipStatus.attention => Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Icon(Icons.error_outline, size: N360IconSize.sm, color: N360Colors.stampRed),
            const SizedBox(width: 4),
            Text(label, style: text.copyWith(color: N360Colors.stampRed)),
          ],
        ),
      SlipStatus.sent => Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(label, style: text.copyWith(color: N360Colors.mossInk)),
            const SizedBox(width: 6),
            _TickStamp(tick: tick, landAt: landAt),
          ],
        ),
    };
  }
}

/// The small tick that lands on a slip: a double ring with a check, tilted like
/// the status stamps. Opaque from frame 0, it only changes size.
class _TickStamp extends StatelessWidget {
  const _TickStamp({required this.tick, required this.landAt});

  final Animation<double> tick;
  final double landAt;

  @override
  Widget build(BuildContext context) {
    return RepaintBoundary(
      child: AnimatedBuilder(
        animation: tick,
        child: const CustomPaint(size: Size.square(28), painter: _TickPainter()),
        builder: (context, child) {
          final double local = landAt <= 0 ? 1 : (tick.value / landAt).clamp(0.0, 1.0);
          final double v = N360Curves.stamp.transform(local);
          return Transform.scale(scale: lerpDouble(2.1, 1, v), child: child);
        },
      ),
    );
  }
}

class _TickPainter extends CustomPainter {
  const _TickPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final Offset c = size.center(Offset.zero);
    final double r = size.shortestSide / 2;
    canvas.save();
    canvas.translate(c.dx, c.dy);
    canvas.rotate(-7 * math.pi / 180);
    canvas.drawRRect(
      RRect.fromRectAndRadius(Rect.fromCircle(center: Offset.zero, radius: r - 1), const Radius.circular(9)),
      Paint()..color = N360Colors.moss,
    );
    final Path check = Path()
      ..moveTo(-r * 0.46, r * 0.04)
      ..lineTo(-r * 0.12, r * 0.38)
      ..lineTo(r * 0.5, -r * 0.34);
    canvas.drawPath(
      check,
      Paint()
        ..color = N360Colors.sheet
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..strokeWidth = 3,
    );
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _SlipSpinner extends StatefulWidget {
  const _SlipSpinner();

  @override
  State<_SlipSpinner> createState() => _SlipSpinnerState();
}

class _SlipSpinnerState extends State<_SlipSpinner> with SingleTickerProviderStateMixin {
  late final AnimationController _spin =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1100));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (context.motion.reduced) {
      _spin.stop();
    } else if (!_spin.isAnimating) {
      _spin.repeat();
    }
  }

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return RepaintBoundary(
      child: RotationTransition(
        turns: _spin,
        child: const Icon(Icons.sync, size: N360IconSize.sm, color: N360Colors.laterite),
      ),
    );
  }
}

/// The outbox: slips in a tray. Give it the current queue and it does the rest.
///
/// When a slip's status becomes sent, or its id drops out of [slips], a tick
/// stamps it, then it slides up and out of the tray and the slips below rise to
/// fill the gap. They leave one at a time, so a large batch reads as a steady
/// run of satisfying ticks instead of a flash. Only [maxVisible] slips are
/// live; the rest show as paper edges under the stack and in the tray lip.
///
/// With reduced motion, sent slips are simply gone.
class SlipStack extends StatefulWidget {
  const SlipStack({
    super.key,
    required this.count,
    required this.slips,
    this.trayLabel,
    this.maxVisible = 4,
    this.onSlipTap,
    this.onSlipGone,
  });

  /// Everything waiting, which can exceed [slips] when the queue is long.
  final int count;

  /// Queue order, oldest first. The oldest sits at the top of the tray.
  final List<SlipData> slips;

  /// Words beside the rolling count on the tray lip, such as "on phone".
  final String? trayLabel;
  final int maxVisible;
  final ValueChanged<SlipData>? onSlipTap;

  /// A slip has fully left the tray.
  final ValueChanged<String>? onSlipGone;

  @override
  State<SlipStack> createState() => _SlipStackState();
}

class _SlipEntry {
  _SlipEntry(this.data);

  SlipData data;
  bool settled = false;
}

class _SlipStackState extends State<SlipStack> with SingleTickerProviderStateMixin {
  late final AnimationController _leave;
  final List<_SlipEntry> _entries = <_SlipEntry>[];
  _SlipEntry? _leaving;
  bool _ready = false;

  static const double _gap = N360Space.sm;

  @override
  void initState() {
    super.initState();
    _leave = AnimationController(vsync: this);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_ready) return;
    _ready = true;
    _reconcile();
  }

  @override
  void didUpdateWidget(covariant SlipStack oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.slips != widget.slips) _reconcile();
  }

  void _reconcile() {
    final bool reduced = context.motion.reduced;
    final Map<String, _SlipEntry> known = <String, _SlipEntry>{for (final e in _entries) e.data.id: e};
    final Map<String, int> oldIndex = <String, int>{
      for (var i = 0; i < _entries.length; i++) _entries[i].data.id: i,
    };
    final List<_SlipEntry> next = <_SlipEntry>[];
    for (final data in widget.slips) {
      final entry = known.remove(data.id);
      if (entry == null) {
        next.add(_SlipEntry(data));
      } else {
        entry.data = data;
        next.add(entry);
      }
    }
    // A slip the caller already removed still gets its tick and exit.
    final List<_SlipEntry> departed = known.values.toList()
      ..sort((a, b) => oldIndex[a.data.id]!.compareTo(oldIndex[b.data.id]!));
    for (final entry in departed) {
      if (entry.data.status != SlipStatus.sent) {
        entry.data = entry.data.sent(entry.data.statusLabel);
      }
      next.insert(math.min(oldIndex[entry.data.id]!, next.length), entry);
    }
    _entries
      ..clear()
      ..addAll(next);
    if (reduced) {
      _entries.removeWhere((e) => e.data.status == SlipStatus.sent);
      _leave.stop();
      _leaving = null;
    }
  }

  void _settled(_SlipEntry entry) {
    entry.settled = true;
    _advance();
  }

  void _advance() {
    if (!mounted || _leaving != null) return;
    final index = _entries.indexWhere((e) => e.settled);
    if (index == -1) return;
    final entry = _entries[index];
    setState(() => _leaving = entry);
    _leave.duration = context.motion.slipOut;
    _leave.forward(from: 0).whenComplete(() {
      if (!mounted) return;
      final String id = entry.data.id;
      setState(() {
        _entries.remove(entry);
        _leaving = null;
        _leave.value = 0;
      });
      widget.onSlipGone?.call(id);
      _advance();
    });
  }

  @override
  void dispose() {
    _leave.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final int visible = math.min(_entries.length, widget.maxVisible);
    final int hidden = math.max(0, widget.count - visible);

    return Semantics(
      container: true,
      label: widget.trayLabel == null ? '${widget.count}' : '${widget.count} ${widget.trayLabel}',
      child: CustomPaint(
        painter: const SoftSurfacePainter(edge: N360Colors.cardEdge, shadow: true),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.sm + 2, N360Space.sm + 2, N360Space.sm + 2, N360Space.xs),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              // Clipped to the tray's top edge so a leaving slip disappears over it.
              ClipRect(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    for (final (index, entry) in _entries.take(visible).indexed)
                      _SlipRow(
                        key: ObjectKey(entry),
                        animation: entry == _leaving ? _leave : null,
                        gap: index == visible - 1 ? 0 : _gap,
                        child: OutboxSlip(
                          title: entry.data.title,
                          subtitle: entry.data.subtitle,
                          status: entry.data.status,
                          statusLabel: entry.data.statusLabel,
                          icon: entry.data.icon,
                          onSettled: () => _settled(entry),
                          onTap: widget.onSlipTap == null ? null : () => widget.onSlipTap!(entry.data),
                        ),
                      ),
                  ],
                ),
              ),
              if (hidden > 0) _HiddenEdges(count: hidden),
              _TrayLip(count: widget.count, label: widget.trayLabel),
            ],
          ),
        ),
      ),
    );
  }
}

/// A slip's place in the tray. When leaving, the bottom of the slip stays glued
/// to the rising gap while its top slides out over the tray's edge.
class _SlipRow extends StatelessWidget {
  const _SlipRow({super.key, required this.animation, required this.gap, required this.child});

  final Animation<double>? animation;
  final double gap;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final Animation<double> leaving = animation ?? kAlwaysDismissedAnimation;
    return RepaintBoundary(
      child: ClipRect(
        child: AnimatedBuilder(
          animation: leaving,
          child: Padding(padding: EdgeInsets.only(bottom: gap), child: child),
          builder: (context, child) => Align(
            alignment: Alignment.bottomCenter,
            heightFactor: 1 - N360Curves.rise.transform(leaving.value),
            child: child,
          ),
        ),
      ),
    );
  }
}

/// Soft bars showing that more slips are stacked beneath the visible ones.
class _HiddenEdges extends StatelessWidget {
  const _HiddenEdges({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final int layers = math.min(count, 3);
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Column(
        children: <Widget>[
          for (var i = 0; i < layers; i++)
            Padding(
              padding: EdgeInsets.fromLTRB(8.0 + i * 8, 0, 8.0 + i * 8, 2),
              child: const SizedBox(
                height: 3,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: N360Colors.paper2,
                    borderRadius: BorderRadius.all(Radius.circular(2)),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _TrayLip extends StatelessWidget {
  const _TrayLip({required this.count, this.label});

  final int count;
  final String? label;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: N360Size.control,
      child: Row(
        children: <Widget>[
          const SizedBox(width: 4),
          const Icon(Icons.inbox_outlined, size: N360IconSize.md, color: N360Colors.inkLabel),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              label ?? '',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: N360Type.fit(N360Type.label, label ?? ''),
            ),
          ),
          RollingNumber(
            value: '$count',
            style: N360Type.heading.copyWith(fontSize: 16, color: N360Colors.ink),
          ),
          const SizedBox(width: 6),
        ],
      ),
    );
  }
}
