import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';
import 'inspection_models.dart';

export 'inspection_models.dart';

/// Inspections from checklists. What the server last said is kept per project,
/// and an inspection is filled in on the phone, then finished into the outbox as
/// one call that starts it, marks every item and completes it.
///
/// That one call is the only safe thing to queue. The server repeats it by
/// `client_uuid` and applies nothing the second time, whereas `PATCH` and
/// `complete` on an inspection that already exists have no id of their own: a
/// `complete` whose reply was lost is answered 409 on the retry, because the
/// inspection is by then locked. So the phone never splits the work up.
class InspectionRepository {
  InspectionRepository(this._ctx);

  final DataContext _ctx;

  static const Duration _freshFor = Duration(minutes: 10);

  /// Inspections whose items are read ahead for offline use, newest first.
  static const int _detailsKept = 15;
  static const int _detailsAtOnce = 5;

  AppDatabase get _db => _ctx.db;

  /// Open to this person on this plan. Only `checklist.fill` reaches the app.
  bool get isOpenToUser {
    final user = _ctx.session.user;
    return user != null && !user.isContractor && user.can('checklist.fill') && user.features.contains('qhs');
  }

  // Reading --------------------------------------------------------------------

  Stream<InspectionOverview?> watchCached(int projectId) => _db.watchMeta(MetaKey.inspections(projectId)).map(_decode);

  SimpleSelectStatement<$OutboxTable, OutboxRow> _unsentQuery(int projectId) {
    return _db.select(_db.outbox)
      ..where(
        (t) =>
            t.projectId.equals(projectId) &
            t.type.equals(OutboxType.inspection.name) &
            t.status.equals(OutboxStatus.done.name).not(),
      )
      ..orderBy([(t) => OrderingTerm.asc(t.id)]);
  }

  Stream<List<OutboxRow>> watchUnsent(int projectId) => _unsentQuery(projectId).watch();

  /// The last copy on this phone with finished inspections still waiting laid
  /// over it, read once.
  Future<InspectionOverview?> readOverview(int projectId) async {
    final InspectionOverview? cached = _decode(await _db.readMeta(MetaKey.inspections(projectId)));
    return cached?.withUnsent(await _unsentQuery(projectId).get());
  }

  InspectionOverview? _decode(String? raw) {
    if (raw == null) return null;
    try {
      return InspectionOverview.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
    } on Object {
      // A copy that cannot be read is the same as no copy: the next read replaces it.
      return null;
    }
  }

  Map<String, dynamic> _kept(String? raw) {
    if (raw == null) return <String, dynamic>{};
    try {
      return (jsonDecode(raw) as Map).cast<String, dynamic>();
    } on Object {
      return <String, dynamic>{};
    }
  }

  bool _isRefusal(ServerFailure e) => e.statusCode == 403 || e.statusCode == 404;

  List<Map<String, dynamic>> _rows(Object? value) => value is List
      ? <Map<String, dynamic>>[
          for (final Object? row in value)
            if (row is Map) row.cast<String, dynamic>(),
        ]
      : const <Map<String, dynamic>>[];

  /// Throws an [ApiFailure] when the server cannot be reached, leaving the last
  /// copy in place. A refusal replaces it, because it may no longer be seen.
  Future<void> refresh(int projectId) async {
    final String key = MetaKey.inspections(projectId);
    final List<Map<String, dynamic>> templates;
    final Map<String, dynamic> page;
    try {
      templates = await _ctx.api.checklistTemplates();
      page = await _ctx.api.inspections(projectId);
    } on ServerFailure catch (e) {
      if (!_isRefusal(e)) rethrow;
      await _db.writeMeta(key, jsonEncode(<String, dynamic>{'refused': true, 'fetched_at': _now}));
      return;
    }

    final List<Map<String, dynamic>> list = _rows(page['data']);
    final Object? keptDetails = _kept(await _db.readMeta(key))['details'];
    final Map<String, Object?> details = <String, Object?>{};
    final List<int> toRead = <int>[];
    for (final Map<String, dynamic> row in list.take(_detailsKept)) {
      final int? id = (row['id'] as num?)?.toInt();
      if (id == null) continue;
      final Object? had = keptDetails is Map ? keptDetails['$id'] : null;
      // The copy of a locked inspection stays right while its stamp is the same.
      if (had is Map && had['updated_at'] == row['updated_at']) {
        details['$id'] = had;
      } else {
        toRead.add(id);
      }
    }
    for (var start = 0; start < toRead.length; start += _detailsAtOnce) {
      final Iterable<int> batch = toRead.skip(start).take(_detailsAtOnce);
      final List<Map<String, dynamic>?> read = await Future.wait(batch.map(_detailUnlessRefused));
      for (final Map<String, dynamic>? detail in read) {
        final Object? id = detail?['id'];
        if (detail != null && id != null) details['$id'] = detail;
      }
    }

    await _db.writeMeta(
      key,
      jsonEncode(<String, dynamic>{
        'templates': templates,
        'inspections': list,
        'details': details,
        'more': ((page['last_page'] as num?)?.toInt() ?? 1) > 1,
        'fetched_at': _now,
      }),
    );
  }

  Future<Map<String, dynamic>?> _detailUnlessRefused(int id) async {
    try {
      return await _ctx.api.inspection(id);
    } on ServerFailure catch (e) {
      if (_isRefusal(e)) return null;
      rethrow;
    }
  }

  /// Reads one inspection when it is opened, so more than the read-ahead works.
  Future<void> refreshInspection(int projectId, int id) async {
    final Map<String, dynamic>? detail = await _detailUnlessRefused(id);
    if (detail == null) return;
    final String key = MetaKey.inspections(projectId);
    final Map<String, dynamic> kept = _kept(await _db.readMeta(key));
    if (kept.isEmpty || kept['refused'] == true) return;
    final Object? details = kept['details'];
    kept['details'] = <String, Object?>{if (details is Map) ...details.cast<String, Object?>(), '$id': detail};
    await _db.writeMeta(key, jsonEncode(kept));
  }

  /// For the sync engine: keeps the selected project ready to use offline.
  Future<void> refreshCurrent({bool force = false}) async {
    final int? projectId = int.tryParse(await _db.readMeta(MetaKey.selectedProject) ?? '');
    if (projectId == null) return;
    if (!isOpenToUser) {
      // Someone who no longer has this should not keep a copy to open.
      if (_ctx.session.user != null) await _db.deleteMeta(MetaKey.inspections(projectId));
      return;
    }
    // A project taken away is not read again. It would only be refused.
    final known = await (_db.select(_db.projects)..where((t) => t.id.equals(projectId))).getSingleOrNull();
    if (known == null) return;
    if (!force) {
      final DateTime? last = _decode(await _db.readMeta(MetaKey.inspections(projectId)))?.fetchedAt;
      if (last != null && _ctx.clock.now().difference(last) < _freshFor) return;
    }
    await refresh(projectId);
  }

  // Drafts ---------------------------------------------------------------------

  Map<String, dynamic> _draftMap(String? raw) => _kept(raw);

  List<InspectionDraft> _drafts(String? raw) {
    final List<InspectionDraft> drafts = <InspectionDraft>[];
    for (final Object? value in _draftMap(raw).values) {
      if (value is! Map) continue;
      try {
        drafts.add(InspectionDraft.fromJson(value.cast<String, dynamic>()));
      } on Object {
        // A damaged draft is skipped, not allowed to hide the others.
      }
    }
    return drafts..sort((a, b) => b.startedAt.compareTo(a.startedAt));
  }

  Stream<List<InspectionDraft>> watchDrafts(int projectId) =>
      _db.watchMeta(MetaKey.inspectionDrafts(projectId)).map(_drafts);

  Future<List<InspectionDraft>> readDrafts(int projectId) async =>
      _drafts(await _db.readMeta(MetaKey.inspectionDrafts(projectId)));

  Future<InspectionDraft?> readDraft(int projectId, String uuid) async {
    for (final InspectionDraft draft in await readDrafts(projectId)) {
      if (draft.uuid == uuid) return draft;
    }
    return null;
  }

  /// Starts an inspection from [template] on this phone. Nothing is sent.
  Future<InspectionDraft> startDraft({required int projectId, required ChecklistTemplate template}) async {
    _ctx.assertCanWrite();
    final InspectionDraft draft = InspectionDraft(
      uuid: newUuid(),
      projectId: projectId,
      templateId: template.id,
      templateName: template.name,
      category: template.category,
      templateUpdatedAt: template.updatedAt,
      items: template.items,
      inspectedOn: _ctx.today,
      startedAt: _now,
    );
    await _db.transaction(() async {
      final String key = MetaKey.inspectionDrafts(projectId);
      final Map<String, dynamic> map = _draftMap(await _db.readMeta(key));
      map[draft.uuid] = draft.toJson();
      await _db.writeMeta(key, jsonEncode(map));
    });
    return draft;
  }

  /// Keeps what has been marked so far. A draft that has been finished or
  /// discarded in the meantime is not brought back.
  Future<void> saveDraft(InspectionDraft draft) {
    _ctx.assertCanWrite();
    return _db.transaction(() async {
      final String key = MetaKey.inspectionDrafts(draft.projectId);
      final Map<String, dynamic> map = _draftMap(await _db.readMeta(key));
      if (!map.containsKey(draft.uuid)) return;
      map[draft.uuid] = draft.toJson();
      await _db.writeMeta(key, jsonEncode(map));
    });
  }

  Future<void> discardDraft(int projectId, String uuid) {
    _ctx.assertCanWrite();
    return _db.transaction(() async {
      final String key = MetaKey.inspectionDrafts(projectId);
      final Map<String, dynamic> map = _draftMap(await _db.readMeta(key));
      if (map.remove(uuid) == null) return;
      if (map.isEmpty) {
        await _db.deleteMeta(key);
      } else {
        await _db.writeMeta(key, jsonEncode(map));
      }
    });
  }

  /// Finishes a draft: queues the one call that starts, marks and completes the
  /// inspection, and takes the draft away, in one transaction. Doing it twice
  /// queues it once. The server's rules, checked here first so a refusal
  /// does not come back days later in the sync screen:
  ///
  /// - every item has a result (not applicable counts), or it answers 422;
  /// - a failed item says what is wrong, or it answers 422;
  /// - the date is not in the future.
  ///
  /// Throws a [ValidationFailure] when the draft is not ready.
  Future<void> finish(InspectionDraft draft) async {
    _ctx.assertCanWrite();
    if (draft.unmarked.isNotEmpty) {
      const String message = 'Every item needs a result.';
      throw const ValidationFailure(message, errors: {'items': [message]});
    }
    if (draft.needRemark.isNotEmpty) {
      const String message = 'Say what is wrong with a failed item.';
      throw const ValidationFailure(message, errors: {'results': [message]});
    }
    _ctx.assertNotFuture(draft.inspectedOn, 'inspected_on');
    _ctx.assertWithin(<(String, String?, int)>[
      ('location', draft.location.trim(), kInspectionLocationMax),
      for (final int position in List<int>.generate(draft.items.length, (i) => i + 1))
        ('results', draft.remarkAt(position).trim(), kInspectionRemarkMax),
    ]);

    final String? place = draft.location.trim().isEmpty ? null : draft.location.trim();
    await _db.transaction(() async {
      final String key = MetaKey.inspectionDrafts(draft.projectId);
      final Map<String, dynamic> map = _draftMap(await _db.readMeta(key));
      // Already finished by an earlier tap.
      if (map.remove(draft.uuid) == null) return;
      await _ctx.outbox.enqueue(
        type: OutboxType.inspection,
        wireUuid: draft.uuid,
        recordUuid: draft.uuid,
        groupUuid: draft.uuid,
        projectId: draft.projectId,
        data: draft.toPayload(),
        createdAt: _ctx.clock.now(),
        title: draft.templateName,
        subtitle: place,
      );
      if (map.isEmpty) {
        await _db.deleteMeta(key);
      } else {
        await _db.writeMeta(key, jsonEncode(map));
      }
    });
  }

  String get _now => _ctx.clock.now().toUtc().toIso8601String();
}
