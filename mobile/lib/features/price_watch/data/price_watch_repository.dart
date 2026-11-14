import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/session/current_user.dart';
import '../../../core/sync/data_context.dart';
import 'price_watch_models.dart';

export 'price_watch_models.dart';

/// The company's recorded prices, read only. What the server last said is kept
/// on the phone so the screens open with no network.
///
/// Nobody without `money.view` (and the `price_watch` plan feature) reaches
/// any of it: the phone does not ask, a copy kept from before the permission
/// went is not shown and is cleared, and the project's budget effect needs
/// `money.overview` on top.
class PriceWatchRepository {
  PriceWatchRepository(this._ctx);

  final DataContext _ctx;

  static const Duration _freshFor = Duration(minutes: 10);

  /// Materials whose whole list of prices is read ahead, a few at a time.
  static const int _kept = 30;
  static const int _atOnce = 5;

  static const String feature = 'price_watch';
  static const String ratesPermission = 'money.view';
  static const String budgetPermission = 'money.overview';

  AppDatabase get _db => _ctx.db;

  /// Whether this person may see recorded prices at all.
  bool get seesPrices => _allows(ratesPermission);

  /// Whether this person may also see what they do to a project's budget.
  bool get seesBudget => _allows(budgetPermission);

  bool _allows(String permission) {
    final CurrentUser? user = _ctx.session.user;
    return user != null && !user.isContractor && user.can(permission) && user.features.contains(feature);
  }

  Stream<PriceBoardCopy?> watchBoard() => _db.watchMeta(MetaKey.priceWatch).map(_decodeBoard);

  Stream<ProjectImpactCopy?> watchProject(int projectId) =>
      _db.watchMeta(MetaKey.priceWatchProject(projectId)).map(_decodeProject);

  Future<PriceBoardCopy?> readBoard() async => _decodeBoard(await _db.readMeta(MetaKey.priceWatch));

  Future<ProjectImpactCopy?> readProject(int projectId) async =>
      _decodeProject(await _db.readMeta(MetaKey.priceWatchProject(projectId)));

  Map<String, dynamic>? _json(String? raw) {
    if (raw == null) return null;
    try {
      return (jsonDecode(raw) as Map).cast<String, dynamic>();
    } on Object {
      // A copy that cannot be read is the same as none: the next read replaces it.
      return null;
    }
  }

  PriceBoardCopy? _decodeBoard(String? raw) {
    if (!seesPrices) return null;
    final Map<String, dynamic>? json = _json(raw);
    return json == null ? null : PriceBoardCopy.fromJson(json);
  }

  ProjectImpactCopy? _decodeProject(String? raw) {
    if (!seesBudget) return null;
    final Map<String, dynamic>? json = _json(raw);
    return json == null ? null : ProjectImpactCopy.fromJson(json);
  }

  bool _isRefusal(ServerFailure e) => e.statusCode == 403 || e.statusCode == 404;

  String get _now => _ctx.clock.now().toUtc().toIso8601String();

  bool _fresh(DateTime? at) => at != null && _ctx.clock.now().difference(at) < _freshFor;

  /// Removes the recorded prices from the phone: all of them, or with
  /// [budgetOnly] just the projects' budget effects. Does nothing, and tells no
  /// listener anything, when there are none.
  Future<void> forget({bool budgetOnly = false}) async {
    final String prefix = budgetOnly ? '${MetaKey.priceWatchPrefix}project.' : MetaKey.priceWatchPrefix;
    final query = _db.select(_db.keyValues)..where((t) => t.key.like('$prefix%'));
    if ((await query.get()).isEmpty) return;
    await (_db.delete(_db.keyValues)..where((t) => t.key.like('$prefix%'))).go();
  }

  /// Reads the board and, with `money.overview` and a [projectId], that
  /// project's budget effect. Without [force], a copy under ten minutes old
  /// is left alone.
  ///
  /// Throws an [ApiFailure] when the server cannot be reached, leaving the
  /// last copy in place. A refusal replaces the copy, because what it held
  /// may no longer be seen.
  Future<void> refreshAll({int? projectId, bool force = true}) async {
    if (!seesPrices) return;
    if (force || !_fresh((await readBoard())?.fetchedAt)) await _refreshBoard();
    if (projectId != null && seesBudget && (force || !_fresh((await readProject(projectId))?.fetchedAt))) {
      await _refreshProject(projectId);
    }
  }

  Future<void> _refreshBoard() async {
    final Map<String, dynamic> body;
    try {
      body = await _ctx.api.priceBoard();
    } on ServerFailure catch (e) {
      if (!_isRefusal(e)) rethrow;
      await _db.writeMeta(MetaKey.priceWatch, jsonEncode(<String, dynamic>{'refused': true, 'fetched_at': _now}));
      return;
    }

    final List<Map<String, dynamic>> rows = <Map<String, dynamic>>[
      if (body['data'] is List)
        for (final Object? row in body['data'] as List)
          if (row is Map && _idOf(row) != null) row.cast<String, dynamic>(),
    ];
    final Object? keptHistories = (_json(await _db.readMeta(MetaKey.priceWatch)) ?? const <String, dynamic>{})['histories'];
    final Map<String, Object?> histories = <String, Object?>{
      if (keptHistories is Map)
        for (final MapEntry<dynamic, dynamic> entry in keptHistories.entries) '${entry.key}': entry.value,
    }..removeWhere((String id, Object? _) => !rows.any((Map<String, dynamic> row) => '${_idOf(row)}' == id));

    // A material's list of prices is read again only when its board row says
    // something was recorded or changed since the list was kept.
    final List<Map<String, dynamic>> toRead = rows
        .where((Map<String, dynamic> row) => _signature(row) != _signature(_mapOrNull(histories['${_idOf(row)}'])))
        .take(_kept)
        .toList(growable: false);
    for (var start = 0; start < toRead.length; start += _atOnce) {
      final List<Map<String, dynamic>?> read = await Future.wait(
        toRead.skip(start).take(_atOnce).map((Map<String, dynamic> row) => _historyUnlessGone(_idOf(row)!)),
      );
      for (final Map<String, dynamic>? history in read) {
        final int? id = history == null ? null : _idOf(history);
        if (history != null && id != null) histories['$id'] = history;
      }
    }

    await _db.writeMeta(
      MetaKey.priceWatch,
      jsonEncode(<String, dynamic>{
        'data': rows,
        'meta': body['meta'],
        'histories': histories,
        'fetched_at': _now,
      }),
    );
  }

  Future<void> _refreshProject(int projectId) async {
    final String key = MetaKey.priceWatchProject(projectId);
    final Map<String, dynamic> body;
    try {
      body = await _ctx.api.priceProject(projectId);
    } on ServerFailure catch (e) {
      if (!_isRefusal(e)) rethrow;
      await _db.writeMeta(key, jsonEncode(<String, dynamic>{'refused': true, 'fetched_at': _now}));
      return;
    }
    await _db.writeMeta(
      key,
      jsonEncode(<String, dynamic>{
        'data': body['data'],
        'summary': body['summary'],
        'meta': body['meta'],
        'fetched_at': _now,
      }),
    );
  }

  Future<Map<String, dynamic>?> _historyUnlessGone(int id) async {
    try {
      final Object? data = (await _ctx.api.priceMaterial(id))['data'];
      return data is Map ? data.cast<String, dynamic>() : null;
    } on ServerFailure catch (e) {
      if (_isRefusal(e)) return null;
      rethrow;
    }
  }

  int? _idOf(Map<dynamic, dynamic> row) {
    final Object? id = row['id'];
    return id is num ? id.toInt() : int.tryParse('${id ?? ''}');
  }

  Map<String, dynamic>? _mapOrNull(Object? value) => value is Map ? value.cast<String, dynamic>() : null;

  /// What a board row says about its prices, without the "out of date" flags
  /// that change with the day.
  String? _signature(Map<String, dynamic>? row) {
    if (row == null) return null;
    Object? price(Object? raw) => raw is Map ? <Object?>[raw['id'], raw['rate'], raw['priced_on']] : null;
    return jsonEncode(<Object?>[row['count'], price(row['current']), price(row['previous'])]);
  }

  /// For the sync engine: keeps the board, and the selected project's budget
  /// effect, ready to open offline. Clears them when the person may no longer
  /// see them.
  Future<void> refreshCurrent({bool force = false}) async {
    if (!seesPrices) {
      if (_ctx.session.user != null) await forget();
      return;
    }
    int? projectId;
    if (!seesBudget) await forget(budgetOnly: true);
    if (seesBudget) {
      projectId = int.tryParse(await _db.readMeta(MetaKey.selectedProject) ?? '');
      // A project taken away is not read again. It would only be refused.
      if (projectId != null) {
        final known = await (_db.select(_db.projects)..where((t) => t.id.equals(projectId!))).getSingleOrNull();
        if (known == null) projectId = null;
      }
    }
    await refreshAll(projectId: projectId, force: force);
  }
}
