import 'dart:convert';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/session/current_user.dart';
import '../../../core/sync/data_context.dart';
import 'site_alerts_models.dart';

export 'site_alerts_models.dart';

/// The site dashboard and open alerts: what the server last said is kept per
/// project, so the screen opens with no network. The one thing it writes to the
/// server is a scan, which needs the network and `alerts.manage`.
class SiteAlertsRepository {
  SiteAlertsRepository(this._ctx);

  final DataContext _ctx;

  static const Duration _freshFor = Duration(minutes: 10);

  AppDatabase get _db => _ctx.db;

  Stream<SiteOverview?> watchCached(int projectId) => _db.watchMeta(MetaKey.siteOverview(projectId)).map(_decode);

  Future<SiteOverview?> readOverview(int projectId) async => _decode(await _db.readMeta(MetaKey.siteOverview(projectId)));

  SiteOverview? _decode(String? raw) {
    if (raw == null) return null;
    final CurrentUser? user = _ctx.session.user;
    final bool seesMoney = user != null && (user.can('money.view') || user.can('money.overview'));
    return SiteOverview.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>()).forViewer(seesMoney: seesMoney);
  }

  /// Null when the server refuses. A role without alerts still has a
  /// dashboard, and no permission that reaches the phone tells the two apart.
  Future<Map<String, dynamic>?> _unlessRefused(Future<Map<String, dynamic>> Function() read) async {
    try {
      return await read();
    } on ServerFailure catch (e) {
      if (e.statusCode == 403 || e.statusCode == 404) return null;
      rethrow;
    }
  }

  /// Throws an [ApiFailure] when the server cannot be reached, leaving the
  /// last copy in place.
  ///
  /// The dashboard is asked for with `progress.view` and the alerts with
  /// `alerts.view`, the permissions the server checks for each. A part a person
  /// may not read is not asked for, and is kept as refused.
  Future<void> refresh(int projectId) async {
    final CurrentUser? user = _ctx.session.user;
    final Map<String, dynamic>? dashboard = (user?.can('progress.view') ?? false)
        ? await _unlessRefused(() => _ctx.api.siteDashboard(projectId))
        : null;
    final Map<String, dynamic>? alerts = (user?.can('alerts.view') ?? false)
        ? await _unlessRefused(() => _ctx.api.siteAlerts(projectId))
        : null;
    await _db.writeMeta(
      MetaKey.siteOverview(projectId),
      jsonEncode(<String, dynamic>{
        'dashboard': dashboard,
        'alerts': alerts == null ? null : <String, dynamic>{'data': alerts['data'], 'total': alerts['total']},
        'fetched_at': _ctx.clock.now().toUtc().toIso8601String(),
      }),
    );
  }

  /// For the sync engine: keeps the selected project ready to use offline.
  Future<void> refreshCurrent({bool force = false}) async {
    final CurrentUser? user = _ctx.session.user;
    if (user == null || !(user.can('progress.view') || user.can('alerts.view')) || !user.features.contains('site_control')) return;
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

  /// Asks the server to scan the project now, then reads the alerts again.
  ///
  /// Needs the network, and `alerts.manage`, which the phone checks so that it
  /// never asks for what the server would refuse. Throws the server's own
  /// refusal as it comes: [RateLimited] when the ten a minute are used,
  /// [ServerFailure] for a 403, [OfflineFailure] with no network.
  Future<AlertScan> scan(int projectId) async {
    final CurrentUser? user = _ctx.session.user;
    if (user == null || !user.can('alerts.manage')) {
      // Nothing reaches the server: the screen never offers this without the permission.
      throw const ServerFailure('A scan needs alerts.manage.', statusCode: 403);
    }
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();
    final Map<String, dynamic> reply = await _ctx.api.scanSiteAlerts(projectId);
    final AlertScan result = AlertScan.fromJson(reply);
    try {
      await refresh(projectId);
    } on ApiFailure {
      // The scan itself worked. The list is read again at the next chance.
    }
    return result;
  }
}
