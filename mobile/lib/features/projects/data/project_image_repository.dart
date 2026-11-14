import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/data_context.dart';

/// Project photos need the sign-in token, and have to show with no network, so
/// each one is kept on the phone with the address it came from. The address
/// changes whenever the photo does.
class ProjectImageRepository {
  ProjectImageRepository(this._ctx);

  final DataContext _ctx;

  static const String _folder = 'project_images';

  AppDatabase get _db => _ctx.db;

  String _fileName(int projectId) => '$projectId.jpg';

  /// The photo at [imageUrl], or null when there is nothing to show. An older
  /// photo of the same project is better than none when the new one cannot be
  /// fetched.
  Future<Uint8List?> bytesFor(int projectId, String imageUrl) async {
    final String? storedUrl = await _db.readMeta(MetaKey.projectImage(projectId));
    final Uint8List? stored = await _ctx.attachments.readFile(folder: _folder, fileName: _fileName(projectId));
    if (stored != null && storedUrl == imageUrl) return stored;
    if (!_ctx.connectivity.isOnline) return stored;
    try {
      final Uint8List bytes = await _ctx.api.projectImageBytes(imageUrl);
      await _ctx.attachments.writeFile(bytes, folder: _folder, fileName: _fileName(projectId));
      await _db.writeMeta(MetaKey.projectImage(projectId), imageUrl);
      return bytes;
    } on ApiFailure {
      return stored;
    }
  }

  /// Clears photos of projects that have left the phone or no longer have one.
  Future<void> sweepRemoved() async {
    final List<ProjectRow> projects = await _db.select(_db.projects).get();
    final Set<int> keep = <int>{
      for (final ProjectRow project in projects)
        if (project.imageUrl != null) project.id,
    };
    final List<KeyValueRow> stored = await (_db.select(_db.keyValues)
          ..where((t) => t.key.like('${MetaKey.projectImagePrefix}%')))
        .get();
    for (final KeyValueRow row in stored) {
      final int? projectId = int.tryParse(row.key.substring(MetaKey.projectImagePrefix.length));
      if (projectId != null && keep.contains(projectId)) continue;
      if (projectId != null) {
        await _ctx.attachments.deleteFile(folder: _folder, fileName: _fileName(projectId));
      }
      await _db.deleteMeta(row.key);
    }
  }
}
