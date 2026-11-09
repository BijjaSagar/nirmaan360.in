import 'package:drift/drift.dart';

import '../converters.dart';

/// Masters are owned by the server. Pull upserts them unconditionally.

@DataClassName('ProjectRow')
class Projects extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get code => text()();
  TextColumn get name => text()();
  TextColumn get location => text().nullable()();
  TextColumn get status => text().withDefault(const Constant('active'))();
  TextColumn get startDate => text().nullable()();
  TextColumn get endDate => text().nullable()();
  TextColumn get measurementTolerancePct =>
      text().withDefault(const Constant('5.00'))();
  DateTimeColumn get updatedAt => dateTime().nullable()();
}

@DataClassName('SiteRow')
@TableIndex(name: 'sites_project', columns: {#projectId})
class Sites extends Table {
  IntColumn get id => integer().autoIncrement()();
  IntColumn get projectId => integer()();
  TextColumn get name => text()();
  TextColumn get address => text().nullable()();
  DateTimeColumn get updatedAt => dateTime().nullable()();
}

@DataClassName('BoqItemRow')
@TableIndex(name: 'boq_items_project_sort', columns: {#projectId, #sort})
class BoqItems extends Table {
  IntColumn get id => integer().autoIncrement()();
  IntColumn get projectId => integer()();
  TextColumn get section => text().nullable()();
  TextColumn get code => text()();
  TextColumn get description => text()();
  TextColumn get unit => text()();
  TextColumn get qty => text().map(const QtyConverter())();
  TextColumn get rate => text().map(const MoneyConverter())();
  IntColumn get sort => integer().withDefault(const Constant(0))();

  /// Ledger figures as the server last reported them.
  TextColumn get measuredQty => text().map(const QtyConverter())();
  TextColumn get approvedQty => text().map(const QtyConverter())();
  TextColumn get balanceQty => text().map(const QtyConverter())();
  DateTimeColumn get updatedAt => dateTime().nullable()();
}

@DataClassName('WorkerRow')
@TableIndex(name: 'workers_active_name', columns: {#isActive, #name})
class Workers extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get name => text()();
  TextColumn get phone => text().nullable()();
  TextColumn get trade => text()();
  TextColumn get type => text().withDefault(const Constant('own'))();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  DateTimeColumn get updatedAt => dateTime().nullable()();
}

@DataClassName('WalletRow')
@TableIndex(name: 'wallets_project', columns: {#projectId})
class Wallets extends Table {
  IntColumn get id => integer().autoIncrement()();
  IntColumn get projectId => integer()();
  TextColumn get name => text()();
  IntColumn get userId => integer().nullable()();
  TextColumn get balance => text().map(const MoneyConverter())();
  DateTimeColumn get updatedAt => dateTime().nullable()();
}

@DataClassName('UserRow')
class Users extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get name => text()();
  TextColumn get role => text().nullable()();
}

/// Key-value store: watermark, session user, selected project, locale, lease.
@DataClassName('KeyValueRow')
class KeyValues extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column> get primaryKey => {key};
}
