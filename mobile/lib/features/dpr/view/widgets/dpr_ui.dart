import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/dpr_repository.dart';
import 'dpr_format.dart';

/// A plain tappable patch: 56dp or more, sinks a little under the thumb.
class DprTapPad extends StatefulWidget {
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
  State<DprTapPad> createState() => _DprTapPadState();
}

class _DprTapPadState extends State<DprTapPad> {
  bool _down = false;

  void _set(bool down) {
    if (_down != down) setState(() => _down = down);
  }

  @override
  Widget build(BuildContext context) {
    final bool enabled = widget.onTap != null;
    final motion = context.motion;
    return Semantics(
      container: true,
      button: true,
      enabled: enabled,
      label: widget.semanticsLabel,
      onTap: widget.onTap,
      child: ExcludeSemantics(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapDown: enabled
              ? (_) {
                  N360Haptics.tap();
                  _set(true);
                }
              : null,
          onTapUp: enabled ? (_) => _set(false) : null,
          onTapCancel: enabled ? () => _set(false) : null,
          onTap: widget.onTap,
          child: ConstrainedBox(
            constraints: BoxConstraints(
              minHeight: widget.minHeight,
              minWidth: widget.minWidth,
            ),
            child: TweenAnimationBuilder<double>(
              tween: Tween<double>(end: _down ? 1 : 0),
              duration: _down ? motion.press : motion.release,
              curve: N360Curves.press,
              child: widget.child,
              builder: (context, depth, child) => Transform.translate(
                offset: Offset(0, depth * 2),
                child: child,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The strip above the sheet: a way back on the left, and status on the right.
class DprTopBar extends StatelessWidget {
  const DprTopBar({super.key, this.title, this.trailing});

  final String? title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 0, N360Space.md, 0),
      child: Row(
        children: <Widget>[
          DprTapPad(
            onTap: () => dprGoBack(context),
            semanticsLabel: l.commonBack,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(4, 14, 10, 14),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  const Icon(Icons.arrow_back, size: 26, color: N360Colors.ink),
                  const SizedBox(width: 6),
                  Text(l.commonBack, style: N360Type.bodyStrong),
                ],
              ),
            ),
          ),
          if (title != null) ...<Widget>[
            const SizedBox(width: N360Space.xs),
            Expanded(
              child: Text(
                title!,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.heading,
              ),
            ),
          ] else
            const Spacer(),
          ?trailing,
        ],
      ),
    );
  }
}

/// One slice of the single continuous sheet. Slices sit edge to edge, so a
/// lazy list can build only what is on screen and it still reads as one page.
class DprSheetSlice extends StatelessWidget {
  const DprSheetSlice({
    super.key,
    required this.child,
    this.first = false,
    this.last = false,
    this.top = 0,
    this.bottom = 0,
  });

  final Widget child;
  final bool first;
  final bool last;
  final double top;
  final double bottom;

  static const BorderSide _side = BorderSide(color: N360Colors.rule);

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: N360Space.sm),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: N360Colors.sheet,
          border: Border(
            left: _side,
            right: _side,
            top: first ? _side : BorderSide.none,
            bottom: last
                ? const BorderSide(color: N360Colors.paperEdge, width: N360Size.edge)
                : BorderSide.none,
          ),
          borderRadius: first
              ? const BorderRadius.vertical(top: Radius.circular(N360Radius.small))
              : null,
        ),
        child: Padding(
          padding: EdgeInsets.fromLTRB(N360Space.md, top, N360Space.md, bottom),
          child: child,
        ),
      ),
    );
  }
}

/// A numbered section heading, the way a folio numbers its entries.
class DprSectionHead extends StatelessWidget {
  const DprSectionHead({
    super.key,
    required this.number,
    required this.title,
    this.trailing,
    this.error,
  });

  final int number;
  final String title;
  final Widget? trailing;

  /// A plain message about this section. Shown under the rule, in red.
  final String? error;

  @override
  Widget build(BuildContext context) {
    final Color ink = error != null ? N360Colors.stampRed : N360Colors.ink;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: <Widget>[
            SizedBox(
              width: 22,
              child: Text(
                '$number',
                style: N360Type.figure.copyWith(
                  color: N360Colors.stampRed,
                  fontSize: 19,
                ),
              ),
            ),
            Expanded(
              child: Text(title, style: N360Type.heading.copyWith(color: ink)),
            ),
            if (trailing != null) ...<Widget>[
              const SizedBox(width: N360Space.sm),
              trailing!,
            ],
          ],
        ),
        const SizedBox(height: N360Space.xs),
        SizedBox(height: 1.5, child: ColoredBox(color: ink)),
        const SizedBox(height: 2),
        const RuledDivider(),
        if (error != null) ...<Widget>[
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

/// A slip pinned to the top of a report: the approver's words.
class DprRemarkSlip extends StatelessWidget {
  const DprRemarkSlip({super.key, required this.title, required this.message});

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          color: N360Colors.paper,
          border: Border(left: BorderSide(color: N360Colors.haldiInk, width: 5)),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.sm, N360Space.md, N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(title, style: N360Type.bodyStrong.copyWith(color: N360Colors.haldiInk)),
              const SizedBox(height: 2),
              Text(message, style: N360Type.bodyLarge),
            ],
          ),
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

/// The app's bottom sheet: paper, no blur, durations from `context.motion`.
Future<T?> showDprSheet<T>(BuildContext context, WidgetBuilder builder) {
  final motion = context.motion;
  return showModalBottomSheet<T>(
    context: context,
    useRootNavigator: true,
    isScrollControlled: true,
    useSafeArea: true,
    barrierColor: const Color(0x8C16201F),
    sheetAnimationStyle: AnimationStyle(
      duration: motion.of(N360Durations.page),
      reverseDuration: motion.of(N360Durations.toast),
    ),
    builder: builder,
  );
}

/// The screen's one primary action, pinned in the thumb zone.
class DprBottomAction extends StatelessWidget {
  const DprBottomAction({
    super.key,
    required this.label,
    required this.icon,
    required this.onPressed,
  });

  final String label;
  final IconData icon;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final double safe = MediaQuery.paddingOf(context).bottom;
    return ColoredBox(
      color: N360Colors.sheet,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          const SizedBox(height: 1, child: ColoredBox(color: N360Colors.ruleStrong)),
          Padding(
            padding: EdgeInsets.fromLTRB(N360Space.gutter, 10, N360Space.gutter, 10 + safe),
            child: GloveButton(
              label: label,
              icon: icon,
              large: true,
              fullWidth: true,
              onPressed: onPressed,
            ),
          ),
        ],
      ),
    );
  }
}

/// The little ruled grip at the top of a sheet.
class DprSheetGrip extends StatelessWidget {
  const DprSheetGrip({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.only(top: N360Space.sm, bottom: N360Space.xs),
      child: Center(
        child: SizedBox(
          width: 44,
          height: 4,
          child: ColoredBox(color: N360Colors.ruleStrong),
        ),
      ),
    );
  }
}
