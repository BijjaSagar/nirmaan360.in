import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/dpr_repository.dart';
import 'dpr_ui.dart';

/// The big camera tile: a thumb lands on it without looking.
class DprCameraButton extends StatelessWidget {
  const DprCameraButton({super.key, required this.onTap, required this.busy});

  final VoidCallback onTap;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return LedgerCard(
      onTap: busy ? null : onTap,
      semanticsLabel: l.dprTakePhoto,
      padding: const EdgeInsets.symmetric(vertical: 14),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          const Icon(Icons.photo_camera, size: 40, color: N360Colors.ink),
          const SizedBox(width: N360Space.md),
          Text(l.dprTakePhoto, style: N360Type.title.copyWith(height: 1.2)),
        ],
      ),
    );
  }
}

/// Taken photos, side by side. Horizontal so ten photos cost one row of sheet.
class DprPhotoStrip extends StatelessWidget {
  const DprPhotoStrip({
    super.key,
    required this.photos,
    required this.repository,
    required this.onRemove,
    required this.landed,
  });

  final List<DprPhotoRow> photos;
  final DprRepository repository;

  /// Null on a read-only report.
  final ValueChanged<DprPhotoRow>? onRemove;
  final Set<Object> landed;

  static const double _thumb = 112;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final bool removable = onRemove != null;
    return SizedBox(
      height: _thumb + (removable ? N360Size.tap + 2 : 2),
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        itemCount: photos.length,
        itemBuilder: (context, index) {
          final DprPhotoRow photo = photos[index];
          return Padding(
            key: ValueKey<String>(photo.clientUuid),
            padding: EdgeInsets.only(right: index == photos.length - 1 ? 0 : N360Space.sm),
            child: DprLandOnce(
              id: photo.clientUuid,
              landed: landed,
              child: SizedBox(
                width: _thumb,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    DprPhotoThumb(photo: photo, repository: repository, size: _thumb),
                    if (removable)
                      DprTapPad(
                        onTap: () => onRemove!(photo),
                        semanticsLabel: l.dprRemove,
                        minWidth: _thumb,
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: <Widget>[
                            const Icon(Icons.close, size: 22, color: N360Colors.stampRed),
                            const SizedBox(width: 4),
                            Text(
                              l.dprRemove,
                              style: N360Type.caption.copyWith(
                                color: N360Colors.stampRed,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
