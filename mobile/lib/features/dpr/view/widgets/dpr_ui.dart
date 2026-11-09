import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/dpr_repository.dart';
import 'dpr_format.dart';

/// A plain tappable patch: 56dp or more, squeezes a little under the thumb.
class DprTapPad extends StatelessWidget {
  const DprTapPad({
    super.key,
    required this.onTap,
    required this.semanticsLabel,
    required this.child,
    this.minHeight = N360Size.tap,
    this.minWidth = N360Size.tap,
  });

  final VoidCallback? onTap;
  final String semanticsLabel;
  final Widget child;
  final double minHeight;
  final double minWidth;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      semanticsLabel: semanticsLabel,
      minHeight: minHeight,
      minWidth: minWidth,
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth),
        child: child,
      ),
      child: child,
    );
  }
}

/// The header: a labelled way back, a centred title, and status on the right.
class DprTopBar extends StatelessWidget {
  const DprTopBar({super.key, this.title, this.subtitle, this.trailing});

  final String? title;
  final String? subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.xs),
      child: Row(
        children: <Widget>[
          DprTapPad(
            onTap: () => dprGoBack(context),
            semanticsLabel: l.commonBack,
            child: Center(
              widthFactor: 1,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: N360Colors.sheet,
                  borderRadius: N360Radius.sm,
                  border: Border.all(color: N360Colors.rule),
                ),
                child: SizedBox(
                  height: N360Size.tile,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: N360Space.tile),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        const Icon(Icons.arrow_back, size: N360IconSize.md, color: N360Colors.ink),
                        const SizedBox(width: N360Space.xs),
                        Text(l.commonBack, style: N360Type.label.copyWith(color: N360Colors.ink)),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(width: N360Space.sm),
          Expanded(
            child: title == null
                ? const SizedBox.shrink()
                : Column(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        title!,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: TextAlign.center,
                        style: N360Type.heading,
                      ),
                      if (subtitle != null)
                        Text(
                          subtitle!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.center,
                          style: N360Type.caption,
                        ),
                    ],
                  ),
          ),
          const SizedBox(width: N360Space.sm),
          trailing ?? const SizedBox(width: N360Size.tap),
        ],
      ),
    );
  }
}

/// A plain bottom bar holding the screen's one primary action, in the thumb
/// zone. The body scrolls above it and never under it.
class DprActionLayout extends StatelessWidget {
  const DprActionLayout({super.key, required this.body, this.action});

  final Widget body;

  /// Usually a large [GloveButton]. Null hides the bar, as while typing.
  final Widget? action;

  /// The space a scrolling body should leave at its foot.
  static double clearance(BuildContext context) => N360Space.lg;

  @override
  Widget build(BuildContext context) {
    final double safe = MediaQuery.paddingOf(context).bottom;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(child: body),
        if (action != null)
          ColoredBox(
            color: N360Colors.sheet,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const RuledDivider(),
                Padding(
                  padding: EdgeInsets.fromLTRB(
                    N360Space.gutter,
                    N360Space.tile,
                    N360Space.gutter,
                    N360Space.tile + safe,
                  ),
                  child: action,
                ),
              ],
            ),
          ),
      ],
    );
  }
}

/// A section of a report: a white card with its heading on a hairline, the way
/// every grouped list in the app is drawn.
class DprSectionCard extends StatelessWidget {
  const DprSectionCard({
    super.key,
    required this.icon,
    required this.title,
    required this.child,
    this.trailing,
    this.done = false,
    this.error,
  });

  final IconData icon;
  final String title;
  final Widget child;
  final Widget? trailing;

  /// Whether this section has been filled, shown as a tick so a tired eye can
  /// run down the sheet and see what is left.
  final bool done;

  /// A plain message about this section. Shown under the heading, in red.
  final String? error;

  @override
  Widget build(BuildContext context) {
    final bool bad = error != null;
    final Color ink = bad ? N360Colors.stampRed : N360Colors.ink;
    return LedgerCard(
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.card, N360Space.md, N360Space.card, N360Space.sm),
            child: Row(
              children: <Widget>[
                Icon(icon, size: N360IconSize.md, color: bad ? N360Colors.stampRed : N360Colors.ink2),
                const SizedBox(width: N360Space.sm),
                Expanded(child: Text(title, style: N360Type.heading.copyWith(color: ink))),
                if (trailing != null) ...<Widget>[
                  const SizedBox(width: N360Space.sm),
                  trailing!,
                ],
                if (done) ...<Widget>[
                  const SizedBox(width: N360Space.sm),
                  const Icon(Icons.check_circle, size: N360IconSize.md, color: N360Colors.mossInk),
                ],
              ],
            ),
          ),
          const RuledDivider(),
          Padding(
            padding: const EdgeInsets.all(N360Space.card),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (bad) ...<Widget>[
                  DprFieldError(message: error!),
                  const SizedBox(height: N360Space.md),
                ],
                child,
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A validation line. It lands with a small lift so the eye catches it.
class DprFieldError extends StatelessWidget {
  const DprFieldError({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      liveRegion: true,
      child: StaggeredRise(
        index: 0,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Padding(
              padding: EdgeInsets.only(top: N360Space.xxs, right: N360Space.xs),
              child: Icon(Icons.error_outline, size: N360IconSize.md, color: N360Colors.stampRed),
            ),
            Expanded(
              child: Text(
                message,
                style: N360Type.bodyStrong.copyWith(color: N360Colors.stampRed),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The approver's words, at the top of a report that came back.
class DprRemarkCard extends StatelessWidget {
  const DprRemarkCard({super.key, required this.title, required this.message});

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(child: Text(title, style: N360Type.heading)),
              const SizedBox(width: N360Space.sm),
              StatusPill(kind: StampKind.returned, label: l.statusReturned),
            ],
          ),
          const SizedBox(height: N360Space.xs),
          Text(message, style: N360Type.body),
        ],
      ),
    );
  }
}

/// A photo decoded at thumbnail size. Comes from the phone while the file is
/// here and from the server after upload, when `relativePath` is gone.
class DprPhotoThumb extends StatefulWidget {
  const DprPhotoThumb({
    super.key,
    required this.photo,
    required this.repository,
    this.size = 96,
  });

  final DprPhotoRow photo;
  final DprRepository repository;
  final double size;

  @override
  State<DprPhotoThumb> createState() => _DprPhotoThumbState();
}

class _DprPhotoThumbState extends State<DprPhotoThumb> {
  late Future<Uint8List> _bytes = widget.repository.photoBytes(widget.photo);

  @override
  void didUpdateWidget(covariant DprPhotoThumb oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.photo.clientUuid != widget.photo.clientUuid) {
      _bytes = widget.repository.photoBytes(widget.photo);
    }
  }

  Widget _plate({required bool failed, required AppLocalizations l}) {
    return ColoredBox(
      color: N360Colors.paper,
      child: Center(
        child: failed
            ? Column(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  const Icon(Icons.image_not_supported_outlined, size: N360IconSize.xl, color: N360Colors.inkLabel),
                  Text(l.dprPhotoMissing, textAlign: TextAlign.center, style: N360Type.caption),
                ],
              )
            : const Icon(Icons.image_outlined, size: N360IconSize.xl, color: N360Colors.ink3),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final int decodeWidth = (widget.size * MediaQuery.devicePixelRatioOf(context)).round();
    return ClipRRect(
      borderRadius: N360Radius.sm,
      child: SizedBox.square(
        dimension: widget.size,
        child: FutureBuilder<Uint8List>(
          future: _bytes,
          builder: (context, snapshot) {
            if (snapshot.hasError) return _plate(failed: true, l: l);
            final bytes = snapshot.data;
            if (bytes == null) return _plate(failed: false, l: l);
            return Image.memory(
              bytes,
              width: widget.size,
              height: widget.size,
              fit: BoxFit.cover,
              cacheWidth: decodeWidth,
              gaplessPlayback: true,
              errorBuilder: (context, error, stack) => _plate(failed: true, l: l),
            );
          },
        ),
      ),
    );
  }
}

/// Plays a small lift the first time [id] appears and never again, so
/// scrolling a row out and back does not replay it.
class DprLandOnce extends StatefulWidget {
  const DprLandOnce({
    super.key,
    required this.id,
    required this.landed,
    required this.child,
  });

  final Object id;

  /// The ids that have already landed, owned by the screen.
  final Set<Object> landed;
  final Widget child;

  @override
  State<DprLandOnce> createState() => _DprLandOnceState();
}

class _DprLandOnceState extends State<DprLandOnce> {
  late final bool _play = widget.landed.add(widget.id);

  @override
  Widget build(BuildContext context) {
    final motion = context.motion;
    if (!_play || motion.reduced) return widget.child;
    return TweenAnimationBuilder<double>(
      tween: Tween<double>(begin: 1, end: 0),
      duration: motion.rise,
      curve: N360Curves.rise,
      child: RepaintBoundary(child: widget.child),
      builder: (context, remaining, child) => Transform.translate(
        offset: Offset(0, 18 * remaining),
        child: child,
      ),
    );
  }
}
