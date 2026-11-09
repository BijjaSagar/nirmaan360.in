import 'package:uuid/uuid.dart';

const Uuid _uuid = Uuid();

/// A new random id for a record or an operation.
String newUuid() => _uuid.v4();

/// A stable UUID v5 of `<type>:<server_id>`, for server rows with no
/// `client_uuid`. The same input always gives the same id on every device.
String stableUuid(String type, int serverId) =>
    _uuid.v5(Namespace.url.value, '$type:$serverId');

/// Wire names used for [stableUuid].
abstract final class RecordType {
  static const dpr = 'dpr';
  static const dprPhoto = 'dpr_photo';
  static const measurement = 'measurement';
  static const issue = 'issue';
  static const attendance = 'attendance';
  static const pettyCash = 'petty_cash';
}
