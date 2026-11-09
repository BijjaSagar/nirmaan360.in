import 'dart:io';

import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

/// Photos waiting for upload, kept under the app documents directory.
///
/// Rows store paths relative to that directory, because the absolute iOS
/// container path changes between launches. Absolute paths are computed at
/// the moment of use and never stored.
abstract interface class AttachmentStore {
  /// Copies [sourcePath] in as `<folder>/<fileName>` and returns the relative
  /// path. The copy is complete before this returns.
  Future<String> importFile(
    String sourcePath, {
    required String folder,
    required String fileName,
  });

  Future<String> absolutePath(String relativePath);

  Future<bool> exists(String relativePath);

  Future<void> delete(String relativePath);

  /// Removes every stored attachment. Used only by a confirmed sign-out.
  Future<void> deleteAll();
}

class DirectoryAttachmentStore implements AttachmentStore {
  /// [baseDirectory] resolves the documents directory on each use.
  DirectoryAttachmentStore(this._baseDirectory);

  /// The real store, rooted in the app documents directory.
  factory DirectoryAttachmentStore.documents() => DirectoryAttachmentStore(
    () async => (await getApplicationDocumentsDirectory()).path,
  );

  final Future<String> Function() _baseDirectory;

  static const _root = 'attachments';

  @override
  Future<String> importFile(
    String sourcePath, {
    required String folder,
    required String fileName,
  }) async {
    final relative = p.join(_root, folder, fileName);
    final target = File(await absolutePath(relative));
    await target.parent.create(recursive: true);

    // Copy to a temporary name first so a half-written file can never be
    // mistaken for a complete one after a crash.
    final temporary = File('${target.path}.tmp');
    await File(sourcePath).copy(temporary.path);
    await temporary.rename(target.path);
    return relative;
  }

  @override
  Future<String> absolutePath(String relativePath) async =>
      p.join(await _baseDirectory(), relativePath);

  @override
  Future<bool> exists(String relativePath) async =>
      File(await absolutePath(relativePath)).exists();

  @override
  Future<void> delete(String relativePath) async {
    final file = File(await absolutePath(relativePath));
    if (await file.exists()) await file.delete();
  }

  @override
  Future<void> deleteAll() async {
    final directory = Directory(p.join(await _baseDirectory(), _root));
    if (await directory.exists()) await directory.delete(recursive: true);
  }
}

/// Keeps nothing on disk. For widget tests that do not exercise uploads.
class InMemoryAttachmentStore implements AttachmentStore {
  final Set<String> files = {};

  @override
  Future<String> importFile(
    String sourcePath, {
    required String folder,
    required String fileName,
  }) async {
    final relative = '$folder/$fileName';
    files.add(relative);
    return relative;
  }

  @override
  Future<String> absolutePath(String relativePath) async =>
      '/memory/$relativePath';

  @override
  Future<bool> exists(String relativePath) async =>
      files.contains(relativePath);

  @override
  Future<void> delete(String relativePath) async => files.remove(relativePath);

  @override
  Future<void> deleteAll() async => files.clear();
}
