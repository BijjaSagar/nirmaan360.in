import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/session/current_user.dart';
import '../../../core/sync/data_context.dart';
import 'contractor_models.dart';

export 'contractor_models.dart';

/// Contracts and payment requests. What the server last said is kept per
/// project, and a request waits in the outbox until it can be sent.
class ContractorRepository {
  ContractorRepository(this._ctx);

  final DataContext _ctx;

  static const Duration _freshFor = Duration(minutes: 10);

  /// Contracts read ahead for offline use, worst first, a few at a time.
  static const int _detailsKept = 30;
  static const int _detailsAtOnce = 5;

  AppDatabase get _db => _ctx.db;

  bool get _seesMoney => _ctx.session.user?.can('money.view') ?? false;

  Stream<ContractorOverview?> watchCached(int projectId) =>
      _db.watchMeta(MetaKey.contractorOverview(projectId)).map(_decode);

  SimpleSelectStatement<$OutboxTable, OutboxRow> _unsentQuery(int projectId) {
    return _db.select(_db.outbox)
      ..where(
        (t) =>
            t.projectId.equals(projectId) &
            t.type.equals(OutboxType.contractorPayment.name) &
            t.status.equals(OutboxStatus.done.name).not(),
      )
      ..orderBy([(t) => OrderingTerm.asc(t.id)]);
  }

  Stream<List<OutboxRow>> watchUnsent(int projectId) => _unsentQuery(projectId).watch();

  /// The last copy on this phone with requests still waiting laid over it, read once.
  Future<ContractorOverview?> readOverview(int projectId) async {
    final ContractorOverview? cached = _decode(await _db.readMeta(MetaKey.contractorOverview(projectId)));
    return cached?.withUnsent(await _unsentQuery(projectId).get(), seesMoney: _seesMoney);
  }

  ContractorOverview? _decode(String? raw) {
    if (raw == null) return null;
    return ContractorOverview.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>()).forViewer(seesMoney: _seesMoney);
  }

  Map<String, dynamic> _kept(String? raw) => raw == null ? <String, dynamic>{} : (jsonDecode(raw) as Map).cast<String, dynamic>();

  bool _isRefusal(ServerFailure e) => e.statusCode == 403 || e.statusCode == 404;

  /// Throws an [ApiFailure] when the server cannot be reached, leaving the
  /// last copy in place. A refusal replaces it, because it may no longer be seen.
  Future<void> refresh(int projectId) async {
    final String key = MetaKey.contractorOverview(projectId);
    final List<Map<String, dynamic>> board;
    final Map<String, dynamic> payments;
    try {
      board = await _ctx.api.contractorBoard(projectId);
      payments = await _ctx.api.contractorPayments(projectId);
    } on ServerFailure catch (e) {
      if (!_isRefusal(e)) rethrow;
      await _db.writeMeta(key, jsonEncode(<String, dynamic>{'refused': true, 'fetched_at': _now}));
      return;
    }

    final Object? kept = _kept(await _db.readMeta(key))['contracts'];
    final Map<String, Object?> details = <String, Object?>{
      for (final Map<String, dynamic> row in board)
        if (kept is Map && kept['${row['work_order_id']}'] != null)
          '${row['work_order_id']}': kept['${row['work_order_id']}'],
    };

    final List<int> ids = <int>[
      for (final Map<String, dynamic> row in board)
        if (row['contract_status'] != 'CLOSED') (row['work_order_id'] as num).toInt(),
    ].take(_detailsKept).toList(growable: false);
    for (var start = 0; start < ids.length; start += _detailsAtOnce) {
      final Iterable<int> batch = ids.skip(start).take(_detailsAtOnce);
      final List<Map<String, dynamic>?> read = await Future.wait(batch.map(_detailUnlessRefused));
      for (final Map<String, dynamic>? detail in read) {
        if (detail != null) details['${detail['work_order_id']}'] = detail;
      }
    }

    final Object? meta = payments['meta'];
    final bool more = meta is Map && ((meta['last_page'] as num?)?.toInt() ?? 1) > 1;
    await _db.writeMeta(
      key,
      jsonEncode(<String, dynamic>{
        'board': board,
        'contracts': details,
        'payments': payments['data'],
        'more_payments': more,
        'fetched_at': _now,
      }),
    );
  }

  Future<Map<String, dynamic>?> _detailUnlessRefused(int workOrderId) async {
    try {
      return await _ctx.api.contractorContract(workOrderId);
    } on ServerFailure catch (e) {
      if (_isRefusal(e)) return null;
      rethrow;
    }
  }

  /// Reads one contract when it is opened, so more than the read-ahead works.
  Future<void> refreshContract(int projectId, int workOrderId) async {
    final Map<String, dynamic>? detail = await _detailUnlessRefused(workOrderId);
    if (detail == null) return;
    final String key = MetaKey.contractorOverview(projectId);
    final Map<String, dynamic> kept = _kept(await _db.readMeta(key));
    final Object? details = kept['contracts'];
    kept['contracts'] = <String, Object?>{
      if (details is Map) ...details.cast<String, Object?>(),
      '$workOrderId': detail,
    };
    await _db.writeMeta(key, jsonEncode(kept));
  }

  /// For the sync engine: keeps the selected project ready to use offline.
  Future<void> refreshCurrent({bool force = false}) async {
    final CurrentUser? user = _ctx.session.user;
    if (user == null || !user.can('contractor.view') || !user.features.contains('site_control')) return;
    final int? projectId = int.tryParse(await _db.readMeta(MetaKey.selectedProject) ?? '');
    if (projectId == null) return;
    // A project taken away is not read again. It would only be refused.
    final known = await (_db.select(_db.projects)..where((t) => t.id.equals(projectId))).getSingleOrNull();
    if (known == null) return;
    if (!force) {
      final DateTime? last = (await readOverview(projectId))?.fetchedAt;
      if (last != null && _ctx.clock.now().difference(last) < _freshFor) return;
    }
    await refresh(projectId);
  }

  /// What approving this payment would do. Null when offline or not allowed.
  Future<PaymentImpact?> readImpact(int paymentId) async {
    try {
      return PaymentImpact.tryFromJson((await _ctx.api.contractorPayment(paymentId))['impact']);
    } on ApiFailure {
      return null;
    }
  }

  /// Throws an [ApiFailure] when it cannot be read.
  Future<Uint8List> billBytes(int paymentId) => _ctx.api.contractorPaymentBill(paymentId);

  /// Queues a payment request. The server checks no live balance when a
  /// request is raised, so it is safe to write offline: a manager sees the
  /// figures when deciding, and [uuid] makes a resend return the first row.
  ///
  /// Throws a [ValidationFailure] for a date in the future.
  Future<String> requestPayment({
    required String uuid,
    required int projectId,
    required int workOrderId,
    required Money amount,
    required String paidOn,
    required String mode,
    String? paidTo,
    String? reference,
    bool isAdvance = false,
    String? notes,
    int? boqItemId,
    int? milestoneId,
    String? billPath,
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertNotFuture(paidOn, 'paid_on');
    String? stored;
    if (billPath != null) {
      final String extension = p.extension(billPath);
      stored = await _ctx.attachments.importFile(
        billPath,
        folder: 'contractor_bills',
        fileName: '$uuid${extension.isEmpty ? '.jpg' : extension}',
      );
    }
    await _db.transaction(() async {
      await _ctx.outbox.enqueue(
        type: OutboxType.contractorPayment,
        wireUuid: uuid,
        recordUuid: uuid,
        groupUuid: uuid,
        projectId: projectId,
        data: <String, dynamic>{
          'work_order_id': workOrderId,
          'paid_on': paidOn,
          'amount': amount.toWire(),
          'mode': mode,
          'paid_to': paidTo,
          'reference': reference,
          // The server's boolean rule takes 1 and 0 from multipart, not true and false.
          'is_advance': isAdvance ? 1 : 0,
          'notes': notes,
          'boq_item_id': boqItemId,
          'contractor_milestone_id': milestoneId,
        },
        filePath: stored,
        createdAt: _ctx.clock.now(),
        title: title,
        subtitle: subtitle,
      );
    });
    return uuid;
  }

  String get _now => _ctx.clock.now().toUtc().toIso8601String();
}
