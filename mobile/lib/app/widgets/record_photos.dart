import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/design/design.dart';
import '../../core/sync/record_photos.dart';
import '../../l10n/generated/app_localizations.dart';

/// What a photo on the server shows, fetched or read from this phone.
typedef PhotoBytesLoader = Future<Uint8List> Function(ServerPhoto photo);

/// The full path of a photo still waiting on this phone.
typedef LocalPhotoPath = Future<String> Function(String relativePath);

/// One kind of photo on a record: a title, the pictures, and the button that
/// takes another. Shared by issues and measurements so both read alike.
class RecordPhotoGroup extends StatelessWidget {
  const RecordPhotoGroup({
    super.key,
    required this.title,
    required this.photos,
    required this.loadBytes,
    required this.localPath,
    this.addLabel,
    this.onAdd,
    this.emptyLabel,
  });

  final String title;
  final List<RecordPhoto> photos;
  final PhotoBytesLoader loadBytes;
  final LocalPhotoPath localPath;

  /// Null hides the button: nobody here may add, or no more fit.
  final String? addLabel;
  final VoidCallback? onAdd;

  /// Said when there are no pictures.
  final String? emptyLabel;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(title, style: N360Type.fit(N360Type.label, title)),
        const SizedBox(height: N360Space.xs),
        if (photos.isEmpty && emptyLabel != null)
          Text(emptyLabel!, style: N360Type.fit(N360Type.caption, emptyLabel!))
        else
          Wrap(
            spacing: N360Space.sm,
            runSpacing: N360Space.sm,
            children: <Widget>[
              for (final RecordPhoto photo in photos)
                RecordPhotoTile(key: ValueKey<String>(photo.key), photo: photo, loadBytes: loadBytes, localPath: localPath),
            ],
          ),
        if (onAdd != null && addLabel != null) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          GloveButton(
            label: addLabel!,
            icon: Icons.photo_camera_outlined,
            kind: GloveButtonKind.secondary,
            fullWidth: true,
            onPressed: onAdd,
          ),
        ],
      ],
    );
  }
}

/// A picture the size of a thumb's pad. Opens larger when tapped.
class RecordPhotoTile extends StatefulWidget {
  const RecordPhotoTile({
    super.key,
    required this.photo,
    required this.loadBytes,
    required this.localPath,
    this.size = 88,
  });

  final RecordPhoto photo;
  final PhotoBytesLoader loadBytes;
  final LocalPhotoPath localPath;
  final double size;

  @override
  State<RecordPhotoTile> createState() => _RecordPhotoTileState();
}

class _RecordPhotoTileState extends State<RecordPhotoTile> {
  Future<Uint8List>? _bytes;
  Future<String>? _path;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant RecordPhotoTile oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.photo.key != widget.photo.key) _load();
  }

  void _load() {
    final ServerPhoto? server = widget.photo.server;
    if (server != null && server.isImage) {
      _bytes = widget.loadBytes(server);
      _path = null;
    } else if (server == null) {
      _path = widget.localPath(widget.photo.localPath!);
      _bytes = null;
    }
  }

  Widget _plate({required IconData icon, String? words}) {
    return ColoredBox(
      color: N360Colors.paper,
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(icon, size: N360IconSize.xl, color: N360Colors.inkLabel),
            if (words != null) Text(words, textAlign: TextAlign.center, maxLines: 2, style: N360Type.fit(N360Type.caption, words)),
          ],
        ),
      ),
    );
  }

  Widget _image(BuildContext context, AppLocalizations l, {BoxFit fit = BoxFit.cover, double? side}) {
    final ServerPhoto? server = widget.photo.server;
    final int? decodeWidth = side == null ? null : (side * MediaQuery.devicePixelRatioOf(context)).round();
    if (server != null && !server.isImage) return _plate(icon: Icons.picture_as_pdf_outlined, words: l.photosDocument);
    if (server != null) {
      return FutureBuilder<Uint8List>(
        future: _bytes,
        builder: (BuildContext context, AsyncSnapshot<Uint8List> snapshot) {
          if (snapshot.hasError) return _plate(icon: Icons.image_not_supported_outlined, words: l.photosNeedNetwork);
          final Uint8List? bytes = snapshot.data;
          if (bytes == null) return _plate(icon: Icons.image_outlined);
          return Image.memory(
            bytes,
            fit: fit,
            cacheWidth: decodeWidth,
            gaplessPlayback: true,
            errorBuilder: (BuildContext context, Object error, StackTrace? stack) =>
                _plate(icon: Icons.image_not_supported_outlined, words: l.photosNeedNetwork),
          );
        },
      );
    }
    return FutureBuilder<String>(
      future: _path,
      builder: (BuildContext context, AsyncSnapshot<String> snapshot) {
        final String? path = snapshot.data;
        if (path == null) return _plate(icon: Icons.image_outlined);
        return Image.file(
          File(path),
          fit: fit,
          cacheWidth: decodeWidth,
          gaplessPlayback: true,
          errorBuilder: (BuildContext context, Object error, StackTrace? stack) => _plate(icon: Icons.image_outlined),
        );
      },
    );
  }

  Future<void> _open(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return showLedgerSheet<void>(
      context,
      isScrollControlled: true,
      builder: (BuildContext sheet) => _Viewer(
        caption: widget.photo.photoCaption,
        image: _image(sheet, l, fit: BoxFit.contain),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final RecordPhoto photo = widget.photo;
    final bool openable = photo.server?.isImage ?? true;
    final String? status = !photo.isWaiting ? null : (photo.failed ? l.photosNotSent : l.photosWaiting);

    final Widget picture = ClipRRect(
      borderRadius: N360Radius.md,
      child: SizedBox.square(dimension: widget.size, child: _image(context, l, side: widget.size)),
    );

    return SizedBox(
      width: widget.size,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if (openable)
            PressTarget(
              onTap: () => _open(context),
              haptic: N360Haptics.tap,
              semanticsLabel: l.photosOpen,
              minWidth: widget.size,
              minHeight: widget.size,
              builder: (BuildContext context, double depth, Widget? child) => Transform.scale(scale: pressedScale(depth), child: child),
              child: picture,
            )
          else
            picture,
          if (status != null)
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xxs),
              child: Text(
                status,
                textAlign: TextAlign.center,
                style: N360Type.fit(N360Type.caption, status).copyWith(color: photo.failed ? N360Colors.stampRed : N360Colors.inkLabel),
              ),
            )
          else if (photo.photoCaption != null && photo.photoCaption!.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xxs),
              child: Text(
                photo.photoCaption!,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: N360Type.fit(N360Type.caption, photo.photoCaption!),
              ),
            ),
        ],
      ),
    );
  }
}

class _Viewer extends StatelessWidget {
  const _Viewer({required this.image, this.caption});

  final Widget image;
  final String? caption;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final double tallest = MediaQuery.sizeOf(context).height * 0.6;
    return Padding(
      padding: ledgerSheetPadding,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          ConstrainedBox(
            constraints: BoxConstraints(maxHeight: tallest),
            child: ClipRRect(
              borderRadius: N360Radius.md,
              child: InteractiveViewer(minScale: 1, maxScale: 5, child: image),
            ),
          ),
          if (caption != null && caption!.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Text(caption!, style: N360Type.fit(N360Type.body, caption!)),
          ],
          const SizedBox(height: N360Space.md),
          GloveButton(
            label: l.photosClose,
            icon: Icons.close,
            kind: GloveButtonKind.secondary,
            fullWidth: true,
            onPressed: () => Navigator.of(context).pop(),
          ),
        ],
      ),
    );
  }
}
