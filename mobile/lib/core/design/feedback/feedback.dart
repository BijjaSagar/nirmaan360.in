import 'dart:async';

import 'package:flutter/material.dart';

import '../controls/glove_button.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

enum PaperToastKind { info, success, warning, error }

/// A paper slip with a short message. The band and the icon carry the meaning.
class PaperToast extends StatelessWidget {
  const PaperToast({super.key, required this.message, this.kind = PaperToastKind.info, this.icon});

  final String message;
  final PaperToastKind kind;
  final IconData? icon;

  ({IconData icon, Color ink}) get _look => switch (kind) {
        PaperToastKind.info => (icon: Icons.info_outline, ink: N360Colors.ink2),
        PaperToastKind.success => (icon: Icons.task_alt, ink: N360Colors.mossInk),
        PaperToastKind.warning => (icon: Icons.warning_amber, ink: N360Colors.haldiInk),
        PaperToastKind.error => (icon: Icons.error_outline, ink: N360Colors.stampRed),
      };

  @override
  Widget build(BuildContext context) {
    final look = _look;
    return Semantics(
      container: true,
      liveRegion: true,
      label: message,
      child: ExcludeSemantics(
        child: CustomPaint(
          painter: _SlipBandPainter(band: look.ink),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.lg + 4, N360Space.md, N360Space.lg, N360Space.md + 3),
            child: Row(
              children: <Widget>[
                Icon(icon ?? look.icon, size: 24, color: look.ink),
                const SizedBox(width: N360Space.md),
                Expanded(child: Text(message, style: N360Type.bodyStrong)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SlipBandPainter extends CustomPainter {
  const _SlipBandPainter({required this.band});

  final Color band;

  @override
  void paint(Canvas canvas, Size size) {
    const edge = 3.0;
    final Rect face = Rect.fromLTWH(0, 0, size.width, size.height - edge);
    canvas.drawRect(Rect.fromLTWH(0, edge, size.width, size.height - edge), Paint()..color = N360Colors.ink2);
    canvas.drawRect(face, Paint()..color = N360Colors.sheet);
    canvas.drawRect(Rect.fromLTWH(0, 0, 5, face.height), Paint()..color = band);
  }

  @override
  bool shouldRepaint(_SlipBandPainter oldDelegate) => oldDelegate.band != band;
}

/// Shows a [PaperToast] that slides up from the bottom and leaves on its own.
void showPaperToast(
  BuildContext context,
  String message, {
  PaperToastKind kind = PaperToastKind.info,
  IconData? icon,
  Duration duration = const Duration(milliseconds: 2600),
  double bottomOffset = N360Size.bar + N360Space.lg,
}) {
  final overlay = Overlay.of(context, rootOverlay: true);
  late final OverlayEntry entry;
  entry = OverlayEntry(
    builder: (context) => _ToastHost(
      duration: duration,
      bottom: bottomOffset,
      toast: PaperToast(message: message, kind: kind, icon: icon),
      onDone: () {
        entry.remove();
        entry.dispose();
      },
    ),
  );
  overlay.insert(entry);
}

class _ToastHost extends StatefulWidget {
  const _ToastHost({required this.duration, required this.bottom, required this.toast, required this.onDone});

  final Duration duration;
  final double bottom;
  final Widget toast;
  final VoidCallback onDone;

  @override
  State<_ToastHost> createState() => _ToastHostState();
}

class _ToastHostState extends State<_ToastHost> with SingleTickerProviderStateMixin {
  late final AnimationController _slide = AnimationController(vsync: this);
  Timer? _timer;
  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    final motion = context.motion;
    _slide.duration = motion.toast;
    _slide.forward();
    _timer = Timer(widget.duration, () async {
      if (!mounted) return;
      await _slide.reverse();
      if (mounted) widget.onDone();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _slide.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final MediaQueryData mq = MediaQuery.of(context);
    final double lift = widget.bottom + mq.padding.bottom;
    return Positioned(
      left: N360Space.gutter,
      right: N360Space.gutter,
      bottom: lift,
      child: Material(
        type: MaterialType.transparency,
        child: IgnorePointer(
          child: RepaintBoundary(
            child: AnimatedBuilder(
              animation: _slide,
              child: widget.toast,
              builder: (context, child) {
                final double v = N360Curves.rise.transform(_slide.value);
                return Transform.translate(offset: Offset(0, (1 - v) * (lift + 80)), child: child);
              },
            ),
          ),
        ),
      ),
    );
  }
}

/// A strip across the top saying the phone is offline. Construction hazard tape
/// marks the edge, so it reads from across a sunny site.
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key, required this.label, this.detail});

  final String label;
  final String? detail;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      liveRegion: true,
      label: detail == null ? label : '$label. $detail',
      child: ExcludeSemantics(
        child: ColoredBox(
          color: N360Colors.haldi,
          child: Row(
            children: <Widget>[
              const SizedBox(
                width: 22,
                height: 48,
                child: CustomPaint(painter: _HazardPainter()),
              ),
              const SizedBox(width: N360Space.md),
              const Icon(Icons.cloud_off, size: 22, color: N360Colors.ink),
              const SizedBox(width: N360Space.sm),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
                  child: Text.rich(
                    TextSpan(
                      text: label,
                      style: N360Type.bodyStrong.copyWith(color: N360Colors.ink),
                      children: <InlineSpan>[
                        if (detail != null)
                          TextSpan(
                            text: '  $detail',
                            style: N360Type.body.copyWith(color: N360Colors.ink),
                          ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(width: N360Space.sm),
            ],
          ),
        ),
      ),
    );
  }
}

class _HazardPainter extends CustomPainter {
  const _HazardPainter();

  @override
  void paint(Canvas canvas, Size size) {
    canvas.clipRect(Offset.zero & size);
    final Paint ink = Paint()..color = N360Colors.ink;
    for (double y = -size.width; y < size.height + size.width; y += 16) {
      final Path stripe = Path()
        ..moveTo(0, y + size.width)
        ..lineTo(size.width, y)
        ..lineTo(size.width, y + 8)
        ..lineTo(0, y + size.width + 8)
        ..close();
      canvas.drawPath(stripe, ink);
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// An error on a torn slip, with a clear way forward.
class ErrorSlip extends StatelessWidget {
  const ErrorSlip({super.key, required this.title, this.message, this.actionLabel, this.onAction});

  final String title;
  final String? message;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      liveRegion: true,
      child: CustomPaint(
        painter: const _TornSlipPainter(),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.lg + 4, N360Space.md, N360Space.lg, N360Space.lg + 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Padding(
                    padding: EdgeInsets.only(top: 3, right: 8),
                    child: Icon(Icons.error_outline, size: 26, color: N360Colors.stampRed),
                  ),
                  Expanded(
                    child: Text(title, style: N360Type.heading.copyWith(color: N360Colors.stampRed)),
                  ),
                ],
              ),
              if (message != null) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Text(message!, style: N360Type.body),
              ],
              if (actionLabel != null && onAction != null) ...<Widget>[
                const SizedBox(height: N360Space.sm),
                Align(
                  alignment: Alignment.centerLeft,
                  child: GloveButton(
                    label: actionLabel!,
                    onPressed: onAction,
                    kind: GloveButtonKind.danger,
                    icon: Icons.refresh,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _TornSlipPainter extends CustomPainter {
  const _TornSlipPainter();

  @override
  void paint(Canvas canvas, Size size) {
    const tooth = 7.0;
    final Path slip = Path()
      ..moveTo(0, 0)
      ..lineTo(size.width, 0)
      ..lineTo(size.width, size.height - tooth);
    var x = size.width;
    var down = false;
    while (x > 0) {
      x -= tooth;
      slip.lineTo(x < 0 ? 0 : x, size.height - (down ? tooth : 0));
      down = !down;
    }
    slip.close();
    canvas.drawPath(slip, Paint()..color = N360Colors.sheet);
    canvas.drawPath(
      slip,
      Paint()
        ..color = N360Colors.ruleStrong
        ..style = PaintingStyle.stroke,
    );
    canvas.drawRect(Rect.fromLTWH(0, 0, 5, size.height - tooth), Paint()..color = N360Colors.stampRed);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// A warm empty state: an open measurement book waiting for its first entry.
class EmptyLedger extends StatelessWidget {
  const EmptyLedger({super.key, required this.title, this.message, this.action});

  final String title;
  final String? message;

  /// Usually a [GloveButton] that starts the first entry.
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: action != null ? null : (message == null ? title : '$title. $message'),
      child: ExcludeSemantics(
        excluding: action == null,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.xl, vertical: N360Space.xl),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const SizedBox(
                width: 220,
                height: 156,
                child: CustomPaint(painter: _LedgerIllustrationPainter()),
              ),
              const SizedBox(height: N360Space.md),
              Text(title, textAlign: TextAlign.center, style: N360Type.title),
              if (message != null) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Text(
                  message!,
                  textAlign: TextAlign.center,
                  style: N360Type.body.copyWith(color: N360Colors.ink2),
                ),
              ],
              if (action != null) ...<Widget>[
                const SizedBox(height: N360Space.lg),
                action!,
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _LedgerIllustrationPainter extends CustomPainter {
  const _LedgerIllustrationPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final double w = size.width;
    final double h = size.height;

    final Paint ink = Paint()
      ..color = N360Colors.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.6
      ..strokeJoin = StrokeJoin.round
      ..strokeCap = StrokeCap.round;
    final Paint thin = Paint()
      ..color = N360Colors.ruleStrong
      ..strokeWidth = 1;
    final Paint page = Paint()..color = N360Colors.sheet;
    final Paint red = Paint()
      ..color = N360Colors.marginRed
      ..strokeWidth = 1.2;

    final double spineX = w * 0.5;
    const top = 16.0;
    final double bottom = h - 26;

    // The book, drawn flat and open.
    final Path left = Path()
      ..moveTo(spineX, top + 6)
      ..lineTo(w * 0.1, top)
      ..lineTo(w * 0.1, bottom - 6)
      ..lineTo(spineX, bottom)
      ..close();
    final Path right = Path()
      ..moveTo(spineX, top + 6)
      ..lineTo(w * 0.9, top)
      ..lineTo(w * 0.9, bottom - 6)
      ..lineTo(spineX, bottom)
      ..close();

    // Paper edges under the open book.
    final Paint edge = Paint()..color = N360Colors.paperEdge;
    canvas.drawPath(left.shift(const Offset(-3, 3)), edge);
    canvas.drawPath(right.shift(const Offset(3, 3)), edge);
    canvas.drawPath(left, page);
    canvas.drawPath(right, page);

    // Ruled lines fading towards an empty foot of the page.
    canvas.save();
    canvas.clipPath(left);
    for (var i = 0; i < 6; i++) {
      final double y = top + 14 + i * 13;
      canvas.drawLine(Offset(w * 0.1, y - 3), Offset(spineX, y + 3), thin);
    }
    canvas.restore();
    canvas.save();
    canvas.clipPath(right);
    for (var i = 0; i < 6; i++) {
      final double y = top + 14 + i * 13;
      canvas.drawLine(Offset(spineX, y + 3), Offset(w * 0.9, y - 3), thin);
    }
    canvas.restore();

    // Double red margin on the left page.
    canvas.save();
    canvas.clipPath(left);
    final double mx = w * 0.1 + 18;
    canvas.drawLine(Offset(mx, top - 2), Offset(mx, bottom), red);
    canvas.drawLine(Offset(mx + 3, top - 2), Offset(mx + 3, bottom), red);
    canvas.restore();

    canvas.drawPath(left, ink);
    canvas.drawPath(right, ink);

    // A pencil left on the right-hand page.
    canvas.save();
    canvas.translate(w * 0.72, h * 0.42);
    canvas.rotate(-0.5);
    final RRect body = RRect.fromRectAndRadius(const Rect.fromLTWH(-34, -4.5, 54, 9), const Radius.circular(1));
    canvas.drawRRect(body, Paint()..color = N360Colors.laterite);
    final Path tip = Path()
      ..moveTo(20, -4.5)
      ..lineTo(30, 0)
      ..lineTo(20, 4.5)
      ..close();
    canvas.drawPath(tip, Paint()..color = N360Colors.paper);
    canvas.drawPath(tip, ink..strokeWidth = 1.2);
    canvas.drawLine(const Offset(30, 0), const Offset(26, -1.6), ink);
    canvas.drawRRect(body, ink);
    canvas.restore();

    // A dimension line under the book, with nothing measured yet.
    final Paint dim = Paint()
      ..color = N360Colors.laterite
      ..strokeWidth = 1.4
      ..strokeCap = StrokeCap.round;
    final double y = h - 11;
    canvas.drawLine(Offset(w * 0.1, y), Offset(w * 0.38, y), dim);
    canvas.drawLine(Offset(w * 0.62, y), Offset(w * 0.9, y), dim);
    void tick(double x) => canvas.drawLine(Offset(x - 3, y + 6), Offset(x + 3, y - 6), dim);
    tick(w * 0.1);
    tick(w * 0.9);

    final TextPainter figure = TextPainter(
      text: const TextSpan(
        text: '0.000',
        style: TextStyle(fontFamily: N360Type.mono, fontSize: 13, color: N360Colors.stampRed),
      ),
      textDirection: TextDirection.ltr,
    )..layout();
    figure.paint(canvas, Offset(spineX - figure.width / 2, y - figure.height / 2));
    figure.dispose();
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
