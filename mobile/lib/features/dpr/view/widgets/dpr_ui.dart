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
        scale: 1 - 0.04 * depth,
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
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.sm),
      child: Row(
        children: <Widget>[
          DprTapPad(
            onTap: () => dprGoBack(context),
            semanticsLabel: l.commonBack,
            child: DecoratedBox(
              decoration: const BoxDecoration(
                color: N360Colors.paper2,
                borderRadius: N360Radius.md,
              ),
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: N360Size.tap),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      const Icon(Icons.arrow_back, size: 24, color: N360Colors.ink),
                      const SizedBox(width: 6),
                      Text(l.commonBack, style: N360Type.bodyStrong),
                    ],
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
      110 + MediaQuery.paddingOf(context).bottom;

  @override
  Widget build(BuildContext context) {
    final double safe = MediaQuery.paddingOf(context).bottom;
    return Stack(
      fit: StackFit.expand,
      children: <Widget>[
        body,
        if (action != null)
          Positioned(
            left: 10,
            right: 10,
            bottom: N360Space.sm + safe,
            child: LedgerCard(padding: const EdgeInsets.all(7), child: action!),
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
  });

  final IconData icon;
  final String title;
  final Widget? trailing;

  /// A plain message about this section. Shown under the heading, in red.
  final String? error;

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
                borderRadius: const BorderRadius.all(Radius.circular(13)),
              ),
              child: SizedBox.square(
                dimension: 42,
                child: Icon(
                  icon,
                  size: 23,
                  color: bad ? N360Colors.stampRed : N360Colors.laterite,
                ),
              ),
            ),
            const SizedBox(width: N360Space.md),
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
              padding: EdgeInsets.only(top: 2, right: 6),
              child: Icon(Icons.error_outline, size: 22, color: N360Colors.stampRed),
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
          padding: const EdgeInsets.all(N360Space.lg),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              const DecoratedBox(
                decoration: BoxDecoration(
                  gradient: N360Gradients.gold,
                  borderRadius: BorderRadius.all(Radius.circular(13)),
                ),
                child: SizedBox.square(
                  dimension: 42,
                  child: Icon(Icons.chat_bubble_outline, size: 22, color: N360Colors.ink),
                ),
              ),
              const SizedBox(width: N360Space.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(title, style: N360Type.heading.copyWith(color: N360Colors.ink)),
                    const SizedBox(height: 2),
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

  /// Stacks the picture over the word, for a large target.
  final bool tall;

  /// Fills the picture tile with gold, for the action most likely next.
  final bool gold;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      semanticsLabel: label,
      minHeight: tall ? 96 : 68,
      builder: (context, depth, child) => Transform.scale(
        scale: 1 - 0.035 * depth,
        child: DecoratedBox(
          decoration: const BoxDecoration(
            color: N360Colors.paper2,
            borderRadius: N360Radius.tileRadius,
          ),
          child: child,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            DecoratedBox(
              decoration: BoxDecoration(
                color: gold ? null : N360Colors.sheet,
                gradient: gold ? N360Gradients.gold : null,
                borderRadius: const BorderRadius.all(Radius.circular(13)),
              ),
              child: SizedBox.square(
                dimension: tall ? 52 : 44,
                child: Icon(icon, size: tall ? 30 : 24, color: N360Colors.ink),
              ),
            ),
            const SizedBox(width: N360Space.md),
            Flexible(
              child: Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: N360Type.button.copyWith(fontSize: tall ? 18 : 16),
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
            padding: const EdgeInsets.all(7),
            child: Row(
              children: <Widget>[
                DecoratedBox(
                  decoration: const BoxDecoration(
                    color: N360Colors.paper2,
                    borderRadius: BorderRadius.all(Radius.circular(12)),
                  ),
                  child: SizedBox.square(
                    dimension: 38,
                    child: Icon(icon, size: 21, color: N360Colors.ink),
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
  const DprDateTile({super.key, required this.ymd, this.today = false, this.size = 58});

  final String ymd;
  final bool today;
  final double size;

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
        dimension: size,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Text(
              '${d.day}',
              style: N360Type.figureLarge.copyWith(fontSize: 22, height: 1.15),
            ),
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
        Icon(look.icon, size: 16, color: look.ink),
        const SizedBox(width: 4),
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
    this.size = 112,
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
                  const Icon(Icons.image_not_supported_outlined, size: 26, color: N360Colors.inkLabel),
                  Text(l.dprPhotoMissing, textAlign: TextAlign.center, style: N360Type.caption),
                ],
              )
            : const Icon(Icons.image_outlined, size: 30, color: N360Colors.ink3),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final int decodeWidth = (widget.size * MediaQuery.devicePixelRatioOf(context)).round();
    return ClipRRect(
      borderRadius: N360Radius.tileRadius,
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

/// Plays a small lift the first time [id] appears on the sheet and never
/// again, so scrolling a row out and back does not replay it.
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

/// The app's bottom sheet: no blur, durations from `context.motion`.
Future<T?> showDprSheet<T>(BuildContext context, WidgetBuilder builder) {
  final motion = context.motion;
  return showModalBottomSheet<T>(
    context: context,
    useRootNavigator: true,
    isScrollControlled: true,
    useSafeArea: true,
    barrierColor: N360Colors.ink.withValues(alpha: 0.55),
    sheetAnimationStyle: AnimationStyle(
      duration: motion.of(N360Durations.page),
      reverseDuration: motion.of(N360Durations.toast),
    ),
    builder: builder,
  );
}

/// The little grip at the top of a sheet.
class DprSheetGrip extends StatelessWidget {
  const DprSheetGrip({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.only(top: N360Space.sm, bottom: N360Space.xs),
      child: Center(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.ruleStrong,
            borderRadius: BorderRadius.all(Radius.circular(2)),
          ),
          child: SizedBox(width: 44, height: 4),
        ),
      ),
    );
  }
}
