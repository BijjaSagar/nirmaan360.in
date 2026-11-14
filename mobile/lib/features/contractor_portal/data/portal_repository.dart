import 'dart:convert';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/session/current_user.dart';
import '../../../core/sync/data_context.dart';
import 'portal_models.dart';

export 'portal_models.dart';

/// The contractor portal, read only. A contractor login has no sites to
/// pull and nothing to send, so this is the whole of its data: what the
/// server last said is kept on the phone, and the screens open from it with
/// no network.
class PortalRepository {
  PortalRepository(this._ctx);

  final DataContext _ctx;

  static const Duration _freshFor = Duration(minutes: 10);

  /// Contracts and bills read ahead for offline use, a few at a time.
  static const int _kept = 30;
  static const int _atOnce = 5;

  /// The permission and plan feature that open the portal.
  static const String permission = 'contractor_portal.view';
  static const String feature = 'contractor_portal';

  AppDatabase get _db => _ctx.db;

  /// Null until the portal has been read once on this phone.
  Stream<PortalSnapshot?> watchCached() => _db.watchMeta(MetaKey.portal).map(_decode);

  Future<PortalSnapshot?> read() async => _decode(await _db.readMeta(MetaKey.portal));

  PortalSnapshot? _decode(String? raw) {
    if (raw == null) return null;
    final PortalSnapshot kept;
    try {
      kept = PortalSnapshot.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
    } on Object {
      // A copy that cannot be read is the same as no copy: the next read replaces it.
      return null;
    }
    // Never show another contractor's figures, whatever is left on the phone.
    final int? mine = _ctx.session.user?.contractorPartyId;
    if (mine != null && kept.partyId != null && kept.partyId != mine) return null;
    return kept;
  }

  bool _isRefusal(ServerFailure e) => e.statusCode == 403 || e.statusCode == 404;

  String get _now => _ctx.clock.now().toUtc().toIso8601String();

  Future<Map<String, dynamic>> _stored() async {
    final String? raw = await _db.readMeta(MetaKey.portal);
    if (raw == null) return <String, dynamic>{};
    try {
      return (jsonDecode(raw) as Map).cast<String, dynamic>();
    } on Object {
      return <String, dynamic>{};
    }
  }

  /// Asks the server who this is, so a change to the login (a new permission,
  /// or a move to the site team) reaches a phone that never pulls. A refusal
  /// here is left to the portal reads to report.
  ///
  /// Throws [OfflineFailure], [AuthExpired] and [RateLimited] as they come.
  Future<CurrentUser?> _refreshUser() async {
    try {
      final CurrentUser fresh = CurrentUser.fromJson(await _ctx.api.me());
      await _ctx.session.updateUser(fresh);
    } on ServerFailure {
      // Keep the user the phone has.
    } on ValidationFailure {
      // Same.
    }
    return _ctx.session.user;
  }

  /// Reads the portal again. Throws an [ApiFailure] when the server cannot be
  /// reached, leaving the last copy in place. A refusal replaces the copy,
  /// because what it held may no longer be seen.
  Future<void> refresh() async {
    final CurrentUser? user = await _refreshUser();
    // Not a contractor any more: the gate moves this person to the site screens.
    if (user == null || !user.isContractor) return;

    if (!user.can(permission) || !user.features.contains(feature)) {
      await _writeRefused(user);
      return;
    }

    final Map<String, dynamic> overview;
    final List<Map<String, dynamic>> payments;
    final List<Map<String, dynamic>> bills;
    try {
      overview = await _ctx.api.portalOverview();
      payments = await _ctx.api.portalPayments();
      bills = await _ctx.api.portalBills();
    } on ServerFailure catch (e) {
      if (!_isRefusal(e)) rethrow;
      await _writeRefused(user);
      return;
    }

    final Map<String, dynamic> stored = await _stored();
    final Map<String, Object?> contracts = _keepOnly(stored['contracts'], _ids(overview['contracts']));
    final Map<String, Object?> opened = _keepOnly(stored['bill_details'], _billIds(bills));

    final List<int> contractIds = _ids(overview['contracts']).take(_kept).toList(growable: false);
    for (var start = 0; start < contractIds.length; start += _atOnce) {
      final List<Map<String, dynamic>?> read = await Future.wait(
        contractIds.skip(start).take(_atOnce).map(_contractUnlessGone),
      );
      for (final Map<String, dynamic>? detail in read) {
        final int? id = detail == null ? null : _idOf(detail);
        if (detail != null && id != null) contracts['$id'] = detail;
      }
    }

    final List<int> billIds = _billIds(bills).take(_kept).toList(growable: false);
    for (var start = 0; start < billIds.length; start += _atOnce) {
      final List<Map<String, dynamic>?> read = await Future.wait(
        billIds.skip(start).take(_atOnce).map(_billUnlessGone),
      );
      for (final Map<String, dynamic>? detail in read) {
        final int? id = detail == null ? null : _idOf(detail);
        if (detail != null && id != null) opened['$id'] = detail;
      }
    }

    await _db.writeMeta(
      MetaKey.portal,
      jsonEncode(<String, dynamic>{
        'party_id': user.contractorPartyId,
        'overview': overview,
        'payments': payments,
        'bills': bills,
        'contracts': contracts,
        'bill_details': opened,
        'fetched_at': _now,
      }),
    );
  }

  Future<void> _writeRefused(CurrentUser user) {
    return _db.writeMeta(
      MetaKey.portal,
      jsonEncode(<String, dynamic>{'refused': true, 'party_id': user.contractorPartyId, 'fetched_at': _now}),
    );
  }

  int? _idOf(Map<String, dynamic> row) {
    final Object? id = row['id'];
    return id is num ? id.toInt() : int.tryParse('${id ?? ''}');
  }

  List<int> _ids(Object? rows) => <int>[
    if (rows is List)
      for (final Object? row in rows)
        if (row is Map && _idOf(row.cast<String, dynamic>()) != null) _idOf(row.cast<String, dynamic>())!,
  ];

  List<int> _billIds(List<Map<String, dynamic>> bills) => <int>[
    for (final Map<String, dynamic> row in bills)
      if (_idOf(row) != null) _idOf(row)!,
  ];

  /// What was kept for the ids that are still listed, so a contract the
  /// server no longer lists does not linger.
  Map<String, Object?> _keepOnly(Object? kept, List<int> ids) {
    final Set<String> wanted = ids.map((int id) => '$id').toSet();
    return <String, Object?>{
      if (kept is Map)
        for (final MapEntry<dynamic, dynamic> entry in kept.entries)
          if (wanted.contains('${entry.key}')) '${entry.key}': entry.value,
    };
  }

  Future<Map<String, dynamic>?> _contractUnlessGone(int id) async {
    try {
      return await _ctx.api.portalContract(id);
    } on ServerFailure catch (e) {
      if (_isRefusal(e)) return null;
      rethrow;
    }
  }

  Future<Map<String, dynamic>?> _billUnlessGone(int id) async {
    try {
      return await _ctx.api.portalBill(id);
    } on ServerFailure catch (e) {
      if (_isRefusal(e)) return null;
      rethrow;
    }
  }

  /// Reads one contract when it is opened, so the read-ahead limit is no limit.
  Future<void> refreshContract(int workOrderId) async {
    final Map<String, dynamic>? detail = await _contractUnlessGone(workOrderId);
    if (detail == null) return;
    await _put('contracts', workOrderId, detail);
  }

  /// Reads one bill with its lines when it is opened.
  Future<void> refreshBill(int billId) async {
    final Map<String, dynamic>? detail = await _billUnlessGone(billId);
    if (detail == null) return;
    await _put('bill_details', billId, detail);
  }

  Future<void> _put(String section, int id, Map<String, dynamic> detail) async {
    final Map<String, dynamic> stored = await _stored();
    // Nothing to add to: the first full read has not happened yet.
    if (stored['overview'] == null) return;
    final Object? current = stored[section];
    stored[section] = <String, Object?>{if (current is Map) ...current.cast<String, Object?>(), '$id': detail};
    await _db.writeMeta(MetaKey.portal, jsonEncode(stored));
  }

  /// Reads the portal unless a recent copy is on the phone.
  Future<void> refreshIfStale() async {
    final PortalSnapshot? kept = await read();
    final DateTime? last = kept?.fetchedAt;
    if (last != null && _ctx.clock.now().difference(last) < _freshFor) return;
    await refresh();
  }
}
