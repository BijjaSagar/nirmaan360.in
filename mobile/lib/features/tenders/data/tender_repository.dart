import 'dart:typed_data';

import '../../../core/api/api_failure.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';
import 'tender_models.dart';

export 'tender_models.dart';

/// What the quote form sends. Either [partyId] or [newContractor] names who quoted,
/// and either [rates] (by tender line) or [quotedAmount] prices it.
class QuoteInput {
  const QuoteInput({
    this.partyId,
    this.newContractor,
    this.newContractorPhone,
    this.rates = const <int, Money>{},
    this.quotedAmount,
    this.receivedOn,
    this.validUntil,
    this.includesMaterial = false,
    this.completionDays,
    this.paymentTerms,
    this.remarks,
    this.quotationPath,
  });

  final int? partyId;
  final String? newContractor;
  final String? newContractorPhone;
  final Map<int, Money> rates;
  final Money? quotedAmount;
  final String? receivedOn;
  final String? validUntil;
  final bool includesMaterial;
  final int? completionDays;
  final String? paymentTerms;
  final String? remarks;
  final String? quotationPath;
}

/// The contract terms the award form sends. Percentages are typed text the form
/// has already checked, sent as they are so no float touches them.
class AwardInput {
  const AwardInput({
    required this.bidId,
    required this.retentionPct,
    required this.tdsPct,
    required this.gstPct,
    required this.advanceAmount,
    required this.advanceRecoveryPct,
    required this.deductionBasis,
    required this.deductionOrder,
    this.startDate,
    this.endDate,
    this.note,
  });

  final int bidId;
  final String retentionPct;
  final String tdsPct;
  final String gstPct;
  final Money advanceAmount;
  final String advanceRecoveryPct;
  final String deductionBasis;
  final String deductionOrder;
  final String? startDate;
  final String? endDate;
  final String? note;
}

/// Tenders and quotes on BOQ lines. Online only, by design: a quote is compared
/// and a contractor chosen against what the office holds now, so nothing waits
/// in the outbox. Each write that the server repeats safely keeps its
/// `client_uuid` until the server has answered, so a retry never makes two.
class TenderRepository {
  TenderRepository(this._ctx);

  final DataContext _ctx;

  bool get seesMoney => _ctx.session.user?.can('money.view') ?? false;

  /// Whether this person may open a line's tenders at all. A store keeper may
  /// not, and a company without site control has none.
  bool get canView {
    final user = _ctx.session.user;
    return user != null &&
        user.features.contains('site_control') &&
        user.can('boq.view') &&
        user.can('contractor.view');
  }

  /// Starting a tender from a line is pricing work, so it needs `money.view` too.
  bool get canStart {
    final user = _ctx.session.user;
    return canView && user != null && user.can('money.view') && user.can('contractor.tender');
  }

  void _online() {
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();
  }

  /// Markers for every line of the project that is in a tender. Throws an
  /// [ApiFailure] when the server cannot be reached.
  Future<Map<int, TenderMarker>> markers(int projectId) async {
    if (!canView) return const <int, TenderMarker>{};
    _online();
    final List<Map<String, dynamic>> rows = await _ctx.api.boqWithTenders(projectId);
    return <int, TenderMarker>{
      for (final Map<String, dynamic> row in rows)
        if (TenderMarker.tryFromJson(row['tenders']) case final TenderMarker marker)
          (row['id'] as num).toInt(): marker,
    };
  }

  /// Throws an [ApiFailure] when the line cannot be read.
  Future<LineTenders> line(int boqItemId) async {
    _online();
    return LineTenders.fromJson(await _ctx.api.boqLineTenders(boqItemId));
  }

  /// Starts a tender for one line. Returns the new tender's id when the server sends it.
  Future<int?> startTender(int boqItemId, {required String title, String? scope, String? dueOn}) async {
    _online();
    final String key = 'idempotency.tender.line.$boqItemId';
    final String uuid = await _uuid(key);
    final Map<String, dynamic> reply = await _ctx.api.createLineTender(boqItemId, <String, dynamic>{
      'title': title,
      'scope': ?scope,
      'due_on': ?dueOn,
      'client_uuid': uuid,
    });
    await _ctx.db.deleteMeta(key);
    return (reply['id'] as num?)?.toInt();
  }

  Future<List<Subcontractor>> subcontractors([String query = '']) async {
    _online();
    final List<Map<String, dynamic>> rows = await _ctx.api.subcontractors(query: query.trim());
    return rows.map(Subcontractor.fromJson).where((Subcontractor row) => row.name.isNotEmpty).toList(growable: false);
  }

  Future<Subcontractor> addSubcontractor({
    required String name,
    String? phone,
    String? contactPerson,
    String? gstin,
  }) async {
    _online();
    final Map<String, dynamic> reply = await _ctx.api.createSubcontractor(<String, dynamic>{
      'name': name,
      'phone': ?phone,
      'contact_person': ?contactPerson,
      'gstin': ?gstin,
    });
    // Some replies wrap the contractor, others are the contractor.
    final Object? inner = reply['party'] ?? reply['contractor'];
    return Subcontractor.fromJson(inner is Map ? inner.cast<String, dynamic>() : reply);
  }

  /// Sends a quote. Throws a [ValidationFailure] in the server's words.
  Future<void> addQuote(int tenderId, QuoteInput input) async {
    _online();
    final String key = 'idempotency.quote.$tenderId';
    final String uuid = await _uuid(key);
    await _ctx.api.postTenderQuote(
      tenderId,
      <String, dynamic>{
        'party_id': ?input.partyId,
        if (input.partyId == null) 'new_contractor': ?input.newContractor,
        if (input.partyId == null) 'new_contractor_phone': ?input.newContractorPhone,
        if (input.rates.isNotEmpty)
          'rates': <String, String>{
            for (final MapEntry<int, Money> rate in input.rates.entries) '${rate.key}': rate.value.toWire(),
          },
        if (input.rates.isEmpty) 'quoted_amount': ?input.quotedAmount?.toWire(),
        'received_on': ?input.receivedOn,
        'valid_until': ?input.validUntil,
        // The server's boolean rule takes 1 and 0 from multipart, not true and false.
        'includes_material': input.includesMaterial ? 1 : 0,
        'completion_days': ?input.completionDays,
        'payment_terms': ?input.paymentTerms,
        'remarks': ?input.remarks,
        'client_uuid': uuid,
      },
      filePath: input.quotationPath,
    );
    await _ctx.db.deleteMeta(key);
  }

  /// Throws an [ApiFailure] when it cannot be read.
  Future<Uint8List> quotation(int bidId) {
    _online();
    return _ctx.api.quotationBytes(bidId);
  }

  /// Chooses a quote. Returns the work order made from it, when the server says.
  /// Sending the same choice again returns the same work order.
  Future<int?> award(int tenderId, AwardInput input) async {
    _online();
    final String key = 'idempotency.award.$tenderId.${input.bidId}';
    final String uuid = await _uuid(key);
    final Map<String, dynamic> reply = await _ctx.api.awardTender(tenderId, <String, dynamic>{
      'bid_id': input.bidId,
      'start_date': ?input.startDate,
      'end_date': ?input.endDate,
      'retention_pct': input.retentionPct,
      'tds_pct': input.tdsPct,
      'gst_pct': input.gstPct,
      'advance_amount': input.advanceAmount.toWire(),
      'advance_recovery_pct': input.advanceRecoveryPct,
      'deduction_basis': input.deductionBasis,
      'deduction_order': input.deductionOrder,
      'award_note': ?input.note,
      'client_uuid': uuid,
    });
    await _ctx.db.deleteMeta(key);
    final Object? order = reply['work_order'];
    final Object? tender = reply['data'];
    final Object? id = order is Map ? order['id'] : (tender is Map ? tender['work_order_id'] : null);
    return (id as num?)?.toInt();
  }

  /// The same id until the server has answered, so a retry is the same write.
  Future<String> _uuid(String key) async {
    final String? kept = await _ctx.db.readMeta(key);
    if (kept != null) return kept;
    final String uuid = newUuid();
    await _ctx.db.writeMeta(key, uuid);
    return uuid;
  }
}
