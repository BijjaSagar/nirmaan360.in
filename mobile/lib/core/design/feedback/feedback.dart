import 'dart:async';

import 'package:flutter/material.dart';

import '../controls/glove_button.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';
import '../viz/building_progress_art.dart';
import '../viz/looping_art.dart';

export 'ledger_refresh.dart';
export 'loading_skeleton.dart';

enum PaperToastKind { info, success, warning, error }

/// A small floating card with a short message. The icon carries the meaning.
class PaperToast extends StatelessWidget {
  const PaperToast({super.key, required this.message, this.kind = PaperToastKind.info, this.icon});

  final String message;
  final PaperToastKind kind;
  final IconData? icon;

  ({IconData icon, Color ink, Color wash}) get _look => switch (kind) {
        PaperToastKind.info => (icon: Icons.info_outline, ink: N360Colors.ink2, wash: N360Colors.paper2),
        PaperToastKind.success => (icon: Icons.task_alt, ink: N360Colors.mossInk, wash: const Color(0xFFE4F1E8)),
        PaperToastKind.warning => (icon: Icons.warning_amber, ink: N360Colors.haldiInk, wash: N360Colors.goldTint),
        PaperToastKind.error => (icon: Icons.error_outline, ink: N360Colors.stampRed, wash: const Color(0xFFFBE9E6)),
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
          painter: const SoftSurfacePainter(radius: N360Radius.tile + 2, edge: N360Colors.cardEdge, shadow: true),
          child: Padding(
            padding: const EdgeInsets.all(N360Space.tile),
            child: Row(
              children: <Widget>[
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: look.wash,
                    borderRadius: const BorderRadius.all(Radius.circular(N360Radius.small)),
                  ),
                  child: SizedBox.square(
                    dimension: N360Size.tileSmall,
                    child: Icon(icon ?? look.icon, size: N360IconSize.md, color: look.ink),
                  ),
                ),
                const SizedBox(width: N360Space.md),
                Expanded(child: Text(message, style: N360Type.fit(N360Type.bodyStrong, message))),
                const SizedBox(width: N360Space.xs),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Shows a [PaperToast] that slides up from the bottom and leaves on its own.
void showPaperToast(
  BuildContext context,
  String message, {
  PaperToastKind kind = PaperToastKind.info,
  IconData? icon,
  Duration duration = const Duration(milliseconds: 2600),
  double bottomOffset = N360Size.bar + N360Space.xl,
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

/// A soft banner saying the phone is offline.
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
        child: Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.xs, N360Space.md, N360Space.xs),
          child: CustomPaint(
            painter: const SoftSurfacePainter(radius: N360Radius.medium, fill: N360Colors.goldTint),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: N360Space.sm + 2, vertical: 6),
              child: Row(
                children: <Widget>[
                  const SizedBox.square(
                    dimension: 30,
                    child: CustomPaint(
                      painter: SoftSurfacePainter(radius: N360Radius.small - 1, gradient: N360Gradients.gold),
                      child: Icon(Icons.cloud_off, size: N360IconSize.sm, color: N360Colors.ink),
                    ),
                  ),
                  const SizedBox(width: N360Space.sm + 2),
                  Expanded(
                    child: Text.rich(
                      TextSpan(
                        text: label,
                        style: N360Type.fit(N360Type.bodyStrong, label),
                        children: <InlineSpan>[
                          if (detail != null) TextSpan(text: '  $detail', style: N360Type.fit(N360Type.body, detail!)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// An error on a soft card, with a clear way forward.
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
        painter: const SoftSurfacePainter(edge: N360Colors.cardEdge, shadow: true),
        child: Padding(
          padding: const EdgeInsets.all(N360Space.card),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: <Widget>[
                  const DecoratedBox(
                    decoration: BoxDecoration(
                      color: Color(0xFFFBE9E6),
                      borderRadius: BorderRadius.all(Radius.circular(N360Radius.small)),
                    ),
                    child: SizedBox.square(
                      dimension: N360Size.tileSmall,
                      child: Icon(Icons.error_outline, size: N360IconSize.md, color: N360Colors.stampRed),
                    ),
                  ),
                  const SizedBox(width: N360Space.md),
                  Expanded(child: Text(title, style: N360Type.fit(N360Type.heading, title))),
                ],
              ),
              if (message != null) ...<Widget>[
                const SizedBox(height: N360Space.sm),
                Text(message!, style: N360Type.fit(N360Type.body, message!)),
              ],
              if (actionLabel != null && onAction != null) ...<Widget>[
                const SizedBox(height: N360Space.md),
                Align(
                  alignment: Alignment.centerLeft,
                  child: GloveButton(
                    label: actionLabel!,
                    onPressed: onAction,
                    kind: GloveButtonKind.secondary,
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

/// A warm empty state: the site waiting for its first entry, with the crane
/// quietly hoisting a beam.
///
/// The picture is [LoopingArt.craneHoist] unless [animated] is false or an
/// [art] of your own is given; with reduced motion it is the still drawing.
/// Empty states sit on their own, never inside a list, so the loop is the one
/// moving thing on the screen.
class EmptyLedger extends StatelessWidget {
  const EmptyLedger({
    super.key,
    required this.title,
    this.message,
    this.action,
    this.art,
    this.animated = true,
  });

  final String title;
  final String? message;

  /// Usually a [GloveButton] that starts the first entry.
  final Widget? action;

  /// Replaces the default picture. Pass a [LoopingArt] or a
  /// [BuildingProgressArt]; it is laid out at [artWidth].
  final Widget? art;

  /// False shows the still drawing even when motion is allowed.
  final bool animated;

  static const double artWidth = 176;

  @override
  Widget build(BuildContext context) {
    final Widget picture = art ??
        (animated
            ? LoopingArt.craneHoist(semanticLabel: title)
            : const BuildingProgressArt(fraction: 0, animateEntry: false));
    return Semantics(
      container: true,
      label: action != null ? null : (message == null ? title : '$title. $message'),
      child: ExcludeSemantics(
        excluding: action == null,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.xl, vertical: N360Space.lg),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              ExcludeSemantics(
                child: SizedBox(width: artWidth, child: picture),
              ),
              const SizedBox(height: N360Space.md),
              Text(title, textAlign: TextAlign.center, style: N360Type.fit(N360Type.title, title)),
              if (message != null) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Text(
                  message!,
                  textAlign: TextAlign.center,
                  style: N360Type.fit(N360Type.body, message!).copyWith(color: N360Colors.ink2),
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
