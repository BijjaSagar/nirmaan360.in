import '../../../core/db/app_database.dart';

/// BOQ lines on this phone, by project and code. An approval's document names
/// its line by code and carries no BOQ quantity, so a share of BOQ comes from
/// the phone's own copy, and is simply absent when the line is not here.
typedef BoqIndex = Map<(int, String), BoqItemRow>;

Stream<BoqIndex> watchBoqIndex(AppDatabase db) {
  return db
      .select(db.boqItems)
      .watch()
      .map((rows) => <(int, String), BoqItemRow>{for (final BoqItemRow row in rows) (row.projectId, row.code): row});
}
