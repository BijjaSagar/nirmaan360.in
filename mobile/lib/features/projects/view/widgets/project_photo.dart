import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/db/app_database.dart';
import '../../../../core/design/design.dart';

/// A project's photo, or the first letter of its name when it has none or the
/// photo cannot be fetched. Decoration only: the name is always written beside
/// it, so a screen reader skips this.
class ProjectPhoto extends StatefulWidget {
  const ProjectPhoto({super.key, required this.project, this.size = 48});

  final ProjectRow project;
  final double size;

  @override
  State<ProjectPhoto> createState() => _ProjectPhotoState();
}

class _ProjectPhotoState extends State<ProjectPhoto> {
  Future<Uint8List?>? _bytes;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ProjectPhoto oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.project.id != widget.project.id || oldWidget.project.imageUrl != widget.project.imageUrl) {
      _load();
    }
  }

  void _load() {
    final String? imageUrl = widget.project.imageUrl;
    _bytes = imageUrl == null
        ? null
        : context.read<AppServices>().projectImages.bytesFor(widget.project.id, imageUrl);
  }

  Widget _letter() {
    final String name = widget.project.name.trim();
    final String letter = name.isEmpty ? '?' : name.characters.first.toUpperCase();
    return ColoredBox(
      color: N360Colors.primaryTint,
      child: Center(
        child: Text(letter, style: N360Type.fit(N360Type.heading, letter).copyWith(color: N360Colors.laterite)),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final int decodeWidth = (widget.size * MediaQuery.devicePixelRatioOf(context)).round();
    return ExcludeSemantics(
      child: ClipRRect(
        borderRadius: N360Radius.md,
        child: SizedBox.square(
          dimension: widget.size,
          child: _bytes == null
              ? _letter()
              : FutureBuilder<Uint8List?>(
                  future: _bytes,
                  builder: (context, snapshot) {
                    final Uint8List? bytes = snapshot.data;
                    if (bytes == null) return _letter();
                    return Image.memory(
                      bytes,
                      key: const ValueKey<String>('project-photo'),
                      fit: BoxFit.cover,
                      cacheWidth: decodeWidth,
                      gaplessPlayback: true,
                      errorBuilder: (context, error, stack) => _letter(),
                    );
                  },
                ),
        ),
      ),
    );
  }
}
