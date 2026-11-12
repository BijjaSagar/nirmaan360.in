import 'dart:convert';
import 'dart:io';
import 'dart:isolate';

import 'package:archive/archive_io.dart';
import 'package:drift/drift.dart' show StringExpressionOperators;
import 'package:path/path.dart' as p;

import '../../../core/config/app_config.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/data_context.dart';

/// The tables that hold the person's work, and the rows waiting behind them.
const List<String> kExportTables = <String>[
  'projects',
  'sites',
  'boq_items',
  'workers',
  'wallets',
  'users',
  'dprs',
  'dpr_photos',
  'measurements',
  'issues',
  'attendance_marks',
  'petty_cash_entries',
  'outbox',
];

/// One finished backup, ready to hand to the system share sheet.
class OfflineExport {
  const OfflineExport({required this.path, required this.photos, required this.missingPhotos});

  final String path;

  /// Photos and bills written into the file.
  final int photos;

  /// Photos the records point to that are no longer on the phone.
  final int missingPhotos;
}

/// Writes everything the person has on this phone into one zip file: all the
/// tables as JSON and CSV, and every photo and bill.
class OfflineExporter {
  OfflineExporter(this._ctx, {required this._tempDirectory});

  final DataContext _ctx;
  final Future<String> Function() _tempDirectory;

  Future<OfflineExport> create() async {
    final AppDatabase db = _ctx.db;
    final DateTime now = _ctx.clock.now().toUtc();

    final tables = <String, List<Map<String, Object?>>>{};
    for (final String table in kExportTables) {
      final rows = await db.customSelect('SELECT * FROM $table').get();
      tables[table] = <Map<String, Object?>>[for (final row in rows) _readable(row.data)];
    }

    final List<_Photo> photos = await _photos(tables);
    final int found = photos.where((photo) => photo.absolute != null).length;

    final String data = const JsonEncoder.withIndent('  ').convert(<String, Object?>{
      'exported_at': now.toIso8601String(),
      'user': _ctx.session.user?.toJson(),
      // Upload details and anything else with no column of its own.
      'local_details': await _localDetails(db),
      'tables': tables,
    });
    final Map<String, String> texts = <String, String>{
      'data.json': data,
      'README.txt': _readme,
      for (final entry in tables.entries) 'csv/${entry.key}.csv': _csv(entry.value),
    };

    final Directory folder = Directory(p.join(await _tempDirectory(), AppConfig.offlineExportFolder));
    // Only earlier copies live here, and each was already handed over.
    if (await folder.exists()) await folder.delete(recursive: true);
    await folder.create(recursive: true);
    final String stamp = now.toIso8601String().substring(0, 19).replaceAll(RegExp(r'[-:T]'), '');
    final String path = p.join(folder.path, 'nirmaan360-backup-$stamp.zip');

    final List<(String, String)> files = <(String, String)>[
      for (final _Photo photo in photos)
        if (photo.absolute != null) (photo.absolute!, 'photos/${photo.relative}'),
    ];
    // Photos can be many and large; the screen should keep moving meanwhile.
    await Isolate.run(() => _writeZip(path, texts, files));

    return OfflineExport(path: path, photos: found, missingPhotos: photos.length - found);
  }

  Future<List<_Photo>> _photos(Map<String, List<Map<String, Object?>>> tables) async {
    final paths = <String>{
      for (final row in tables['dpr_photos']!)
        if (row['relative_path'] case final String path) path,
      for (final row in tables['petty_cash_entries']!)
        if (row['bill_relative_path'] case final String path) path,
      for (final row in tables['outbox']!)
        if (row['file_path'] case final String path) path,
    };
    return <_Photo>[
      for (final String relative in paths)
        _Photo(relative, await _ctx.attachments.exists(relative) ? await _ctx.attachments.absolutePath(relative) : null),
    ];
  }

  Future<Map<String, String>> _localDetails(AppDatabase db) async {
    final rows = await (db.select(db.keyValues)..where((t) => t.key.like('offline.record.%'))).get();
    return <String, String>{for (final row in rows) row.key: row.value};
  }

  /// Drift stores timestamps as seconds since 1970. Nobody can read that.
  static Map<String, Object?> _readable(Map<String, Object?> row) {
    return <String, Object?>{
      for (final entry in row.entries)
        entry.key: entry.key.endsWith('_at') && entry.value is int
            ? DateTime.fromMillisecondsSinceEpoch((entry.value as int) * 1000, isUtc: true).toIso8601String()
            : entry.value,
    };
  }

  static String _csv(List<Map<String, Object?>> rows) {
    if (rows.isEmpty) return '';
    final List<String> columns = rows.first.keys.toList();
    String cell(Object? value) {
      final String text = value?.toString() ?? '';
      if (!text.contains(RegExp(r'[",\r\n]'))) return text;
      return '"${text.replaceAll('"', '""')}"';
    }

    final buffer = StringBuffer()..writeln(columns.map(cell).join(','));
    for (final row in rows) {
      buffer.writeln(columns.map((c) => cell(row[c])).join(','));
    }
    return buffer.toString();
  }
}

class _Photo {
  const _Photo(this.relative, this.absolute);

  final String relative;

  /// Null when the file is gone from the phone.
  final String? absolute;
}

const String _readme = 'Nirmaan360 backup\n\n'
    'data.json has everything on the phone, in one file.\n'
    'The csv folder has the same tables for a spreadsheet.\n'
    'The photos folder has every photo and bill. Each record names its file.\n\n'
    'Keep this file somewhere safe: it is the only other copy of your data.\n';

/// Runs in an isolate, so everything it touches is plain data and files.
Future<void> _writeZip(String path, Map<String, String> texts, List<(String, String)> files) async {
  final encoder = ZipFileEncoder();
  encoder.create(path, level: ZipFileEncoder.store);
  for (final entry in texts.entries) {
    final List<int> bytes = utf8.encode(entry.value);
    encoder.addArchiveFile(ArchiveFile.bytes(entry.key, bytes));
  }
  for (final (String source, String name) in files) {
    await encoder.addFile(File(source), name);
  }
  await encoder.close();
}
