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
  const DprTopBar({super.key, this.title, this.trailing});

  final String? title;
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
                decoration: const BoxDecoration(
                  color: N360Colors.paper2,
                  borderRadius: N360Radius.md,
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
                        Text(l.commonBack, style: N360Type.bodyStrong),
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
                : Text(
                    title!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: N360Type.heading,
                  ),
          ),
          const SizedBox(width: N360Space.sm),
          trailing ?? const SizedBox(width: N360Size.tap),
        ],
      ),
    );
  }
}

/// A floating footer holding the screen's one primary action, in the thumb
/// zone. The body scrolls behind it, so the body must keep [clearance] free.
class DprActionLayout extends StatelessWidget {
  const DprActionLayout({super.key, required this.body, this.action});

  final Widget body;

  /// Usually a large [GloveButton]. Null hides the footer, as while typing.
  final Widget? action;

  /// The space a scrolling body must leave at its foot.
  static double clearance(BuildContext context) =>
      96 + MediaQuery.paddingOf(context).bottom;

  @override
  Widget build(BuildContext context) {
    final double safe = MediaQuery.paddingOf(context).bottom;
    return Stack(
      fit: StackFit.expand,
      children: <Widget>[
        body,
        if (action != null)
          Positioned(
            left: N360Space.gutter - N360Space.xs,
            right: N360Space.gutter - N360Space.xs,
            bottom: N360Space.sm + safe,
            child: LedgerCard(padding: const EdgeInsets.all(N360Space.xs + 2), child: action!),
          ),
      ],
    );
  }
}

/// A section's heading on a card: a soft tile with a picture, then the words.
class DprCardTitle extends StatelessWidget {
  const DprCardTitle({
    super.key,
    required this.icon,
    required this.title,
    this.trailing,
    this.error,
    this.done = false,
  });

  final IconData icon;
  final String title;
  final Widget? trailing;

  /// A plain message about this section. Shown under the heading, in red.
  final String? error;

  /// Whether this section has been filled, shown as a tick so a tired eye can
  /// run down the sheet and see what is left.
  final bool done;

  @override
  Widget build(BuildContext context) {
    final bool bad = error != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            DecoratedBox(
              decoration: BoxDecoration(
                color: bad ? N360Colors.stampRed.withValues(alpha: 0.12) : N360Colors.goldTint,
                borderRadius: N360Radius.sm,
              ),
              child: SizedBox.square(
                dimension: N360Size.tileSmall,
                child: Icon(
                  icon,
                  size: N360IconSize.tile,
                  color: bad ? N360Colors.stampRed : N360Colors.laterite,
                ),
              ),
            ),
            const SizedBox(width: N360Space.tile),
            Expanded(
              child: Text(
                title,
                style: N360Type.heading.copyWith(
                  color: bad ? N360Colors.stampRed : N360Colors.ink,
                ),
              ),
            ),
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
        if (bad) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          DprFieldError(message: error!),
        ],
      ],
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
    return Semantics(
      container: true,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          color: N360Colors.goldTint,
          borderRadius: N360Radius.cardRadius,
        ),
        child: Padding(
          padding: const EdgeInsets.all(N360Space.card),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              const DecoratedBox(
                decoration: BoxDecoration(
                  gradient: N360Gradients.gold,
                  borderRadius: N360Radius.sm,
                ),
                child: SizedBox.square(
                  dimension: N360Size.tileSmall,
                  child: Icon(Icons.chat_bubble_outline, size: N360IconSize.md, color: N360Colors.ink),
                ),
              ),
              const SizedBox(width: N360Space.tile),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(title, style: N360Type.heading),
                    const SizedBox(height: N360Space.xxs),
                    Text(message, style: N360Type.bodyLarge),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A soft tile with a picture and a word, for the big actions on the form.
class DprActionTile extends StatelessWidget {
  const DprActionTile({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.tall = false,
    this.gold = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;

  /// A larger target with a bigger picture tile, for the camera.
  final bool tall;

  /// Fills the picture tile with gold, for the action most likely next.
  final bool gold;

  @override
  Widget build(BuildContext context) {
    final double tile = tall ? N360Size.tile : N360Size.tileSmall;
    return PressTarget(
      onTap: onTap,
      semanticsLabel: label,
      minHeight: tall ? 72 : N360Size.tap,
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth),
        child: DecoratedBox(
          decoration: const BoxDecoration(
            color: N360Colors.paper2,
            borderRadius: N360Radius.tileRadius,
          ),
          child: child,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(N360Space.tile),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            DecoratedBox(
              decoration: BoxDecoration(
                color: gold ? null : N360Colors.sheet,
                gradient: gold ? N360Gradients.gold : null,
                borderRadius: N360Radius.sm,
              ),
              child: SizedBox.square(
                dimension: tile,
                child: Icon(
                  icon,
                  size: tall ? N360IconSize.xl : N360IconSize.tile,
                  color: N360Colors.ink,
                ),
              ),
            ),
            const SizedBox(width: N360Space.tile),
            Flexible(
              child: Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: N360Type.button,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A chip with a small picture and one fact, like the reference's top pills.
class DprInfoChip extends StatelessWidget {
  const DprInfoChip({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: text,
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: const BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.tileRadius,
          ),
          child: Padding(
            padding: const EdgeInsets.all(N360Space.xs + 2),
            child: Row(
              children: <Widget>[
                DecoratedBox(
                  decoration: const BoxDecoration(
                    color: N360Colors.paper2,
                    borderRadius: N360Radius.sm,
                  ),
                  child: SizedBox.square(
                    dimension: N360Size.tileSmall,
                    child: Icon(icon, size: N360IconSize.md, color: N360Colors.ink),
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: Text(
                    text,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.label.copyWith(color: N360Colors.ink),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The day of a report as a tile, gold when it is today.
class DprDateTile extends StatelessWidget {
  const DprDateTile({super.key, required this.ymd, this.today = false});

  final String ymd;
  final bool today;

  @override
  Widget build(BuildContext context) {
    final d = dprDateTile(context, ymd);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: today ? null : N360Colors.paper2,
        gradient: today ? N360Gradients.gold : null,
        borderRadius: N360Radius.md,
      ),
      child: SizedBox.square(
        dimension: N360Size.tap,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Text('${d.day}', style: N360Type.figureLarge.copyWith(height: 1.1)),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                d.month,
                maxLines: 1,
                style: N360Type.caption.copyWith(
                  color: today ? N360Colors.ink : N360Colors.inkLabel,
                  height: 1.2,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A word and an icon saying where a report is on its way to the office.
/// Never says "sent" for anything still on the phone.
class DprSyncTag extends StatelessWidget {
  const DprSyncTag({super.key, required this.record});

  final SyncedRecord<DprRow> record;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final dpr = record.record;
    final ({IconData icon, Color ink, String text}) look = switch (record.syncState) {
      LocalSyncState.attention => (
        icon: Icons.error_outline,
        ink: N360Colors.stampRed,
        text: l.dprSyncAttention,
      ),
      LocalSyncState.sending => (icon: Icons.sync, ink: N360Colors.neel, text: l.dprSyncSending),
      LocalSyncState.queued => (
        icon: Icons.schedule,
        ink: N360Colors.haldiInk,
        text: l.dprSyncWaiting,
      ),
      LocalSyncState.synced when dpr.localDraft || dpr.serverId == null => (
        icon: Icons.smartphone,
        ink: N360Colors.ink2,
        text: l.dprSyncPhoneOnly,
      ),
      LocalSyncState.synced => (
        icon: Icons.cloud_done_outlined,
        ink: N360Colors.mossInk,
        text: l.statusSynced,
      ),
    };
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(look.icon, size: N360IconSize.xs, color: look.ink),
        const SizedBox(width: N360Space.xs),
        Flexible(
          child: Text(
            look.text,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: N360Type.caption.copyWith(color: look.ink, fontWeight: FontWeight.w600),
          ),
        ),
      ],
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
      color: N360Colors.paper2,
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
      borderRadius: N360Radius.md,
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
