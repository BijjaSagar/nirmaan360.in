import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/progress_models.dart';

/// For drawing a bar only, never for arithmetic.
double percentFraction(String? raw) => ((double.tryParse(raw ?? '') ?? 0) / 100).clamp(0.0, 1.0);

StampKind stageKind(String status) => switch (status) {
  'done' => StampKind.approved,
  'in_progress' => StampKind.inReview,
  'pending' => StampKind.returned,
  _ => StampKind.draft,
};

IconData stageIcon(String status) => switch (status) {
  'done' => Icons.check_circle_outline,
  'in_progress' => Icons.timelapse,
  'pending' => Icons.pause_circle_outline,
  'na' => Icons.block,
  _ => Icons.radio_button_unchecked,
};

/// A name, how far along, and a bar. Used for floors and for BOQ lines.
class ProgressBarTile extends StatelessWidget {
  const ProgressBarTile({
    super.key,
    required this.title,
    required this.percent,
    this.caption,
    this.alert,
    this.onTap,
  });

  final String title;
  final String? percent;
  final String? caption;

  /// A short warning shown instead of a plain caption's colour.
  final String? alert;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final String shown = percentText(percent);
    final Widget body = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: Text(title, style: N360Type.fit(N360Type.bodyStrong, title))),
              const SizedBox(width: N360Space.sm),
              Text(shown, style: N360Type.figureSmall),
              if (onTap != null) ...<Widget>[
                const SizedBox(width: N360Space.xs),
                const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.ink2),
              ],
            ],
          ),
          HatchedProgressBar(fraction: percentFraction(percent), height: 6, semanticLabel: '$title, $shown'),
          if (caption != null) Text(caption!, style: N360Type.fit(N360Type.caption, caption!)),
          if (alert != null)
            Text(
              alert!,
              style: N360Type.fit(N360Type.caption, alert!).copyWith(color: N360Colors.laterite),
            ),
        ],
      ),
    );
    if (onTap == null) return body;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: '$title, $shown',
      builder: (context, depth, child) => Opacity(opacity: 1 - depth * 0.3, child: child),
      child: ConstrainedBox(constraints: const BoxConstraints(minHeight: N360Size.tap), child: body),
    );
  }
}

/// Photos waiting to be saved with an entry, and the button to take one.
class ProgressPhotoRow extends StatelessWidget {
  const ProgressPhotoRow({
    super.key,
    required this.photos,
    required this.onAdd,
    required this.onRemove,
    this.max = 3,
    this.label,
  });

  final List<String> photos;
  final ValueChanged<String> onAdd;
  final ValueChanged<String> onRemove;

  final int max;

  /// The button's words. Defaults to "Add photo".
  final String? label;
  static const double _thumb = 72;

  Future<void> _take(BuildContext context) async {
    final String? path = await context.read<AppServices>().photos.capture();
    if (path != null) onAdd(path);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (photos.isNotEmpty) ...<Widget>[
          Wrap(
            spacing: N360Space.sm,
            runSpacing: N360Space.sm,
            children: <Widget>[
              for (final String path in photos)
                Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    ClipRRect(
                      borderRadius: N360Radius.md,
                      child: Image.file(
                        File(path),
                        width: _thumb,
                        height: _thumb,
                        fit: BoxFit.cover,
                        errorBuilder: (context, error, stack) => const SizedBox(
                          width: _thumb,
                          height: _thumb,
                          child: Icon(Icons.image_outlined, color: N360Colors.ink2),
                        ),
                      ),
                    ),
                    IconButton(
                      tooltip: l.progressFormRemovePhoto,
                      onPressed: () => onRemove(path),
                      icon: const Icon(Icons.close, size: N360IconSize.md, color: N360Colors.ink2),
                    ),
                  ],
                ),
            ],
          ),
          const SizedBox(height: N360Space.xs),
        ],
        if (photos.length < max)
          GloveButton(
            label: label ?? l.progressFormPhoto,
            icon: Icons.photo_camera_outlined,
            kind: GloveButtonKind.secondary,
            fullWidth: true,
            onPressed: () => _take(context),
          ),
      ],
    );
  }
}
