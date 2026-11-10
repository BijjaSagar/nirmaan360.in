import '../db/app_database.dart';
import 'push_target.dart';

/// The `client_uuid` of the record this phone holds for a server id, or null
/// when it has not been pulled yet. Approvals have no uuid and are not asked.
Future<String?> localUuidFor(AppDatabase db, PushKind kind, int serverId) async {
  switch (kind) {
    case PushKind.report:
      final DprRow? row = await (db.select(db.dprs)..where((t) => t.serverId.equals(serverId))).getSingleOrNull();
      return row?.clientUuid;
    case PushKind.measurement:
      final MeasurementRow? row =
          await (db.select(db.measurements)..where((t) => t.serverId.equals(serverId))).getSingleOrNull();
      return row?.clientUuid;
    case PushKind.issue:
      final IssueRow? row = await (db.select(db.issues)..where((t) => t.serverId.equals(serverId))).getSingleOrNull();
      return row?.clientUuid;
    case PushKind.approval || PushKind.alert:
      return null;
  }
}
