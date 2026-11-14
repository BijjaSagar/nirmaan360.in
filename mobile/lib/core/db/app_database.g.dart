// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'app_database.dart';

// ignore_for_file: type=lint
class $ProjectsTable extends Projects
    with TableInfo<$ProjectsTable, ProjectRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $ProjectsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _codeMeta = const VerificationMeta('code');
  @override
  late final GeneratedColumn<String> code = GeneratedColumn<String>(
    'code',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _locationMeta = const VerificationMeta(
    'location',
  );
  @override
  late final GeneratedColumn<String> location = GeneratedColumn<String>(
    'location',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('active'),
  );
  static const VerificationMeta _startDateMeta = const VerificationMeta(
    'startDate',
  );
  @override
  late final GeneratedColumn<String> startDate = GeneratedColumn<String>(
    'start_date',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _endDateMeta = const VerificationMeta(
    'endDate',
  );
  @override
  late final GeneratedColumn<String> endDate = GeneratedColumn<String>(
    'end_date',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _measurementTolerancePctMeta =
      const VerificationMeta('measurementTolerancePct');
  @override
  late final GeneratedColumn<String> measurementTolerancePct =
      GeneratedColumn<String>(
        'measurement_tolerance_pct',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
        defaultValue: const Constant('5.00'),
      );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _imageUrlMeta = const VerificationMeta(
    'imageUrl',
  );
  @override
  late final GeneratedColumn<String> imageUrl = GeneratedColumn<String>(
    'image_url',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    code,
    name,
    location,
    status,
    startDate,
    endDate,
    measurementTolerancePct,
    updatedAt,
    imageUrl,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'projects';
  @override
  VerificationContext validateIntegrity(
    Insertable<ProjectRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('code')) {
      context.handle(
        _codeMeta,
        code.isAcceptableOrUnknown(data['code']!, _codeMeta),
      );
    } else if (isInserting) {
      context.missing(_codeMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('location')) {
      context.handle(
        _locationMeta,
        location.isAcceptableOrUnknown(data['location']!, _locationMeta),
      );
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    if (data.containsKey('start_date')) {
      context.handle(
        _startDateMeta,
        startDate.isAcceptableOrUnknown(data['start_date']!, _startDateMeta),
      );
    }
    if (data.containsKey('end_date')) {
      context.handle(
        _endDateMeta,
        endDate.isAcceptableOrUnknown(data['end_date']!, _endDateMeta),
      );
    }
    if (data.containsKey('measurement_tolerance_pct')) {
      context.handle(
        _measurementTolerancePctMeta,
        measurementTolerancePct.isAcceptableOrUnknown(
          data['measurement_tolerance_pct']!,
          _measurementTolerancePctMeta,
        ),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    if (data.containsKey('image_url')) {
      context.handle(
        _imageUrlMeta,
        imageUrl.isAcceptableOrUnknown(data['image_url']!, _imageUrlMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  ProjectRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return ProjectRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      code: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}code'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      location: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}location'],
      ),
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      startDate: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}start_date'],
      ),
      endDate: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}end_date'],
      ),
      measurementTolerancePct: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}measurement_tolerance_pct'],
      )!,
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
      imageUrl: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}image_url'],
      ),
    );
  }

  @override
  $ProjectsTable createAlias(String alias) {
    return $ProjectsTable(attachedDatabase, alias);
  }
}

class ProjectRow extends DataClass implements Insertable<ProjectRow> {
  final int id;
  final String code;
  final String name;
  final String? location;
  final String status;
  final String? startDate;
  final String? endDate;
  final String measurementTolerancePct;
  final DateTime? updatedAt;

  /// Where the project's photo is. Needs the sign-in token. Null when none.
  final String? imageUrl;
  const ProjectRow({
    required this.id,
    required this.code,
    required this.name,
    this.location,
    required this.status,
    this.startDate,
    this.endDate,
    required this.measurementTolerancePct,
    this.updatedAt,
    this.imageUrl,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['code'] = Variable<String>(code);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || location != null) {
      map['location'] = Variable<String>(location);
    }
    map['status'] = Variable<String>(status);
    if (!nullToAbsent || startDate != null) {
      map['start_date'] = Variable<String>(startDate);
    }
    if (!nullToAbsent || endDate != null) {
      map['end_date'] = Variable<String>(endDate);
    }
    map['measurement_tolerance_pct'] = Variable<String>(
      measurementTolerancePct,
    );
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    if (!nullToAbsent || imageUrl != null) {
      map['image_url'] = Variable<String>(imageUrl);
    }
    return map;
  }

  ProjectsCompanion toCompanion(bool nullToAbsent) {
    return ProjectsCompanion(
      id: Value(id),
      code: Value(code),
      name: Value(name),
      location: location == null && nullToAbsent
          ? const Value.absent()
          : Value(location),
      status: Value(status),
      startDate: startDate == null && nullToAbsent
          ? const Value.absent()
          : Value(startDate),
      endDate: endDate == null && nullToAbsent
          ? const Value.absent()
          : Value(endDate),
      measurementTolerancePct: Value(measurementTolerancePct),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
      imageUrl: imageUrl == null && nullToAbsent
          ? const Value.absent()
          : Value(imageUrl),
    );
  }

  factory ProjectRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return ProjectRow(
      id: serializer.fromJson<int>(json['id']),
      code: serializer.fromJson<String>(json['code']),
      name: serializer.fromJson<String>(json['name']),
      location: serializer.fromJson<String?>(json['location']),
      status: serializer.fromJson<String>(json['status']),
      startDate: serializer.fromJson<String?>(json['startDate']),
      endDate: serializer.fromJson<String?>(json['endDate']),
      measurementTolerancePct: serializer.fromJson<String>(
        json['measurementTolerancePct'],
      ),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
      imageUrl: serializer.fromJson<String?>(json['imageUrl']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'code': serializer.toJson<String>(code),
      'name': serializer.toJson<String>(name),
      'location': serializer.toJson<String?>(location),
      'status': serializer.toJson<String>(status),
      'startDate': serializer.toJson<String?>(startDate),
      'endDate': serializer.toJson<String?>(endDate),
      'measurementTolerancePct': serializer.toJson<String>(
        measurementTolerancePct,
      ),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
      'imageUrl': serializer.toJson<String?>(imageUrl),
    };
  }

  ProjectRow copyWith({
    int? id,
    String? code,
    String? name,
    Value<String?> location = const Value.absent(),
    String? status,
    Value<String?> startDate = const Value.absent(),
    Value<String?> endDate = const Value.absent(),
    String? measurementTolerancePct,
    Value<DateTime?> updatedAt = const Value.absent(),
    Value<String?> imageUrl = const Value.absent(),
  }) => ProjectRow(
    id: id ?? this.id,
    code: code ?? this.code,
    name: name ?? this.name,
    location: location.present ? location.value : this.location,
    status: status ?? this.status,
    startDate: startDate.present ? startDate.value : this.startDate,
    endDate: endDate.present ? endDate.value : this.endDate,
    measurementTolerancePct:
        measurementTolerancePct ?? this.measurementTolerancePct,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
    imageUrl: imageUrl.present ? imageUrl.value : this.imageUrl,
  );
  ProjectRow copyWithCompanion(ProjectsCompanion data) {
    return ProjectRow(
      id: data.id.present ? data.id.value : this.id,
      code: data.code.present ? data.code.value : this.code,
      name: data.name.present ? data.name.value : this.name,
      location: data.location.present ? data.location.value : this.location,
      status: data.status.present ? data.status.value : this.status,
      startDate: data.startDate.present ? data.startDate.value : this.startDate,
      endDate: data.endDate.present ? data.endDate.value : this.endDate,
      measurementTolerancePct: data.measurementTolerancePct.present
          ? data.measurementTolerancePct.value
          : this.measurementTolerancePct,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
      imageUrl: data.imageUrl.present ? data.imageUrl.value : this.imageUrl,
    );
  }

  @override
  String toString() {
    return (StringBuffer('ProjectRow(')
          ..write('id: $id, ')
          ..write('code: $code, ')
          ..write('name: $name, ')
          ..write('location: $location, ')
          ..write('status: $status, ')
          ..write('startDate: $startDate, ')
          ..write('endDate: $endDate, ')
          ..write('measurementTolerancePct: $measurementTolerancePct, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('imageUrl: $imageUrl')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    code,
    name,
    location,
    status,
    startDate,
    endDate,
    measurementTolerancePct,
    updatedAt,
    imageUrl,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is ProjectRow &&
          other.id == this.id &&
          other.code == this.code &&
          other.name == this.name &&
          other.location == this.location &&
          other.status == this.status &&
          other.startDate == this.startDate &&
          other.endDate == this.endDate &&
          other.measurementTolerancePct == this.measurementTolerancePct &&
          other.updatedAt == this.updatedAt &&
          other.imageUrl == this.imageUrl);
}

class ProjectsCompanion extends UpdateCompanion<ProjectRow> {
  final Value<int> id;
  final Value<String> code;
  final Value<String> name;
  final Value<String?> location;
  final Value<String> status;
  final Value<String?> startDate;
  final Value<String?> endDate;
  final Value<String> measurementTolerancePct;
  final Value<DateTime?> updatedAt;
  final Value<String?> imageUrl;
  const ProjectsCompanion({
    this.id = const Value.absent(),
    this.code = const Value.absent(),
    this.name = const Value.absent(),
    this.location = const Value.absent(),
    this.status = const Value.absent(),
    this.startDate = const Value.absent(),
    this.endDate = const Value.absent(),
    this.measurementTolerancePct = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.imageUrl = const Value.absent(),
  });
  ProjectsCompanion.insert({
    this.id = const Value.absent(),
    required String code,
    required String name,
    this.location = const Value.absent(),
    this.status = const Value.absent(),
    this.startDate = const Value.absent(),
    this.endDate = const Value.absent(),
    this.measurementTolerancePct = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.imageUrl = const Value.absent(),
  }) : code = Value(code),
       name = Value(name);
  static Insertable<ProjectRow> custom({
    Expression<int>? id,
    Expression<String>? code,
    Expression<String>? name,
    Expression<String>? location,
    Expression<String>? status,
    Expression<String>? startDate,
    Expression<String>? endDate,
    Expression<String>? measurementTolerancePct,
    Expression<DateTime>? updatedAt,
    Expression<String>? imageUrl,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (code != null) 'code': code,
      if (name != null) 'name': name,
      if (location != null) 'location': location,
      if (status != null) 'status': status,
      if (startDate != null) 'start_date': startDate,
      if (endDate != null) 'end_date': endDate,
      if (measurementTolerancePct != null)
        'measurement_tolerance_pct': measurementTolerancePct,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (imageUrl != null) 'image_url': imageUrl,
    });
  }

  ProjectsCompanion copyWith({
    Value<int>? id,
    Value<String>? code,
    Value<String>? name,
    Value<String?>? location,
    Value<String>? status,
    Value<String?>? startDate,
    Value<String?>? endDate,
    Value<String>? measurementTolerancePct,
    Value<DateTime?>? updatedAt,
    Value<String?>? imageUrl,
  }) {
    return ProjectsCompanion(
      id: id ?? this.id,
      code: code ?? this.code,
      name: name ?? this.name,
      location: location ?? this.location,
      status: status ?? this.status,
      startDate: startDate ?? this.startDate,
      endDate: endDate ?? this.endDate,
      measurementTolerancePct:
          measurementTolerancePct ?? this.measurementTolerancePct,
      updatedAt: updatedAt ?? this.updatedAt,
      imageUrl: imageUrl ?? this.imageUrl,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (code.present) {
      map['code'] = Variable<String>(code.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (location.present) {
      map['location'] = Variable<String>(location.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (startDate.present) {
      map['start_date'] = Variable<String>(startDate.value);
    }
    if (endDate.present) {
      map['end_date'] = Variable<String>(endDate.value);
    }
    if (measurementTolerancePct.present) {
      map['measurement_tolerance_pct'] = Variable<String>(
        measurementTolerancePct.value,
      );
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (imageUrl.present) {
      map['image_url'] = Variable<String>(imageUrl.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('ProjectsCompanion(')
          ..write('id: $id, ')
          ..write('code: $code, ')
          ..write('name: $name, ')
          ..write('location: $location, ')
          ..write('status: $status, ')
          ..write('startDate: $startDate, ')
          ..write('endDate: $endDate, ')
          ..write('measurementTolerancePct: $measurementTolerancePct, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('imageUrl: $imageUrl')
          ..write(')'))
        .toString();
  }
}

class $SitesTable extends Sites with TableInfo<$SitesTable, SiteRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $SitesTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _addressMeta = const VerificationMeta(
    'address',
  );
  @override
  late final GeneratedColumn<String> address = GeneratedColumn<String>(
    'address',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    projectId,
    name,
    address,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'sites';
  @override
  VerificationContext validateIntegrity(
    Insertable<SiteRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('address')) {
      context.handle(
        _addressMeta,
        address.isAcceptableOrUnknown(data['address']!, _addressMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  SiteRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return SiteRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      address: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}address'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $SitesTable createAlias(String alias) {
    return $SitesTable(attachedDatabase, alias);
  }
}

class SiteRow extends DataClass implements Insertable<SiteRow> {
  final int id;
  final int projectId;
  final String name;
  final String? address;
  final DateTime? updatedAt;
  const SiteRow({
    required this.id,
    required this.projectId,
    required this.name,
    this.address,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['project_id'] = Variable<int>(projectId);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || address != null) {
      map['address'] = Variable<String>(address);
    }
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  SitesCompanion toCompanion(bool nullToAbsent) {
    return SitesCompanion(
      id: Value(id),
      projectId: Value(projectId),
      name: Value(name),
      address: address == null && nullToAbsent
          ? const Value.absent()
          : Value(address),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory SiteRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return SiteRow(
      id: serializer.fromJson<int>(json['id']),
      projectId: serializer.fromJson<int>(json['projectId']),
      name: serializer.fromJson<String>(json['name']),
      address: serializer.fromJson<String?>(json['address']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'projectId': serializer.toJson<int>(projectId),
      'name': serializer.toJson<String>(name),
      'address': serializer.toJson<String?>(address),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  SiteRow copyWith({
    int? id,
    int? projectId,
    String? name,
    Value<String?> address = const Value.absent(),
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => SiteRow(
    id: id ?? this.id,
    projectId: projectId ?? this.projectId,
    name: name ?? this.name,
    address: address.present ? address.value : this.address,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  SiteRow copyWithCompanion(SitesCompanion data) {
    return SiteRow(
      id: data.id.present ? data.id.value : this.id,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      name: data.name.present ? data.name.value : this.name,
      address: data.address.present ? data.address.value : this.address,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('SiteRow(')
          ..write('id: $id, ')
          ..write('projectId: $projectId, ')
          ..write('name: $name, ')
          ..write('address: $address, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(id, projectId, name, address, updatedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is SiteRow &&
          other.id == this.id &&
          other.projectId == this.projectId &&
          other.name == this.name &&
          other.address == this.address &&
          other.updatedAt == this.updatedAt);
}

class SitesCompanion extends UpdateCompanion<SiteRow> {
  final Value<int> id;
  final Value<int> projectId;
  final Value<String> name;
  final Value<String?> address;
  final Value<DateTime?> updatedAt;
  const SitesCompanion({
    this.id = const Value.absent(),
    this.projectId = const Value.absent(),
    this.name = const Value.absent(),
    this.address = const Value.absent(),
    this.updatedAt = const Value.absent(),
  });
  SitesCompanion.insert({
    this.id = const Value.absent(),
    required int projectId,
    required String name,
    this.address = const Value.absent(),
    this.updatedAt = const Value.absent(),
  }) : projectId = Value(projectId),
       name = Value(name);
  static Insertable<SiteRow> custom({
    Expression<int>? id,
    Expression<int>? projectId,
    Expression<String>? name,
    Expression<String>? address,
    Expression<DateTime>? updatedAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (projectId != null) 'project_id': projectId,
      if (name != null) 'name': name,
      if (address != null) 'address': address,
      if (updatedAt != null) 'updated_at': updatedAt,
    });
  }

  SitesCompanion copyWith({
    Value<int>? id,
    Value<int>? projectId,
    Value<String>? name,
    Value<String?>? address,
    Value<DateTime?>? updatedAt,
  }) {
    return SitesCompanion(
      id: id ?? this.id,
      projectId: projectId ?? this.projectId,
      name: name ?? this.name,
      address: address ?? this.address,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (address.present) {
      map['address'] = Variable<String>(address.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('SitesCompanion(')
          ..write('id: $id, ')
          ..write('projectId: $projectId, ')
          ..write('name: $name, ')
          ..write('address: $address, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }
}

class $BoqItemsTable extends BoqItems
    with TableInfo<$BoqItemsTable, BoqItemRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $BoqItemsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _sectionMeta = const VerificationMeta(
    'section',
  );
  @override
  late final GeneratedColumn<String> section = GeneratedColumn<String>(
    'section',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _codeMeta = const VerificationMeta('code');
  @override
  late final GeneratedColumn<String> code = GeneratedColumn<String>(
    'code',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _descriptionMeta = const VerificationMeta(
    'description',
  );
  @override
  late final GeneratedColumn<String> description = GeneratedColumn<String>(
    'description',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _unitMeta = const VerificationMeta('unit');
  @override
  late final GeneratedColumn<String> unit = GeneratedColumn<String>(
    'unit',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  late final GeneratedColumnWithTypeConverter<Qty, String> qty =
      GeneratedColumn<String>(
        'qty',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Qty>($BoqItemsTable.$converterqty);
  @override
  late final GeneratedColumnWithTypeConverter<Money, String> rate =
      GeneratedColumn<String>(
        'rate',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Money>($BoqItemsTable.$converterrate);
  static const VerificationMeta _sortMeta = const VerificationMeta('sort');
  @override
  late final GeneratedColumn<int> sort = GeneratedColumn<int>(
    'sort',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  @override
  late final GeneratedColumnWithTypeConverter<Qty, String> measuredQty =
      GeneratedColumn<String>(
        'measured_qty',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Qty>($BoqItemsTable.$convertermeasuredQty);
  @override
  late final GeneratedColumnWithTypeConverter<Qty, String> approvedQty =
      GeneratedColumn<String>(
        'approved_qty',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Qty>($BoqItemsTable.$converterapprovedQty);
  @override
  late final GeneratedColumnWithTypeConverter<Qty, String> balanceQty =
      GeneratedColumn<String>(
        'balance_qty',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Qty>($BoqItemsTable.$converterbalanceQty);
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    projectId,
    section,
    code,
    description,
    unit,
    qty,
    rate,
    sort,
    measuredQty,
    approvedQty,
    balanceQty,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'boq_items';
  @override
  VerificationContext validateIntegrity(
    Insertable<BoqItemRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('section')) {
      context.handle(
        _sectionMeta,
        section.isAcceptableOrUnknown(data['section']!, _sectionMeta),
      );
    }
    if (data.containsKey('code')) {
      context.handle(
        _codeMeta,
        code.isAcceptableOrUnknown(data['code']!, _codeMeta),
      );
    } else if (isInserting) {
      context.missing(_codeMeta);
    }
    if (data.containsKey('description')) {
      context.handle(
        _descriptionMeta,
        description.isAcceptableOrUnknown(
          data['description']!,
          _descriptionMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_descriptionMeta);
    }
    if (data.containsKey('unit')) {
      context.handle(
        _unitMeta,
        unit.isAcceptableOrUnknown(data['unit']!, _unitMeta),
      );
    } else if (isInserting) {
      context.missing(_unitMeta);
    }
    if (data.containsKey('sort')) {
      context.handle(
        _sortMeta,
        sort.isAcceptableOrUnknown(data['sort']!, _sortMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  BoqItemRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return BoqItemRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      section: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}section'],
      ),
      code: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}code'],
      )!,
      description: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}description'],
      )!,
      unit: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}unit'],
      )!,
      qty: $BoqItemsTable.$converterqty.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}qty'],
        )!,
      ),
      rate: $BoqItemsTable.$converterrate.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}rate'],
        )!,
      ),
      sort: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}sort'],
      )!,
      measuredQty: $BoqItemsTable.$convertermeasuredQty.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}measured_qty'],
        )!,
      ),
      approvedQty: $BoqItemsTable.$converterapprovedQty.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}approved_qty'],
        )!,
      ),
      balanceQty: $BoqItemsTable.$converterbalanceQty.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}balance_qty'],
        )!,
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $BoqItemsTable createAlias(String alias) {
    return $BoqItemsTable(attachedDatabase, alias);
  }

  static TypeConverter<Qty, String> $converterqty = const QtyConverter();
  static TypeConverter<Money, String> $converterrate = const MoneyConverter();
  static TypeConverter<Qty, String> $convertermeasuredQty =
      const QtyConverter();
  static TypeConverter<Qty, String> $converterapprovedQty =
      const QtyConverter();
  static TypeConverter<Qty, String> $converterbalanceQty = const QtyConverter();
}

class BoqItemRow extends DataClass implements Insertable<BoqItemRow> {
  final int id;
  final int projectId;
  final String? section;
  final String code;
  final String description;
  final String unit;
  final Qty qty;
  final Money rate;
  final int sort;

  /// Ledger figures as the server last reported them.
  final Qty measuredQty;
  final Qty approvedQty;
  final Qty balanceQty;
  final DateTime? updatedAt;
  const BoqItemRow({
    required this.id,
    required this.projectId,
    this.section,
    required this.code,
    required this.description,
    required this.unit,
    required this.qty,
    required this.rate,
    required this.sort,
    required this.measuredQty,
    required this.approvedQty,
    required this.balanceQty,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['project_id'] = Variable<int>(projectId);
    if (!nullToAbsent || section != null) {
      map['section'] = Variable<String>(section);
    }
    map['code'] = Variable<String>(code);
    map['description'] = Variable<String>(description);
    map['unit'] = Variable<String>(unit);
    {
      map['qty'] = Variable<String>($BoqItemsTable.$converterqty.toSql(qty));
    }
    {
      map['rate'] = Variable<String>($BoqItemsTable.$converterrate.toSql(rate));
    }
    map['sort'] = Variable<int>(sort);
    {
      map['measured_qty'] = Variable<String>(
        $BoqItemsTable.$convertermeasuredQty.toSql(measuredQty),
      );
    }
    {
      map['approved_qty'] = Variable<String>(
        $BoqItemsTable.$converterapprovedQty.toSql(approvedQty),
      );
    }
    {
      map['balance_qty'] = Variable<String>(
        $BoqItemsTable.$converterbalanceQty.toSql(balanceQty),
      );
    }
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  BoqItemsCompanion toCompanion(bool nullToAbsent) {
    return BoqItemsCompanion(
      id: Value(id),
      projectId: Value(projectId),
      section: section == null && nullToAbsent
          ? const Value.absent()
          : Value(section),
      code: Value(code),
      description: Value(description),
      unit: Value(unit),
      qty: Value(qty),
      rate: Value(rate),
      sort: Value(sort),
      measuredQty: Value(measuredQty),
      approvedQty: Value(approvedQty),
      balanceQty: Value(balanceQty),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory BoqItemRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return BoqItemRow(
      id: serializer.fromJson<int>(json['id']),
      projectId: serializer.fromJson<int>(json['projectId']),
      section: serializer.fromJson<String?>(json['section']),
      code: serializer.fromJson<String>(json['code']),
      description: serializer.fromJson<String>(json['description']),
      unit: serializer.fromJson<String>(json['unit']),
      qty: serializer.fromJson<Qty>(json['qty']),
      rate: serializer.fromJson<Money>(json['rate']),
      sort: serializer.fromJson<int>(json['sort']),
      measuredQty: serializer.fromJson<Qty>(json['measuredQty']),
      approvedQty: serializer.fromJson<Qty>(json['approvedQty']),
      balanceQty: serializer.fromJson<Qty>(json['balanceQty']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'projectId': serializer.toJson<int>(projectId),
      'section': serializer.toJson<String?>(section),
      'code': serializer.toJson<String>(code),
      'description': serializer.toJson<String>(description),
      'unit': serializer.toJson<String>(unit),
      'qty': serializer.toJson<Qty>(qty),
      'rate': serializer.toJson<Money>(rate),
      'sort': serializer.toJson<int>(sort),
      'measuredQty': serializer.toJson<Qty>(measuredQty),
      'approvedQty': serializer.toJson<Qty>(approvedQty),
      'balanceQty': serializer.toJson<Qty>(balanceQty),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  BoqItemRow copyWith({
    int? id,
    int? projectId,
    Value<String?> section = const Value.absent(),
    String? code,
    String? description,
    String? unit,
    Qty? qty,
    Money? rate,
    int? sort,
    Qty? measuredQty,
    Qty? approvedQty,
    Qty? balanceQty,
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => BoqItemRow(
    id: id ?? this.id,
    projectId: projectId ?? this.projectId,
    section: section.present ? section.value : this.section,
    code: code ?? this.code,
    description: description ?? this.description,
    unit: unit ?? this.unit,
    qty: qty ?? this.qty,
    rate: rate ?? this.rate,
    sort: sort ?? this.sort,
    measuredQty: measuredQty ?? this.measuredQty,
    approvedQty: approvedQty ?? this.approvedQty,
    balanceQty: balanceQty ?? this.balanceQty,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  BoqItemRow copyWithCompanion(BoqItemsCompanion data) {
    return BoqItemRow(
      id: data.id.present ? data.id.value : this.id,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      section: data.section.present ? data.section.value : this.section,
      code: data.code.present ? data.code.value : this.code,
      description: data.description.present
          ? data.description.value
          : this.description,
      unit: data.unit.present ? data.unit.value : this.unit,
      qty: data.qty.present ? data.qty.value : this.qty,
      rate: data.rate.present ? data.rate.value : this.rate,
      sort: data.sort.present ? data.sort.value : this.sort,
      measuredQty: data.measuredQty.present
          ? data.measuredQty.value
          : this.measuredQty,
      approvedQty: data.approvedQty.present
          ? data.approvedQty.value
          : this.approvedQty,
      balanceQty: data.balanceQty.present
          ? data.balanceQty.value
          : this.balanceQty,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('BoqItemRow(')
          ..write('id: $id, ')
          ..write('projectId: $projectId, ')
          ..write('section: $section, ')
          ..write('code: $code, ')
          ..write('description: $description, ')
          ..write('unit: $unit, ')
          ..write('qty: $qty, ')
          ..write('rate: $rate, ')
          ..write('sort: $sort, ')
          ..write('measuredQty: $measuredQty, ')
          ..write('approvedQty: $approvedQty, ')
          ..write('balanceQty: $balanceQty, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    projectId,
    section,
    code,
    description,
    unit,
    qty,
    rate,
    sort,
    measuredQty,
    approvedQty,
    balanceQty,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is BoqItemRow &&
          other.id == this.id &&
          other.projectId == this.projectId &&
          other.section == this.section &&
          other.code == this.code &&
          other.description == this.description &&
          other.unit == this.unit &&
          other.qty == this.qty &&
          other.rate == this.rate &&
          other.sort == this.sort &&
          other.measuredQty == this.measuredQty &&
          other.approvedQty == this.approvedQty &&
          other.balanceQty == this.balanceQty &&
          other.updatedAt == this.updatedAt);
}

class BoqItemsCompanion extends UpdateCompanion<BoqItemRow> {
  final Value<int> id;
  final Value<int> projectId;
  final Value<String?> section;
  final Value<String> code;
  final Value<String> description;
  final Value<String> unit;
  final Value<Qty> qty;
  final Value<Money> rate;
  final Value<int> sort;
  final Value<Qty> measuredQty;
  final Value<Qty> approvedQty;
  final Value<Qty> balanceQty;
  final Value<DateTime?> updatedAt;
  const BoqItemsCompanion({
    this.id = const Value.absent(),
    this.projectId = const Value.absent(),
    this.section = const Value.absent(),
    this.code = const Value.absent(),
    this.description = const Value.absent(),
    this.unit = const Value.absent(),
    this.qty = const Value.absent(),
    this.rate = const Value.absent(),
    this.sort = const Value.absent(),
    this.measuredQty = const Value.absent(),
    this.approvedQty = const Value.absent(),
    this.balanceQty = const Value.absent(),
    this.updatedAt = const Value.absent(),
  });
  BoqItemsCompanion.insert({
    this.id = const Value.absent(),
    required int projectId,
    this.section = const Value.absent(),
    required String code,
    required String description,
    required String unit,
    required Qty qty,
    required Money rate,
    this.sort = const Value.absent(),
    required Qty measuredQty,
    required Qty approvedQty,
    required Qty balanceQty,
    this.updatedAt = const Value.absent(),
  }) : projectId = Value(projectId),
       code = Value(code),
       description = Value(description),
       unit = Value(unit),
       qty = Value(qty),
       rate = Value(rate),
       measuredQty = Value(measuredQty),
       approvedQty = Value(approvedQty),
       balanceQty = Value(balanceQty);
  static Insertable<BoqItemRow> custom({
    Expression<int>? id,
    Expression<int>? projectId,
    Expression<String>? section,
    Expression<String>? code,
    Expression<String>? description,
    Expression<String>? unit,
    Expression<String>? qty,
    Expression<String>? rate,
    Expression<int>? sort,
    Expression<String>? measuredQty,
    Expression<String>? approvedQty,
    Expression<String>? balanceQty,
    Expression<DateTime>? updatedAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (projectId != null) 'project_id': projectId,
      if (section != null) 'section': section,
      if (code != null) 'code': code,
      if (description != null) 'description': description,
      if (unit != null) 'unit': unit,
      if (qty != null) 'qty': qty,
      if (rate != null) 'rate': rate,
      if (sort != null) 'sort': sort,
      if (measuredQty != null) 'measured_qty': measuredQty,
      if (approvedQty != null) 'approved_qty': approvedQty,
      if (balanceQty != null) 'balance_qty': balanceQty,
      if (updatedAt != null) 'updated_at': updatedAt,
    });
  }

  BoqItemsCompanion copyWith({
    Value<int>? id,
    Value<int>? projectId,
    Value<String?>? section,
    Value<String>? code,
    Value<String>? description,
    Value<String>? unit,
    Value<Qty>? qty,
    Value<Money>? rate,
    Value<int>? sort,
    Value<Qty>? measuredQty,
    Value<Qty>? approvedQty,
    Value<Qty>? balanceQty,
    Value<DateTime?>? updatedAt,
  }) {
    return BoqItemsCompanion(
      id: id ?? this.id,
      projectId: projectId ?? this.projectId,
      section: section ?? this.section,
      code: code ?? this.code,
      description: description ?? this.description,
      unit: unit ?? this.unit,
      qty: qty ?? this.qty,
      rate: rate ?? this.rate,
      sort: sort ?? this.sort,
      measuredQty: measuredQty ?? this.measuredQty,
      approvedQty: approvedQty ?? this.approvedQty,
      balanceQty: balanceQty ?? this.balanceQty,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (section.present) {
      map['section'] = Variable<String>(section.value);
    }
    if (code.present) {
      map['code'] = Variable<String>(code.value);
    }
    if (description.present) {
      map['description'] = Variable<String>(description.value);
    }
    if (unit.present) {
      map['unit'] = Variable<String>(unit.value);
    }
    if (qty.present) {
      map['qty'] = Variable<String>(
        $BoqItemsTable.$converterqty.toSql(qty.value),
      );
    }
    if (rate.present) {
      map['rate'] = Variable<String>(
        $BoqItemsTable.$converterrate.toSql(rate.value),
      );
    }
    if (sort.present) {
      map['sort'] = Variable<int>(sort.value);
    }
    if (measuredQty.present) {
      map['measured_qty'] = Variable<String>(
        $BoqItemsTable.$convertermeasuredQty.toSql(measuredQty.value),
      );
    }
    if (approvedQty.present) {
      map['approved_qty'] = Variable<String>(
        $BoqItemsTable.$converterapprovedQty.toSql(approvedQty.value),
      );
    }
    if (balanceQty.present) {
      map['balance_qty'] = Variable<String>(
        $BoqItemsTable.$converterbalanceQty.toSql(balanceQty.value),
      );
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('BoqItemsCompanion(')
          ..write('id: $id, ')
          ..write('projectId: $projectId, ')
          ..write('section: $section, ')
          ..write('code: $code, ')
          ..write('description: $description, ')
          ..write('unit: $unit, ')
          ..write('qty: $qty, ')
          ..write('rate: $rate, ')
          ..write('sort: $sort, ')
          ..write('measuredQty: $measuredQty, ')
          ..write('approvedQty: $approvedQty, ')
          ..write('balanceQty: $balanceQty, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }
}

class $WorkersTable extends Workers with TableInfo<$WorkersTable, WorkerRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $WorkersTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _phoneMeta = const VerificationMeta('phone');
  @override
  late final GeneratedColumn<String> phone = GeneratedColumn<String>(
    'phone',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _tradeMeta = const VerificationMeta('trade');
  @override
  late final GeneratedColumn<String> trade = GeneratedColumn<String>(
    'trade',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _typeMeta = const VerificationMeta('type');
  @override
  late final GeneratedColumn<String> type = GeneratedColumn<String>(
    'type',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('own'),
  );
  static const VerificationMeta _isActiveMeta = const VerificationMeta(
    'isActive',
  );
  @override
  late final GeneratedColumn<bool> isActive = GeneratedColumn<bool>(
    'is_active',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("is_active" IN (0, 1))',
    ),
    defaultValue: const Constant(true),
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    name,
    phone,
    trade,
    type,
    isActive,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'workers';
  @override
  VerificationContext validateIntegrity(
    Insertable<WorkerRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('phone')) {
      context.handle(
        _phoneMeta,
        phone.isAcceptableOrUnknown(data['phone']!, _phoneMeta),
      );
    }
    if (data.containsKey('trade')) {
      context.handle(
        _tradeMeta,
        trade.isAcceptableOrUnknown(data['trade']!, _tradeMeta),
      );
    } else if (isInserting) {
      context.missing(_tradeMeta);
    }
    if (data.containsKey('type')) {
      context.handle(
        _typeMeta,
        type.isAcceptableOrUnknown(data['type']!, _typeMeta),
      );
    }
    if (data.containsKey('is_active')) {
      context.handle(
        _isActiveMeta,
        isActive.isAcceptableOrUnknown(data['is_active']!, _isActiveMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  WorkerRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return WorkerRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      phone: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}phone'],
      ),
      trade: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}trade'],
      )!,
      type: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}type'],
      )!,
      isActive: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}is_active'],
      )!,
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $WorkersTable createAlias(String alias) {
    return $WorkersTable(attachedDatabase, alias);
  }
}

class WorkerRow extends DataClass implements Insertable<WorkerRow> {
  final int id;
  final String name;
  final String? phone;
  final String trade;
  final String type;
  final bool isActive;
  final DateTime? updatedAt;
  const WorkerRow({
    required this.id,
    required this.name,
    this.phone,
    required this.trade,
    required this.type,
    required this.isActive,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || phone != null) {
      map['phone'] = Variable<String>(phone);
    }
    map['trade'] = Variable<String>(trade);
    map['type'] = Variable<String>(type);
    map['is_active'] = Variable<bool>(isActive);
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  WorkersCompanion toCompanion(bool nullToAbsent) {
    return WorkersCompanion(
      id: Value(id),
      name: Value(name),
      phone: phone == null && nullToAbsent
          ? const Value.absent()
          : Value(phone),
      trade: Value(trade),
      type: Value(type),
      isActive: Value(isActive),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory WorkerRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return WorkerRow(
      id: serializer.fromJson<int>(json['id']),
      name: serializer.fromJson<String>(json['name']),
      phone: serializer.fromJson<String?>(json['phone']),
      trade: serializer.fromJson<String>(json['trade']),
      type: serializer.fromJson<String>(json['type']),
      isActive: serializer.fromJson<bool>(json['isActive']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'name': serializer.toJson<String>(name),
      'phone': serializer.toJson<String?>(phone),
      'trade': serializer.toJson<String>(trade),
      'type': serializer.toJson<String>(type),
      'isActive': serializer.toJson<bool>(isActive),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  WorkerRow copyWith({
    int? id,
    String? name,
    Value<String?> phone = const Value.absent(),
    String? trade,
    String? type,
    bool? isActive,
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => WorkerRow(
    id: id ?? this.id,
    name: name ?? this.name,
    phone: phone.present ? phone.value : this.phone,
    trade: trade ?? this.trade,
    type: type ?? this.type,
    isActive: isActive ?? this.isActive,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  WorkerRow copyWithCompanion(WorkersCompanion data) {
    return WorkerRow(
      id: data.id.present ? data.id.value : this.id,
      name: data.name.present ? data.name.value : this.name,
      phone: data.phone.present ? data.phone.value : this.phone,
      trade: data.trade.present ? data.trade.value : this.trade,
      type: data.type.present ? data.type.value : this.type,
      isActive: data.isActive.present ? data.isActive.value : this.isActive,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('WorkerRow(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('phone: $phone, ')
          ..write('trade: $trade, ')
          ..write('type: $type, ')
          ..write('isActive: $isActive, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(id, name, phone, trade, type, isActive, updatedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is WorkerRow &&
          other.id == this.id &&
          other.name == this.name &&
          other.phone == this.phone &&
          other.trade == this.trade &&
          other.type == this.type &&
          other.isActive == this.isActive &&
          other.updatedAt == this.updatedAt);
}

class WorkersCompanion extends UpdateCompanion<WorkerRow> {
  final Value<int> id;
  final Value<String> name;
  final Value<String?> phone;
  final Value<String> trade;
  final Value<String> type;
  final Value<bool> isActive;
  final Value<DateTime?> updatedAt;
  const WorkersCompanion({
    this.id = const Value.absent(),
    this.name = const Value.absent(),
    this.phone = const Value.absent(),
    this.trade = const Value.absent(),
    this.type = const Value.absent(),
    this.isActive = const Value.absent(),
    this.updatedAt = const Value.absent(),
  });
  WorkersCompanion.insert({
    this.id = const Value.absent(),
    required String name,
    this.phone = const Value.absent(),
    required String trade,
    this.type = const Value.absent(),
    this.isActive = const Value.absent(),
    this.updatedAt = const Value.absent(),
  }) : name = Value(name),
       trade = Value(trade);
  static Insertable<WorkerRow> custom({
    Expression<int>? id,
    Expression<String>? name,
    Expression<String>? phone,
    Expression<String>? trade,
    Expression<String>? type,
    Expression<bool>? isActive,
    Expression<DateTime>? updatedAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (phone != null) 'phone': phone,
      if (trade != null) 'trade': trade,
      if (type != null) 'type': type,
      if (isActive != null) 'is_active': isActive,
      if (updatedAt != null) 'updated_at': updatedAt,
    });
  }

  WorkersCompanion copyWith({
    Value<int>? id,
    Value<String>? name,
    Value<String?>? phone,
    Value<String>? trade,
    Value<String>? type,
    Value<bool>? isActive,
    Value<DateTime?>? updatedAt,
  }) {
    return WorkersCompanion(
      id: id ?? this.id,
      name: name ?? this.name,
      phone: phone ?? this.phone,
      trade: trade ?? this.trade,
      type: type ?? this.type,
      isActive: isActive ?? this.isActive,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (phone.present) {
      map['phone'] = Variable<String>(phone.value);
    }
    if (trade.present) {
      map['trade'] = Variable<String>(trade.value);
    }
    if (type.present) {
      map['type'] = Variable<String>(type.value);
    }
    if (isActive.present) {
      map['is_active'] = Variable<bool>(isActive.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('WorkersCompanion(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('phone: $phone, ')
          ..write('trade: $trade, ')
          ..write('type: $type, ')
          ..write('isActive: $isActive, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }
}

class $WalletsTable extends Wallets with TableInfo<$WalletsTable, WalletRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $WalletsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _userIdMeta = const VerificationMeta('userId');
  @override
  late final GeneratedColumn<int> userId = GeneratedColumn<int>(
    'user_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  @override
  late final GeneratedColumnWithTypeConverter<Money, String> balance =
      GeneratedColumn<String>(
        'balance',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Money>($WalletsTable.$converterbalance);
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    projectId,
    name,
    userId,
    balance,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'wallets';
  @override
  VerificationContext validateIntegrity(
    Insertable<WalletRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('user_id')) {
      context.handle(
        _userIdMeta,
        userId.isAcceptableOrUnknown(data['user_id']!, _userIdMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  WalletRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return WalletRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      userId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}user_id'],
      ),
      balance: $WalletsTable.$converterbalance.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}balance'],
        )!,
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $WalletsTable createAlias(String alias) {
    return $WalletsTable(attachedDatabase, alias);
  }

  static TypeConverter<Money, String> $converterbalance =
      const MoneyConverter();
}

class WalletRow extends DataClass implements Insertable<WalletRow> {
  final int id;
  final int projectId;
  final String name;
  final int? userId;
  final Money balance;
  final DateTime? updatedAt;
  const WalletRow({
    required this.id,
    required this.projectId,
    required this.name,
    this.userId,
    required this.balance,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['project_id'] = Variable<int>(projectId);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || userId != null) {
      map['user_id'] = Variable<int>(userId);
    }
    {
      map['balance'] = Variable<String>(
        $WalletsTable.$converterbalance.toSql(balance),
      );
    }
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  WalletsCompanion toCompanion(bool nullToAbsent) {
    return WalletsCompanion(
      id: Value(id),
      projectId: Value(projectId),
      name: Value(name),
      userId: userId == null && nullToAbsent
          ? const Value.absent()
          : Value(userId),
      balance: Value(balance),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory WalletRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return WalletRow(
      id: serializer.fromJson<int>(json['id']),
      projectId: serializer.fromJson<int>(json['projectId']),
      name: serializer.fromJson<String>(json['name']),
      userId: serializer.fromJson<int?>(json['userId']),
      balance: serializer.fromJson<Money>(json['balance']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'projectId': serializer.toJson<int>(projectId),
      'name': serializer.toJson<String>(name),
      'userId': serializer.toJson<int?>(userId),
      'balance': serializer.toJson<Money>(balance),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  WalletRow copyWith({
    int? id,
    int? projectId,
    String? name,
    Value<int?> userId = const Value.absent(),
    Money? balance,
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => WalletRow(
    id: id ?? this.id,
    projectId: projectId ?? this.projectId,
    name: name ?? this.name,
    userId: userId.present ? userId.value : this.userId,
    balance: balance ?? this.balance,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  WalletRow copyWithCompanion(WalletsCompanion data) {
    return WalletRow(
      id: data.id.present ? data.id.value : this.id,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      name: data.name.present ? data.name.value : this.name,
      userId: data.userId.present ? data.userId.value : this.userId,
      balance: data.balance.present ? data.balance.value : this.balance,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('WalletRow(')
          ..write('id: $id, ')
          ..write('projectId: $projectId, ')
          ..write('name: $name, ')
          ..write('userId: $userId, ')
          ..write('balance: $balance, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(id, projectId, name, userId, balance, updatedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is WalletRow &&
          other.id == this.id &&
          other.projectId == this.projectId &&
          other.name == this.name &&
          other.userId == this.userId &&
          other.balance == this.balance &&
          other.updatedAt == this.updatedAt);
}

class WalletsCompanion extends UpdateCompanion<WalletRow> {
  final Value<int> id;
  final Value<int> projectId;
  final Value<String> name;
  final Value<int?> userId;
  final Value<Money> balance;
  final Value<DateTime?> updatedAt;
  const WalletsCompanion({
    this.id = const Value.absent(),
    this.projectId = const Value.absent(),
    this.name = const Value.absent(),
    this.userId = const Value.absent(),
    this.balance = const Value.absent(),
    this.updatedAt = const Value.absent(),
  });
  WalletsCompanion.insert({
    this.id = const Value.absent(),
    required int projectId,
    required String name,
    this.userId = const Value.absent(),
    required Money balance,
    this.updatedAt = const Value.absent(),
  }) : projectId = Value(projectId),
       name = Value(name),
       balance = Value(balance);
  static Insertable<WalletRow> custom({
    Expression<int>? id,
    Expression<int>? projectId,
    Expression<String>? name,
    Expression<int>? userId,
    Expression<String>? balance,
    Expression<DateTime>? updatedAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (projectId != null) 'project_id': projectId,
      if (name != null) 'name': name,
      if (userId != null) 'user_id': userId,
      if (balance != null) 'balance': balance,
      if (updatedAt != null) 'updated_at': updatedAt,
    });
  }

  WalletsCompanion copyWith({
    Value<int>? id,
    Value<int>? projectId,
    Value<String>? name,
    Value<int?>? userId,
    Value<Money>? balance,
    Value<DateTime?>? updatedAt,
  }) {
    return WalletsCompanion(
      id: id ?? this.id,
      projectId: projectId ?? this.projectId,
      name: name ?? this.name,
      userId: userId ?? this.userId,
      balance: balance ?? this.balance,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (userId.present) {
      map['user_id'] = Variable<int>(userId.value);
    }
    if (balance.present) {
      map['balance'] = Variable<String>(
        $WalletsTable.$converterbalance.toSql(balance.value),
      );
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('WalletsCompanion(')
          ..write('id: $id, ')
          ..write('projectId: $projectId, ')
          ..write('name: $name, ')
          ..write('userId: $userId, ')
          ..write('balance: $balance, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }
}

class $UsersTable extends Users with TableInfo<$UsersTable, UserRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $UsersTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _roleMeta = const VerificationMeta('role');
  @override
  late final GeneratedColumn<String> role = GeneratedColumn<String>(
    'role',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [id, name, role];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'users';
  @override
  VerificationContext validateIntegrity(
    Insertable<UserRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('role')) {
      context.handle(
        _roleMeta,
        role.isAcceptableOrUnknown(data['role']!, _roleMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  UserRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return UserRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      role: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}role'],
      ),
    );
  }

  @override
  $UsersTable createAlias(String alias) {
    return $UsersTable(attachedDatabase, alias);
  }
}

class UserRow extends DataClass implements Insertable<UserRow> {
  final int id;
  final String name;
  final String? role;
  const UserRow({required this.id, required this.name, this.role});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || role != null) {
      map['role'] = Variable<String>(role);
    }
    return map;
  }

  UsersCompanion toCompanion(bool nullToAbsent) {
    return UsersCompanion(
      id: Value(id),
      name: Value(name),
      role: role == null && nullToAbsent ? const Value.absent() : Value(role),
    );
  }

  factory UserRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return UserRow(
      id: serializer.fromJson<int>(json['id']),
      name: serializer.fromJson<String>(json['name']),
      role: serializer.fromJson<String?>(json['role']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'name': serializer.toJson<String>(name),
      'role': serializer.toJson<String?>(role),
    };
  }

  UserRow copyWith({
    int? id,
    String? name,
    Value<String?> role = const Value.absent(),
  }) => UserRow(
    id: id ?? this.id,
    name: name ?? this.name,
    role: role.present ? role.value : this.role,
  );
  UserRow copyWithCompanion(UsersCompanion data) {
    return UserRow(
      id: data.id.present ? data.id.value : this.id,
      name: data.name.present ? data.name.value : this.name,
      role: data.role.present ? data.role.value : this.role,
    );
  }

  @override
  String toString() {
    return (StringBuffer('UserRow(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('role: $role')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(id, name, role);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is UserRow &&
          other.id == this.id &&
          other.name == this.name &&
          other.role == this.role);
}

class UsersCompanion extends UpdateCompanion<UserRow> {
  final Value<int> id;
  final Value<String> name;
  final Value<String?> role;
  const UsersCompanion({
    this.id = const Value.absent(),
    this.name = const Value.absent(),
    this.role = const Value.absent(),
  });
  UsersCompanion.insert({
    this.id = const Value.absent(),
    required String name,
    this.role = const Value.absent(),
  }) : name = Value(name);
  static Insertable<UserRow> custom({
    Expression<int>? id,
    Expression<String>? name,
    Expression<String>? role,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (role != null) 'role': role,
    });
  }

  UsersCompanion copyWith({
    Value<int>? id,
    Value<String>? name,
    Value<String?>? role,
  }) {
    return UsersCompanion(
      id: id ?? this.id,
      name: name ?? this.name,
      role: role ?? this.role,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (role.present) {
      map['role'] = Variable<String>(role.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('UsersCompanion(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('role: $role')
          ..write(')'))
        .toString();
  }
}

class $KeyValuesTable extends KeyValues
    with TableInfo<$KeyValuesTable, KeyValueRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $KeyValuesTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _keyMeta = const VerificationMeta('key');
  @override
  late final GeneratedColumn<String> key = GeneratedColumn<String>(
    'key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _valueMeta = const VerificationMeta('value');
  @override
  late final GeneratedColumn<String> value = GeneratedColumn<String>(
    'value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [key, value];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'key_values';
  @override
  VerificationContext validateIntegrity(
    Insertable<KeyValueRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('key')) {
      context.handle(
        _keyMeta,
        key.isAcceptableOrUnknown(data['key']!, _keyMeta),
      );
    } else if (isInserting) {
      context.missing(_keyMeta);
    }
    if (data.containsKey('value')) {
      context.handle(
        _valueMeta,
        value.isAcceptableOrUnknown(data['value']!, _valueMeta),
      );
    } else if (isInserting) {
      context.missing(_valueMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {key};
  @override
  KeyValueRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return KeyValueRow(
      key: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}key'],
      )!,
      value: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}value'],
      )!,
    );
  }

  @override
  $KeyValuesTable createAlias(String alias) {
    return $KeyValuesTable(attachedDatabase, alias);
  }
}

class KeyValueRow extends DataClass implements Insertable<KeyValueRow> {
  final String key;
  final String value;
  const KeyValueRow({required this.key, required this.value});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['key'] = Variable<String>(key);
    map['value'] = Variable<String>(value);
    return map;
  }

  KeyValuesCompanion toCompanion(bool nullToAbsent) {
    return KeyValuesCompanion(key: Value(key), value: Value(value));
  }

  factory KeyValueRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return KeyValueRow(
      key: serializer.fromJson<String>(json['key']),
      value: serializer.fromJson<String>(json['value']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'key': serializer.toJson<String>(key),
      'value': serializer.toJson<String>(value),
    };
  }

  KeyValueRow copyWith({String? key, String? value}) =>
      KeyValueRow(key: key ?? this.key, value: value ?? this.value);
  KeyValueRow copyWithCompanion(KeyValuesCompanion data) {
    return KeyValueRow(
      key: data.key.present ? data.key.value : this.key,
      value: data.value.present ? data.value.value : this.value,
    );
  }

  @override
  String toString() {
    return (StringBuffer('KeyValueRow(')
          ..write('key: $key, ')
          ..write('value: $value')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(key, value);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is KeyValueRow &&
          other.key == this.key &&
          other.value == this.value);
}

class KeyValuesCompanion extends UpdateCompanion<KeyValueRow> {
  final Value<String> key;
  final Value<String> value;
  final Value<int> rowid;
  const KeyValuesCompanion({
    this.key = const Value.absent(),
    this.value = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  KeyValuesCompanion.insert({
    required String key,
    required String value,
    this.rowid = const Value.absent(),
  }) : key = Value(key),
       value = Value(value);
  static Insertable<KeyValueRow> custom({
    Expression<String>? key,
    Expression<String>? value,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (key != null) 'key': key,
      if (value != null) 'value': value,
      if (rowid != null) 'rowid': rowid,
    });
  }

  KeyValuesCompanion copyWith({
    Value<String>? key,
    Value<String>? value,
    Value<int>? rowid,
  }) {
    return KeyValuesCompanion(
      key: key ?? this.key,
      value: value ?? this.value,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (key.present) {
      map['key'] = Variable<String>(key.value);
    }
    if (value.present) {
      map['value'] = Variable<String>(value.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('KeyValuesCompanion(')
          ..write('key: $key, ')
          ..write('value: $value, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $DprsTable extends Dprs with TableInfo<$DprsTable, DprRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $DprsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _clientUuidMeta = const VerificationMeta(
    'clientUuid',
  );
  @override
  late final GeneratedColumn<String> clientUuid = GeneratedColumn<String>(
    'client_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _serverIdMeta = const VerificationMeta(
    'serverId',
  );
  @override
  late final GeneratedColumn<int> serverId = GeneratedColumn<int>(
    'server_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _siteIdMeta = const VerificationMeta('siteId');
  @override
  late final GeneratedColumn<int> siteId = GeneratedColumn<int>(
    'site_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _reportDateMeta = const VerificationMeta(
    'reportDate',
  );
  @override
  late final GeneratedColumn<String> reportDate = GeneratedColumn<String>(
    'report_date',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _weatherMeta = const VerificationMeta(
    'weather',
  );
  @override
  late final GeneratedColumn<String> weather = GeneratedColumn<String>(
    'weather',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _summaryMeta = const VerificationMeta(
    'summary',
  );
  @override
  late final GeneratedColumn<String> summary = GeneratedColumn<String>(
    'summary',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _hindrancesMeta = const VerificationMeta(
    'hindrances',
  );
  @override
  late final GeneratedColumn<String> hindrances = GeneratedColumn<String>(
    'hindrances',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('DRAFT'),
  );
  static const VerificationMeta _localDraftMeta = const VerificationMeta(
    'localDraft',
  );
  @override
  late final GeneratedColumn<bool> localDraft = GeneratedColumn<bool>(
    'local_draft',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("local_draft" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _createdByMeta = const VerificationMeta(
    'createdBy',
  );
  @override
  late final GeneratedColumn<int> createdBy = GeneratedColumn<int>(
    'created_by',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdByNameMeta = const VerificationMeta(
    'createdByName',
  );
  @override
  late final GeneratedColumn<String> createdByName = GeneratedColumn<String>(
    'created_by_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  late final GeneratedColumnWithTypeConverter<List<ManpowerLine>, String>
  manpower = GeneratedColumn<String>(
    'manpower',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  ).withConverter<List<ManpowerLine>>($DprsTable.$convertermanpower);
  @override
  late final GeneratedColumnWithTypeConverter<List<MachineryLine>, String>
  machinery = GeneratedColumn<String>(
    'machinery',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  ).withConverter<List<MachineryLine>>($DprsTable.$convertermachinery);
  @override
  late final GeneratedColumnWithTypeConverter<List<WorkLine>, String>
  workItems = GeneratedColumn<String>(
    'work_items',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  ).withConverter<List<WorkLine>>($DprsTable.$converterworkItems);
  static const VerificationMeta _returnRemarkMeta = const VerificationMeta(
    'returnRemark',
  );
  @override
  late final GeneratedColumn<String> returnRemark = GeneratedColumn<String>(
    'return_remark',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _clientUpdatedAtMeta = const VerificationMeta(
    'clientUpdatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> clientUpdatedAt =
      GeneratedColumn<DateTime>(
        'client_updated_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    clientUuid,
    serverId,
    projectId,
    siteId,
    reportDate,
    weather,
    summary,
    hindrances,
    status,
    localDraft,
    createdBy,
    createdByName,
    manpower,
    machinery,
    workItems,
    returnRemark,
    clientUpdatedAt,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'dprs';
  @override
  VerificationContext validateIntegrity(
    Insertable<DprRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('client_uuid')) {
      context.handle(
        _clientUuidMeta,
        clientUuid.isAcceptableOrUnknown(data['client_uuid']!, _clientUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_clientUuidMeta);
    }
    if (data.containsKey('server_id')) {
      context.handle(
        _serverIdMeta,
        serverId.isAcceptableOrUnknown(data['server_id']!, _serverIdMeta),
      );
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('site_id')) {
      context.handle(
        _siteIdMeta,
        siteId.isAcceptableOrUnknown(data['site_id']!, _siteIdMeta),
      );
    }
    if (data.containsKey('report_date')) {
      context.handle(
        _reportDateMeta,
        reportDate.isAcceptableOrUnknown(data['report_date']!, _reportDateMeta),
      );
    } else if (isInserting) {
      context.missing(_reportDateMeta);
    }
    if (data.containsKey('weather')) {
      context.handle(
        _weatherMeta,
        weather.isAcceptableOrUnknown(data['weather']!, _weatherMeta),
      );
    }
    if (data.containsKey('summary')) {
      context.handle(
        _summaryMeta,
        summary.isAcceptableOrUnknown(data['summary']!, _summaryMeta),
      );
    }
    if (data.containsKey('hindrances')) {
      context.handle(
        _hindrancesMeta,
        hindrances.isAcceptableOrUnknown(data['hindrances']!, _hindrancesMeta),
      );
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    if (data.containsKey('local_draft')) {
      context.handle(
        _localDraftMeta,
        localDraft.isAcceptableOrUnknown(data['local_draft']!, _localDraftMeta),
      );
    }
    if (data.containsKey('created_by')) {
      context.handle(
        _createdByMeta,
        createdBy.isAcceptableOrUnknown(data['created_by']!, _createdByMeta),
      );
    }
    if (data.containsKey('created_by_name')) {
      context.handle(
        _createdByNameMeta,
        createdByName.isAcceptableOrUnknown(
          data['created_by_name']!,
          _createdByNameMeta,
        ),
      );
    }
    if (data.containsKey('return_remark')) {
      context.handle(
        _returnRemarkMeta,
        returnRemark.isAcceptableOrUnknown(
          data['return_remark']!,
          _returnRemarkMeta,
        ),
      );
    }
    if (data.containsKey('client_updated_at')) {
      context.handle(
        _clientUpdatedAtMeta,
        clientUpdatedAt.isAcceptableOrUnknown(
          data['client_updated_at']!,
          _clientUpdatedAtMeta,
        ),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clientUuid};
  @override
  DprRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return DprRow(
      clientUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}client_uuid'],
      )!,
      serverId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}server_id'],
      ),
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      siteId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}site_id'],
      ),
      reportDate: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}report_date'],
      )!,
      weather: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}weather'],
      ),
      summary: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}summary'],
      ),
      hindrances: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}hindrances'],
      ),
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      localDraft: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}local_draft'],
      )!,
      createdBy: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_by'],
      ),
      createdByName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}created_by_name'],
      ),
      manpower: $DprsTable.$convertermanpower.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}manpower'],
        )!,
      ),
      machinery: $DprsTable.$convertermachinery.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}machinery'],
        )!,
      ),
      workItems: $DprsTable.$converterworkItems.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}work_items'],
        )!,
      ),
      returnRemark: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}return_remark'],
      ),
      clientUpdatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}client_updated_at'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $DprsTable createAlias(String alias) {
    return $DprsTable(attachedDatabase, alias);
  }

  static TypeConverter<List<ManpowerLine>, String> $convertermanpower =
      const ManpowerLinesConverter();
  static TypeConverter<List<MachineryLine>, String> $convertermachinery =
      const MachineryLinesConverter();
  static TypeConverter<List<WorkLine>, String> $converterworkItems =
      const WorkLinesConverter();
}

class DprRow extends DataClass implements Insertable<DprRow> {
  final String clientUuid;
  final int? serverId;
  final int projectId;
  final int? siteId;
  final String reportDate;
  final String? weather;
  final String? summary;
  final String? hindrances;
  final String status;

  /// True while the user has edits that are saved here but not queued.
  /// A pull must treat this the same as an unsent outbox item.
  final bool localDraft;
  final int? createdBy;
  final String? createdByName;
  final List<ManpowerLine> manpower;
  final List<MachineryLine> machinery;
  final List<WorkLine> workItems;
  final String? returnRemark;
  final DateTime? clientUpdatedAt;
  final DateTime? updatedAt;
  const DprRow({
    required this.clientUuid,
    this.serverId,
    required this.projectId,
    this.siteId,
    required this.reportDate,
    this.weather,
    this.summary,
    this.hindrances,
    required this.status,
    required this.localDraft,
    this.createdBy,
    this.createdByName,
    required this.manpower,
    required this.machinery,
    required this.workItems,
    this.returnRemark,
    this.clientUpdatedAt,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['client_uuid'] = Variable<String>(clientUuid);
    if (!nullToAbsent || serverId != null) {
      map['server_id'] = Variable<int>(serverId);
    }
    map['project_id'] = Variable<int>(projectId);
    if (!nullToAbsent || siteId != null) {
      map['site_id'] = Variable<int>(siteId);
    }
    map['report_date'] = Variable<String>(reportDate);
    if (!nullToAbsent || weather != null) {
      map['weather'] = Variable<String>(weather);
    }
    if (!nullToAbsent || summary != null) {
      map['summary'] = Variable<String>(summary);
    }
    if (!nullToAbsent || hindrances != null) {
      map['hindrances'] = Variable<String>(hindrances);
    }
    map['status'] = Variable<String>(status);
    map['local_draft'] = Variable<bool>(localDraft);
    if (!nullToAbsent || createdBy != null) {
      map['created_by'] = Variable<int>(createdBy);
    }
    if (!nullToAbsent || createdByName != null) {
      map['created_by_name'] = Variable<String>(createdByName);
    }
    {
      map['manpower'] = Variable<String>(
        $DprsTable.$convertermanpower.toSql(manpower),
      );
    }
    {
      map['machinery'] = Variable<String>(
        $DprsTable.$convertermachinery.toSql(machinery),
      );
    }
    {
      map['work_items'] = Variable<String>(
        $DprsTable.$converterworkItems.toSql(workItems),
      );
    }
    if (!nullToAbsent || returnRemark != null) {
      map['return_remark'] = Variable<String>(returnRemark);
    }
    if (!nullToAbsent || clientUpdatedAt != null) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt);
    }
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  DprsCompanion toCompanion(bool nullToAbsent) {
    return DprsCompanion(
      clientUuid: Value(clientUuid),
      serverId: serverId == null && nullToAbsent
          ? const Value.absent()
          : Value(serverId),
      projectId: Value(projectId),
      siteId: siteId == null && nullToAbsent
          ? const Value.absent()
          : Value(siteId),
      reportDate: Value(reportDate),
      weather: weather == null && nullToAbsent
          ? const Value.absent()
          : Value(weather),
      summary: summary == null && nullToAbsent
          ? const Value.absent()
          : Value(summary),
      hindrances: hindrances == null && nullToAbsent
          ? const Value.absent()
          : Value(hindrances),
      status: Value(status),
      localDraft: Value(localDraft),
      createdBy: createdBy == null && nullToAbsent
          ? const Value.absent()
          : Value(createdBy),
      createdByName: createdByName == null && nullToAbsent
          ? const Value.absent()
          : Value(createdByName),
      manpower: Value(manpower),
      machinery: Value(machinery),
      workItems: Value(workItems),
      returnRemark: returnRemark == null && nullToAbsent
          ? const Value.absent()
          : Value(returnRemark),
      clientUpdatedAt: clientUpdatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(clientUpdatedAt),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory DprRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return DprRow(
      clientUuid: serializer.fromJson<String>(json['clientUuid']),
      serverId: serializer.fromJson<int?>(json['serverId']),
      projectId: serializer.fromJson<int>(json['projectId']),
      siteId: serializer.fromJson<int?>(json['siteId']),
      reportDate: serializer.fromJson<String>(json['reportDate']),
      weather: serializer.fromJson<String?>(json['weather']),
      summary: serializer.fromJson<String?>(json['summary']),
      hindrances: serializer.fromJson<String?>(json['hindrances']),
      status: serializer.fromJson<String>(json['status']),
      localDraft: serializer.fromJson<bool>(json['localDraft']),
      createdBy: serializer.fromJson<int?>(json['createdBy']),
      createdByName: serializer.fromJson<String?>(json['createdByName']),
      manpower: serializer.fromJson<List<ManpowerLine>>(json['manpower']),
      machinery: serializer.fromJson<List<MachineryLine>>(json['machinery']),
      workItems: serializer.fromJson<List<WorkLine>>(json['workItems']),
      returnRemark: serializer.fromJson<String?>(json['returnRemark']),
      clientUpdatedAt: serializer.fromJson<DateTime?>(json['clientUpdatedAt']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clientUuid': serializer.toJson<String>(clientUuid),
      'serverId': serializer.toJson<int?>(serverId),
      'projectId': serializer.toJson<int>(projectId),
      'siteId': serializer.toJson<int?>(siteId),
      'reportDate': serializer.toJson<String>(reportDate),
      'weather': serializer.toJson<String?>(weather),
      'summary': serializer.toJson<String?>(summary),
      'hindrances': serializer.toJson<String?>(hindrances),
      'status': serializer.toJson<String>(status),
      'localDraft': serializer.toJson<bool>(localDraft),
      'createdBy': serializer.toJson<int?>(createdBy),
      'createdByName': serializer.toJson<String?>(createdByName),
      'manpower': serializer.toJson<List<ManpowerLine>>(manpower),
      'machinery': serializer.toJson<List<MachineryLine>>(machinery),
      'workItems': serializer.toJson<List<WorkLine>>(workItems),
      'returnRemark': serializer.toJson<String?>(returnRemark),
      'clientUpdatedAt': serializer.toJson<DateTime?>(clientUpdatedAt),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  DprRow copyWith({
    String? clientUuid,
    Value<int?> serverId = const Value.absent(),
    int? projectId,
    Value<int?> siteId = const Value.absent(),
    String? reportDate,
    Value<String?> weather = const Value.absent(),
    Value<String?> summary = const Value.absent(),
    Value<String?> hindrances = const Value.absent(),
    String? status,
    bool? localDraft,
    Value<int?> createdBy = const Value.absent(),
    Value<String?> createdByName = const Value.absent(),
    List<ManpowerLine>? manpower,
    List<MachineryLine>? machinery,
    List<WorkLine>? workItems,
    Value<String?> returnRemark = const Value.absent(),
    Value<DateTime?> clientUpdatedAt = const Value.absent(),
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => DprRow(
    clientUuid: clientUuid ?? this.clientUuid,
    serverId: serverId.present ? serverId.value : this.serverId,
    projectId: projectId ?? this.projectId,
    siteId: siteId.present ? siteId.value : this.siteId,
    reportDate: reportDate ?? this.reportDate,
    weather: weather.present ? weather.value : this.weather,
    summary: summary.present ? summary.value : this.summary,
    hindrances: hindrances.present ? hindrances.value : this.hindrances,
    status: status ?? this.status,
    localDraft: localDraft ?? this.localDraft,
    createdBy: createdBy.present ? createdBy.value : this.createdBy,
    createdByName: createdByName.present
        ? createdByName.value
        : this.createdByName,
    manpower: manpower ?? this.manpower,
    machinery: machinery ?? this.machinery,
    workItems: workItems ?? this.workItems,
    returnRemark: returnRemark.present ? returnRemark.value : this.returnRemark,
    clientUpdatedAt: clientUpdatedAt.present
        ? clientUpdatedAt.value
        : this.clientUpdatedAt,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  DprRow copyWithCompanion(DprsCompanion data) {
    return DprRow(
      clientUuid: data.clientUuid.present
          ? data.clientUuid.value
          : this.clientUuid,
      serverId: data.serverId.present ? data.serverId.value : this.serverId,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      siteId: data.siteId.present ? data.siteId.value : this.siteId,
      reportDate: data.reportDate.present
          ? data.reportDate.value
          : this.reportDate,
      weather: data.weather.present ? data.weather.value : this.weather,
      summary: data.summary.present ? data.summary.value : this.summary,
      hindrances: data.hindrances.present
          ? data.hindrances.value
          : this.hindrances,
      status: data.status.present ? data.status.value : this.status,
      localDraft: data.localDraft.present
          ? data.localDraft.value
          : this.localDraft,
      createdBy: data.createdBy.present ? data.createdBy.value : this.createdBy,
      createdByName: data.createdByName.present
          ? data.createdByName.value
          : this.createdByName,
      manpower: data.manpower.present ? data.manpower.value : this.manpower,
      machinery: data.machinery.present ? data.machinery.value : this.machinery,
      workItems: data.workItems.present ? data.workItems.value : this.workItems,
      returnRemark: data.returnRemark.present
          ? data.returnRemark.value
          : this.returnRemark,
      clientUpdatedAt: data.clientUpdatedAt.present
          ? data.clientUpdatedAt.value
          : this.clientUpdatedAt,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('DprRow(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('siteId: $siteId, ')
          ..write('reportDate: $reportDate, ')
          ..write('weather: $weather, ')
          ..write('summary: $summary, ')
          ..write('hindrances: $hindrances, ')
          ..write('status: $status, ')
          ..write('localDraft: $localDraft, ')
          ..write('createdBy: $createdBy, ')
          ..write('createdByName: $createdByName, ')
          ..write('manpower: $manpower, ')
          ..write('machinery: $machinery, ')
          ..write('workItems: $workItems, ')
          ..write('returnRemark: $returnRemark, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    clientUuid,
    serverId,
    projectId,
    siteId,
    reportDate,
    weather,
    summary,
    hindrances,
    status,
    localDraft,
    createdBy,
    createdByName,
    manpower,
    machinery,
    workItems,
    returnRemark,
    clientUpdatedAt,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is DprRow &&
          other.clientUuid == this.clientUuid &&
          other.serverId == this.serverId &&
          other.projectId == this.projectId &&
          other.siteId == this.siteId &&
          other.reportDate == this.reportDate &&
          other.weather == this.weather &&
          other.summary == this.summary &&
          other.hindrances == this.hindrances &&
          other.status == this.status &&
          other.localDraft == this.localDraft &&
          other.createdBy == this.createdBy &&
          other.createdByName == this.createdByName &&
          other.manpower == this.manpower &&
          other.machinery == this.machinery &&
          other.workItems == this.workItems &&
          other.returnRemark == this.returnRemark &&
          other.clientUpdatedAt == this.clientUpdatedAt &&
          other.updatedAt == this.updatedAt);
}

class DprsCompanion extends UpdateCompanion<DprRow> {
  final Value<String> clientUuid;
  final Value<int?> serverId;
  final Value<int> projectId;
  final Value<int?> siteId;
  final Value<String> reportDate;
  final Value<String?> weather;
  final Value<String?> summary;
  final Value<String?> hindrances;
  final Value<String> status;
  final Value<bool> localDraft;
  final Value<int?> createdBy;
  final Value<String?> createdByName;
  final Value<List<ManpowerLine>> manpower;
  final Value<List<MachineryLine>> machinery;
  final Value<List<WorkLine>> workItems;
  final Value<String?> returnRemark;
  final Value<DateTime?> clientUpdatedAt;
  final Value<DateTime?> updatedAt;
  final Value<int> rowid;
  const DprsCompanion({
    this.clientUuid = const Value.absent(),
    this.serverId = const Value.absent(),
    this.projectId = const Value.absent(),
    this.siteId = const Value.absent(),
    this.reportDate = const Value.absent(),
    this.weather = const Value.absent(),
    this.summary = const Value.absent(),
    this.hindrances = const Value.absent(),
    this.status = const Value.absent(),
    this.localDraft = const Value.absent(),
    this.createdBy = const Value.absent(),
    this.createdByName = const Value.absent(),
    this.manpower = const Value.absent(),
    this.machinery = const Value.absent(),
    this.workItems = const Value.absent(),
    this.returnRemark = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  DprsCompanion.insert({
    required String clientUuid,
    this.serverId = const Value.absent(),
    required int projectId,
    this.siteId = const Value.absent(),
    required String reportDate,
    this.weather = const Value.absent(),
    this.summary = const Value.absent(),
    this.hindrances = const Value.absent(),
    this.status = const Value.absent(),
    this.localDraft = const Value.absent(),
    this.createdBy = const Value.absent(),
    this.createdByName = const Value.absent(),
    required List<ManpowerLine> manpower,
    required List<MachineryLine> machinery,
    required List<WorkLine> workItems,
    this.returnRemark = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : clientUuid = Value(clientUuid),
       projectId = Value(projectId),
       reportDate = Value(reportDate),
       manpower = Value(manpower),
       machinery = Value(machinery),
       workItems = Value(workItems);
  static Insertable<DprRow> custom({
    Expression<String>? clientUuid,
    Expression<int>? serverId,
    Expression<int>? projectId,
    Expression<int>? siteId,
    Expression<String>? reportDate,
    Expression<String>? weather,
    Expression<String>? summary,
    Expression<String>? hindrances,
    Expression<String>? status,
    Expression<bool>? localDraft,
    Expression<int>? createdBy,
    Expression<String>? createdByName,
    Expression<String>? manpower,
    Expression<String>? machinery,
    Expression<String>? workItems,
    Expression<String>? returnRemark,
    Expression<DateTime>? clientUpdatedAt,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clientUuid != null) 'client_uuid': clientUuid,
      if (serverId != null) 'server_id': serverId,
      if (projectId != null) 'project_id': projectId,
      if (siteId != null) 'site_id': siteId,
      if (reportDate != null) 'report_date': reportDate,
      if (weather != null) 'weather': weather,
      if (summary != null) 'summary': summary,
      if (hindrances != null) 'hindrances': hindrances,
      if (status != null) 'status': status,
      if (localDraft != null) 'local_draft': localDraft,
      if (createdBy != null) 'created_by': createdBy,
      if (createdByName != null) 'created_by_name': createdByName,
      if (manpower != null) 'manpower': manpower,
      if (machinery != null) 'machinery': machinery,
      if (workItems != null) 'work_items': workItems,
      if (returnRemark != null) 'return_remark': returnRemark,
      if (clientUpdatedAt != null) 'client_updated_at': clientUpdatedAt,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  DprsCompanion copyWith({
    Value<String>? clientUuid,
    Value<int?>? serverId,
    Value<int>? projectId,
    Value<int?>? siteId,
    Value<String>? reportDate,
    Value<String?>? weather,
    Value<String?>? summary,
    Value<String?>? hindrances,
    Value<String>? status,
    Value<bool>? localDraft,
    Value<int?>? createdBy,
    Value<String?>? createdByName,
    Value<List<ManpowerLine>>? manpower,
    Value<List<MachineryLine>>? machinery,
    Value<List<WorkLine>>? workItems,
    Value<String?>? returnRemark,
    Value<DateTime?>? clientUpdatedAt,
    Value<DateTime?>? updatedAt,
    Value<int>? rowid,
  }) {
    return DprsCompanion(
      clientUuid: clientUuid ?? this.clientUuid,
      serverId: serverId ?? this.serverId,
      projectId: projectId ?? this.projectId,
      siteId: siteId ?? this.siteId,
      reportDate: reportDate ?? this.reportDate,
      weather: weather ?? this.weather,
      summary: summary ?? this.summary,
      hindrances: hindrances ?? this.hindrances,
      status: status ?? this.status,
      localDraft: localDraft ?? this.localDraft,
      createdBy: createdBy ?? this.createdBy,
      createdByName: createdByName ?? this.createdByName,
      manpower: manpower ?? this.manpower,
      machinery: machinery ?? this.machinery,
      workItems: workItems ?? this.workItems,
      returnRemark: returnRemark ?? this.returnRemark,
      clientUpdatedAt: clientUpdatedAt ?? this.clientUpdatedAt,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clientUuid.present) {
      map['client_uuid'] = Variable<String>(clientUuid.value);
    }
    if (serverId.present) {
      map['server_id'] = Variable<int>(serverId.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (siteId.present) {
      map['site_id'] = Variable<int>(siteId.value);
    }
    if (reportDate.present) {
      map['report_date'] = Variable<String>(reportDate.value);
    }
    if (weather.present) {
      map['weather'] = Variable<String>(weather.value);
    }
    if (summary.present) {
      map['summary'] = Variable<String>(summary.value);
    }
    if (hindrances.present) {
      map['hindrances'] = Variable<String>(hindrances.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (localDraft.present) {
      map['local_draft'] = Variable<bool>(localDraft.value);
    }
    if (createdBy.present) {
      map['created_by'] = Variable<int>(createdBy.value);
    }
    if (createdByName.present) {
      map['created_by_name'] = Variable<String>(createdByName.value);
    }
    if (manpower.present) {
      map['manpower'] = Variable<String>(
        $DprsTable.$convertermanpower.toSql(manpower.value),
      );
    }
    if (machinery.present) {
      map['machinery'] = Variable<String>(
        $DprsTable.$convertermachinery.toSql(machinery.value),
      );
    }
    if (workItems.present) {
      map['work_items'] = Variable<String>(
        $DprsTable.$converterworkItems.toSql(workItems.value),
      );
    }
    if (returnRemark.present) {
      map['return_remark'] = Variable<String>(returnRemark.value);
    }
    if (clientUpdatedAt.present) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('DprsCompanion(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('siteId: $siteId, ')
          ..write('reportDate: $reportDate, ')
          ..write('weather: $weather, ')
          ..write('summary: $summary, ')
          ..write('hindrances: $hindrances, ')
          ..write('status: $status, ')
          ..write('localDraft: $localDraft, ')
          ..write('createdBy: $createdBy, ')
          ..write('createdByName: $createdByName, ')
          ..write('manpower: $manpower, ')
          ..write('machinery: $machinery, ')
          ..write('workItems: $workItems, ')
          ..write('returnRemark: $returnRemark, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $DprPhotosTable extends DprPhotos
    with TableInfo<$DprPhotosTable, DprPhotoRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $DprPhotosTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _clientUuidMeta = const VerificationMeta(
    'clientUuid',
  );
  @override
  late final GeneratedColumn<String> clientUuid = GeneratedColumn<String>(
    'client_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _serverIdMeta = const VerificationMeta(
    'serverId',
  );
  @override
  late final GeneratedColumn<int> serverId = GeneratedColumn<int>(
    'server_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _dprClientUuidMeta = const VerificationMeta(
    'dprClientUuid',
  );
  @override
  late final GeneratedColumn<String> dprClientUuid = GeneratedColumn<String>(
    'dpr_client_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _relativePathMeta = const VerificationMeta(
    'relativePath',
  );
  @override
  late final GeneratedColumn<String> relativePath = GeneratedColumn<String>(
    'relative_path',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _captionMeta = const VerificationMeta(
    'caption',
  );
  @override
  late final GeneratedColumn<String> caption = GeneratedColumn<String>(
    'caption',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _takenAtMeta = const VerificationMeta(
    'takenAt',
  );
  @override
  late final GeneratedColumn<DateTime> takenAt = GeneratedColumn<DateTime>(
    'taken_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<DateTime> createdAt = GeneratedColumn<DateTime>(
    'created_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    clientUuid,
    serverId,
    dprClientUuid,
    relativePath,
    caption,
    takenAt,
    createdAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'dpr_photos';
  @override
  VerificationContext validateIntegrity(
    Insertable<DprPhotoRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('client_uuid')) {
      context.handle(
        _clientUuidMeta,
        clientUuid.isAcceptableOrUnknown(data['client_uuid']!, _clientUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_clientUuidMeta);
    }
    if (data.containsKey('server_id')) {
      context.handle(
        _serverIdMeta,
        serverId.isAcceptableOrUnknown(data['server_id']!, _serverIdMeta),
      );
    }
    if (data.containsKey('dpr_client_uuid')) {
      context.handle(
        _dprClientUuidMeta,
        dprClientUuid.isAcceptableOrUnknown(
          data['dpr_client_uuid']!,
          _dprClientUuidMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_dprClientUuidMeta);
    }
    if (data.containsKey('relative_path')) {
      context.handle(
        _relativePathMeta,
        relativePath.isAcceptableOrUnknown(
          data['relative_path']!,
          _relativePathMeta,
        ),
      );
    }
    if (data.containsKey('caption')) {
      context.handle(
        _captionMeta,
        caption.isAcceptableOrUnknown(data['caption']!, _captionMeta),
      );
    }
    if (data.containsKey('taken_at')) {
      context.handle(
        _takenAtMeta,
        takenAt.isAcceptableOrUnknown(data['taken_at']!, _takenAtMeta),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clientUuid};
  @override
  DprPhotoRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return DprPhotoRow(
      clientUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}client_uuid'],
      )!,
      serverId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}server_id'],
      ),
      dprClientUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}dpr_client_uuid'],
      )!,
      relativePath: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}relative_path'],
      ),
      caption: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}caption'],
      ),
      takenAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}taken_at'],
      ),
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}created_at'],
      ),
    );
  }

  @override
  $DprPhotosTable createAlias(String alias) {
    return $DprPhotosTable(attachedDatabase, alias);
  }
}

class DprPhotoRow extends DataClass implements Insertable<DprPhotoRow> {
  final String clientUuid;
  final int? serverId;
  final String dprClientUuid;

  /// Relative to the app documents directory, because the iOS container
  /// path changes between launches. Null once the file exists only remotely.
  final String? relativePath;
  final String? caption;
  final DateTime? takenAt;
  final DateTime? createdAt;
  const DprPhotoRow({
    required this.clientUuid,
    this.serverId,
    required this.dprClientUuid,
    this.relativePath,
    this.caption,
    this.takenAt,
    this.createdAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['client_uuid'] = Variable<String>(clientUuid);
    if (!nullToAbsent || serverId != null) {
      map['server_id'] = Variable<int>(serverId);
    }
    map['dpr_client_uuid'] = Variable<String>(dprClientUuid);
    if (!nullToAbsent || relativePath != null) {
      map['relative_path'] = Variable<String>(relativePath);
    }
    if (!nullToAbsent || caption != null) {
      map['caption'] = Variable<String>(caption);
    }
    if (!nullToAbsent || takenAt != null) {
      map['taken_at'] = Variable<DateTime>(takenAt);
    }
    if (!nullToAbsent || createdAt != null) {
      map['created_at'] = Variable<DateTime>(createdAt);
    }
    return map;
  }

  DprPhotosCompanion toCompanion(bool nullToAbsent) {
    return DprPhotosCompanion(
      clientUuid: Value(clientUuid),
      serverId: serverId == null && nullToAbsent
          ? const Value.absent()
          : Value(serverId),
      dprClientUuid: Value(dprClientUuid),
      relativePath: relativePath == null && nullToAbsent
          ? const Value.absent()
          : Value(relativePath),
      caption: caption == null && nullToAbsent
          ? const Value.absent()
          : Value(caption),
      takenAt: takenAt == null && nullToAbsent
          ? const Value.absent()
          : Value(takenAt),
      createdAt: createdAt == null && nullToAbsent
          ? const Value.absent()
          : Value(createdAt),
    );
  }

  factory DprPhotoRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return DprPhotoRow(
      clientUuid: serializer.fromJson<String>(json['clientUuid']),
      serverId: serializer.fromJson<int?>(json['serverId']),
      dprClientUuid: serializer.fromJson<String>(json['dprClientUuid']),
      relativePath: serializer.fromJson<String?>(json['relativePath']),
      caption: serializer.fromJson<String?>(json['caption']),
      takenAt: serializer.fromJson<DateTime?>(json['takenAt']),
      createdAt: serializer.fromJson<DateTime?>(json['createdAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clientUuid': serializer.toJson<String>(clientUuid),
      'serverId': serializer.toJson<int?>(serverId),
      'dprClientUuid': serializer.toJson<String>(dprClientUuid),
      'relativePath': serializer.toJson<String?>(relativePath),
      'caption': serializer.toJson<String?>(caption),
      'takenAt': serializer.toJson<DateTime?>(takenAt),
      'createdAt': serializer.toJson<DateTime?>(createdAt),
    };
  }

  DprPhotoRow copyWith({
    String? clientUuid,
    Value<int?> serverId = const Value.absent(),
    String? dprClientUuid,
    Value<String?> relativePath = const Value.absent(),
    Value<String?> caption = const Value.absent(),
    Value<DateTime?> takenAt = const Value.absent(),
    Value<DateTime?> createdAt = const Value.absent(),
  }) => DprPhotoRow(
    clientUuid: clientUuid ?? this.clientUuid,
    serverId: serverId.present ? serverId.value : this.serverId,
    dprClientUuid: dprClientUuid ?? this.dprClientUuid,
    relativePath: relativePath.present ? relativePath.value : this.relativePath,
    caption: caption.present ? caption.value : this.caption,
    takenAt: takenAt.present ? takenAt.value : this.takenAt,
    createdAt: createdAt.present ? createdAt.value : this.createdAt,
  );
  DprPhotoRow copyWithCompanion(DprPhotosCompanion data) {
    return DprPhotoRow(
      clientUuid: data.clientUuid.present
          ? data.clientUuid.value
          : this.clientUuid,
      serverId: data.serverId.present ? data.serverId.value : this.serverId,
      dprClientUuid: data.dprClientUuid.present
          ? data.dprClientUuid.value
          : this.dprClientUuid,
      relativePath: data.relativePath.present
          ? data.relativePath.value
          : this.relativePath,
      caption: data.caption.present ? data.caption.value : this.caption,
      takenAt: data.takenAt.present ? data.takenAt.value : this.takenAt,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('DprPhotoRow(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('dprClientUuid: $dprClientUuid, ')
          ..write('relativePath: $relativePath, ')
          ..write('caption: $caption, ')
          ..write('takenAt: $takenAt, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    clientUuid,
    serverId,
    dprClientUuid,
    relativePath,
    caption,
    takenAt,
    createdAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is DprPhotoRow &&
          other.clientUuid == this.clientUuid &&
          other.serverId == this.serverId &&
          other.dprClientUuid == this.dprClientUuid &&
          other.relativePath == this.relativePath &&
          other.caption == this.caption &&
          other.takenAt == this.takenAt &&
          other.createdAt == this.createdAt);
}

class DprPhotosCompanion extends UpdateCompanion<DprPhotoRow> {
  final Value<String> clientUuid;
  final Value<int?> serverId;
  final Value<String> dprClientUuid;
  final Value<String?> relativePath;
  final Value<String?> caption;
  final Value<DateTime?> takenAt;
  final Value<DateTime?> createdAt;
  final Value<int> rowid;
  const DprPhotosCompanion({
    this.clientUuid = const Value.absent(),
    this.serverId = const Value.absent(),
    this.dprClientUuid = const Value.absent(),
    this.relativePath = const Value.absent(),
    this.caption = const Value.absent(),
    this.takenAt = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  DprPhotosCompanion.insert({
    required String clientUuid,
    this.serverId = const Value.absent(),
    required String dprClientUuid,
    this.relativePath = const Value.absent(),
    this.caption = const Value.absent(),
    this.takenAt = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : clientUuid = Value(clientUuid),
       dprClientUuid = Value(dprClientUuid);
  static Insertable<DprPhotoRow> custom({
    Expression<String>? clientUuid,
    Expression<int>? serverId,
    Expression<String>? dprClientUuid,
    Expression<String>? relativePath,
    Expression<String>? caption,
    Expression<DateTime>? takenAt,
    Expression<DateTime>? createdAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clientUuid != null) 'client_uuid': clientUuid,
      if (serverId != null) 'server_id': serverId,
      if (dprClientUuid != null) 'dpr_client_uuid': dprClientUuid,
      if (relativePath != null) 'relative_path': relativePath,
      if (caption != null) 'caption': caption,
      if (takenAt != null) 'taken_at': takenAt,
      if (createdAt != null) 'created_at': createdAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  DprPhotosCompanion copyWith({
    Value<String>? clientUuid,
    Value<int?>? serverId,
    Value<String>? dprClientUuid,
    Value<String?>? relativePath,
    Value<String?>? caption,
    Value<DateTime?>? takenAt,
    Value<DateTime?>? createdAt,
    Value<int>? rowid,
  }) {
    return DprPhotosCompanion(
      clientUuid: clientUuid ?? this.clientUuid,
      serverId: serverId ?? this.serverId,
      dprClientUuid: dprClientUuid ?? this.dprClientUuid,
      relativePath: relativePath ?? this.relativePath,
      caption: caption ?? this.caption,
      takenAt: takenAt ?? this.takenAt,
      createdAt: createdAt ?? this.createdAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clientUuid.present) {
      map['client_uuid'] = Variable<String>(clientUuid.value);
    }
    if (serverId.present) {
      map['server_id'] = Variable<int>(serverId.value);
    }
    if (dprClientUuid.present) {
      map['dpr_client_uuid'] = Variable<String>(dprClientUuid.value);
    }
    if (relativePath.present) {
      map['relative_path'] = Variable<String>(relativePath.value);
    }
    if (caption.present) {
      map['caption'] = Variable<String>(caption.value);
    }
    if (takenAt.present) {
      map['taken_at'] = Variable<DateTime>(takenAt.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<DateTime>(createdAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('DprPhotosCompanion(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('dprClientUuid: $dprClientUuid, ')
          ..write('relativePath: $relativePath, ')
          ..write('caption: $caption, ')
          ..write('takenAt: $takenAt, ')
          ..write('createdAt: $createdAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $MeasurementsTable extends Measurements
    with TableInfo<$MeasurementsTable, MeasurementRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $MeasurementsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _clientUuidMeta = const VerificationMeta(
    'clientUuid',
  );
  @override
  late final GeneratedColumn<String> clientUuid = GeneratedColumn<String>(
    'client_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _serverIdMeta = const VerificationMeta(
    'serverId',
  );
  @override
  late final GeneratedColumn<int> serverId = GeneratedColumn<int>(
    'server_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _boqItemIdMeta = const VerificationMeta(
    'boqItemId',
  );
  @override
  late final GeneratedColumn<int> boqItemId = GeneratedColumn<int>(
    'boq_item_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _workOrderIdMeta = const VerificationMeta(
    'workOrderId',
  );
  @override
  late final GeneratedColumn<int> workOrderId = GeneratedColumn<int>(
    'work_order_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _measuredOnMeta = const VerificationMeta(
    'measuredOn',
  );
  @override
  late final GeneratedColumn<String> measuredOn = GeneratedColumn<String>(
    'measured_on',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _descriptionMeta = const VerificationMeta(
    'description',
  );
  @override
  late final GeneratedColumn<String> description = GeneratedColumn<String>(
    'description',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _locationMeta = const VerificationMeta(
    'location',
  );
  @override
  late final GeneratedColumn<String> location = GeneratedColumn<String>(
    'location',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  late final GeneratedColumnWithTypeConverter<Qty?, String> nos =
      GeneratedColumn<String>(
        'nos',
        aliasedName,
        true,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
      ).withConverter<Qty?>($MeasurementsTable.$converternosn);
  @override
  late final GeneratedColumnWithTypeConverter<Qty?, String> length =
      GeneratedColumn<String>(
        'length',
        aliasedName,
        true,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
      ).withConverter<Qty?>($MeasurementsTable.$converterlengthn);
  @override
  late final GeneratedColumnWithTypeConverter<Qty?, String> breadth =
      GeneratedColumn<String>(
        'breadth',
        aliasedName,
        true,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
      ).withConverter<Qty?>($MeasurementsTable.$converterbreadthn);
  @override
  late final GeneratedColumnWithTypeConverter<Qty?, String> depth =
      GeneratedColumn<String>(
        'depth',
        aliasedName,
        true,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
      ).withConverter<Qty?>($MeasurementsTable.$converterdepthn);
  static const VerificationMeta _isDeductionMeta = const VerificationMeta(
    'isDeduction',
  );
  @override
  late final GeneratedColumn<bool> isDeduction = GeneratedColumn<bool>(
    'is_deduction',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("is_deduction" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  @override
  late final GeneratedColumnWithTypeConverter<Qty, String> qty =
      GeneratedColumn<String>(
        'qty',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Qty>($MeasurementsTable.$converterqty);
  @override
  late final GeneratedColumnWithTypeConverter<Qty?, String> serverQty =
      GeneratedColumn<String>(
        'server_qty',
        aliasedName,
        true,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
      ).withConverter<Qty?>($MeasurementsTable.$converterserverQtyn);
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('DRAFT'),
  );
  static const VerificationMeta _deviationMeta = const VerificationMeta(
    'deviation',
  );
  @override
  late final GeneratedColumn<bool> deviation = GeneratedColumn<bool>(
    'deviation',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("deviation" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _remarksMeta = const VerificationMeta(
    'remarks',
  );
  @override
  late final GeneratedColumn<String> remarks = GeneratedColumn<String>(
    'remarks',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _returnRemarkMeta = const VerificationMeta(
    'returnRemark',
  );
  @override
  late final GeneratedColumn<String> returnRemark = GeneratedColumn<String>(
    'return_remark',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdByMeta = const VerificationMeta(
    'createdBy',
  );
  @override
  late final GeneratedColumn<int> createdBy = GeneratedColumn<int>(
    'created_by',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _clientUpdatedAtMeta = const VerificationMeta(
    'clientUpdatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> clientUpdatedAt =
      GeneratedColumn<DateTime>(
        'client_updated_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    clientUuid,
    serverId,
    projectId,
    boqItemId,
    workOrderId,
    measuredOn,
    description,
    location,
    nos,
    length,
    breadth,
    depth,
    isDeduction,
    qty,
    serverQty,
    status,
    deviation,
    remarks,
    returnRemark,
    createdBy,
    clientUpdatedAt,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'measurements';
  @override
  VerificationContext validateIntegrity(
    Insertable<MeasurementRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('client_uuid')) {
      context.handle(
        _clientUuidMeta,
        clientUuid.isAcceptableOrUnknown(data['client_uuid']!, _clientUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_clientUuidMeta);
    }
    if (data.containsKey('server_id')) {
      context.handle(
        _serverIdMeta,
        serverId.isAcceptableOrUnknown(data['server_id']!, _serverIdMeta),
      );
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('boq_item_id')) {
      context.handle(
        _boqItemIdMeta,
        boqItemId.isAcceptableOrUnknown(data['boq_item_id']!, _boqItemIdMeta),
      );
    } else if (isInserting) {
      context.missing(_boqItemIdMeta);
    }
    if (data.containsKey('work_order_id')) {
      context.handle(
        _workOrderIdMeta,
        workOrderId.isAcceptableOrUnknown(
          data['work_order_id']!,
          _workOrderIdMeta,
        ),
      );
    }
    if (data.containsKey('measured_on')) {
      context.handle(
        _measuredOnMeta,
        measuredOn.isAcceptableOrUnknown(data['measured_on']!, _measuredOnMeta),
      );
    } else if (isInserting) {
      context.missing(_measuredOnMeta);
    }
    if (data.containsKey('description')) {
      context.handle(
        _descriptionMeta,
        description.isAcceptableOrUnknown(
          data['description']!,
          _descriptionMeta,
        ),
      );
    }
    if (data.containsKey('location')) {
      context.handle(
        _locationMeta,
        location.isAcceptableOrUnknown(data['location']!, _locationMeta),
      );
    }
    if (data.containsKey('is_deduction')) {
      context.handle(
        _isDeductionMeta,
        isDeduction.isAcceptableOrUnknown(
          data['is_deduction']!,
          _isDeductionMeta,
        ),
      );
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    if (data.containsKey('deviation')) {
      context.handle(
        _deviationMeta,
        deviation.isAcceptableOrUnknown(data['deviation']!, _deviationMeta),
      );
    }
    if (data.containsKey('remarks')) {
      context.handle(
        _remarksMeta,
        remarks.isAcceptableOrUnknown(data['remarks']!, _remarksMeta),
      );
    }
    if (data.containsKey('return_remark')) {
      context.handle(
        _returnRemarkMeta,
        returnRemark.isAcceptableOrUnknown(
          data['return_remark']!,
          _returnRemarkMeta,
        ),
      );
    }
    if (data.containsKey('created_by')) {
      context.handle(
        _createdByMeta,
        createdBy.isAcceptableOrUnknown(data['created_by']!, _createdByMeta),
      );
    }
    if (data.containsKey('client_updated_at')) {
      context.handle(
        _clientUpdatedAtMeta,
        clientUpdatedAt.isAcceptableOrUnknown(
          data['client_updated_at']!,
          _clientUpdatedAtMeta,
        ),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clientUuid};
  @override
  MeasurementRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return MeasurementRow(
      clientUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}client_uuid'],
      )!,
      serverId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}server_id'],
      ),
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      boqItemId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}boq_item_id'],
      )!,
      workOrderId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}work_order_id'],
      ),
      measuredOn: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}measured_on'],
      )!,
      description: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}description'],
      ),
      location: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}location'],
      ),
      nos: $MeasurementsTable.$converternosn.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}nos'],
        ),
      ),
      length: $MeasurementsTable.$converterlengthn.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}length'],
        ),
      ),
      breadth: $MeasurementsTable.$converterbreadthn.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}breadth'],
        ),
      ),
      depth: $MeasurementsTable.$converterdepthn.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}depth'],
        ),
      ),
      isDeduction: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}is_deduction'],
      )!,
      qty: $MeasurementsTable.$converterqty.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}qty'],
        )!,
      ),
      serverQty: $MeasurementsTable.$converterserverQtyn.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}server_qty'],
        ),
      ),
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      deviation: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}deviation'],
      )!,
      remarks: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}remarks'],
      ),
      returnRemark: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}return_remark'],
      ),
      createdBy: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_by'],
      ),
      clientUpdatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}client_updated_at'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $MeasurementsTable createAlias(String alias) {
    return $MeasurementsTable(attachedDatabase, alias);
  }

  static TypeConverter<Qty, String> $converternos = const QtyConverter();
  static TypeConverter<Qty?, String?> $converternosn =
      NullAwareTypeConverter.wrap($converternos);
  static TypeConverter<Qty, String> $converterlength = const QtyConverter();
  static TypeConverter<Qty?, String?> $converterlengthn =
      NullAwareTypeConverter.wrap($converterlength);
  static TypeConverter<Qty, String> $converterbreadth = const QtyConverter();
  static TypeConverter<Qty?, String?> $converterbreadthn =
      NullAwareTypeConverter.wrap($converterbreadth);
  static TypeConverter<Qty, String> $converterdepth = const QtyConverter();
  static TypeConverter<Qty?, String?> $converterdepthn =
      NullAwareTypeConverter.wrap($converterdepth);
  static TypeConverter<Qty, String> $converterqty = const QtyConverter();
  static TypeConverter<Qty, String> $converterserverQty = const QtyConverter();
  static TypeConverter<Qty?, String?> $converterserverQtyn =
      NullAwareTypeConverter.wrap($converterserverQty);
}

class MeasurementRow extends DataClass implements Insertable<MeasurementRow> {
  final String clientUuid;
  final int? serverId;
  final int projectId;
  final int boqItemId;
  final int? workOrderId;
  final String measuredOn;
  final String? description;
  final String? location;
  final Qty? nos;
  final Qty? length;
  final Qty? breadth;
  final Qty? depth;
  final bool isDeduction;

  /// Local preview until the server's figure replaces it on the next pull.
  final Qty qty;

  /// What the server's ledger counted for this entry at the last pull.
  /// Null means the ledger has not seen it yet. Drives the balance preview.
  final Qty? serverQty;
  final String status;
  final bool deviation;
  final String? remarks;
  final String? returnRemark;
  final int? createdBy;
  final DateTime? clientUpdatedAt;
  final DateTime? updatedAt;
  const MeasurementRow({
    required this.clientUuid,
    this.serverId,
    required this.projectId,
    required this.boqItemId,
    this.workOrderId,
    required this.measuredOn,
    this.description,
    this.location,
    this.nos,
    this.length,
    this.breadth,
    this.depth,
    required this.isDeduction,
    required this.qty,
    this.serverQty,
    required this.status,
    required this.deviation,
    this.remarks,
    this.returnRemark,
    this.createdBy,
    this.clientUpdatedAt,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['client_uuid'] = Variable<String>(clientUuid);
    if (!nullToAbsent || serverId != null) {
      map['server_id'] = Variable<int>(serverId);
    }
    map['project_id'] = Variable<int>(projectId);
    map['boq_item_id'] = Variable<int>(boqItemId);
    if (!nullToAbsent || workOrderId != null) {
      map['work_order_id'] = Variable<int>(workOrderId);
    }
    map['measured_on'] = Variable<String>(measuredOn);
    if (!nullToAbsent || description != null) {
      map['description'] = Variable<String>(description);
    }
    if (!nullToAbsent || location != null) {
      map['location'] = Variable<String>(location);
    }
    if (!nullToAbsent || nos != null) {
      map['nos'] = Variable<String>(
        $MeasurementsTable.$converternosn.toSql(nos),
      );
    }
    if (!nullToAbsent || length != null) {
      map['length'] = Variable<String>(
        $MeasurementsTable.$converterlengthn.toSql(length),
      );
    }
    if (!nullToAbsent || breadth != null) {
      map['breadth'] = Variable<String>(
        $MeasurementsTable.$converterbreadthn.toSql(breadth),
      );
    }
    if (!nullToAbsent || depth != null) {
      map['depth'] = Variable<String>(
        $MeasurementsTable.$converterdepthn.toSql(depth),
      );
    }
    map['is_deduction'] = Variable<bool>(isDeduction);
    {
      map['qty'] = Variable<String>(
        $MeasurementsTable.$converterqty.toSql(qty),
      );
    }
    if (!nullToAbsent || serverQty != null) {
      map['server_qty'] = Variable<String>(
        $MeasurementsTable.$converterserverQtyn.toSql(serverQty),
      );
    }
    map['status'] = Variable<String>(status);
    map['deviation'] = Variable<bool>(deviation);
    if (!nullToAbsent || remarks != null) {
      map['remarks'] = Variable<String>(remarks);
    }
    if (!nullToAbsent || returnRemark != null) {
      map['return_remark'] = Variable<String>(returnRemark);
    }
    if (!nullToAbsent || createdBy != null) {
      map['created_by'] = Variable<int>(createdBy);
    }
    if (!nullToAbsent || clientUpdatedAt != null) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt);
    }
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  MeasurementsCompanion toCompanion(bool nullToAbsent) {
    return MeasurementsCompanion(
      clientUuid: Value(clientUuid),
      serverId: serverId == null && nullToAbsent
          ? const Value.absent()
          : Value(serverId),
      projectId: Value(projectId),
      boqItemId: Value(boqItemId),
      workOrderId: workOrderId == null && nullToAbsent
          ? const Value.absent()
          : Value(workOrderId),
      measuredOn: Value(measuredOn),
      description: description == null && nullToAbsent
          ? const Value.absent()
          : Value(description),
      location: location == null && nullToAbsent
          ? const Value.absent()
          : Value(location),
      nos: nos == null && nullToAbsent ? const Value.absent() : Value(nos),
      length: length == null && nullToAbsent
          ? const Value.absent()
          : Value(length),
      breadth: breadth == null && nullToAbsent
          ? const Value.absent()
          : Value(breadth),
      depth: depth == null && nullToAbsent
          ? const Value.absent()
          : Value(depth),
      isDeduction: Value(isDeduction),
      qty: Value(qty),
      serverQty: serverQty == null && nullToAbsent
          ? const Value.absent()
          : Value(serverQty),
      status: Value(status),
      deviation: Value(deviation),
      remarks: remarks == null && nullToAbsent
          ? const Value.absent()
          : Value(remarks),
      returnRemark: returnRemark == null && nullToAbsent
          ? const Value.absent()
          : Value(returnRemark),
      createdBy: createdBy == null && nullToAbsent
          ? const Value.absent()
          : Value(createdBy),
      clientUpdatedAt: clientUpdatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(clientUpdatedAt),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory MeasurementRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return MeasurementRow(
      clientUuid: serializer.fromJson<String>(json['clientUuid']),
      serverId: serializer.fromJson<int?>(json['serverId']),
      projectId: serializer.fromJson<int>(json['projectId']),
      boqItemId: serializer.fromJson<int>(json['boqItemId']),
      workOrderId: serializer.fromJson<int?>(json['workOrderId']),
      measuredOn: serializer.fromJson<String>(json['measuredOn']),
      description: serializer.fromJson<String?>(json['description']),
      location: serializer.fromJson<String?>(json['location']),
      nos: serializer.fromJson<Qty?>(json['nos']),
      length: serializer.fromJson<Qty?>(json['length']),
      breadth: serializer.fromJson<Qty?>(json['breadth']),
      depth: serializer.fromJson<Qty?>(json['depth']),
      isDeduction: serializer.fromJson<bool>(json['isDeduction']),
      qty: serializer.fromJson<Qty>(json['qty']),
      serverQty: serializer.fromJson<Qty?>(json['serverQty']),
      status: serializer.fromJson<String>(json['status']),
      deviation: serializer.fromJson<bool>(json['deviation']),
      remarks: serializer.fromJson<String?>(json['remarks']),
      returnRemark: serializer.fromJson<String?>(json['returnRemark']),
      createdBy: serializer.fromJson<int?>(json['createdBy']),
      clientUpdatedAt: serializer.fromJson<DateTime?>(json['clientUpdatedAt']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clientUuid': serializer.toJson<String>(clientUuid),
      'serverId': serializer.toJson<int?>(serverId),
      'projectId': serializer.toJson<int>(projectId),
      'boqItemId': serializer.toJson<int>(boqItemId),
      'workOrderId': serializer.toJson<int?>(workOrderId),
      'measuredOn': serializer.toJson<String>(measuredOn),
      'description': serializer.toJson<String?>(description),
      'location': serializer.toJson<String?>(location),
      'nos': serializer.toJson<Qty?>(nos),
      'length': serializer.toJson<Qty?>(length),
      'breadth': serializer.toJson<Qty?>(breadth),
      'depth': serializer.toJson<Qty?>(depth),
      'isDeduction': serializer.toJson<bool>(isDeduction),
      'qty': serializer.toJson<Qty>(qty),
      'serverQty': serializer.toJson<Qty?>(serverQty),
      'status': serializer.toJson<String>(status),
      'deviation': serializer.toJson<bool>(deviation),
      'remarks': serializer.toJson<String?>(remarks),
      'returnRemark': serializer.toJson<String?>(returnRemark),
      'createdBy': serializer.toJson<int?>(createdBy),
      'clientUpdatedAt': serializer.toJson<DateTime?>(clientUpdatedAt),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  MeasurementRow copyWith({
    String? clientUuid,
    Value<int?> serverId = const Value.absent(),
    int? projectId,
    int? boqItemId,
    Value<int?> workOrderId = const Value.absent(),
    String? measuredOn,
    Value<String?> description = const Value.absent(),
    Value<String?> location = const Value.absent(),
    Value<Qty?> nos = const Value.absent(),
    Value<Qty?> length = const Value.absent(),
    Value<Qty?> breadth = const Value.absent(),
    Value<Qty?> depth = const Value.absent(),
    bool? isDeduction,
    Qty? qty,
    Value<Qty?> serverQty = const Value.absent(),
    String? status,
    bool? deviation,
    Value<String?> remarks = const Value.absent(),
    Value<String?> returnRemark = const Value.absent(),
    Value<int?> createdBy = const Value.absent(),
    Value<DateTime?> clientUpdatedAt = const Value.absent(),
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => MeasurementRow(
    clientUuid: clientUuid ?? this.clientUuid,
    serverId: serverId.present ? serverId.value : this.serverId,
    projectId: projectId ?? this.projectId,
    boqItemId: boqItemId ?? this.boqItemId,
    workOrderId: workOrderId.present ? workOrderId.value : this.workOrderId,
    measuredOn: measuredOn ?? this.measuredOn,
    description: description.present ? description.value : this.description,
    location: location.present ? location.value : this.location,
    nos: nos.present ? nos.value : this.nos,
    length: length.present ? length.value : this.length,
    breadth: breadth.present ? breadth.value : this.breadth,
    depth: depth.present ? depth.value : this.depth,
    isDeduction: isDeduction ?? this.isDeduction,
    qty: qty ?? this.qty,
    serverQty: serverQty.present ? serverQty.value : this.serverQty,
    status: status ?? this.status,
    deviation: deviation ?? this.deviation,
    remarks: remarks.present ? remarks.value : this.remarks,
    returnRemark: returnRemark.present ? returnRemark.value : this.returnRemark,
    createdBy: createdBy.present ? createdBy.value : this.createdBy,
    clientUpdatedAt: clientUpdatedAt.present
        ? clientUpdatedAt.value
        : this.clientUpdatedAt,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  MeasurementRow copyWithCompanion(MeasurementsCompanion data) {
    return MeasurementRow(
      clientUuid: data.clientUuid.present
          ? data.clientUuid.value
          : this.clientUuid,
      serverId: data.serverId.present ? data.serverId.value : this.serverId,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      boqItemId: data.boqItemId.present ? data.boqItemId.value : this.boqItemId,
      workOrderId: data.workOrderId.present
          ? data.workOrderId.value
          : this.workOrderId,
      measuredOn: data.measuredOn.present
          ? data.measuredOn.value
          : this.measuredOn,
      description: data.description.present
          ? data.description.value
          : this.description,
      location: data.location.present ? data.location.value : this.location,
      nos: data.nos.present ? data.nos.value : this.nos,
      length: data.length.present ? data.length.value : this.length,
      breadth: data.breadth.present ? data.breadth.value : this.breadth,
      depth: data.depth.present ? data.depth.value : this.depth,
      isDeduction: data.isDeduction.present
          ? data.isDeduction.value
          : this.isDeduction,
      qty: data.qty.present ? data.qty.value : this.qty,
      serverQty: data.serverQty.present ? data.serverQty.value : this.serverQty,
      status: data.status.present ? data.status.value : this.status,
      deviation: data.deviation.present ? data.deviation.value : this.deviation,
      remarks: data.remarks.present ? data.remarks.value : this.remarks,
      returnRemark: data.returnRemark.present
          ? data.returnRemark.value
          : this.returnRemark,
      createdBy: data.createdBy.present ? data.createdBy.value : this.createdBy,
      clientUpdatedAt: data.clientUpdatedAt.present
          ? data.clientUpdatedAt.value
          : this.clientUpdatedAt,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('MeasurementRow(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('boqItemId: $boqItemId, ')
          ..write('workOrderId: $workOrderId, ')
          ..write('measuredOn: $measuredOn, ')
          ..write('description: $description, ')
          ..write('location: $location, ')
          ..write('nos: $nos, ')
          ..write('length: $length, ')
          ..write('breadth: $breadth, ')
          ..write('depth: $depth, ')
          ..write('isDeduction: $isDeduction, ')
          ..write('qty: $qty, ')
          ..write('serverQty: $serverQty, ')
          ..write('status: $status, ')
          ..write('deviation: $deviation, ')
          ..write('remarks: $remarks, ')
          ..write('returnRemark: $returnRemark, ')
          ..write('createdBy: $createdBy, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hashAll([
    clientUuid,
    serverId,
    projectId,
    boqItemId,
    workOrderId,
    measuredOn,
    description,
    location,
    nos,
    length,
    breadth,
    depth,
    isDeduction,
    qty,
    serverQty,
    status,
    deviation,
    remarks,
    returnRemark,
    createdBy,
    clientUpdatedAt,
    updatedAt,
  ]);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is MeasurementRow &&
          other.clientUuid == this.clientUuid &&
          other.serverId == this.serverId &&
          other.projectId == this.projectId &&
          other.boqItemId == this.boqItemId &&
          other.workOrderId == this.workOrderId &&
          other.measuredOn == this.measuredOn &&
          other.description == this.description &&
          other.location == this.location &&
          other.nos == this.nos &&
          other.length == this.length &&
          other.breadth == this.breadth &&
          other.depth == this.depth &&
          other.isDeduction == this.isDeduction &&
          other.qty == this.qty &&
          other.serverQty == this.serverQty &&
          other.status == this.status &&
          other.deviation == this.deviation &&
          other.remarks == this.remarks &&
          other.returnRemark == this.returnRemark &&
          other.createdBy == this.createdBy &&
          other.clientUpdatedAt == this.clientUpdatedAt &&
          other.updatedAt == this.updatedAt);
}

class MeasurementsCompanion extends UpdateCompanion<MeasurementRow> {
  final Value<String> clientUuid;
  final Value<int?> serverId;
  final Value<int> projectId;
  final Value<int> boqItemId;
  final Value<int?> workOrderId;
  final Value<String> measuredOn;
  final Value<String?> description;
  final Value<String?> location;
  final Value<Qty?> nos;
  final Value<Qty?> length;
  final Value<Qty?> breadth;
  final Value<Qty?> depth;
  final Value<bool> isDeduction;
  final Value<Qty> qty;
  final Value<Qty?> serverQty;
  final Value<String> status;
  final Value<bool> deviation;
  final Value<String?> remarks;
  final Value<String?> returnRemark;
  final Value<int?> createdBy;
  final Value<DateTime?> clientUpdatedAt;
  final Value<DateTime?> updatedAt;
  final Value<int> rowid;
  const MeasurementsCompanion({
    this.clientUuid = const Value.absent(),
    this.serverId = const Value.absent(),
    this.projectId = const Value.absent(),
    this.boqItemId = const Value.absent(),
    this.workOrderId = const Value.absent(),
    this.measuredOn = const Value.absent(),
    this.description = const Value.absent(),
    this.location = const Value.absent(),
    this.nos = const Value.absent(),
    this.length = const Value.absent(),
    this.breadth = const Value.absent(),
    this.depth = const Value.absent(),
    this.isDeduction = const Value.absent(),
    this.qty = const Value.absent(),
    this.serverQty = const Value.absent(),
    this.status = const Value.absent(),
    this.deviation = const Value.absent(),
    this.remarks = const Value.absent(),
    this.returnRemark = const Value.absent(),
    this.createdBy = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  MeasurementsCompanion.insert({
    required String clientUuid,
    this.serverId = const Value.absent(),
    required int projectId,
    required int boqItemId,
    this.workOrderId = const Value.absent(),
    required String measuredOn,
    this.description = const Value.absent(),
    this.location = const Value.absent(),
    this.nos = const Value.absent(),
    this.length = const Value.absent(),
    this.breadth = const Value.absent(),
    this.depth = const Value.absent(),
    this.isDeduction = const Value.absent(),
    required Qty qty,
    this.serverQty = const Value.absent(),
    this.status = const Value.absent(),
    this.deviation = const Value.absent(),
    this.remarks = const Value.absent(),
    this.returnRemark = const Value.absent(),
    this.createdBy = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : clientUuid = Value(clientUuid),
       projectId = Value(projectId),
       boqItemId = Value(boqItemId),
       measuredOn = Value(measuredOn),
       qty = Value(qty);
  static Insertable<MeasurementRow> custom({
    Expression<String>? clientUuid,
    Expression<int>? serverId,
    Expression<int>? projectId,
    Expression<int>? boqItemId,
    Expression<int>? workOrderId,
    Expression<String>? measuredOn,
    Expression<String>? description,
    Expression<String>? location,
    Expression<String>? nos,
    Expression<String>? length,
    Expression<String>? breadth,
    Expression<String>? depth,
    Expression<bool>? isDeduction,
    Expression<String>? qty,
    Expression<String>? serverQty,
    Expression<String>? status,
    Expression<bool>? deviation,
    Expression<String>? remarks,
    Expression<String>? returnRemark,
    Expression<int>? createdBy,
    Expression<DateTime>? clientUpdatedAt,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clientUuid != null) 'client_uuid': clientUuid,
      if (serverId != null) 'server_id': serverId,
      if (projectId != null) 'project_id': projectId,
      if (boqItemId != null) 'boq_item_id': boqItemId,
      if (workOrderId != null) 'work_order_id': workOrderId,
      if (measuredOn != null) 'measured_on': measuredOn,
      if (description != null) 'description': description,
      if (location != null) 'location': location,
      if (nos != null) 'nos': nos,
      if (length != null) 'length': length,
      if (breadth != null) 'breadth': breadth,
      if (depth != null) 'depth': depth,
      if (isDeduction != null) 'is_deduction': isDeduction,
      if (qty != null) 'qty': qty,
      if (serverQty != null) 'server_qty': serverQty,
      if (status != null) 'status': status,
      if (deviation != null) 'deviation': deviation,
      if (remarks != null) 'remarks': remarks,
      if (returnRemark != null) 'return_remark': returnRemark,
      if (createdBy != null) 'created_by': createdBy,
      if (clientUpdatedAt != null) 'client_updated_at': clientUpdatedAt,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  MeasurementsCompanion copyWith({
    Value<String>? clientUuid,
    Value<int?>? serverId,
    Value<int>? projectId,
    Value<int>? boqItemId,
    Value<int?>? workOrderId,
    Value<String>? measuredOn,
    Value<String?>? description,
    Value<String?>? location,
    Value<Qty?>? nos,
    Value<Qty?>? length,
    Value<Qty?>? breadth,
    Value<Qty?>? depth,
    Value<bool>? isDeduction,
    Value<Qty>? qty,
    Value<Qty?>? serverQty,
    Value<String>? status,
    Value<bool>? deviation,
    Value<String?>? remarks,
    Value<String?>? returnRemark,
    Value<int?>? createdBy,
    Value<DateTime?>? clientUpdatedAt,
    Value<DateTime?>? updatedAt,
    Value<int>? rowid,
  }) {
    return MeasurementsCompanion(
      clientUuid: clientUuid ?? this.clientUuid,
      serverId: serverId ?? this.serverId,
      projectId: projectId ?? this.projectId,
      boqItemId: boqItemId ?? this.boqItemId,
      workOrderId: workOrderId ?? this.workOrderId,
      measuredOn: measuredOn ?? this.measuredOn,
      description: description ?? this.description,
      location: location ?? this.location,
      nos: nos ?? this.nos,
      length: length ?? this.length,
      breadth: breadth ?? this.breadth,
      depth: depth ?? this.depth,
      isDeduction: isDeduction ?? this.isDeduction,
      qty: qty ?? this.qty,
      serverQty: serverQty ?? this.serverQty,
      status: status ?? this.status,
      deviation: deviation ?? this.deviation,
      remarks: remarks ?? this.remarks,
      returnRemark: returnRemark ?? this.returnRemark,
      createdBy: createdBy ?? this.createdBy,
      clientUpdatedAt: clientUpdatedAt ?? this.clientUpdatedAt,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clientUuid.present) {
      map['client_uuid'] = Variable<String>(clientUuid.value);
    }
    if (serverId.present) {
      map['server_id'] = Variable<int>(serverId.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (boqItemId.present) {
      map['boq_item_id'] = Variable<int>(boqItemId.value);
    }
    if (workOrderId.present) {
      map['work_order_id'] = Variable<int>(workOrderId.value);
    }
    if (measuredOn.present) {
      map['measured_on'] = Variable<String>(measuredOn.value);
    }
    if (description.present) {
      map['description'] = Variable<String>(description.value);
    }
    if (location.present) {
      map['location'] = Variable<String>(location.value);
    }
    if (nos.present) {
      map['nos'] = Variable<String>(
        $MeasurementsTable.$converternosn.toSql(nos.value),
      );
    }
    if (length.present) {
      map['length'] = Variable<String>(
        $MeasurementsTable.$converterlengthn.toSql(length.value),
      );
    }
    if (breadth.present) {
      map['breadth'] = Variable<String>(
        $MeasurementsTable.$converterbreadthn.toSql(breadth.value),
      );
    }
    if (depth.present) {
      map['depth'] = Variable<String>(
        $MeasurementsTable.$converterdepthn.toSql(depth.value),
      );
    }
    if (isDeduction.present) {
      map['is_deduction'] = Variable<bool>(isDeduction.value);
    }
    if (qty.present) {
      map['qty'] = Variable<String>(
        $MeasurementsTable.$converterqty.toSql(qty.value),
      );
    }
    if (serverQty.present) {
      map['server_qty'] = Variable<String>(
        $MeasurementsTable.$converterserverQtyn.toSql(serverQty.value),
      );
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (deviation.present) {
      map['deviation'] = Variable<bool>(deviation.value);
    }
    if (remarks.present) {
      map['remarks'] = Variable<String>(remarks.value);
    }
    if (returnRemark.present) {
      map['return_remark'] = Variable<String>(returnRemark.value);
    }
    if (createdBy.present) {
      map['created_by'] = Variable<int>(createdBy.value);
    }
    if (clientUpdatedAt.present) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('MeasurementsCompanion(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('boqItemId: $boqItemId, ')
          ..write('workOrderId: $workOrderId, ')
          ..write('measuredOn: $measuredOn, ')
          ..write('description: $description, ')
          ..write('location: $location, ')
          ..write('nos: $nos, ')
          ..write('length: $length, ')
          ..write('breadth: $breadth, ')
          ..write('depth: $depth, ')
          ..write('isDeduction: $isDeduction, ')
          ..write('qty: $qty, ')
          ..write('serverQty: $serverQty, ')
          ..write('status: $status, ')
          ..write('deviation: $deviation, ')
          ..write('remarks: $remarks, ')
          ..write('returnRemark: $returnRemark, ')
          ..write('createdBy: $createdBy, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $IssuesTable extends Issues with TableInfo<$IssuesTable, IssueRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $IssuesTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _clientUuidMeta = const VerificationMeta(
    'clientUuid',
  );
  @override
  late final GeneratedColumn<String> clientUuid = GeneratedColumn<String>(
    'client_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _serverIdMeta = const VerificationMeta(
    'serverId',
  );
  @override
  late final GeneratedColumn<int> serverId = GeneratedColumn<int>(
    'server_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _siteIdMeta = const VerificationMeta('siteId');
  @override
  late final GeneratedColumn<int> siteId = GeneratedColumn<int>(
    'site_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _titleMeta = const VerificationMeta('title');
  @override
  late final GeneratedColumn<String> title = GeneratedColumn<String>(
    'title',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _descriptionMeta = const VerificationMeta(
    'description',
  );
  @override
  late final GeneratedColumn<String> description = GeneratedColumn<String>(
    'description',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _priorityMeta = const VerificationMeta(
    'priority',
  );
  @override
  late final GeneratedColumn<String> priority = GeneratedColumn<String>(
    'priority',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('medium'),
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('open'),
  );
  static const VerificationMeta _locationNoteMeta = const VerificationMeta(
    'locationNote',
  );
  @override
  late final GeneratedColumn<String> locationNote = GeneratedColumn<String>(
    'location_note',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _assignedToMeta = const VerificationMeta(
    'assignedTo',
  );
  @override
  late final GeneratedColumn<int> assignedTo = GeneratedColumn<int>(
    'assigned_to',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _raisedByMeta = const VerificationMeta(
    'raisedBy',
  );
  @override
  late final GeneratedColumn<int> raisedBy = GeneratedColumn<int>(
    'raised_by',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _dueDateMeta = const VerificationMeta(
    'dueDate',
  );
  @override
  late final GeneratedColumn<String> dueDate = GeneratedColumn<String>(
    'due_date',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _resolvedAtMeta = const VerificationMeta(
    'resolvedAt',
  );
  @override
  late final GeneratedColumn<DateTime> resolvedAt = GeneratedColumn<DateTime>(
    'resolved_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _clientUpdatedAtMeta = const VerificationMeta(
    'clientUpdatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> clientUpdatedAt =
      GeneratedColumn<DateTime>(
        'client_updated_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    clientUuid,
    serverId,
    projectId,
    siteId,
    title,
    description,
    priority,
    status,
    locationNote,
    assignedTo,
    raisedBy,
    dueDate,
    resolvedAt,
    clientUpdatedAt,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'issues';
  @override
  VerificationContext validateIntegrity(
    Insertable<IssueRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('client_uuid')) {
      context.handle(
        _clientUuidMeta,
        clientUuid.isAcceptableOrUnknown(data['client_uuid']!, _clientUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_clientUuidMeta);
    }
    if (data.containsKey('server_id')) {
      context.handle(
        _serverIdMeta,
        serverId.isAcceptableOrUnknown(data['server_id']!, _serverIdMeta),
      );
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('site_id')) {
      context.handle(
        _siteIdMeta,
        siteId.isAcceptableOrUnknown(data['site_id']!, _siteIdMeta),
      );
    }
    if (data.containsKey('title')) {
      context.handle(
        _titleMeta,
        title.isAcceptableOrUnknown(data['title']!, _titleMeta),
      );
    } else if (isInserting) {
      context.missing(_titleMeta);
    }
    if (data.containsKey('description')) {
      context.handle(
        _descriptionMeta,
        description.isAcceptableOrUnknown(
          data['description']!,
          _descriptionMeta,
        ),
      );
    }
    if (data.containsKey('priority')) {
      context.handle(
        _priorityMeta,
        priority.isAcceptableOrUnknown(data['priority']!, _priorityMeta),
      );
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    if (data.containsKey('location_note')) {
      context.handle(
        _locationNoteMeta,
        locationNote.isAcceptableOrUnknown(
          data['location_note']!,
          _locationNoteMeta,
        ),
      );
    }
    if (data.containsKey('assigned_to')) {
      context.handle(
        _assignedToMeta,
        assignedTo.isAcceptableOrUnknown(data['assigned_to']!, _assignedToMeta),
      );
    }
    if (data.containsKey('raised_by')) {
      context.handle(
        _raisedByMeta,
        raisedBy.isAcceptableOrUnknown(data['raised_by']!, _raisedByMeta),
      );
    }
    if (data.containsKey('due_date')) {
      context.handle(
        _dueDateMeta,
        dueDate.isAcceptableOrUnknown(data['due_date']!, _dueDateMeta),
      );
    }
    if (data.containsKey('resolved_at')) {
      context.handle(
        _resolvedAtMeta,
        resolvedAt.isAcceptableOrUnknown(data['resolved_at']!, _resolvedAtMeta),
      );
    }
    if (data.containsKey('client_updated_at')) {
      context.handle(
        _clientUpdatedAtMeta,
        clientUpdatedAt.isAcceptableOrUnknown(
          data['client_updated_at']!,
          _clientUpdatedAtMeta,
        ),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clientUuid};
  @override
  IssueRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return IssueRow(
      clientUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}client_uuid'],
      )!,
      serverId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}server_id'],
      ),
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      siteId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}site_id'],
      ),
      title: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}title'],
      )!,
      description: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}description'],
      ),
      priority: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}priority'],
      )!,
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      locationNote: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}location_note'],
      ),
      assignedTo: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}assigned_to'],
      ),
      raisedBy: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}raised_by'],
      ),
      dueDate: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}due_date'],
      ),
      resolvedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}resolved_at'],
      ),
      clientUpdatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}client_updated_at'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $IssuesTable createAlias(String alias) {
    return $IssuesTable(attachedDatabase, alias);
  }
}

class IssueRow extends DataClass implements Insertable<IssueRow> {
  final String clientUuid;
  final int? serverId;
  final int projectId;
  final int? siteId;
  final String title;
  final String? description;
  final String priority;
  final String status;
  final String? locationNote;
  final int? assignedTo;
  final int? raisedBy;
  final String? dueDate;
  final DateTime? resolvedAt;
  final DateTime? clientUpdatedAt;
  final DateTime? updatedAt;
  const IssueRow({
    required this.clientUuid,
    this.serverId,
    required this.projectId,
    this.siteId,
    required this.title,
    this.description,
    required this.priority,
    required this.status,
    this.locationNote,
    this.assignedTo,
    this.raisedBy,
    this.dueDate,
    this.resolvedAt,
    this.clientUpdatedAt,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['client_uuid'] = Variable<String>(clientUuid);
    if (!nullToAbsent || serverId != null) {
      map['server_id'] = Variable<int>(serverId);
    }
    map['project_id'] = Variable<int>(projectId);
    if (!nullToAbsent || siteId != null) {
      map['site_id'] = Variable<int>(siteId);
    }
    map['title'] = Variable<String>(title);
    if (!nullToAbsent || description != null) {
      map['description'] = Variable<String>(description);
    }
    map['priority'] = Variable<String>(priority);
    map['status'] = Variable<String>(status);
    if (!nullToAbsent || locationNote != null) {
      map['location_note'] = Variable<String>(locationNote);
    }
    if (!nullToAbsent || assignedTo != null) {
      map['assigned_to'] = Variable<int>(assignedTo);
    }
    if (!nullToAbsent || raisedBy != null) {
      map['raised_by'] = Variable<int>(raisedBy);
    }
    if (!nullToAbsent || dueDate != null) {
      map['due_date'] = Variable<String>(dueDate);
    }
    if (!nullToAbsent || resolvedAt != null) {
      map['resolved_at'] = Variable<DateTime>(resolvedAt);
    }
    if (!nullToAbsent || clientUpdatedAt != null) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt);
    }
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  IssuesCompanion toCompanion(bool nullToAbsent) {
    return IssuesCompanion(
      clientUuid: Value(clientUuid),
      serverId: serverId == null && nullToAbsent
          ? const Value.absent()
          : Value(serverId),
      projectId: Value(projectId),
      siteId: siteId == null && nullToAbsent
          ? const Value.absent()
          : Value(siteId),
      title: Value(title),
      description: description == null && nullToAbsent
          ? const Value.absent()
          : Value(description),
      priority: Value(priority),
      status: Value(status),
      locationNote: locationNote == null && nullToAbsent
          ? const Value.absent()
          : Value(locationNote),
      assignedTo: assignedTo == null && nullToAbsent
          ? const Value.absent()
          : Value(assignedTo),
      raisedBy: raisedBy == null && nullToAbsent
          ? const Value.absent()
          : Value(raisedBy),
      dueDate: dueDate == null && nullToAbsent
          ? const Value.absent()
          : Value(dueDate),
      resolvedAt: resolvedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(resolvedAt),
      clientUpdatedAt: clientUpdatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(clientUpdatedAt),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory IssueRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return IssueRow(
      clientUuid: serializer.fromJson<String>(json['clientUuid']),
      serverId: serializer.fromJson<int?>(json['serverId']),
      projectId: serializer.fromJson<int>(json['projectId']),
      siteId: serializer.fromJson<int?>(json['siteId']),
      title: serializer.fromJson<String>(json['title']),
      description: serializer.fromJson<String?>(json['description']),
      priority: serializer.fromJson<String>(json['priority']),
      status: serializer.fromJson<String>(json['status']),
      locationNote: serializer.fromJson<String?>(json['locationNote']),
      assignedTo: serializer.fromJson<int?>(json['assignedTo']),
      raisedBy: serializer.fromJson<int?>(json['raisedBy']),
      dueDate: serializer.fromJson<String?>(json['dueDate']),
      resolvedAt: serializer.fromJson<DateTime?>(json['resolvedAt']),
      clientUpdatedAt: serializer.fromJson<DateTime?>(json['clientUpdatedAt']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clientUuid': serializer.toJson<String>(clientUuid),
      'serverId': serializer.toJson<int?>(serverId),
      'projectId': serializer.toJson<int>(projectId),
      'siteId': serializer.toJson<int?>(siteId),
      'title': serializer.toJson<String>(title),
      'description': serializer.toJson<String?>(description),
      'priority': serializer.toJson<String>(priority),
      'status': serializer.toJson<String>(status),
      'locationNote': serializer.toJson<String?>(locationNote),
      'assignedTo': serializer.toJson<int?>(assignedTo),
      'raisedBy': serializer.toJson<int?>(raisedBy),
      'dueDate': serializer.toJson<String?>(dueDate),
      'resolvedAt': serializer.toJson<DateTime?>(resolvedAt),
      'clientUpdatedAt': serializer.toJson<DateTime?>(clientUpdatedAt),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  IssueRow copyWith({
    String? clientUuid,
    Value<int?> serverId = const Value.absent(),
    int? projectId,
    Value<int?> siteId = const Value.absent(),
    String? title,
    Value<String?> description = const Value.absent(),
    String? priority,
    String? status,
    Value<String?> locationNote = const Value.absent(),
    Value<int?> assignedTo = const Value.absent(),
    Value<int?> raisedBy = const Value.absent(),
    Value<String?> dueDate = const Value.absent(),
    Value<DateTime?> resolvedAt = const Value.absent(),
    Value<DateTime?> clientUpdatedAt = const Value.absent(),
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => IssueRow(
    clientUuid: clientUuid ?? this.clientUuid,
    serverId: serverId.present ? serverId.value : this.serverId,
    projectId: projectId ?? this.projectId,
    siteId: siteId.present ? siteId.value : this.siteId,
    title: title ?? this.title,
    description: description.present ? description.value : this.description,
    priority: priority ?? this.priority,
    status: status ?? this.status,
    locationNote: locationNote.present ? locationNote.value : this.locationNote,
    assignedTo: assignedTo.present ? assignedTo.value : this.assignedTo,
    raisedBy: raisedBy.present ? raisedBy.value : this.raisedBy,
    dueDate: dueDate.present ? dueDate.value : this.dueDate,
    resolvedAt: resolvedAt.present ? resolvedAt.value : this.resolvedAt,
    clientUpdatedAt: clientUpdatedAt.present
        ? clientUpdatedAt.value
        : this.clientUpdatedAt,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  IssueRow copyWithCompanion(IssuesCompanion data) {
    return IssueRow(
      clientUuid: data.clientUuid.present
          ? data.clientUuid.value
          : this.clientUuid,
      serverId: data.serverId.present ? data.serverId.value : this.serverId,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      siteId: data.siteId.present ? data.siteId.value : this.siteId,
      title: data.title.present ? data.title.value : this.title,
      description: data.description.present
          ? data.description.value
          : this.description,
      priority: data.priority.present ? data.priority.value : this.priority,
      status: data.status.present ? data.status.value : this.status,
      locationNote: data.locationNote.present
          ? data.locationNote.value
          : this.locationNote,
      assignedTo: data.assignedTo.present
          ? data.assignedTo.value
          : this.assignedTo,
      raisedBy: data.raisedBy.present ? data.raisedBy.value : this.raisedBy,
      dueDate: data.dueDate.present ? data.dueDate.value : this.dueDate,
      resolvedAt: data.resolvedAt.present
          ? data.resolvedAt.value
          : this.resolvedAt,
      clientUpdatedAt: data.clientUpdatedAt.present
          ? data.clientUpdatedAt.value
          : this.clientUpdatedAt,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('IssueRow(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('siteId: $siteId, ')
          ..write('title: $title, ')
          ..write('description: $description, ')
          ..write('priority: $priority, ')
          ..write('status: $status, ')
          ..write('locationNote: $locationNote, ')
          ..write('assignedTo: $assignedTo, ')
          ..write('raisedBy: $raisedBy, ')
          ..write('dueDate: $dueDate, ')
          ..write('resolvedAt: $resolvedAt, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    clientUuid,
    serverId,
    projectId,
    siteId,
    title,
    description,
    priority,
    status,
    locationNote,
    assignedTo,
    raisedBy,
    dueDate,
    resolvedAt,
    clientUpdatedAt,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is IssueRow &&
          other.clientUuid == this.clientUuid &&
          other.serverId == this.serverId &&
          other.projectId == this.projectId &&
          other.siteId == this.siteId &&
          other.title == this.title &&
          other.description == this.description &&
          other.priority == this.priority &&
          other.status == this.status &&
          other.locationNote == this.locationNote &&
          other.assignedTo == this.assignedTo &&
          other.raisedBy == this.raisedBy &&
          other.dueDate == this.dueDate &&
          other.resolvedAt == this.resolvedAt &&
          other.clientUpdatedAt == this.clientUpdatedAt &&
          other.updatedAt == this.updatedAt);
}

class IssuesCompanion extends UpdateCompanion<IssueRow> {
  final Value<String> clientUuid;
  final Value<int?> serverId;
  final Value<int> projectId;
  final Value<int?> siteId;
  final Value<String> title;
  final Value<String?> description;
  final Value<String> priority;
  final Value<String> status;
  final Value<String?> locationNote;
  final Value<int?> assignedTo;
  final Value<int?> raisedBy;
  final Value<String?> dueDate;
  final Value<DateTime?> resolvedAt;
  final Value<DateTime?> clientUpdatedAt;
  final Value<DateTime?> updatedAt;
  final Value<int> rowid;
  const IssuesCompanion({
    this.clientUuid = const Value.absent(),
    this.serverId = const Value.absent(),
    this.projectId = const Value.absent(),
    this.siteId = const Value.absent(),
    this.title = const Value.absent(),
    this.description = const Value.absent(),
    this.priority = const Value.absent(),
    this.status = const Value.absent(),
    this.locationNote = const Value.absent(),
    this.assignedTo = const Value.absent(),
    this.raisedBy = const Value.absent(),
    this.dueDate = const Value.absent(),
    this.resolvedAt = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  IssuesCompanion.insert({
    required String clientUuid,
    this.serverId = const Value.absent(),
    required int projectId,
    this.siteId = const Value.absent(),
    required String title,
    this.description = const Value.absent(),
    this.priority = const Value.absent(),
    this.status = const Value.absent(),
    this.locationNote = const Value.absent(),
    this.assignedTo = const Value.absent(),
    this.raisedBy = const Value.absent(),
    this.dueDate = const Value.absent(),
    this.resolvedAt = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : clientUuid = Value(clientUuid),
       projectId = Value(projectId),
       title = Value(title);
  static Insertable<IssueRow> custom({
    Expression<String>? clientUuid,
    Expression<int>? serverId,
    Expression<int>? projectId,
    Expression<int>? siteId,
    Expression<String>? title,
    Expression<String>? description,
    Expression<String>? priority,
    Expression<String>? status,
    Expression<String>? locationNote,
    Expression<int>? assignedTo,
    Expression<int>? raisedBy,
    Expression<String>? dueDate,
    Expression<DateTime>? resolvedAt,
    Expression<DateTime>? clientUpdatedAt,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clientUuid != null) 'client_uuid': clientUuid,
      if (serverId != null) 'server_id': serverId,
      if (projectId != null) 'project_id': projectId,
      if (siteId != null) 'site_id': siteId,
      if (title != null) 'title': title,
      if (description != null) 'description': description,
      if (priority != null) 'priority': priority,
      if (status != null) 'status': status,
      if (locationNote != null) 'location_note': locationNote,
      if (assignedTo != null) 'assigned_to': assignedTo,
      if (raisedBy != null) 'raised_by': raisedBy,
      if (dueDate != null) 'due_date': dueDate,
      if (resolvedAt != null) 'resolved_at': resolvedAt,
      if (clientUpdatedAt != null) 'client_updated_at': clientUpdatedAt,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  IssuesCompanion copyWith({
    Value<String>? clientUuid,
    Value<int?>? serverId,
    Value<int>? projectId,
    Value<int?>? siteId,
    Value<String>? title,
    Value<String?>? description,
    Value<String>? priority,
    Value<String>? status,
    Value<String?>? locationNote,
    Value<int?>? assignedTo,
    Value<int?>? raisedBy,
    Value<String?>? dueDate,
    Value<DateTime?>? resolvedAt,
    Value<DateTime?>? clientUpdatedAt,
    Value<DateTime?>? updatedAt,
    Value<int>? rowid,
  }) {
    return IssuesCompanion(
      clientUuid: clientUuid ?? this.clientUuid,
      serverId: serverId ?? this.serverId,
      projectId: projectId ?? this.projectId,
      siteId: siteId ?? this.siteId,
      title: title ?? this.title,
      description: description ?? this.description,
      priority: priority ?? this.priority,
      status: status ?? this.status,
      locationNote: locationNote ?? this.locationNote,
      assignedTo: assignedTo ?? this.assignedTo,
      raisedBy: raisedBy ?? this.raisedBy,
      dueDate: dueDate ?? this.dueDate,
      resolvedAt: resolvedAt ?? this.resolvedAt,
      clientUpdatedAt: clientUpdatedAt ?? this.clientUpdatedAt,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clientUuid.present) {
      map['client_uuid'] = Variable<String>(clientUuid.value);
    }
    if (serverId.present) {
      map['server_id'] = Variable<int>(serverId.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (siteId.present) {
      map['site_id'] = Variable<int>(siteId.value);
    }
    if (title.present) {
      map['title'] = Variable<String>(title.value);
    }
    if (description.present) {
      map['description'] = Variable<String>(description.value);
    }
    if (priority.present) {
      map['priority'] = Variable<String>(priority.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (locationNote.present) {
      map['location_note'] = Variable<String>(locationNote.value);
    }
    if (assignedTo.present) {
      map['assigned_to'] = Variable<int>(assignedTo.value);
    }
    if (raisedBy.present) {
      map['raised_by'] = Variable<int>(raisedBy.value);
    }
    if (dueDate.present) {
      map['due_date'] = Variable<String>(dueDate.value);
    }
    if (resolvedAt.present) {
      map['resolved_at'] = Variable<DateTime>(resolvedAt.value);
    }
    if (clientUpdatedAt.present) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('IssuesCompanion(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('siteId: $siteId, ')
          ..write('title: $title, ')
          ..write('description: $description, ')
          ..write('priority: $priority, ')
          ..write('status: $status, ')
          ..write('locationNote: $locationNote, ')
          ..write('assignedTo: $assignedTo, ')
          ..write('raisedBy: $raisedBy, ')
          ..write('dueDate: $dueDate, ')
          ..write('resolvedAt: $resolvedAt, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $AttendanceMarksTable extends AttendanceMarks
    with TableInfo<$AttendanceMarksTable, AttendanceMarkRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $AttendanceMarksTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _clientUuidMeta = const VerificationMeta(
    'clientUuid',
  );
  @override
  late final GeneratedColumn<String> clientUuid = GeneratedColumn<String>(
    'client_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _serverIdMeta = const VerificationMeta(
    'serverId',
  );
  @override
  late final GeneratedColumn<int> serverId = GeneratedColumn<int>(
    'server_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _workerIdMeta = const VerificationMeta(
    'workerId',
  );
  @override
  late final GeneratedColumn<int> workerId = GeneratedColumn<int>(
    'worker_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _attendanceDateMeta = const VerificationMeta(
    'attendanceDate',
  );
  @override
  late final GeneratedColumn<String> attendanceDate = GeneratedColumn<String>(
    'attendance_date',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  late final GeneratedColumnWithTypeConverter<Hours, String> otHours =
      GeneratedColumn<String>(
        'ot_hours',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Hours>($AttendanceMarksTable.$converterotHours);
  static const VerificationMeta _markedByMeta = const VerificationMeta(
    'markedBy',
  );
  @override
  late final GeneratedColumn<int> markedBy = GeneratedColumn<int>(
    'marked_by',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _clientUpdatedAtMeta = const VerificationMeta(
    'clientUpdatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> clientUpdatedAt =
      GeneratedColumn<DateTime>(
        'client_updated_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    clientUuid,
    serverId,
    projectId,
    workerId,
    attendanceDate,
    status,
    otHours,
    markedBy,
    clientUpdatedAt,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'attendance_marks';
  @override
  VerificationContext validateIntegrity(
    Insertable<AttendanceMarkRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('client_uuid')) {
      context.handle(
        _clientUuidMeta,
        clientUuid.isAcceptableOrUnknown(data['client_uuid']!, _clientUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_clientUuidMeta);
    }
    if (data.containsKey('server_id')) {
      context.handle(
        _serverIdMeta,
        serverId.isAcceptableOrUnknown(data['server_id']!, _serverIdMeta),
      );
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('worker_id')) {
      context.handle(
        _workerIdMeta,
        workerId.isAcceptableOrUnknown(data['worker_id']!, _workerIdMeta),
      );
    } else if (isInserting) {
      context.missing(_workerIdMeta);
    }
    if (data.containsKey('attendance_date')) {
      context.handle(
        _attendanceDateMeta,
        attendanceDate.isAcceptableOrUnknown(
          data['attendance_date']!,
          _attendanceDateMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_attendanceDateMeta);
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    } else if (isInserting) {
      context.missing(_statusMeta);
    }
    if (data.containsKey('marked_by')) {
      context.handle(
        _markedByMeta,
        markedBy.isAcceptableOrUnknown(data['marked_by']!, _markedByMeta),
      );
    }
    if (data.containsKey('client_updated_at')) {
      context.handle(
        _clientUpdatedAtMeta,
        clientUpdatedAt.isAcceptableOrUnknown(
          data['client_updated_at']!,
          _clientUpdatedAtMeta,
        ),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clientUuid};
  @override
  List<Set<GeneratedColumn>> get uniqueKeys => [
    {projectId, workerId, attendanceDate},
  ];
  @override
  AttendanceMarkRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return AttendanceMarkRow(
      clientUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}client_uuid'],
      )!,
      serverId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}server_id'],
      ),
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      workerId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}worker_id'],
      )!,
      attendanceDate: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}attendance_date'],
      )!,
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      otHours: $AttendanceMarksTable.$converterotHours.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}ot_hours'],
        )!,
      ),
      markedBy: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}marked_by'],
      ),
      clientUpdatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}client_updated_at'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $AttendanceMarksTable createAlias(String alias) {
    return $AttendanceMarksTable(attachedDatabase, alias);
  }

  static TypeConverter<Hours, String> $converterotHours =
      const HoursConverter();
}

class AttendanceMarkRow extends DataClass
    implements Insertable<AttendanceMarkRow> {
  final String clientUuid;
  final int? serverId;
  final int projectId;
  final int workerId;
  final String attendanceDate;
  final String status;
  final Hours otHours;
  final int? markedBy;
  final DateTime? clientUpdatedAt;
  final DateTime? updatedAt;
  const AttendanceMarkRow({
    required this.clientUuid,
    this.serverId,
    required this.projectId,
    required this.workerId,
    required this.attendanceDate,
    required this.status,
    required this.otHours,
    this.markedBy,
    this.clientUpdatedAt,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['client_uuid'] = Variable<String>(clientUuid);
    if (!nullToAbsent || serverId != null) {
      map['server_id'] = Variable<int>(serverId);
    }
    map['project_id'] = Variable<int>(projectId);
    map['worker_id'] = Variable<int>(workerId);
    map['attendance_date'] = Variable<String>(attendanceDate);
    map['status'] = Variable<String>(status);
    {
      map['ot_hours'] = Variable<String>(
        $AttendanceMarksTable.$converterotHours.toSql(otHours),
      );
    }
    if (!nullToAbsent || markedBy != null) {
      map['marked_by'] = Variable<int>(markedBy);
    }
    if (!nullToAbsent || clientUpdatedAt != null) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt);
    }
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  AttendanceMarksCompanion toCompanion(bool nullToAbsent) {
    return AttendanceMarksCompanion(
      clientUuid: Value(clientUuid),
      serverId: serverId == null && nullToAbsent
          ? const Value.absent()
          : Value(serverId),
      projectId: Value(projectId),
      workerId: Value(workerId),
      attendanceDate: Value(attendanceDate),
      status: Value(status),
      otHours: Value(otHours),
      markedBy: markedBy == null && nullToAbsent
          ? const Value.absent()
          : Value(markedBy),
      clientUpdatedAt: clientUpdatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(clientUpdatedAt),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory AttendanceMarkRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return AttendanceMarkRow(
      clientUuid: serializer.fromJson<String>(json['clientUuid']),
      serverId: serializer.fromJson<int?>(json['serverId']),
      projectId: serializer.fromJson<int>(json['projectId']),
      workerId: serializer.fromJson<int>(json['workerId']),
      attendanceDate: serializer.fromJson<String>(json['attendanceDate']),
      status: serializer.fromJson<String>(json['status']),
      otHours: serializer.fromJson<Hours>(json['otHours']),
      markedBy: serializer.fromJson<int?>(json['markedBy']),
      clientUpdatedAt: serializer.fromJson<DateTime?>(json['clientUpdatedAt']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clientUuid': serializer.toJson<String>(clientUuid),
      'serverId': serializer.toJson<int?>(serverId),
      'projectId': serializer.toJson<int>(projectId),
      'workerId': serializer.toJson<int>(workerId),
      'attendanceDate': serializer.toJson<String>(attendanceDate),
      'status': serializer.toJson<String>(status),
      'otHours': serializer.toJson<Hours>(otHours),
      'markedBy': serializer.toJson<int?>(markedBy),
      'clientUpdatedAt': serializer.toJson<DateTime?>(clientUpdatedAt),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  AttendanceMarkRow copyWith({
    String? clientUuid,
    Value<int?> serverId = const Value.absent(),
    int? projectId,
    int? workerId,
    String? attendanceDate,
    String? status,
    Hours? otHours,
    Value<int?> markedBy = const Value.absent(),
    Value<DateTime?> clientUpdatedAt = const Value.absent(),
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => AttendanceMarkRow(
    clientUuid: clientUuid ?? this.clientUuid,
    serverId: serverId.present ? serverId.value : this.serverId,
    projectId: projectId ?? this.projectId,
    workerId: workerId ?? this.workerId,
    attendanceDate: attendanceDate ?? this.attendanceDate,
    status: status ?? this.status,
    otHours: otHours ?? this.otHours,
    markedBy: markedBy.present ? markedBy.value : this.markedBy,
    clientUpdatedAt: clientUpdatedAt.present
        ? clientUpdatedAt.value
        : this.clientUpdatedAt,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  AttendanceMarkRow copyWithCompanion(AttendanceMarksCompanion data) {
    return AttendanceMarkRow(
      clientUuid: data.clientUuid.present
          ? data.clientUuid.value
          : this.clientUuid,
      serverId: data.serverId.present ? data.serverId.value : this.serverId,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      workerId: data.workerId.present ? data.workerId.value : this.workerId,
      attendanceDate: data.attendanceDate.present
          ? data.attendanceDate.value
          : this.attendanceDate,
      status: data.status.present ? data.status.value : this.status,
      otHours: data.otHours.present ? data.otHours.value : this.otHours,
      markedBy: data.markedBy.present ? data.markedBy.value : this.markedBy,
      clientUpdatedAt: data.clientUpdatedAt.present
          ? data.clientUpdatedAt.value
          : this.clientUpdatedAt,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('AttendanceMarkRow(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('workerId: $workerId, ')
          ..write('attendanceDate: $attendanceDate, ')
          ..write('status: $status, ')
          ..write('otHours: $otHours, ')
          ..write('markedBy: $markedBy, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    clientUuid,
    serverId,
    projectId,
    workerId,
    attendanceDate,
    status,
    otHours,
    markedBy,
    clientUpdatedAt,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is AttendanceMarkRow &&
          other.clientUuid == this.clientUuid &&
          other.serverId == this.serverId &&
          other.projectId == this.projectId &&
          other.workerId == this.workerId &&
          other.attendanceDate == this.attendanceDate &&
          other.status == this.status &&
          other.otHours == this.otHours &&
          other.markedBy == this.markedBy &&
          other.clientUpdatedAt == this.clientUpdatedAt &&
          other.updatedAt == this.updatedAt);
}

class AttendanceMarksCompanion extends UpdateCompanion<AttendanceMarkRow> {
  final Value<String> clientUuid;
  final Value<int?> serverId;
  final Value<int> projectId;
  final Value<int> workerId;
  final Value<String> attendanceDate;
  final Value<String> status;
  final Value<Hours> otHours;
  final Value<int?> markedBy;
  final Value<DateTime?> clientUpdatedAt;
  final Value<DateTime?> updatedAt;
  final Value<int> rowid;
  const AttendanceMarksCompanion({
    this.clientUuid = const Value.absent(),
    this.serverId = const Value.absent(),
    this.projectId = const Value.absent(),
    this.workerId = const Value.absent(),
    this.attendanceDate = const Value.absent(),
    this.status = const Value.absent(),
    this.otHours = const Value.absent(),
    this.markedBy = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  AttendanceMarksCompanion.insert({
    required String clientUuid,
    this.serverId = const Value.absent(),
    required int projectId,
    required int workerId,
    required String attendanceDate,
    required String status,
    required Hours otHours,
    this.markedBy = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : clientUuid = Value(clientUuid),
       projectId = Value(projectId),
       workerId = Value(workerId),
       attendanceDate = Value(attendanceDate),
       status = Value(status),
       otHours = Value(otHours);
  static Insertable<AttendanceMarkRow> custom({
    Expression<String>? clientUuid,
    Expression<int>? serverId,
    Expression<int>? projectId,
    Expression<int>? workerId,
    Expression<String>? attendanceDate,
    Expression<String>? status,
    Expression<String>? otHours,
    Expression<int>? markedBy,
    Expression<DateTime>? clientUpdatedAt,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clientUuid != null) 'client_uuid': clientUuid,
      if (serverId != null) 'server_id': serverId,
      if (projectId != null) 'project_id': projectId,
      if (workerId != null) 'worker_id': workerId,
      if (attendanceDate != null) 'attendance_date': attendanceDate,
      if (status != null) 'status': status,
      if (otHours != null) 'ot_hours': otHours,
      if (markedBy != null) 'marked_by': markedBy,
      if (clientUpdatedAt != null) 'client_updated_at': clientUpdatedAt,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  AttendanceMarksCompanion copyWith({
    Value<String>? clientUuid,
    Value<int?>? serverId,
    Value<int>? projectId,
    Value<int>? workerId,
    Value<String>? attendanceDate,
    Value<String>? status,
    Value<Hours>? otHours,
    Value<int?>? markedBy,
    Value<DateTime?>? clientUpdatedAt,
    Value<DateTime?>? updatedAt,
    Value<int>? rowid,
  }) {
    return AttendanceMarksCompanion(
      clientUuid: clientUuid ?? this.clientUuid,
      serverId: serverId ?? this.serverId,
      projectId: projectId ?? this.projectId,
      workerId: workerId ?? this.workerId,
      attendanceDate: attendanceDate ?? this.attendanceDate,
      status: status ?? this.status,
      otHours: otHours ?? this.otHours,
      markedBy: markedBy ?? this.markedBy,
      clientUpdatedAt: clientUpdatedAt ?? this.clientUpdatedAt,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clientUuid.present) {
      map['client_uuid'] = Variable<String>(clientUuid.value);
    }
    if (serverId.present) {
      map['server_id'] = Variable<int>(serverId.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (workerId.present) {
      map['worker_id'] = Variable<int>(workerId.value);
    }
    if (attendanceDate.present) {
      map['attendance_date'] = Variable<String>(attendanceDate.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (otHours.present) {
      map['ot_hours'] = Variable<String>(
        $AttendanceMarksTable.$converterotHours.toSql(otHours.value),
      );
    }
    if (markedBy.present) {
      map['marked_by'] = Variable<int>(markedBy.value);
    }
    if (clientUpdatedAt.present) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('AttendanceMarksCompanion(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('projectId: $projectId, ')
          ..write('workerId: $workerId, ')
          ..write('attendanceDate: $attendanceDate, ')
          ..write('status: $status, ')
          ..write('otHours: $otHours, ')
          ..write('markedBy: $markedBy, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $PettyCashEntriesTable extends PettyCashEntries
    with TableInfo<$PettyCashEntriesTable, PettyCashEntryRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $PettyCashEntriesTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _clientUuidMeta = const VerificationMeta(
    'clientUuid',
  );
  @override
  late final GeneratedColumn<String> clientUuid = GeneratedColumn<String>(
    'client_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _serverIdMeta = const VerificationMeta(
    'serverId',
  );
  @override
  late final GeneratedColumn<int> serverId = GeneratedColumn<int>(
    'server_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _walletIdMeta = const VerificationMeta(
    'walletId',
  );
  @override
  late final GeneratedColumn<int> walletId = GeneratedColumn<int>(
    'wallet_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _typeMeta = const VerificationMeta('type');
  @override
  late final GeneratedColumn<String> type = GeneratedColumn<String>(
    'type',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('expense'),
  );
  @override
  late final GeneratedColumnWithTypeConverter<Money, String> amount =
      GeneratedColumn<String>(
        'amount',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Money>($PettyCashEntriesTable.$converteramount);
  static const VerificationMeta _categoryMeta = const VerificationMeta(
    'category',
  );
  @override
  late final GeneratedColumn<String> category = GeneratedColumn<String>(
    'category',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _descriptionMeta = const VerificationMeta(
    'description',
  );
  @override
  late final GeneratedColumn<String> description = GeneratedColumn<String>(
    'description',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _entryDateMeta = const VerificationMeta(
    'entryDate',
  );
  @override
  late final GeneratedColumn<String> entryDate = GeneratedColumn<String>(
    'entry_date',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _createdByMeta = const VerificationMeta(
    'createdBy',
  );
  @override
  late final GeneratedColumn<int> createdBy = GeneratedColumn<int>(
    'created_by',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _hasBillMeta = const VerificationMeta(
    'hasBill',
  );
  @override
  late final GeneratedColumn<bool> hasBill = GeneratedColumn<bool>(
    'has_bill',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("has_bill" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _billRelativePathMeta = const VerificationMeta(
    'billRelativePath',
  );
  @override
  late final GeneratedColumn<String> billRelativePath = GeneratedColumn<String>(
    'bill_relative_path',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _countedInBalanceMeta = const VerificationMeta(
    'countedInBalance',
  );
  @override
  late final GeneratedColumn<bool> countedInBalance = GeneratedColumn<bool>(
    'counted_in_balance',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("counted_in_balance" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    clientUuid,
    serverId,
    walletId,
    projectId,
    type,
    amount,
    category,
    description,
    entryDate,
    createdBy,
    hasBill,
    billRelativePath,
    countedInBalance,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'petty_cash_entries';
  @override
  VerificationContext validateIntegrity(
    Insertable<PettyCashEntryRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('client_uuid')) {
      context.handle(
        _clientUuidMeta,
        clientUuid.isAcceptableOrUnknown(data['client_uuid']!, _clientUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_clientUuidMeta);
    }
    if (data.containsKey('server_id')) {
      context.handle(
        _serverIdMeta,
        serverId.isAcceptableOrUnknown(data['server_id']!, _serverIdMeta),
      );
    }
    if (data.containsKey('wallet_id')) {
      context.handle(
        _walletIdMeta,
        walletId.isAcceptableOrUnknown(data['wallet_id']!, _walletIdMeta),
      );
    } else if (isInserting) {
      context.missing(_walletIdMeta);
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    }
    if (data.containsKey('type')) {
      context.handle(
        _typeMeta,
        type.isAcceptableOrUnknown(data['type']!, _typeMeta),
      );
    }
    if (data.containsKey('category')) {
      context.handle(
        _categoryMeta,
        category.isAcceptableOrUnknown(data['category']!, _categoryMeta),
      );
    }
    if (data.containsKey('description')) {
      context.handle(
        _descriptionMeta,
        description.isAcceptableOrUnknown(
          data['description']!,
          _descriptionMeta,
        ),
      );
    }
    if (data.containsKey('entry_date')) {
      context.handle(
        _entryDateMeta,
        entryDate.isAcceptableOrUnknown(data['entry_date']!, _entryDateMeta),
      );
    } else if (isInserting) {
      context.missing(_entryDateMeta);
    }
    if (data.containsKey('created_by')) {
      context.handle(
        _createdByMeta,
        createdBy.isAcceptableOrUnknown(data['created_by']!, _createdByMeta),
      );
    }
    if (data.containsKey('has_bill')) {
      context.handle(
        _hasBillMeta,
        hasBill.isAcceptableOrUnknown(data['has_bill']!, _hasBillMeta),
      );
    }
    if (data.containsKey('bill_relative_path')) {
      context.handle(
        _billRelativePathMeta,
        billRelativePath.isAcceptableOrUnknown(
          data['bill_relative_path']!,
          _billRelativePathMeta,
        ),
      );
    }
    if (data.containsKey('counted_in_balance')) {
      context.handle(
        _countedInBalanceMeta,
        countedInBalance.isAcceptableOrUnknown(
          data['counted_in_balance']!,
          _countedInBalanceMeta,
        ),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clientUuid};
  @override
  PettyCashEntryRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return PettyCashEntryRow(
      clientUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}client_uuid'],
      )!,
      serverId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}server_id'],
      ),
      walletId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}wallet_id'],
      )!,
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      ),
      type: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}type'],
      )!,
      amount: $PettyCashEntriesTable.$converteramount.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}amount'],
        )!,
      ),
      category: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}category'],
      ),
      description: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}description'],
      ),
      entryDate: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}entry_date'],
      )!,
      createdBy: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_by'],
      ),
      hasBill: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}has_bill'],
      )!,
      billRelativePath: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}bill_relative_path'],
      ),
      countedInBalance: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}counted_in_balance'],
      )!,
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      ),
    );
  }

  @override
  $PettyCashEntriesTable createAlias(String alias) {
    return $PettyCashEntriesTable(attachedDatabase, alias);
  }

  static TypeConverter<Money, String> $converteramount = const MoneyConverter();
}

class PettyCashEntryRow extends DataClass
    implements Insertable<PettyCashEntryRow> {
  final String clientUuid;
  final int? serverId;
  final int walletId;
  final int? projectId;
  final String type;
  final Money amount;
  final String? category;
  final String? description;
  final String entryDate;
  final int? createdBy;
  final bool hasBill;

  /// Relative path of the bill photo while it waits for upload.
  final String? billRelativePath;

  /// True once a pull has confirmed the wallet balance includes this entry.
  final bool countedInBalance;
  final DateTime? updatedAt;
  const PettyCashEntryRow({
    required this.clientUuid,
    this.serverId,
    required this.walletId,
    this.projectId,
    required this.type,
    required this.amount,
    this.category,
    this.description,
    required this.entryDate,
    this.createdBy,
    required this.hasBill,
    this.billRelativePath,
    required this.countedInBalance,
    this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['client_uuid'] = Variable<String>(clientUuid);
    if (!nullToAbsent || serverId != null) {
      map['server_id'] = Variable<int>(serverId);
    }
    map['wallet_id'] = Variable<int>(walletId);
    if (!nullToAbsent || projectId != null) {
      map['project_id'] = Variable<int>(projectId);
    }
    map['type'] = Variable<String>(type);
    {
      map['amount'] = Variable<String>(
        $PettyCashEntriesTable.$converteramount.toSql(amount),
      );
    }
    if (!nullToAbsent || category != null) {
      map['category'] = Variable<String>(category);
    }
    if (!nullToAbsent || description != null) {
      map['description'] = Variable<String>(description);
    }
    map['entry_date'] = Variable<String>(entryDate);
    if (!nullToAbsent || createdBy != null) {
      map['created_by'] = Variable<int>(createdBy);
    }
    map['has_bill'] = Variable<bool>(hasBill);
    if (!nullToAbsent || billRelativePath != null) {
      map['bill_relative_path'] = Variable<String>(billRelativePath);
    }
    map['counted_in_balance'] = Variable<bool>(countedInBalance);
    if (!nullToAbsent || updatedAt != null) {
      map['updated_at'] = Variable<DateTime>(updatedAt);
    }
    return map;
  }

  PettyCashEntriesCompanion toCompanion(bool nullToAbsent) {
    return PettyCashEntriesCompanion(
      clientUuid: Value(clientUuid),
      serverId: serverId == null && nullToAbsent
          ? const Value.absent()
          : Value(serverId),
      walletId: Value(walletId),
      projectId: projectId == null && nullToAbsent
          ? const Value.absent()
          : Value(projectId),
      type: Value(type),
      amount: Value(amount),
      category: category == null && nullToAbsent
          ? const Value.absent()
          : Value(category),
      description: description == null && nullToAbsent
          ? const Value.absent()
          : Value(description),
      entryDate: Value(entryDate),
      createdBy: createdBy == null && nullToAbsent
          ? const Value.absent()
          : Value(createdBy),
      hasBill: Value(hasBill),
      billRelativePath: billRelativePath == null && nullToAbsent
          ? const Value.absent()
          : Value(billRelativePath),
      countedInBalance: Value(countedInBalance),
      updatedAt: updatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(updatedAt),
    );
  }

  factory PettyCashEntryRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return PettyCashEntryRow(
      clientUuid: serializer.fromJson<String>(json['clientUuid']),
      serverId: serializer.fromJson<int?>(json['serverId']),
      walletId: serializer.fromJson<int>(json['walletId']),
      projectId: serializer.fromJson<int?>(json['projectId']),
      type: serializer.fromJson<String>(json['type']),
      amount: serializer.fromJson<Money>(json['amount']),
      category: serializer.fromJson<String?>(json['category']),
      description: serializer.fromJson<String?>(json['description']),
      entryDate: serializer.fromJson<String>(json['entryDate']),
      createdBy: serializer.fromJson<int?>(json['createdBy']),
      hasBill: serializer.fromJson<bool>(json['hasBill']),
      billRelativePath: serializer.fromJson<String?>(json['billRelativePath']),
      countedInBalance: serializer.fromJson<bool>(json['countedInBalance']),
      updatedAt: serializer.fromJson<DateTime?>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clientUuid': serializer.toJson<String>(clientUuid),
      'serverId': serializer.toJson<int?>(serverId),
      'walletId': serializer.toJson<int>(walletId),
      'projectId': serializer.toJson<int?>(projectId),
      'type': serializer.toJson<String>(type),
      'amount': serializer.toJson<Money>(amount),
      'category': serializer.toJson<String?>(category),
      'description': serializer.toJson<String?>(description),
      'entryDate': serializer.toJson<String>(entryDate),
      'createdBy': serializer.toJson<int?>(createdBy),
      'hasBill': serializer.toJson<bool>(hasBill),
      'billRelativePath': serializer.toJson<String?>(billRelativePath),
      'countedInBalance': serializer.toJson<bool>(countedInBalance),
      'updatedAt': serializer.toJson<DateTime?>(updatedAt),
    };
  }

  PettyCashEntryRow copyWith({
    String? clientUuid,
    Value<int?> serverId = const Value.absent(),
    int? walletId,
    Value<int?> projectId = const Value.absent(),
    String? type,
    Money? amount,
    Value<String?> category = const Value.absent(),
    Value<String?> description = const Value.absent(),
    String? entryDate,
    Value<int?> createdBy = const Value.absent(),
    bool? hasBill,
    Value<String?> billRelativePath = const Value.absent(),
    bool? countedInBalance,
    Value<DateTime?> updatedAt = const Value.absent(),
  }) => PettyCashEntryRow(
    clientUuid: clientUuid ?? this.clientUuid,
    serverId: serverId.present ? serverId.value : this.serverId,
    walletId: walletId ?? this.walletId,
    projectId: projectId.present ? projectId.value : this.projectId,
    type: type ?? this.type,
    amount: amount ?? this.amount,
    category: category.present ? category.value : this.category,
    description: description.present ? description.value : this.description,
    entryDate: entryDate ?? this.entryDate,
    createdBy: createdBy.present ? createdBy.value : this.createdBy,
    hasBill: hasBill ?? this.hasBill,
    billRelativePath: billRelativePath.present
        ? billRelativePath.value
        : this.billRelativePath,
    countedInBalance: countedInBalance ?? this.countedInBalance,
    updatedAt: updatedAt.present ? updatedAt.value : this.updatedAt,
  );
  PettyCashEntryRow copyWithCompanion(PettyCashEntriesCompanion data) {
    return PettyCashEntryRow(
      clientUuid: data.clientUuid.present
          ? data.clientUuid.value
          : this.clientUuid,
      serverId: data.serverId.present ? data.serverId.value : this.serverId,
      walletId: data.walletId.present ? data.walletId.value : this.walletId,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      type: data.type.present ? data.type.value : this.type,
      amount: data.amount.present ? data.amount.value : this.amount,
      category: data.category.present ? data.category.value : this.category,
      description: data.description.present
          ? data.description.value
          : this.description,
      entryDate: data.entryDate.present ? data.entryDate.value : this.entryDate,
      createdBy: data.createdBy.present ? data.createdBy.value : this.createdBy,
      hasBill: data.hasBill.present ? data.hasBill.value : this.hasBill,
      billRelativePath: data.billRelativePath.present
          ? data.billRelativePath.value
          : this.billRelativePath,
      countedInBalance: data.countedInBalance.present
          ? data.countedInBalance.value
          : this.countedInBalance,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('PettyCashEntryRow(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('walletId: $walletId, ')
          ..write('projectId: $projectId, ')
          ..write('type: $type, ')
          ..write('amount: $amount, ')
          ..write('category: $category, ')
          ..write('description: $description, ')
          ..write('entryDate: $entryDate, ')
          ..write('createdBy: $createdBy, ')
          ..write('hasBill: $hasBill, ')
          ..write('billRelativePath: $billRelativePath, ')
          ..write('countedInBalance: $countedInBalance, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    clientUuid,
    serverId,
    walletId,
    projectId,
    type,
    amount,
    category,
    description,
    entryDate,
    createdBy,
    hasBill,
    billRelativePath,
    countedInBalance,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is PettyCashEntryRow &&
          other.clientUuid == this.clientUuid &&
          other.serverId == this.serverId &&
          other.walletId == this.walletId &&
          other.projectId == this.projectId &&
          other.type == this.type &&
          other.amount == this.amount &&
          other.category == this.category &&
          other.description == this.description &&
          other.entryDate == this.entryDate &&
          other.createdBy == this.createdBy &&
          other.hasBill == this.hasBill &&
          other.billRelativePath == this.billRelativePath &&
          other.countedInBalance == this.countedInBalance &&
          other.updatedAt == this.updatedAt);
}

class PettyCashEntriesCompanion extends UpdateCompanion<PettyCashEntryRow> {
  final Value<String> clientUuid;
  final Value<int?> serverId;
  final Value<int> walletId;
  final Value<int?> projectId;
  final Value<String> type;
  final Value<Money> amount;
  final Value<String?> category;
  final Value<String?> description;
  final Value<String> entryDate;
  final Value<int?> createdBy;
  final Value<bool> hasBill;
  final Value<String?> billRelativePath;
  final Value<bool> countedInBalance;
  final Value<DateTime?> updatedAt;
  final Value<int> rowid;
  const PettyCashEntriesCompanion({
    this.clientUuid = const Value.absent(),
    this.serverId = const Value.absent(),
    this.walletId = const Value.absent(),
    this.projectId = const Value.absent(),
    this.type = const Value.absent(),
    this.amount = const Value.absent(),
    this.category = const Value.absent(),
    this.description = const Value.absent(),
    this.entryDate = const Value.absent(),
    this.createdBy = const Value.absent(),
    this.hasBill = const Value.absent(),
    this.billRelativePath = const Value.absent(),
    this.countedInBalance = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  PettyCashEntriesCompanion.insert({
    required String clientUuid,
    this.serverId = const Value.absent(),
    required int walletId,
    this.projectId = const Value.absent(),
    this.type = const Value.absent(),
    required Money amount,
    this.category = const Value.absent(),
    this.description = const Value.absent(),
    required String entryDate,
    this.createdBy = const Value.absent(),
    this.hasBill = const Value.absent(),
    this.billRelativePath = const Value.absent(),
    this.countedInBalance = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : clientUuid = Value(clientUuid),
       walletId = Value(walletId),
       amount = Value(amount),
       entryDate = Value(entryDate);
  static Insertable<PettyCashEntryRow> custom({
    Expression<String>? clientUuid,
    Expression<int>? serverId,
    Expression<int>? walletId,
    Expression<int>? projectId,
    Expression<String>? type,
    Expression<String>? amount,
    Expression<String>? category,
    Expression<String>? description,
    Expression<String>? entryDate,
    Expression<int>? createdBy,
    Expression<bool>? hasBill,
    Expression<String>? billRelativePath,
    Expression<bool>? countedInBalance,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clientUuid != null) 'client_uuid': clientUuid,
      if (serverId != null) 'server_id': serverId,
      if (walletId != null) 'wallet_id': walletId,
      if (projectId != null) 'project_id': projectId,
      if (type != null) 'type': type,
      if (amount != null) 'amount': amount,
      if (category != null) 'category': category,
      if (description != null) 'description': description,
      if (entryDate != null) 'entry_date': entryDate,
      if (createdBy != null) 'created_by': createdBy,
      if (hasBill != null) 'has_bill': hasBill,
      if (billRelativePath != null) 'bill_relative_path': billRelativePath,
      if (countedInBalance != null) 'counted_in_balance': countedInBalance,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  PettyCashEntriesCompanion copyWith({
    Value<String>? clientUuid,
    Value<int?>? serverId,
    Value<int>? walletId,
    Value<int?>? projectId,
    Value<String>? type,
    Value<Money>? amount,
    Value<String?>? category,
    Value<String?>? description,
    Value<String>? entryDate,
    Value<int?>? createdBy,
    Value<bool>? hasBill,
    Value<String?>? billRelativePath,
    Value<bool>? countedInBalance,
    Value<DateTime?>? updatedAt,
    Value<int>? rowid,
  }) {
    return PettyCashEntriesCompanion(
      clientUuid: clientUuid ?? this.clientUuid,
      serverId: serverId ?? this.serverId,
      walletId: walletId ?? this.walletId,
      projectId: projectId ?? this.projectId,
      type: type ?? this.type,
      amount: amount ?? this.amount,
      category: category ?? this.category,
      description: description ?? this.description,
      entryDate: entryDate ?? this.entryDate,
      createdBy: createdBy ?? this.createdBy,
      hasBill: hasBill ?? this.hasBill,
      billRelativePath: billRelativePath ?? this.billRelativePath,
      countedInBalance: countedInBalance ?? this.countedInBalance,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clientUuid.present) {
      map['client_uuid'] = Variable<String>(clientUuid.value);
    }
    if (serverId.present) {
      map['server_id'] = Variable<int>(serverId.value);
    }
    if (walletId.present) {
      map['wallet_id'] = Variable<int>(walletId.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (type.present) {
      map['type'] = Variable<String>(type.value);
    }
    if (amount.present) {
      map['amount'] = Variable<String>(
        $PettyCashEntriesTable.$converteramount.toSql(amount.value),
      );
    }
    if (category.present) {
      map['category'] = Variable<String>(category.value);
    }
    if (description.present) {
      map['description'] = Variable<String>(description.value);
    }
    if (entryDate.present) {
      map['entry_date'] = Variable<String>(entryDate.value);
    }
    if (createdBy.present) {
      map['created_by'] = Variable<int>(createdBy.value);
    }
    if (hasBill.present) {
      map['has_bill'] = Variable<bool>(hasBill.value);
    }
    if (billRelativePath.present) {
      map['bill_relative_path'] = Variable<String>(billRelativePath.value);
    }
    if (countedInBalance.present) {
      map['counted_in_balance'] = Variable<bool>(countedInBalance.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('PettyCashEntriesCompanion(')
          ..write('clientUuid: $clientUuid, ')
          ..write('serverId: $serverId, ')
          ..write('walletId: $walletId, ')
          ..write('projectId: $projectId, ')
          ..write('type: $type, ')
          ..write('amount: $amount, ')
          ..write('category: $category, ')
          ..write('description: $description, ')
          ..write('entryDate: $entryDate, ')
          ..write('createdBy: $createdBy, ')
          ..write('hasBill: $hasBill, ')
          ..write('billRelativePath: $billRelativePath, ')
          ..write('countedInBalance: $countedInBalance, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $ApprovalsInboxTable extends ApprovalsInbox
    with TableInfo<$ApprovalsInboxTable, ApprovalRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $ApprovalsInboxTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _docTypeMeta = const VerificationMeta(
    'docType',
  );
  @override
  late final GeneratedColumn<String> docType = GeneratedColumn<String>(
    'doc_type',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _titleMeta = const VerificationMeta('title');
  @override
  late final GeneratedColumn<String> title = GeneratedColumn<String>(
    'title',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _projectCodeMeta = const VerificationMeta(
    'projectCode',
  );
  @override
  late final GeneratedColumn<String> projectCode = GeneratedColumn<String>(
    'project_code',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _projectNameMeta = const VerificationMeta(
    'projectName',
  );
  @override
  late final GeneratedColumn<String> projectName = GeneratedColumn<String>(
    'project_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _submittedByIdMeta = const VerificationMeta(
    'submittedById',
  );
  @override
  late final GeneratedColumn<int> submittedById = GeneratedColumn<int>(
    'submitted_by_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _submittedByNameMeta = const VerificationMeta(
    'submittedByName',
  );
  @override
  late final GeneratedColumn<String> submittedByName = GeneratedColumn<String>(
    'submitted_by_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _submittedAtMeta = const VerificationMeta(
    'submittedAt',
  );
  @override
  late final GeneratedColumn<DateTime> submittedAt = GeneratedColumn<DateTime>(
    'submitted_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _currentLevelMeta = const VerificationMeta(
    'currentLevel',
  );
  @override
  late final GeneratedColumn<int> currentLevel = GeneratedColumn<int>(
    'current_level',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  @override
  late final GeneratedColumnWithTypeConverter<List<String>, String> levels =
      GeneratedColumn<String>(
        'levels',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<List<String>>($ApprovalsInboxTable.$converterlevels);
  static const VerificationMeta _currentRoleMeta = const VerificationMeta(
    'currentRole',
  );
  @override
  late final GeneratedColumn<String> currentRole = GeneratedColumn<String>(
    'current_role',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _currentRoleLabelMeta = const VerificationMeta(
    'currentRoleLabel',
  );
  @override
  late final GeneratedColumn<String> currentRoleLabel = GeneratedColumn<String>(
    'current_role_label',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _canActMeta = const VerificationMeta('canAct');
  @override
  late final GeneratedColumn<bool> canAct = GeneratedColumn<bool>(
    'can_act',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("can_act" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  @override
  late final GeneratedColumnWithTypeConverter<Map<String, dynamic>, String>
  document =
      GeneratedColumn<String>(
        'document',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<Map<String, dynamic>>(
        $ApprovalsInboxTable.$converterdocument,
      );
  @override
  late final GeneratedColumnWithTypeConverter<List<dynamic>, String> steps =
      GeneratedColumn<String>(
        'steps',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<List<dynamic>>($ApprovalsInboxTable.$convertersteps);
  @override
  List<GeneratedColumn> get $columns => [
    id,
    docType,
    status,
    title,
    projectId,
    projectCode,
    projectName,
    submittedById,
    submittedByName,
    submittedAt,
    currentLevel,
    levels,
    currentRole,
    currentRoleLabel,
    canAct,
    document,
    steps,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'approvals_inbox';
  @override
  VerificationContext validateIntegrity(
    Insertable<ApprovalRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('doc_type')) {
      context.handle(
        _docTypeMeta,
        docType.isAcceptableOrUnknown(data['doc_type']!, _docTypeMeta),
      );
    } else if (isInserting) {
      context.missing(_docTypeMeta);
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    } else if (isInserting) {
      context.missing(_statusMeta);
    }
    if (data.containsKey('title')) {
      context.handle(
        _titleMeta,
        title.isAcceptableOrUnknown(data['title']!, _titleMeta),
      );
    } else if (isInserting) {
      context.missing(_titleMeta);
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    }
    if (data.containsKey('project_code')) {
      context.handle(
        _projectCodeMeta,
        projectCode.isAcceptableOrUnknown(
          data['project_code']!,
          _projectCodeMeta,
        ),
      );
    }
    if (data.containsKey('project_name')) {
      context.handle(
        _projectNameMeta,
        projectName.isAcceptableOrUnknown(
          data['project_name']!,
          _projectNameMeta,
        ),
      );
    }
    if (data.containsKey('submitted_by_id')) {
      context.handle(
        _submittedByIdMeta,
        submittedById.isAcceptableOrUnknown(
          data['submitted_by_id']!,
          _submittedByIdMeta,
        ),
      );
    }
    if (data.containsKey('submitted_by_name')) {
      context.handle(
        _submittedByNameMeta,
        submittedByName.isAcceptableOrUnknown(
          data['submitted_by_name']!,
          _submittedByNameMeta,
        ),
      );
    }
    if (data.containsKey('submitted_at')) {
      context.handle(
        _submittedAtMeta,
        submittedAt.isAcceptableOrUnknown(
          data['submitted_at']!,
          _submittedAtMeta,
        ),
      );
    }
    if (data.containsKey('current_level')) {
      context.handle(
        _currentLevelMeta,
        currentLevel.isAcceptableOrUnknown(
          data['current_level']!,
          _currentLevelMeta,
        ),
      );
    }
    if (data.containsKey('current_role')) {
      context.handle(
        _currentRoleMeta,
        currentRole.isAcceptableOrUnknown(
          data['current_role']!,
          _currentRoleMeta,
        ),
      );
    }
    if (data.containsKey('current_role_label')) {
      context.handle(
        _currentRoleLabelMeta,
        currentRoleLabel.isAcceptableOrUnknown(
          data['current_role_label']!,
          _currentRoleLabelMeta,
        ),
      );
    }
    if (data.containsKey('can_act')) {
      context.handle(
        _canActMeta,
        canAct.isAcceptableOrUnknown(data['can_act']!, _canActMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  ApprovalRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return ApprovalRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      docType: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}doc_type'],
      )!,
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      title: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}title'],
      )!,
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      ),
      projectCode: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}project_code'],
      ),
      projectName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}project_name'],
      ),
      submittedById: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}submitted_by_id'],
      ),
      submittedByName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}submitted_by_name'],
      ),
      submittedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}submitted_at'],
      ),
      currentLevel: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}current_level'],
      ),
      levels: $ApprovalsInboxTable.$converterlevels.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}levels'],
        )!,
      ),
      currentRole: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}current_role'],
      ),
      currentRoleLabel: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}current_role_label'],
      ),
      canAct: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}can_act'],
      )!,
      document: $ApprovalsInboxTable.$converterdocument.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}document'],
        )!,
      ),
      steps: $ApprovalsInboxTable.$convertersteps.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}steps'],
        )!,
      ),
    );
  }

  @override
  $ApprovalsInboxTable createAlias(String alias) {
    return $ApprovalsInboxTable(attachedDatabase, alias);
  }

  static TypeConverter<List<String>, String> $converterlevels =
      const StringListConverter();
  static TypeConverter<Map<String, dynamic>, String> $converterdocument =
      const JsonMapConverter();
  static TypeConverter<List<dynamic>, String> $convertersteps =
      const JsonListConverter();
}

class ApprovalRow extends DataClass implements Insertable<ApprovalRow> {
  final int id;
  final String docType;
  final String status;
  final String title;
  final int? projectId;
  final String? projectCode;
  final String? projectName;
  final int? submittedById;
  final String? submittedByName;
  final DateTime? submittedAt;
  final int? currentLevel;
  final List<String> levels;
  final String? currentRole;
  final String? currentRoleLabel;
  final bool canAct;
  final Map<String, dynamic> document;
  final List<dynamic> steps;
  const ApprovalRow({
    required this.id,
    required this.docType,
    required this.status,
    required this.title,
    this.projectId,
    this.projectCode,
    this.projectName,
    this.submittedById,
    this.submittedByName,
    this.submittedAt,
    this.currentLevel,
    required this.levels,
    this.currentRole,
    this.currentRoleLabel,
    required this.canAct,
    required this.document,
    required this.steps,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['doc_type'] = Variable<String>(docType);
    map['status'] = Variable<String>(status);
    map['title'] = Variable<String>(title);
    if (!nullToAbsent || projectId != null) {
      map['project_id'] = Variable<int>(projectId);
    }
    if (!nullToAbsent || projectCode != null) {
      map['project_code'] = Variable<String>(projectCode);
    }
    if (!nullToAbsent || projectName != null) {
      map['project_name'] = Variable<String>(projectName);
    }
    if (!nullToAbsent || submittedById != null) {
      map['submitted_by_id'] = Variable<int>(submittedById);
    }
    if (!nullToAbsent || submittedByName != null) {
      map['submitted_by_name'] = Variable<String>(submittedByName);
    }
    if (!nullToAbsent || submittedAt != null) {
      map['submitted_at'] = Variable<DateTime>(submittedAt);
    }
    if (!nullToAbsent || currentLevel != null) {
      map['current_level'] = Variable<int>(currentLevel);
    }
    {
      map['levels'] = Variable<String>(
        $ApprovalsInboxTable.$converterlevels.toSql(levels),
      );
    }
    if (!nullToAbsent || currentRole != null) {
      map['current_role'] = Variable<String>(currentRole);
    }
    if (!nullToAbsent || currentRoleLabel != null) {
      map['current_role_label'] = Variable<String>(currentRoleLabel);
    }
    map['can_act'] = Variable<bool>(canAct);
    {
      map['document'] = Variable<String>(
        $ApprovalsInboxTable.$converterdocument.toSql(document),
      );
    }
    {
      map['steps'] = Variable<String>(
        $ApprovalsInboxTable.$convertersteps.toSql(steps),
      );
    }
    return map;
  }

  ApprovalsInboxCompanion toCompanion(bool nullToAbsent) {
    return ApprovalsInboxCompanion(
      id: Value(id),
      docType: Value(docType),
      status: Value(status),
      title: Value(title),
      projectId: projectId == null && nullToAbsent
          ? const Value.absent()
          : Value(projectId),
      projectCode: projectCode == null && nullToAbsent
          ? const Value.absent()
          : Value(projectCode),
      projectName: projectName == null && nullToAbsent
          ? const Value.absent()
          : Value(projectName),
      submittedById: submittedById == null && nullToAbsent
          ? const Value.absent()
          : Value(submittedById),
      submittedByName: submittedByName == null && nullToAbsent
          ? const Value.absent()
          : Value(submittedByName),
      submittedAt: submittedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(submittedAt),
      currentLevel: currentLevel == null && nullToAbsent
          ? const Value.absent()
          : Value(currentLevel),
      levels: Value(levels),
      currentRole: currentRole == null && nullToAbsent
          ? const Value.absent()
          : Value(currentRole),
      currentRoleLabel: currentRoleLabel == null && nullToAbsent
          ? const Value.absent()
          : Value(currentRoleLabel),
      canAct: Value(canAct),
      document: Value(document),
      steps: Value(steps),
    );
  }

  factory ApprovalRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return ApprovalRow(
      id: serializer.fromJson<int>(json['id']),
      docType: serializer.fromJson<String>(json['docType']),
      status: serializer.fromJson<String>(json['status']),
      title: serializer.fromJson<String>(json['title']),
      projectId: serializer.fromJson<int?>(json['projectId']),
      projectCode: serializer.fromJson<String?>(json['projectCode']),
      projectName: serializer.fromJson<String?>(json['projectName']),
      submittedById: serializer.fromJson<int?>(json['submittedById']),
      submittedByName: serializer.fromJson<String?>(json['submittedByName']),
      submittedAt: serializer.fromJson<DateTime?>(json['submittedAt']),
      currentLevel: serializer.fromJson<int?>(json['currentLevel']),
      levels: serializer.fromJson<List<String>>(json['levels']),
      currentRole: serializer.fromJson<String?>(json['currentRole']),
      currentRoleLabel: serializer.fromJson<String?>(json['currentRoleLabel']),
      canAct: serializer.fromJson<bool>(json['canAct']),
      document: serializer.fromJson<Map<String, dynamic>>(json['document']),
      steps: serializer.fromJson<List<dynamic>>(json['steps']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'docType': serializer.toJson<String>(docType),
      'status': serializer.toJson<String>(status),
      'title': serializer.toJson<String>(title),
      'projectId': serializer.toJson<int?>(projectId),
      'projectCode': serializer.toJson<String?>(projectCode),
      'projectName': serializer.toJson<String?>(projectName),
      'submittedById': serializer.toJson<int?>(submittedById),
      'submittedByName': serializer.toJson<String?>(submittedByName),
      'submittedAt': serializer.toJson<DateTime?>(submittedAt),
      'currentLevel': serializer.toJson<int?>(currentLevel),
      'levels': serializer.toJson<List<String>>(levels),
      'currentRole': serializer.toJson<String?>(currentRole),
      'currentRoleLabel': serializer.toJson<String?>(currentRoleLabel),
      'canAct': serializer.toJson<bool>(canAct),
      'document': serializer.toJson<Map<String, dynamic>>(document),
      'steps': serializer.toJson<List<dynamic>>(steps),
    };
  }

  ApprovalRow copyWith({
    int? id,
    String? docType,
    String? status,
    String? title,
    Value<int?> projectId = const Value.absent(),
    Value<String?> projectCode = const Value.absent(),
    Value<String?> projectName = const Value.absent(),
    Value<int?> submittedById = const Value.absent(),
    Value<String?> submittedByName = const Value.absent(),
    Value<DateTime?> submittedAt = const Value.absent(),
    Value<int?> currentLevel = const Value.absent(),
    List<String>? levels,
    Value<String?> currentRole = const Value.absent(),
    Value<String?> currentRoleLabel = const Value.absent(),
    bool? canAct,
    Map<String, dynamic>? document,
    List<dynamic>? steps,
  }) => ApprovalRow(
    id: id ?? this.id,
    docType: docType ?? this.docType,
    status: status ?? this.status,
    title: title ?? this.title,
    projectId: projectId.present ? projectId.value : this.projectId,
    projectCode: projectCode.present ? projectCode.value : this.projectCode,
    projectName: projectName.present ? projectName.value : this.projectName,
    submittedById: submittedById.present
        ? submittedById.value
        : this.submittedById,
    submittedByName: submittedByName.present
        ? submittedByName.value
        : this.submittedByName,
    submittedAt: submittedAt.present ? submittedAt.value : this.submittedAt,
    currentLevel: currentLevel.present ? currentLevel.value : this.currentLevel,
    levels: levels ?? this.levels,
    currentRole: currentRole.present ? currentRole.value : this.currentRole,
    currentRoleLabel: currentRoleLabel.present
        ? currentRoleLabel.value
        : this.currentRoleLabel,
    canAct: canAct ?? this.canAct,
    document: document ?? this.document,
    steps: steps ?? this.steps,
  );
  ApprovalRow copyWithCompanion(ApprovalsInboxCompanion data) {
    return ApprovalRow(
      id: data.id.present ? data.id.value : this.id,
      docType: data.docType.present ? data.docType.value : this.docType,
      status: data.status.present ? data.status.value : this.status,
      title: data.title.present ? data.title.value : this.title,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      projectCode: data.projectCode.present
          ? data.projectCode.value
          : this.projectCode,
      projectName: data.projectName.present
          ? data.projectName.value
          : this.projectName,
      submittedById: data.submittedById.present
          ? data.submittedById.value
          : this.submittedById,
      submittedByName: data.submittedByName.present
          ? data.submittedByName.value
          : this.submittedByName,
      submittedAt: data.submittedAt.present
          ? data.submittedAt.value
          : this.submittedAt,
      currentLevel: data.currentLevel.present
          ? data.currentLevel.value
          : this.currentLevel,
      levels: data.levels.present ? data.levels.value : this.levels,
      currentRole: data.currentRole.present
          ? data.currentRole.value
          : this.currentRole,
      currentRoleLabel: data.currentRoleLabel.present
          ? data.currentRoleLabel.value
          : this.currentRoleLabel,
      canAct: data.canAct.present ? data.canAct.value : this.canAct,
      document: data.document.present ? data.document.value : this.document,
      steps: data.steps.present ? data.steps.value : this.steps,
    );
  }

  @override
  String toString() {
    return (StringBuffer('ApprovalRow(')
          ..write('id: $id, ')
          ..write('docType: $docType, ')
          ..write('status: $status, ')
          ..write('title: $title, ')
          ..write('projectId: $projectId, ')
          ..write('projectCode: $projectCode, ')
          ..write('projectName: $projectName, ')
          ..write('submittedById: $submittedById, ')
          ..write('submittedByName: $submittedByName, ')
          ..write('submittedAt: $submittedAt, ')
          ..write('currentLevel: $currentLevel, ')
          ..write('levels: $levels, ')
          ..write('currentRole: $currentRole, ')
          ..write('currentRoleLabel: $currentRoleLabel, ')
          ..write('canAct: $canAct, ')
          ..write('document: $document, ')
          ..write('steps: $steps')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    docType,
    status,
    title,
    projectId,
    projectCode,
    projectName,
    submittedById,
    submittedByName,
    submittedAt,
    currentLevel,
    levels,
    currentRole,
    currentRoleLabel,
    canAct,
    document,
    steps,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is ApprovalRow &&
          other.id == this.id &&
          other.docType == this.docType &&
          other.status == this.status &&
          other.title == this.title &&
          other.projectId == this.projectId &&
          other.projectCode == this.projectCode &&
          other.projectName == this.projectName &&
          other.submittedById == this.submittedById &&
          other.submittedByName == this.submittedByName &&
          other.submittedAt == this.submittedAt &&
          other.currentLevel == this.currentLevel &&
          other.levels == this.levels &&
          other.currentRole == this.currentRole &&
          other.currentRoleLabel == this.currentRoleLabel &&
          other.canAct == this.canAct &&
          other.document == this.document &&
          other.steps == this.steps);
}

class ApprovalsInboxCompanion extends UpdateCompanion<ApprovalRow> {
  final Value<int> id;
  final Value<String> docType;
  final Value<String> status;
  final Value<String> title;
  final Value<int?> projectId;
  final Value<String?> projectCode;
  final Value<String?> projectName;
  final Value<int?> submittedById;
  final Value<String?> submittedByName;
  final Value<DateTime?> submittedAt;
  final Value<int?> currentLevel;
  final Value<List<String>> levels;
  final Value<String?> currentRole;
  final Value<String?> currentRoleLabel;
  final Value<bool> canAct;
  final Value<Map<String, dynamic>> document;
  final Value<List<dynamic>> steps;
  const ApprovalsInboxCompanion({
    this.id = const Value.absent(),
    this.docType = const Value.absent(),
    this.status = const Value.absent(),
    this.title = const Value.absent(),
    this.projectId = const Value.absent(),
    this.projectCode = const Value.absent(),
    this.projectName = const Value.absent(),
    this.submittedById = const Value.absent(),
    this.submittedByName = const Value.absent(),
    this.submittedAt = const Value.absent(),
    this.currentLevel = const Value.absent(),
    this.levels = const Value.absent(),
    this.currentRole = const Value.absent(),
    this.currentRoleLabel = const Value.absent(),
    this.canAct = const Value.absent(),
    this.document = const Value.absent(),
    this.steps = const Value.absent(),
  });
  ApprovalsInboxCompanion.insert({
    this.id = const Value.absent(),
    required String docType,
    required String status,
    required String title,
    this.projectId = const Value.absent(),
    this.projectCode = const Value.absent(),
    this.projectName = const Value.absent(),
    this.submittedById = const Value.absent(),
    this.submittedByName = const Value.absent(),
    this.submittedAt = const Value.absent(),
    this.currentLevel = const Value.absent(),
    required List<String> levels,
    this.currentRole = const Value.absent(),
    this.currentRoleLabel = const Value.absent(),
    this.canAct = const Value.absent(),
    required Map<String, dynamic> document,
    required List<dynamic> steps,
  }) : docType = Value(docType),
       status = Value(status),
       title = Value(title),
       levels = Value(levels),
       document = Value(document),
       steps = Value(steps);
  static Insertable<ApprovalRow> custom({
    Expression<int>? id,
    Expression<String>? docType,
    Expression<String>? status,
    Expression<String>? title,
    Expression<int>? projectId,
    Expression<String>? projectCode,
    Expression<String>? projectName,
    Expression<int>? submittedById,
    Expression<String>? submittedByName,
    Expression<DateTime>? submittedAt,
    Expression<int>? currentLevel,
    Expression<String>? levels,
    Expression<String>? currentRole,
    Expression<String>? currentRoleLabel,
    Expression<bool>? canAct,
    Expression<String>? document,
    Expression<String>? steps,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (docType != null) 'doc_type': docType,
      if (status != null) 'status': status,
      if (title != null) 'title': title,
      if (projectId != null) 'project_id': projectId,
      if (projectCode != null) 'project_code': projectCode,
      if (projectName != null) 'project_name': projectName,
      if (submittedById != null) 'submitted_by_id': submittedById,
      if (submittedByName != null) 'submitted_by_name': submittedByName,
      if (submittedAt != null) 'submitted_at': submittedAt,
      if (currentLevel != null) 'current_level': currentLevel,
      if (levels != null) 'levels': levels,
      if (currentRole != null) 'current_role': currentRole,
      if (currentRoleLabel != null) 'current_role_label': currentRoleLabel,
      if (canAct != null) 'can_act': canAct,
      if (document != null) 'document': document,
      if (steps != null) 'steps': steps,
    });
  }

  ApprovalsInboxCompanion copyWith({
    Value<int>? id,
    Value<String>? docType,
    Value<String>? status,
    Value<String>? title,
    Value<int?>? projectId,
    Value<String?>? projectCode,
    Value<String?>? projectName,
    Value<int?>? submittedById,
    Value<String?>? submittedByName,
    Value<DateTime?>? submittedAt,
    Value<int?>? currentLevel,
    Value<List<String>>? levels,
    Value<String?>? currentRole,
    Value<String?>? currentRoleLabel,
    Value<bool>? canAct,
    Value<Map<String, dynamic>>? document,
    Value<List<dynamic>>? steps,
  }) {
    return ApprovalsInboxCompanion(
      id: id ?? this.id,
      docType: docType ?? this.docType,
      status: status ?? this.status,
      title: title ?? this.title,
      projectId: projectId ?? this.projectId,
      projectCode: projectCode ?? this.projectCode,
      projectName: projectName ?? this.projectName,
      submittedById: submittedById ?? this.submittedById,
      submittedByName: submittedByName ?? this.submittedByName,
      submittedAt: submittedAt ?? this.submittedAt,
      currentLevel: currentLevel ?? this.currentLevel,
      levels: levels ?? this.levels,
      currentRole: currentRole ?? this.currentRole,
      currentRoleLabel: currentRoleLabel ?? this.currentRoleLabel,
      canAct: canAct ?? this.canAct,
      document: document ?? this.document,
      steps: steps ?? this.steps,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (docType.present) {
      map['doc_type'] = Variable<String>(docType.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (title.present) {
      map['title'] = Variable<String>(title.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (projectCode.present) {
      map['project_code'] = Variable<String>(projectCode.value);
    }
    if (projectName.present) {
      map['project_name'] = Variable<String>(projectName.value);
    }
    if (submittedById.present) {
      map['submitted_by_id'] = Variable<int>(submittedById.value);
    }
    if (submittedByName.present) {
      map['submitted_by_name'] = Variable<String>(submittedByName.value);
    }
    if (submittedAt.present) {
      map['submitted_at'] = Variable<DateTime>(submittedAt.value);
    }
    if (currentLevel.present) {
      map['current_level'] = Variable<int>(currentLevel.value);
    }
    if (levels.present) {
      map['levels'] = Variable<String>(
        $ApprovalsInboxTable.$converterlevels.toSql(levels.value),
      );
    }
    if (currentRole.present) {
      map['current_role'] = Variable<String>(currentRole.value);
    }
    if (currentRoleLabel.present) {
      map['current_role_label'] = Variable<String>(currentRoleLabel.value);
    }
    if (canAct.present) {
      map['can_act'] = Variable<bool>(canAct.value);
    }
    if (document.present) {
      map['document'] = Variable<String>(
        $ApprovalsInboxTable.$converterdocument.toSql(document.value),
      );
    }
    if (steps.present) {
      map['steps'] = Variable<String>(
        $ApprovalsInboxTable.$convertersteps.toSql(steps.value),
      );
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('ApprovalsInboxCompanion(')
          ..write('id: $id, ')
          ..write('docType: $docType, ')
          ..write('status: $status, ')
          ..write('title: $title, ')
          ..write('projectId: $projectId, ')
          ..write('projectCode: $projectCode, ')
          ..write('projectName: $projectName, ')
          ..write('submittedById: $submittedById, ')
          ..write('submittedByName: $submittedByName, ')
          ..write('submittedAt: $submittedAt, ')
          ..write('currentLevel: $currentLevel, ')
          ..write('levels: $levels, ')
          ..write('currentRole: $currentRole, ')
          ..write('currentRoleLabel: $currentRoleLabel, ')
          ..write('canAct: $canAct, ')
          ..write('document: $document, ')
          ..write('steps: $steps')
          ..write(')'))
        .toString();
  }
}

class $OutboxTable extends Outbox with TableInfo<$OutboxTable, OutboxRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $OutboxTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  @override
  late final GeneratedColumnWithTypeConverter<OutboxType, String> type =
      GeneratedColumn<String>(
        'type',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<OutboxType>($OutboxTable.$convertertype);
  static const VerificationMeta _wireUuidMeta = const VerificationMeta(
    'wireUuid',
  );
  @override
  late final GeneratedColumn<String> wireUuid = GeneratedColumn<String>(
    'wire_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _recordUuidMeta = const VerificationMeta(
    'recordUuid',
  );
  @override
  late final GeneratedColumn<String> recordUuid = GeneratedColumn<String>(
    'record_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _groupUuidMeta = const VerificationMeta(
    'groupUuid',
  );
  @override
  late final GeneratedColumn<String> groupUuid = GeneratedColumn<String>(
    'group_uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _projectIdMeta = const VerificationMeta(
    'projectId',
  );
  @override
  late final GeneratedColumn<int> projectId = GeneratedColumn<int>(
    'project_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _payloadMeta = const VerificationMeta(
    'payload',
  );
  @override
  late final GeneratedColumn<String> payload = GeneratedColumn<String>(
    'payload',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _clientUpdatedAtMeta = const VerificationMeta(
    'clientUpdatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> clientUpdatedAt =
      GeneratedColumn<DateTime>(
        'client_updated_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  @override
  late final GeneratedColumnWithTypeConverter<OutboxStatus, String> status =
      GeneratedColumn<String>(
        'status',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
        defaultValue: const Constant('pending'),
      ).withConverter<OutboxStatus>($OutboxTable.$converterstatus);
  static const VerificationMeta _filePathMeta = const VerificationMeta(
    'filePath',
  );
  @override
  late final GeneratedColumn<String> filePath = GeneratedColumn<String>(
    'file_path',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _parentUuidMeta = const VerificationMeta(
    'parentUuid',
  );
  @override
  late final GeneratedColumn<String> parentUuid = GeneratedColumn<String>(
    'parent_uuid',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _titleMeta = const VerificationMeta('title');
  @override
  late final GeneratedColumn<String> title = GeneratedColumn<String>(
    'title',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant(''),
  );
  static const VerificationMeta _subtitleMeta = const VerificationMeta(
    'subtitle',
  );
  @override
  late final GeneratedColumn<String> subtitle = GeneratedColumn<String>(
    'subtitle',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _resultStatusMeta = const VerificationMeta(
    'resultStatus',
  );
  @override
  late final GeneratedColumn<String> resultStatus = GeneratedColumn<String>(
    'result_status',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _attentionCodeMeta = const VerificationMeta(
    'attentionCode',
  );
  @override
  late final GeneratedColumn<String> attentionCode = GeneratedColumn<String>(
    'attention_code',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _attentionReasonMeta = const VerificationMeta(
    'attentionReason',
  );
  @override
  late final GeneratedColumn<String> attentionReason = GeneratedColumn<String>(
    'attention_reason',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _attentionErrorsMeta = const VerificationMeta(
    'attentionErrors',
  );
  @override
  late final GeneratedColumn<String> attentionErrors = GeneratedColumn<String>(
    'attention_errors',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _attemptsMeta = const VerificationMeta(
    'attempts',
  );
  @override
  late final GeneratedColumn<int> attempts = GeneratedColumn<int>(
    'attempts',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _notFoundCountMeta = const VerificationMeta(
    'notFoundCount',
  );
  @override
  late final GeneratedColumn<int> notFoundCount = GeneratedColumn<int>(
    'not_found_count',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _lastAttemptAtMeta = const VerificationMeta(
    'lastAttemptAt',
  );
  @override
  late final GeneratedColumn<DateTime> lastAttemptAt =
      GeneratedColumn<DateTime>(
        'last_attempt_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<DateTime> createdAt = GeneratedColumn<DateTime>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _doneAtMeta = const VerificationMeta('doneAt');
  @override
  late final GeneratedColumn<DateTime> doneAt = GeneratedColumn<DateTime>(
    'done_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    type,
    wireUuid,
    recordUuid,
    groupUuid,
    projectId,
    payload,
    clientUpdatedAt,
    status,
    filePath,
    parentUuid,
    title,
    subtitle,
    resultStatus,
    attentionCode,
    attentionReason,
    attentionErrors,
    attempts,
    notFoundCount,
    lastAttemptAt,
    createdAt,
    doneAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'outbox';
  @override
  VerificationContext validateIntegrity(
    Insertable<OutboxRow> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('wire_uuid')) {
      context.handle(
        _wireUuidMeta,
        wireUuid.isAcceptableOrUnknown(data['wire_uuid']!, _wireUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_wireUuidMeta);
    }
    if (data.containsKey('record_uuid')) {
      context.handle(
        _recordUuidMeta,
        recordUuid.isAcceptableOrUnknown(data['record_uuid']!, _recordUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_recordUuidMeta);
    }
    if (data.containsKey('group_uuid')) {
      context.handle(
        _groupUuidMeta,
        groupUuid.isAcceptableOrUnknown(data['group_uuid']!, _groupUuidMeta),
      );
    } else if (isInserting) {
      context.missing(_groupUuidMeta);
    }
    if (data.containsKey('project_id')) {
      context.handle(
        _projectIdMeta,
        projectId.isAcceptableOrUnknown(data['project_id']!, _projectIdMeta),
      );
    } else if (isInserting) {
      context.missing(_projectIdMeta);
    }
    if (data.containsKey('payload')) {
      context.handle(
        _payloadMeta,
        payload.isAcceptableOrUnknown(data['payload']!, _payloadMeta),
      );
    } else if (isInserting) {
      context.missing(_payloadMeta);
    }
    if (data.containsKey('client_updated_at')) {
      context.handle(
        _clientUpdatedAtMeta,
        clientUpdatedAt.isAcceptableOrUnknown(
          data['client_updated_at']!,
          _clientUpdatedAtMeta,
        ),
      );
    }
    if (data.containsKey('file_path')) {
      context.handle(
        _filePathMeta,
        filePath.isAcceptableOrUnknown(data['file_path']!, _filePathMeta),
      );
    }
    if (data.containsKey('parent_uuid')) {
      context.handle(
        _parentUuidMeta,
        parentUuid.isAcceptableOrUnknown(data['parent_uuid']!, _parentUuidMeta),
      );
    }
    if (data.containsKey('title')) {
      context.handle(
        _titleMeta,
        title.isAcceptableOrUnknown(data['title']!, _titleMeta),
      );
    }
    if (data.containsKey('subtitle')) {
      context.handle(
        _subtitleMeta,
        subtitle.isAcceptableOrUnknown(data['subtitle']!, _subtitleMeta),
      );
    }
    if (data.containsKey('result_status')) {
      context.handle(
        _resultStatusMeta,
        resultStatus.isAcceptableOrUnknown(
          data['result_status']!,
          _resultStatusMeta,
        ),
      );
    }
    if (data.containsKey('attention_code')) {
      context.handle(
        _attentionCodeMeta,
        attentionCode.isAcceptableOrUnknown(
          data['attention_code']!,
          _attentionCodeMeta,
        ),
      );
    }
    if (data.containsKey('attention_reason')) {
      context.handle(
        _attentionReasonMeta,
        attentionReason.isAcceptableOrUnknown(
          data['attention_reason']!,
          _attentionReasonMeta,
        ),
      );
    }
    if (data.containsKey('attention_errors')) {
      context.handle(
        _attentionErrorsMeta,
        attentionErrors.isAcceptableOrUnknown(
          data['attention_errors']!,
          _attentionErrorsMeta,
        ),
      );
    }
    if (data.containsKey('attempts')) {
      context.handle(
        _attemptsMeta,
        attempts.isAcceptableOrUnknown(data['attempts']!, _attemptsMeta),
      );
    }
    if (data.containsKey('not_found_count')) {
      context.handle(
        _notFoundCountMeta,
        notFoundCount.isAcceptableOrUnknown(
          data['not_found_count']!,
          _notFoundCountMeta,
        ),
      );
    }
    if (data.containsKey('last_attempt_at')) {
      context.handle(
        _lastAttemptAtMeta,
        lastAttemptAt.isAcceptableOrUnknown(
          data['last_attempt_at']!,
          _lastAttemptAtMeta,
        ),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    if (data.containsKey('done_at')) {
      context.handle(
        _doneAtMeta,
        doneAt.isAcceptableOrUnknown(data['done_at']!, _doneAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  OutboxRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return OutboxRow(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      type: $OutboxTable.$convertertype.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}type'],
        )!,
      ),
      wireUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}wire_uuid'],
      )!,
      recordUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}record_uuid'],
      )!,
      groupUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}group_uuid'],
      )!,
      projectId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}project_id'],
      )!,
      payload: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}payload'],
      )!,
      clientUpdatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}client_updated_at'],
      ),
      status: $OutboxTable.$converterstatus.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}status'],
        )!,
      ),
      filePath: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}file_path'],
      ),
      parentUuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}parent_uuid'],
      ),
      title: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}title'],
      )!,
      subtitle: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}subtitle'],
      ),
      resultStatus: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}result_status'],
      ),
      attentionCode: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}attention_code'],
      ),
      attentionReason: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}attention_reason'],
      ),
      attentionErrors: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}attention_errors'],
      ),
      attempts: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}attempts'],
      )!,
      notFoundCount: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}not_found_count'],
      )!,
      lastAttemptAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}last_attempt_at'],
      ),
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}created_at'],
      )!,
      doneAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}done_at'],
      ),
    );
  }

  @override
  $OutboxTable createAlias(String alias) {
    return $OutboxTable(attachedDatabase, alias);
  }

  static JsonTypeConverter2<OutboxType, String, String> $convertertype =
      const EnumNameConverter<OutboxType>(OutboxType.values);
  static JsonTypeConverter2<OutboxStatus, String, String> $converterstatus =
      const EnumNameConverter<OutboxStatus>(OutboxStatus.values);
}

class OutboxRow extends DataClass implements Insertable<OutboxRow> {
  final int id;
  final OutboxType type;

  /// The `client_uuid` sent on the wire. Stable across retries.
  final String wireUuid;

  /// The local record this operation acts on.
  final String recordUuid;

  /// Dependency group: the top-level record (DPR, entry, issue, mark).
  final String groupUuid;
  final int projectId;

  /// JSON of the wire `data`, or upload fields for file rows.
  final String payload;
  final DateTime? clientUpdatedAt;
  final OutboxStatus status;

  /// File rows: path relative to the app documents directory.
  final String? filePath;

  /// File rows: the `client_uuid` of the parent record.
  final String? parentUuid;

  /// Display data captured when queued, so the sync log works without joins.
  final String title;
  final String? subtitle;

  /// Last wire result: created, updated, duplicate, stale, conflict, ...
  final String? resultStatus;

  /// Stable code for a screen to translate: see [AttentionCode].
  final String? attentionCode;

  /// English description for logs and as a fallback.
  final String? attentionReason;

  /// JSON of the server's validation `errors` map.
  final String? attentionErrors;
  final int attempts;

  /// File rows: how many times the server said the parent was not found.
  /// Counted apart from [attempts] so timeouts and 429s cannot use up the
  /// allowance and push a healthy upload to attention.
  final int notFoundCount;
  final DateTime? lastAttemptAt;
  final DateTime createdAt;
  final DateTime? doneAt;
  const OutboxRow({
    required this.id,
    required this.type,
    required this.wireUuid,
    required this.recordUuid,
    required this.groupUuid,
    required this.projectId,
    required this.payload,
    this.clientUpdatedAt,
    required this.status,
    this.filePath,
    this.parentUuid,
    required this.title,
    this.subtitle,
    this.resultStatus,
    this.attentionCode,
    this.attentionReason,
    this.attentionErrors,
    required this.attempts,
    required this.notFoundCount,
    this.lastAttemptAt,
    required this.createdAt,
    this.doneAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    {
      map['type'] = Variable<String>($OutboxTable.$convertertype.toSql(type));
    }
    map['wire_uuid'] = Variable<String>(wireUuid);
    map['record_uuid'] = Variable<String>(recordUuid);
    map['group_uuid'] = Variable<String>(groupUuid);
    map['project_id'] = Variable<int>(projectId);
    map['payload'] = Variable<String>(payload);
    if (!nullToAbsent || clientUpdatedAt != null) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt);
    }
    {
      map['status'] = Variable<String>(
        $OutboxTable.$converterstatus.toSql(status),
      );
    }
    if (!nullToAbsent || filePath != null) {
      map['file_path'] = Variable<String>(filePath);
    }
    if (!nullToAbsent || parentUuid != null) {
      map['parent_uuid'] = Variable<String>(parentUuid);
    }
    map['title'] = Variable<String>(title);
    if (!nullToAbsent || subtitle != null) {
      map['subtitle'] = Variable<String>(subtitle);
    }
    if (!nullToAbsent || resultStatus != null) {
      map['result_status'] = Variable<String>(resultStatus);
    }
    if (!nullToAbsent || attentionCode != null) {
      map['attention_code'] = Variable<String>(attentionCode);
    }
    if (!nullToAbsent || attentionReason != null) {
      map['attention_reason'] = Variable<String>(attentionReason);
    }
    if (!nullToAbsent || attentionErrors != null) {
      map['attention_errors'] = Variable<String>(attentionErrors);
    }
    map['attempts'] = Variable<int>(attempts);
    map['not_found_count'] = Variable<int>(notFoundCount);
    if (!nullToAbsent || lastAttemptAt != null) {
      map['last_attempt_at'] = Variable<DateTime>(lastAttemptAt);
    }
    map['created_at'] = Variable<DateTime>(createdAt);
    if (!nullToAbsent || doneAt != null) {
      map['done_at'] = Variable<DateTime>(doneAt);
    }
    return map;
  }

  OutboxCompanion toCompanion(bool nullToAbsent) {
    return OutboxCompanion(
      id: Value(id),
      type: Value(type),
      wireUuid: Value(wireUuid),
      recordUuid: Value(recordUuid),
      groupUuid: Value(groupUuid),
      projectId: Value(projectId),
      payload: Value(payload),
      clientUpdatedAt: clientUpdatedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(clientUpdatedAt),
      status: Value(status),
      filePath: filePath == null && nullToAbsent
          ? const Value.absent()
          : Value(filePath),
      parentUuid: parentUuid == null && nullToAbsent
          ? const Value.absent()
          : Value(parentUuid),
      title: Value(title),
      subtitle: subtitle == null && nullToAbsent
          ? const Value.absent()
          : Value(subtitle),
      resultStatus: resultStatus == null && nullToAbsent
          ? const Value.absent()
          : Value(resultStatus),
      attentionCode: attentionCode == null && nullToAbsent
          ? const Value.absent()
          : Value(attentionCode),
      attentionReason: attentionReason == null && nullToAbsent
          ? const Value.absent()
          : Value(attentionReason),
      attentionErrors: attentionErrors == null && nullToAbsent
          ? const Value.absent()
          : Value(attentionErrors),
      attempts: Value(attempts),
      notFoundCount: Value(notFoundCount),
      lastAttemptAt: lastAttemptAt == null && nullToAbsent
          ? const Value.absent()
          : Value(lastAttemptAt),
      createdAt: Value(createdAt),
      doneAt: doneAt == null && nullToAbsent
          ? const Value.absent()
          : Value(doneAt),
    );
  }

  factory OutboxRow.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return OutboxRow(
      id: serializer.fromJson<int>(json['id']),
      type: $OutboxTable.$convertertype.fromJson(
        serializer.fromJson<String>(json['type']),
      ),
      wireUuid: serializer.fromJson<String>(json['wireUuid']),
      recordUuid: serializer.fromJson<String>(json['recordUuid']),
      groupUuid: serializer.fromJson<String>(json['groupUuid']),
      projectId: serializer.fromJson<int>(json['projectId']),
      payload: serializer.fromJson<String>(json['payload']),
      clientUpdatedAt: serializer.fromJson<DateTime?>(json['clientUpdatedAt']),
      status: $OutboxTable.$converterstatus.fromJson(
        serializer.fromJson<String>(json['status']),
      ),
      filePath: serializer.fromJson<String?>(json['filePath']),
      parentUuid: serializer.fromJson<String?>(json['parentUuid']),
      title: serializer.fromJson<String>(json['title']),
      subtitle: serializer.fromJson<String?>(json['subtitle']),
      resultStatus: serializer.fromJson<String?>(json['resultStatus']),
      attentionCode: serializer.fromJson<String?>(json['attentionCode']),
      attentionReason: serializer.fromJson<String?>(json['attentionReason']),
      attentionErrors: serializer.fromJson<String?>(json['attentionErrors']),
      attempts: serializer.fromJson<int>(json['attempts']),
      notFoundCount: serializer.fromJson<int>(json['notFoundCount']),
      lastAttemptAt: serializer.fromJson<DateTime?>(json['lastAttemptAt']),
      createdAt: serializer.fromJson<DateTime>(json['createdAt']),
      doneAt: serializer.fromJson<DateTime?>(json['doneAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'type': serializer.toJson<String>(
        $OutboxTable.$convertertype.toJson(type),
      ),
      'wireUuid': serializer.toJson<String>(wireUuid),
      'recordUuid': serializer.toJson<String>(recordUuid),
      'groupUuid': serializer.toJson<String>(groupUuid),
      'projectId': serializer.toJson<int>(projectId),
      'payload': serializer.toJson<String>(payload),
      'clientUpdatedAt': serializer.toJson<DateTime?>(clientUpdatedAt),
      'status': serializer.toJson<String>(
        $OutboxTable.$converterstatus.toJson(status),
      ),
      'filePath': serializer.toJson<String?>(filePath),
      'parentUuid': serializer.toJson<String?>(parentUuid),
      'title': serializer.toJson<String>(title),
      'subtitle': serializer.toJson<String?>(subtitle),
      'resultStatus': serializer.toJson<String?>(resultStatus),
      'attentionCode': serializer.toJson<String?>(attentionCode),
      'attentionReason': serializer.toJson<String?>(attentionReason),
      'attentionErrors': serializer.toJson<String?>(attentionErrors),
      'attempts': serializer.toJson<int>(attempts),
      'notFoundCount': serializer.toJson<int>(notFoundCount),
      'lastAttemptAt': serializer.toJson<DateTime?>(lastAttemptAt),
      'createdAt': serializer.toJson<DateTime>(createdAt),
      'doneAt': serializer.toJson<DateTime?>(doneAt),
    };
  }

  OutboxRow copyWith({
    int? id,
    OutboxType? type,
    String? wireUuid,
    String? recordUuid,
    String? groupUuid,
    int? projectId,
    String? payload,
    Value<DateTime?> clientUpdatedAt = const Value.absent(),
    OutboxStatus? status,
    Value<String?> filePath = const Value.absent(),
    Value<String?> parentUuid = const Value.absent(),
    String? title,
    Value<String?> subtitle = const Value.absent(),
    Value<String?> resultStatus = const Value.absent(),
    Value<String?> attentionCode = const Value.absent(),
    Value<String?> attentionReason = const Value.absent(),
    Value<String?> attentionErrors = const Value.absent(),
    int? attempts,
    int? notFoundCount,
    Value<DateTime?> lastAttemptAt = const Value.absent(),
    DateTime? createdAt,
    Value<DateTime?> doneAt = const Value.absent(),
  }) => OutboxRow(
    id: id ?? this.id,
    type: type ?? this.type,
    wireUuid: wireUuid ?? this.wireUuid,
    recordUuid: recordUuid ?? this.recordUuid,
    groupUuid: groupUuid ?? this.groupUuid,
    projectId: projectId ?? this.projectId,
    payload: payload ?? this.payload,
    clientUpdatedAt: clientUpdatedAt.present
        ? clientUpdatedAt.value
        : this.clientUpdatedAt,
    status: status ?? this.status,
    filePath: filePath.present ? filePath.value : this.filePath,
    parentUuid: parentUuid.present ? parentUuid.value : this.parentUuid,
    title: title ?? this.title,
    subtitle: subtitle.present ? subtitle.value : this.subtitle,
    resultStatus: resultStatus.present ? resultStatus.value : this.resultStatus,
    attentionCode: attentionCode.present
        ? attentionCode.value
        : this.attentionCode,
    attentionReason: attentionReason.present
        ? attentionReason.value
        : this.attentionReason,
    attentionErrors: attentionErrors.present
        ? attentionErrors.value
        : this.attentionErrors,
    attempts: attempts ?? this.attempts,
    notFoundCount: notFoundCount ?? this.notFoundCount,
    lastAttemptAt: lastAttemptAt.present
        ? lastAttemptAt.value
        : this.lastAttemptAt,
    createdAt: createdAt ?? this.createdAt,
    doneAt: doneAt.present ? doneAt.value : this.doneAt,
  );
  OutboxRow copyWithCompanion(OutboxCompanion data) {
    return OutboxRow(
      id: data.id.present ? data.id.value : this.id,
      type: data.type.present ? data.type.value : this.type,
      wireUuid: data.wireUuid.present ? data.wireUuid.value : this.wireUuid,
      recordUuid: data.recordUuid.present
          ? data.recordUuid.value
          : this.recordUuid,
      groupUuid: data.groupUuid.present ? data.groupUuid.value : this.groupUuid,
      projectId: data.projectId.present ? data.projectId.value : this.projectId,
      payload: data.payload.present ? data.payload.value : this.payload,
      clientUpdatedAt: data.clientUpdatedAt.present
          ? data.clientUpdatedAt.value
          : this.clientUpdatedAt,
      status: data.status.present ? data.status.value : this.status,
      filePath: data.filePath.present ? data.filePath.value : this.filePath,
      parentUuid: data.parentUuid.present
          ? data.parentUuid.value
          : this.parentUuid,
      title: data.title.present ? data.title.value : this.title,
      subtitle: data.subtitle.present ? data.subtitle.value : this.subtitle,
      resultStatus: data.resultStatus.present
          ? data.resultStatus.value
          : this.resultStatus,
      attentionCode: data.attentionCode.present
          ? data.attentionCode.value
          : this.attentionCode,
      attentionReason: data.attentionReason.present
          ? data.attentionReason.value
          : this.attentionReason,
      attentionErrors: data.attentionErrors.present
          ? data.attentionErrors.value
          : this.attentionErrors,
      attempts: data.attempts.present ? data.attempts.value : this.attempts,
      notFoundCount: data.notFoundCount.present
          ? data.notFoundCount.value
          : this.notFoundCount,
      lastAttemptAt: data.lastAttemptAt.present
          ? data.lastAttemptAt.value
          : this.lastAttemptAt,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
      doneAt: data.doneAt.present ? data.doneAt.value : this.doneAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('OutboxRow(')
          ..write('id: $id, ')
          ..write('type: $type, ')
          ..write('wireUuid: $wireUuid, ')
          ..write('recordUuid: $recordUuid, ')
          ..write('groupUuid: $groupUuid, ')
          ..write('projectId: $projectId, ')
          ..write('payload: $payload, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('status: $status, ')
          ..write('filePath: $filePath, ')
          ..write('parentUuid: $parentUuid, ')
          ..write('title: $title, ')
          ..write('subtitle: $subtitle, ')
          ..write('resultStatus: $resultStatus, ')
          ..write('attentionCode: $attentionCode, ')
          ..write('attentionReason: $attentionReason, ')
          ..write('attentionErrors: $attentionErrors, ')
          ..write('attempts: $attempts, ')
          ..write('notFoundCount: $notFoundCount, ')
          ..write('lastAttemptAt: $lastAttemptAt, ')
          ..write('createdAt: $createdAt, ')
          ..write('doneAt: $doneAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hashAll([
    id,
    type,
    wireUuid,
    recordUuid,
    groupUuid,
    projectId,
    payload,
    clientUpdatedAt,
    status,
    filePath,
    parentUuid,
    title,
    subtitle,
    resultStatus,
    attentionCode,
    attentionReason,
    attentionErrors,
    attempts,
    notFoundCount,
    lastAttemptAt,
    createdAt,
    doneAt,
  ]);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is OutboxRow &&
          other.id == this.id &&
          other.type == this.type &&
          other.wireUuid == this.wireUuid &&
          other.recordUuid == this.recordUuid &&
          other.groupUuid == this.groupUuid &&
          other.projectId == this.projectId &&
          other.payload == this.payload &&
          other.clientUpdatedAt == this.clientUpdatedAt &&
          other.status == this.status &&
          other.filePath == this.filePath &&
          other.parentUuid == this.parentUuid &&
          other.title == this.title &&
          other.subtitle == this.subtitle &&
          other.resultStatus == this.resultStatus &&
          other.attentionCode == this.attentionCode &&
          other.attentionReason == this.attentionReason &&
          other.attentionErrors == this.attentionErrors &&
          other.attempts == this.attempts &&
          other.notFoundCount == this.notFoundCount &&
          other.lastAttemptAt == this.lastAttemptAt &&
          other.createdAt == this.createdAt &&
          other.doneAt == this.doneAt);
}

class OutboxCompanion extends UpdateCompanion<OutboxRow> {
  final Value<int> id;
  final Value<OutboxType> type;
  final Value<String> wireUuid;
  final Value<String> recordUuid;
  final Value<String> groupUuid;
  final Value<int> projectId;
  final Value<String> payload;
  final Value<DateTime?> clientUpdatedAt;
  final Value<OutboxStatus> status;
  final Value<String?> filePath;
  final Value<String?> parentUuid;
  final Value<String> title;
  final Value<String?> subtitle;
  final Value<String?> resultStatus;
  final Value<String?> attentionCode;
  final Value<String?> attentionReason;
  final Value<String?> attentionErrors;
  final Value<int> attempts;
  final Value<int> notFoundCount;
  final Value<DateTime?> lastAttemptAt;
  final Value<DateTime> createdAt;
  final Value<DateTime?> doneAt;
  const OutboxCompanion({
    this.id = const Value.absent(),
    this.type = const Value.absent(),
    this.wireUuid = const Value.absent(),
    this.recordUuid = const Value.absent(),
    this.groupUuid = const Value.absent(),
    this.projectId = const Value.absent(),
    this.payload = const Value.absent(),
    this.clientUpdatedAt = const Value.absent(),
    this.status = const Value.absent(),
    this.filePath = const Value.absent(),
    this.parentUuid = const Value.absent(),
    this.title = const Value.absent(),
    this.subtitle = const Value.absent(),
    this.resultStatus = const Value.absent(),
    this.attentionCode = const Value.absent(),
    this.attentionReason = const Value.absent(),
    this.attentionErrors = const Value.absent(),
    this.attempts = const Value.absent(),
    this.notFoundCount = const Value.absent(),
    this.lastAttemptAt = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.doneAt = const Value.absent(),
  });
  OutboxCompanion.insert({
    this.id = const Value.absent(),
    required OutboxType type,
    required String wireUuid,
    required String recordUuid,
    required String groupUuid,
    required int projectId,
    required String payload,
    this.clientUpdatedAt = const Value.absent(),
    this.status = const Value.absent(),
    this.filePath = const Value.absent(),
    this.parentUuid = const Value.absent(),
    this.title = const Value.absent(),
    this.subtitle = const Value.absent(),
    this.resultStatus = const Value.absent(),
    this.attentionCode = const Value.absent(),
    this.attentionReason = const Value.absent(),
    this.attentionErrors = const Value.absent(),
    this.attempts = const Value.absent(),
    this.notFoundCount = const Value.absent(),
    this.lastAttemptAt = const Value.absent(),
    required DateTime createdAt,
    this.doneAt = const Value.absent(),
  }) : type = Value(type),
       wireUuid = Value(wireUuid),
       recordUuid = Value(recordUuid),
       groupUuid = Value(groupUuid),
       projectId = Value(projectId),
       payload = Value(payload),
       createdAt = Value(createdAt);
  static Insertable<OutboxRow> custom({
    Expression<int>? id,
    Expression<String>? type,
    Expression<String>? wireUuid,
    Expression<String>? recordUuid,
    Expression<String>? groupUuid,
    Expression<int>? projectId,
    Expression<String>? payload,
    Expression<DateTime>? clientUpdatedAt,
    Expression<String>? status,
    Expression<String>? filePath,
    Expression<String>? parentUuid,
    Expression<String>? title,
    Expression<String>? subtitle,
    Expression<String>? resultStatus,
    Expression<String>? attentionCode,
    Expression<String>? attentionReason,
    Expression<String>? attentionErrors,
    Expression<int>? attempts,
    Expression<int>? notFoundCount,
    Expression<DateTime>? lastAttemptAt,
    Expression<DateTime>? createdAt,
    Expression<DateTime>? doneAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (type != null) 'type': type,
      if (wireUuid != null) 'wire_uuid': wireUuid,
      if (recordUuid != null) 'record_uuid': recordUuid,
      if (groupUuid != null) 'group_uuid': groupUuid,
      if (projectId != null) 'project_id': projectId,
      if (payload != null) 'payload': payload,
      if (clientUpdatedAt != null) 'client_updated_at': clientUpdatedAt,
      if (status != null) 'status': status,
      if (filePath != null) 'file_path': filePath,
      if (parentUuid != null) 'parent_uuid': parentUuid,
      if (title != null) 'title': title,
      if (subtitle != null) 'subtitle': subtitle,
      if (resultStatus != null) 'result_status': resultStatus,
      if (attentionCode != null) 'attention_code': attentionCode,
      if (attentionReason != null) 'attention_reason': attentionReason,
      if (attentionErrors != null) 'attention_errors': attentionErrors,
      if (attempts != null) 'attempts': attempts,
      if (notFoundCount != null) 'not_found_count': notFoundCount,
      if (lastAttemptAt != null) 'last_attempt_at': lastAttemptAt,
      if (createdAt != null) 'created_at': createdAt,
      if (doneAt != null) 'done_at': doneAt,
    });
  }

  OutboxCompanion copyWith({
    Value<int>? id,
    Value<OutboxType>? type,
    Value<String>? wireUuid,
    Value<String>? recordUuid,
    Value<String>? groupUuid,
    Value<int>? projectId,
    Value<String>? payload,
    Value<DateTime?>? clientUpdatedAt,
    Value<OutboxStatus>? status,
    Value<String?>? filePath,
    Value<String?>? parentUuid,
    Value<String>? title,
    Value<String?>? subtitle,
    Value<String?>? resultStatus,
    Value<String?>? attentionCode,
    Value<String?>? attentionReason,
    Value<String?>? attentionErrors,
    Value<int>? attempts,
    Value<int>? notFoundCount,
    Value<DateTime?>? lastAttemptAt,
    Value<DateTime>? createdAt,
    Value<DateTime?>? doneAt,
  }) {
    return OutboxCompanion(
      id: id ?? this.id,
      type: type ?? this.type,
      wireUuid: wireUuid ?? this.wireUuid,
      recordUuid: recordUuid ?? this.recordUuid,
      groupUuid: groupUuid ?? this.groupUuid,
      projectId: projectId ?? this.projectId,
      payload: payload ?? this.payload,
      clientUpdatedAt: clientUpdatedAt ?? this.clientUpdatedAt,
      status: status ?? this.status,
      filePath: filePath ?? this.filePath,
      parentUuid: parentUuid ?? this.parentUuid,
      title: title ?? this.title,
      subtitle: subtitle ?? this.subtitle,
      resultStatus: resultStatus ?? this.resultStatus,
      attentionCode: attentionCode ?? this.attentionCode,
      attentionReason: attentionReason ?? this.attentionReason,
      attentionErrors: attentionErrors ?? this.attentionErrors,
      attempts: attempts ?? this.attempts,
      notFoundCount: notFoundCount ?? this.notFoundCount,
      lastAttemptAt: lastAttemptAt ?? this.lastAttemptAt,
      createdAt: createdAt ?? this.createdAt,
      doneAt: doneAt ?? this.doneAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (type.present) {
      map['type'] = Variable<String>(
        $OutboxTable.$convertertype.toSql(type.value),
      );
    }
    if (wireUuid.present) {
      map['wire_uuid'] = Variable<String>(wireUuid.value);
    }
    if (recordUuid.present) {
      map['record_uuid'] = Variable<String>(recordUuid.value);
    }
    if (groupUuid.present) {
      map['group_uuid'] = Variable<String>(groupUuid.value);
    }
    if (projectId.present) {
      map['project_id'] = Variable<int>(projectId.value);
    }
    if (payload.present) {
      map['payload'] = Variable<String>(payload.value);
    }
    if (clientUpdatedAt.present) {
      map['client_updated_at'] = Variable<DateTime>(clientUpdatedAt.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(
        $OutboxTable.$converterstatus.toSql(status.value),
      );
    }
    if (filePath.present) {
      map['file_path'] = Variable<String>(filePath.value);
    }
    if (parentUuid.present) {
      map['parent_uuid'] = Variable<String>(parentUuid.value);
    }
    if (title.present) {
      map['title'] = Variable<String>(title.value);
    }
    if (subtitle.present) {
      map['subtitle'] = Variable<String>(subtitle.value);
    }
    if (resultStatus.present) {
      map['result_status'] = Variable<String>(resultStatus.value);
    }
    if (attentionCode.present) {
      map['attention_code'] = Variable<String>(attentionCode.value);
    }
    if (attentionReason.present) {
      map['attention_reason'] = Variable<String>(attentionReason.value);
    }
    if (attentionErrors.present) {
      map['attention_errors'] = Variable<String>(attentionErrors.value);
    }
    if (attempts.present) {
      map['attempts'] = Variable<int>(attempts.value);
    }
    if (notFoundCount.present) {
      map['not_found_count'] = Variable<int>(notFoundCount.value);
    }
    if (lastAttemptAt.present) {
      map['last_attempt_at'] = Variable<DateTime>(lastAttemptAt.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<DateTime>(createdAt.value);
    }
    if (doneAt.present) {
      map['done_at'] = Variable<DateTime>(doneAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('OutboxCompanion(')
          ..write('id: $id, ')
          ..write('type: $type, ')
          ..write('wireUuid: $wireUuid, ')
          ..write('recordUuid: $recordUuid, ')
          ..write('groupUuid: $groupUuid, ')
          ..write('projectId: $projectId, ')
          ..write('payload: $payload, ')
          ..write('clientUpdatedAt: $clientUpdatedAt, ')
          ..write('status: $status, ')
          ..write('filePath: $filePath, ')
          ..write('parentUuid: $parentUuid, ')
          ..write('title: $title, ')
          ..write('subtitle: $subtitle, ')
          ..write('resultStatus: $resultStatus, ')
          ..write('attentionCode: $attentionCode, ')
          ..write('attentionReason: $attentionReason, ')
          ..write('attentionErrors: $attentionErrors, ')
          ..write('attempts: $attempts, ')
          ..write('notFoundCount: $notFoundCount, ')
          ..write('lastAttemptAt: $lastAttemptAt, ')
          ..write('createdAt: $createdAt, ')
          ..write('doneAt: $doneAt')
          ..write(')'))
        .toString();
  }
}

abstract class _$AppDatabase extends GeneratedDatabase {
  _$AppDatabase(QueryExecutor e) : super(e);
  $AppDatabaseManager get managers => $AppDatabaseManager(this);
  late final $ProjectsTable projects = $ProjectsTable(this);
  late final $SitesTable sites = $SitesTable(this);
  late final $BoqItemsTable boqItems = $BoqItemsTable(this);
  late final $WorkersTable workers = $WorkersTable(this);
  late final $WalletsTable wallets = $WalletsTable(this);
  late final $UsersTable users = $UsersTable(this);
  late final $KeyValuesTable keyValues = $KeyValuesTable(this);
  late final $DprsTable dprs = $DprsTable(this);
  late final $DprPhotosTable dprPhotos = $DprPhotosTable(this);
  late final $MeasurementsTable measurements = $MeasurementsTable(this);
  late final $IssuesTable issues = $IssuesTable(this);
  late final $AttendanceMarksTable attendanceMarks = $AttendanceMarksTable(
    this,
  );
  late final $PettyCashEntriesTable pettyCashEntries = $PettyCashEntriesTable(
    this,
  );
  late final $ApprovalsInboxTable approvalsInbox = $ApprovalsInboxTable(this);
  late final $OutboxTable outbox = $OutboxTable(this);
  late final Index sitesProject = Index(
    'sites_project',
    'CREATE INDEX sites_project ON sites (project_id)',
  );
  late final Index boqItemsProjectSort = Index(
    'boq_items_project_sort',
    'CREATE INDEX boq_items_project_sort ON boq_items (project_id, sort)',
  );
  late final Index workersActiveName = Index(
    'workers_active_name',
    'CREATE INDEX workers_active_name ON workers (is_active, name)',
  );
  late final Index walletsProject = Index(
    'wallets_project',
    'CREATE INDEX wallets_project ON wallets (project_id)',
  );
  late final Index dprsProjectDate = Index(
    'dprs_project_date',
    'CREATE INDEX dprs_project_date ON dprs (project_id, report_date)',
  );
  late final Index dprsServerId = Index(
    'dprs_server_id',
    'CREATE INDEX dprs_server_id ON dprs (server_id)',
  );
  late final Index dprsStatus = Index(
    'dprs_status',
    'CREATE INDEX dprs_status ON dprs (status)',
  );
  late final Index dprPhotosDpr = Index(
    'dpr_photos_dpr',
    'CREATE INDEX dpr_photos_dpr ON dpr_photos (dpr_client_uuid)',
  );
  late final Index dprPhotosServerId = Index(
    'dpr_photos_server_id',
    'CREATE INDEX dpr_photos_server_id ON dpr_photos (server_id)',
  );
  late final Index measurementsProjectStatusDate = Index(
    'measurements_project_status_date',
    'CREATE INDEX measurements_project_status_date ON measurements (project_id, status, measured_on)',
  );
  late final Index measurementsBoq = Index(
    'measurements_boq',
    'CREATE INDEX measurements_boq ON measurements (boq_item_id)',
  );
  late final Index measurementsServerId = Index(
    'measurements_server_id',
    'CREATE INDEX measurements_server_id ON measurements (server_id)',
  );
  late final Index issuesProjectStatus = Index(
    'issues_project_status',
    'CREATE INDEX issues_project_status ON issues (project_id, status)',
  );
  late final Index issuesServerId = Index(
    'issues_server_id',
    'CREATE INDEX issues_server_id ON issues (server_id)',
  );
  late final Index attendanceProjectDate = Index(
    'attendance_project_date',
    'CREATE INDEX attendance_project_date ON attendance_marks (project_id, attendance_date)',
  );
  late final Index attendanceServerId = Index(
    'attendance_server_id',
    'CREATE INDEX attendance_server_id ON attendance_marks (server_id)',
  );
  late final Index pettyWalletDate = Index(
    'petty_wallet_date',
    'CREATE INDEX petty_wallet_date ON petty_cash_entries (wallet_id, entry_date)',
  );
  late final Index pettyServerId = Index(
    'petty_server_id',
    'CREATE INDEX petty_server_id ON petty_cash_entries (server_id)',
  );
  late final Index approvalsSubmitted = Index(
    'approvals_submitted',
    'CREATE INDEX approvals_submitted ON approvals_inbox (submitted_at)',
  );
  late final Index outboxStatusId = Index(
    'outbox_status_id',
    'CREATE INDEX outbox_status_id ON outbox (status, id)',
  );
  late final Index outboxRecord = Index(
    'outbox_record',
    'CREATE INDEX outbox_record ON outbox (record_uuid)',
  );
  late final Index outboxGroup = Index(
    'outbox_group',
    'CREATE INDEX outbox_group ON outbox (group_uuid, id)',
  );
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [
    projects,
    sites,
    boqItems,
    workers,
    wallets,
    users,
    keyValues,
    dprs,
    dprPhotos,
    measurements,
    issues,
    attendanceMarks,
    pettyCashEntries,
    approvalsInbox,
    outbox,
    sitesProject,
    boqItemsProjectSort,
    workersActiveName,
    walletsProject,
    dprsProjectDate,
    dprsServerId,
    dprsStatus,
    dprPhotosDpr,
    dprPhotosServerId,
    measurementsProjectStatusDate,
    measurementsBoq,
    measurementsServerId,
    issuesProjectStatus,
    issuesServerId,
    attendanceProjectDate,
    attendanceServerId,
    pettyWalletDate,
    pettyServerId,
    approvalsSubmitted,
    outboxStatusId,
    outboxRecord,
    outboxGroup,
  ];
}

typedef $$ProjectsTableCreateCompanionBuilder =
    ProjectsCompanion Function({
      Value<int> id,
      required String code,
      required String name,
      Value<String?> location,
      Value<String> status,
      Value<String?> startDate,
      Value<String?> endDate,
      Value<String> measurementTolerancePct,
      Value<DateTime?> updatedAt,
      Value<String?> imageUrl,
    });
typedef $$ProjectsTableUpdateCompanionBuilder =
    ProjectsCompanion Function({
      Value<int> id,
      Value<String> code,
      Value<String> name,
      Value<String?> location,
      Value<String> status,
      Value<String?> startDate,
      Value<String?> endDate,
      Value<String> measurementTolerancePct,
      Value<DateTime?> updatedAt,
      Value<String?> imageUrl,
    });

class $$ProjectsTableFilterComposer
    extends Composer<_$AppDatabase, $ProjectsTable> {
  $$ProjectsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get code => $composableBuilder(
    column: $table.code,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get location => $composableBuilder(
    column: $table.location,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get startDate => $composableBuilder(
    column: $table.startDate,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get endDate => $composableBuilder(
    column: $table.endDate,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get measurementTolerancePct => $composableBuilder(
    column: $table.measurementTolerancePct,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get imageUrl => $composableBuilder(
    column: $table.imageUrl,
    builder: (column) => ColumnFilters(column),
  );
}

class $$ProjectsTableOrderingComposer
    extends Composer<_$AppDatabase, $ProjectsTable> {
  $$ProjectsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get code => $composableBuilder(
    column: $table.code,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get location => $composableBuilder(
    column: $table.location,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get startDate => $composableBuilder(
    column: $table.startDate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get endDate => $composableBuilder(
    column: $table.endDate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get measurementTolerancePct => $composableBuilder(
    column: $table.measurementTolerancePct,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get imageUrl => $composableBuilder(
    column: $table.imageUrl,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$ProjectsTableAnnotationComposer
    extends Composer<_$AppDatabase, $ProjectsTable> {
  $$ProjectsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get code =>
      $composableBuilder(column: $table.code, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get location =>
      $composableBuilder(column: $table.location, builder: (column) => column);

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<String> get startDate =>
      $composableBuilder(column: $table.startDate, builder: (column) => column);

  GeneratedColumn<String> get endDate =>
      $composableBuilder(column: $table.endDate, builder: (column) => column);

  GeneratedColumn<String> get measurementTolerancePct => $composableBuilder(
    column: $table.measurementTolerancePct,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);

  GeneratedColumn<String> get imageUrl =>
      $composableBuilder(column: $table.imageUrl, builder: (column) => column);
}

class $$ProjectsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $ProjectsTable,
          ProjectRow,
          $$ProjectsTableFilterComposer,
          $$ProjectsTableOrderingComposer,
          $$ProjectsTableAnnotationComposer,
          $$ProjectsTableCreateCompanionBuilder,
          $$ProjectsTableUpdateCompanionBuilder,
          (
            ProjectRow,
            BaseReferences<_$AppDatabase, $ProjectsTable, ProjectRow>,
          ),
          ProjectRow,
          PrefetchHooks Function()
        > {
  $$ProjectsTableTableManager(_$AppDatabase db, $ProjectsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$ProjectsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$ProjectsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$ProjectsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<String> code = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> location = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<String?> startDate = const Value.absent(),
                Value<String?> endDate = const Value.absent(),
                Value<String> measurementTolerancePct = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<String?> imageUrl = const Value.absent(),
              }) => ProjectsCompanion(
                id: id,
                code: code,
                name: name,
                location: location,
                status: status,
                startDate: startDate,
                endDate: endDate,
                measurementTolerancePct: measurementTolerancePct,
                updatedAt: updatedAt,
                imageUrl: imageUrl,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required String code,
                required String name,
                Value<String?> location = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<String?> startDate = const Value.absent(),
                Value<String?> endDate = const Value.absent(),
                Value<String> measurementTolerancePct = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<String?> imageUrl = const Value.absent(),
              }) => ProjectsCompanion.insert(
                id: id,
                code: code,
                name: name,
                location: location,
                status: status,
                startDate: startDate,
                endDate: endDate,
                measurementTolerancePct: measurementTolerancePct,
                updatedAt: updatedAt,
                imageUrl: imageUrl,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$ProjectsTable, ProjectRow>(table),
                  BaseReferences<_$AppDatabase, $ProjectsTable, ProjectRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$ProjectsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $ProjectsTable,
      ProjectRow,
      $$ProjectsTableFilterComposer,
      $$ProjectsTableOrderingComposer,
      $$ProjectsTableAnnotationComposer,
      $$ProjectsTableCreateCompanionBuilder,
      $$ProjectsTableUpdateCompanionBuilder,
      (ProjectRow, BaseReferences<_$AppDatabase, $ProjectsTable, ProjectRow>),
      ProjectRow,
      PrefetchHooks Function()
    >;
typedef $$SitesTableCreateCompanionBuilder =
    SitesCompanion Function({
      Value<int> id,
      required int projectId,
      required String name,
      Value<String?> address,
      Value<DateTime?> updatedAt,
    });
typedef $$SitesTableUpdateCompanionBuilder =
    SitesCompanion Function({
      Value<int> id,
      Value<int> projectId,
      Value<String> name,
      Value<String?> address,
      Value<DateTime?> updatedAt,
    });

class $$SitesTableFilterComposer extends Composer<_$AppDatabase, $SitesTable> {
  $$SitesTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get address => $composableBuilder(
    column: $table.address,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$SitesTableOrderingComposer
    extends Composer<_$AppDatabase, $SitesTable> {
  $$SitesTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get address => $composableBuilder(
    column: $table.address,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$SitesTableAnnotationComposer
    extends Composer<_$AppDatabase, $SitesTable> {
  $$SitesTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get address =>
      $composableBuilder(column: $table.address, builder: (column) => column);

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$SitesTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $SitesTable,
          SiteRow,
          $$SitesTableFilterComposer,
          $$SitesTableOrderingComposer,
          $$SitesTableAnnotationComposer,
          $$SitesTableCreateCompanionBuilder,
          $$SitesTableUpdateCompanionBuilder,
          (SiteRow, BaseReferences<_$AppDatabase, $SitesTable, SiteRow>),
          SiteRow,
          PrefetchHooks Function()
        > {
  $$SitesTableTableManager(_$AppDatabase db, $SitesTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$SitesTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$SitesTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$SitesTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> address = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => SitesCompanion(
                id: id,
                projectId: projectId,
                name: name,
                address: address,
                updatedAt: updatedAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required int projectId,
                required String name,
                Value<String?> address = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => SitesCompanion.insert(
                id: id,
                projectId: projectId,
                name: name,
                address: address,
                updatedAt: updatedAt,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$SitesTable, SiteRow>(table),
                  BaseReferences<_$AppDatabase, $SitesTable, SiteRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$SitesTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $SitesTable,
      SiteRow,
      $$SitesTableFilterComposer,
      $$SitesTableOrderingComposer,
      $$SitesTableAnnotationComposer,
      $$SitesTableCreateCompanionBuilder,
      $$SitesTableUpdateCompanionBuilder,
      (SiteRow, BaseReferences<_$AppDatabase, $SitesTable, SiteRow>),
      SiteRow,
      PrefetchHooks Function()
    >;
typedef $$BoqItemsTableCreateCompanionBuilder =
    BoqItemsCompanion Function({
      Value<int> id,
      required int projectId,
      Value<String?> section,
      required String code,
      required String description,
      required String unit,
      required Qty qty,
      required Money rate,
      Value<int> sort,
      required Qty measuredQty,
      required Qty approvedQty,
      required Qty balanceQty,
      Value<DateTime?> updatedAt,
    });
typedef $$BoqItemsTableUpdateCompanionBuilder =
    BoqItemsCompanion Function({
      Value<int> id,
      Value<int> projectId,
      Value<String?> section,
      Value<String> code,
      Value<String> description,
      Value<String> unit,
      Value<Qty> qty,
      Value<Money> rate,
      Value<int> sort,
      Value<Qty> measuredQty,
      Value<Qty> approvedQty,
      Value<Qty> balanceQty,
      Value<DateTime?> updatedAt,
    });

class $$BoqItemsTableFilterComposer
    extends Composer<_$AppDatabase, $BoqItemsTable> {
  $$BoqItemsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get section => $composableBuilder(
    column: $table.section,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get code => $composableBuilder(
    column: $table.code,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get unit => $composableBuilder(
    column: $table.unit,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<Qty, Qty, String> get qty =>
      $composableBuilder(
        column: $table.qty,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnWithTypeConverterFilters<Money, Money, String> get rate =>
      $composableBuilder(
        column: $table.rate,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<int> get sort => $composableBuilder(
    column: $table.sort,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<Qty, Qty, String> get measuredQty =>
      $composableBuilder(
        column: $table.measuredQty,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnWithTypeConverterFilters<Qty, Qty, String> get approvedQty =>
      $composableBuilder(
        column: $table.approvedQty,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnWithTypeConverterFilters<Qty, Qty, String> get balanceQty =>
      $composableBuilder(
        column: $table.balanceQty,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$BoqItemsTableOrderingComposer
    extends Composer<_$AppDatabase, $BoqItemsTable> {
  $$BoqItemsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get section => $composableBuilder(
    column: $table.section,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get code => $composableBuilder(
    column: $table.code,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get unit => $composableBuilder(
    column: $table.unit,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get qty => $composableBuilder(
    column: $table.qty,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get rate => $composableBuilder(
    column: $table.rate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get sort => $composableBuilder(
    column: $table.sort,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get measuredQty => $composableBuilder(
    column: $table.measuredQty,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get approvedQty => $composableBuilder(
    column: $table.approvedQty,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get balanceQty => $composableBuilder(
    column: $table.balanceQty,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$BoqItemsTableAnnotationComposer
    extends Composer<_$AppDatabase, $BoqItemsTable> {
  $$BoqItemsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<String> get section =>
      $composableBuilder(column: $table.section, builder: (column) => column);

  GeneratedColumn<String> get code =>
      $composableBuilder(column: $table.code, builder: (column) => column);

  GeneratedColumn<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => column,
  );

  GeneratedColumn<String> get unit =>
      $composableBuilder(column: $table.unit, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Qty, String> get qty =>
      $composableBuilder(column: $table.qty, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Money, String> get rate =>
      $composableBuilder(column: $table.rate, builder: (column) => column);

  GeneratedColumn<int> get sort =>
      $composableBuilder(column: $table.sort, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Qty, String> get measuredQty =>
      $composableBuilder(
        column: $table.measuredQty,
        builder: (column) => column,
      );

  GeneratedColumnWithTypeConverter<Qty, String> get approvedQty =>
      $composableBuilder(
        column: $table.approvedQty,
        builder: (column) => column,
      );

  GeneratedColumnWithTypeConverter<Qty, String> get balanceQty =>
      $composableBuilder(
        column: $table.balanceQty,
        builder: (column) => column,
      );

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$BoqItemsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $BoqItemsTable,
          BoqItemRow,
          $$BoqItemsTableFilterComposer,
          $$BoqItemsTableOrderingComposer,
          $$BoqItemsTableAnnotationComposer,
          $$BoqItemsTableCreateCompanionBuilder,
          $$BoqItemsTableUpdateCompanionBuilder,
          (
            BoqItemRow,
            BaseReferences<_$AppDatabase, $BoqItemsTable, BoqItemRow>,
          ),
          BoqItemRow,
          PrefetchHooks Function()
        > {
  $$BoqItemsTableTableManager(_$AppDatabase db, $BoqItemsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$BoqItemsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$BoqItemsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$BoqItemsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<String?> section = const Value.absent(),
                Value<String> code = const Value.absent(),
                Value<String> description = const Value.absent(),
                Value<String> unit = const Value.absent(),
                Value<Qty> qty = const Value.absent(),
                Value<Money> rate = const Value.absent(),
                Value<int> sort = const Value.absent(),
                Value<Qty> measuredQty = const Value.absent(),
                Value<Qty> approvedQty = const Value.absent(),
                Value<Qty> balanceQty = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => BoqItemsCompanion(
                id: id,
                projectId: projectId,
                section: section,
                code: code,
                description: description,
                unit: unit,
                qty: qty,
                rate: rate,
                sort: sort,
                measuredQty: measuredQty,
                approvedQty: approvedQty,
                balanceQty: balanceQty,
                updatedAt: updatedAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required int projectId,
                Value<String?> section = const Value.absent(),
                required String code,
                required String description,
                required String unit,
                required Qty qty,
                required Money rate,
                Value<int> sort = const Value.absent(),
                required Qty measuredQty,
                required Qty approvedQty,
                required Qty balanceQty,
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => BoqItemsCompanion.insert(
                id: id,
                projectId: projectId,
                section: section,
                code: code,
                description: description,
                unit: unit,
                qty: qty,
                rate: rate,
                sort: sort,
                measuredQty: measuredQty,
                approvedQty: approvedQty,
                balanceQty: balanceQty,
                updatedAt: updatedAt,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$BoqItemsTable, BoqItemRow>(table),
                  BaseReferences<_$AppDatabase, $BoqItemsTable, BoqItemRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$BoqItemsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $BoqItemsTable,
      BoqItemRow,
      $$BoqItemsTableFilterComposer,
      $$BoqItemsTableOrderingComposer,
      $$BoqItemsTableAnnotationComposer,
      $$BoqItemsTableCreateCompanionBuilder,
      $$BoqItemsTableUpdateCompanionBuilder,
      (BoqItemRow, BaseReferences<_$AppDatabase, $BoqItemsTable, BoqItemRow>),
      BoqItemRow,
      PrefetchHooks Function()
    >;
typedef $$WorkersTableCreateCompanionBuilder =
    WorkersCompanion Function({
      Value<int> id,
      required String name,
      Value<String?> phone,
      required String trade,
      Value<String> type,
      Value<bool> isActive,
      Value<DateTime?> updatedAt,
    });
typedef $$WorkersTableUpdateCompanionBuilder =
    WorkersCompanion Function({
      Value<int> id,
      Value<String> name,
      Value<String?> phone,
      Value<String> trade,
      Value<String> type,
      Value<bool> isActive,
      Value<DateTime?> updatedAt,
    });

class $$WorkersTableFilterComposer
    extends Composer<_$AppDatabase, $WorkersTable> {
  $$WorkersTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get phone => $composableBuilder(
    column: $table.phone,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get trade => $composableBuilder(
    column: $table.trade,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get type => $composableBuilder(
    column: $table.type,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$WorkersTableOrderingComposer
    extends Composer<_$AppDatabase, $WorkersTable> {
  $$WorkersTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get phone => $composableBuilder(
    column: $table.phone,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get trade => $composableBuilder(
    column: $table.trade,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get type => $composableBuilder(
    column: $table.type,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$WorkersTableAnnotationComposer
    extends Composer<_$AppDatabase, $WorkersTable> {
  $$WorkersTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get phone =>
      $composableBuilder(column: $table.phone, builder: (column) => column);

  GeneratedColumn<String> get trade =>
      $composableBuilder(column: $table.trade, builder: (column) => column);

  GeneratedColumn<String> get type =>
      $composableBuilder(column: $table.type, builder: (column) => column);

  GeneratedColumn<bool> get isActive =>
      $composableBuilder(column: $table.isActive, builder: (column) => column);

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$WorkersTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $WorkersTable,
          WorkerRow,
          $$WorkersTableFilterComposer,
          $$WorkersTableOrderingComposer,
          $$WorkersTableAnnotationComposer,
          $$WorkersTableCreateCompanionBuilder,
          $$WorkersTableUpdateCompanionBuilder,
          (WorkerRow, BaseReferences<_$AppDatabase, $WorkersTable, WorkerRow>),
          WorkerRow,
          PrefetchHooks Function()
        > {
  $$WorkersTableTableManager(_$AppDatabase db, $WorkersTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$WorkersTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$WorkersTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$WorkersTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> phone = const Value.absent(),
                Value<String> trade = const Value.absent(),
                Value<String> type = const Value.absent(),
                Value<bool> isActive = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => WorkersCompanion(
                id: id,
                name: name,
                phone: phone,
                trade: trade,
                type: type,
                isActive: isActive,
                updatedAt: updatedAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required String name,
                Value<String?> phone = const Value.absent(),
                required String trade,
                Value<String> type = const Value.absent(),
                Value<bool> isActive = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => WorkersCompanion.insert(
                id: id,
                name: name,
                phone: phone,
                trade: trade,
                type: type,
                isActive: isActive,
                updatedAt: updatedAt,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$WorkersTable, WorkerRow>(table),
                  BaseReferences<_$AppDatabase, $WorkersTable, WorkerRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$WorkersTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $WorkersTable,
      WorkerRow,
      $$WorkersTableFilterComposer,
      $$WorkersTableOrderingComposer,
      $$WorkersTableAnnotationComposer,
      $$WorkersTableCreateCompanionBuilder,
      $$WorkersTableUpdateCompanionBuilder,
      (WorkerRow, BaseReferences<_$AppDatabase, $WorkersTable, WorkerRow>),
      WorkerRow,
      PrefetchHooks Function()
    >;
typedef $$WalletsTableCreateCompanionBuilder =
    WalletsCompanion Function({
      Value<int> id,
      required int projectId,
      required String name,
      Value<int?> userId,
      required Money balance,
      Value<DateTime?> updatedAt,
    });
typedef $$WalletsTableUpdateCompanionBuilder =
    WalletsCompanion Function({
      Value<int> id,
      Value<int> projectId,
      Value<String> name,
      Value<int?> userId,
      Value<Money> balance,
      Value<DateTime?> updatedAt,
    });

class $$WalletsTableFilterComposer
    extends Composer<_$AppDatabase, $WalletsTable> {
  $$WalletsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get userId => $composableBuilder(
    column: $table.userId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<Money, Money, String> get balance =>
      $composableBuilder(
        column: $table.balance,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$WalletsTableOrderingComposer
    extends Composer<_$AppDatabase, $WalletsTable> {
  $$WalletsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get userId => $composableBuilder(
    column: $table.userId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get balance => $composableBuilder(
    column: $table.balance,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$WalletsTableAnnotationComposer
    extends Composer<_$AppDatabase, $WalletsTable> {
  $$WalletsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<int> get userId =>
      $composableBuilder(column: $table.userId, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Money, String> get balance =>
      $composableBuilder(column: $table.balance, builder: (column) => column);

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$WalletsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $WalletsTable,
          WalletRow,
          $$WalletsTableFilterComposer,
          $$WalletsTableOrderingComposer,
          $$WalletsTableAnnotationComposer,
          $$WalletsTableCreateCompanionBuilder,
          $$WalletsTableUpdateCompanionBuilder,
          (WalletRow, BaseReferences<_$AppDatabase, $WalletsTable, WalletRow>),
          WalletRow,
          PrefetchHooks Function()
        > {
  $$WalletsTableTableManager(_$AppDatabase db, $WalletsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$WalletsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$WalletsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$WalletsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<int?> userId = const Value.absent(),
                Value<Money> balance = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => WalletsCompanion(
                id: id,
                projectId: projectId,
                name: name,
                userId: userId,
                balance: balance,
                updatedAt: updatedAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required int projectId,
                required String name,
                Value<int?> userId = const Value.absent(),
                required Money balance,
                Value<DateTime?> updatedAt = const Value.absent(),
              }) => WalletsCompanion.insert(
                id: id,
                projectId: projectId,
                name: name,
                userId: userId,
                balance: balance,
                updatedAt: updatedAt,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$WalletsTable, WalletRow>(table),
                  BaseReferences<_$AppDatabase, $WalletsTable, WalletRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$WalletsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $WalletsTable,
      WalletRow,
      $$WalletsTableFilterComposer,
      $$WalletsTableOrderingComposer,
      $$WalletsTableAnnotationComposer,
      $$WalletsTableCreateCompanionBuilder,
      $$WalletsTableUpdateCompanionBuilder,
      (WalletRow, BaseReferences<_$AppDatabase, $WalletsTable, WalletRow>),
      WalletRow,
      PrefetchHooks Function()
    >;
typedef $$UsersTableCreateCompanionBuilder =
    UsersCompanion Function({
      Value<int> id,
      required String name,
      Value<String?> role,
    });
typedef $$UsersTableUpdateCompanionBuilder =
    UsersCompanion Function({
      Value<int> id,
      Value<String> name,
      Value<String?> role,
    });

class $$UsersTableFilterComposer extends Composer<_$AppDatabase, $UsersTable> {
  $$UsersTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get role => $composableBuilder(
    column: $table.role,
    builder: (column) => ColumnFilters(column),
  );
}

class $$UsersTableOrderingComposer
    extends Composer<_$AppDatabase, $UsersTable> {
  $$UsersTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get role => $composableBuilder(
    column: $table.role,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$UsersTableAnnotationComposer
    extends Composer<_$AppDatabase, $UsersTable> {
  $$UsersTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get role =>
      $composableBuilder(column: $table.role, builder: (column) => column);
}

class $$UsersTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $UsersTable,
          UserRow,
          $$UsersTableFilterComposer,
          $$UsersTableOrderingComposer,
          $$UsersTableAnnotationComposer,
          $$UsersTableCreateCompanionBuilder,
          $$UsersTableUpdateCompanionBuilder,
          (UserRow, BaseReferences<_$AppDatabase, $UsersTable, UserRow>),
          UserRow,
          PrefetchHooks Function()
        > {
  $$UsersTableTableManager(_$AppDatabase db, $UsersTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$UsersTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$UsersTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$UsersTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> role = const Value.absent(),
              }) => UsersCompanion(id: id, name: name, role: role),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required String name,
                Value<String?> role = const Value.absent(),
              }) => UsersCompanion.insert(id: id, name: name, role: role),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$UsersTable, UserRow>(table),
                  BaseReferences<_$AppDatabase, $UsersTable, UserRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$UsersTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $UsersTable,
      UserRow,
      $$UsersTableFilterComposer,
      $$UsersTableOrderingComposer,
      $$UsersTableAnnotationComposer,
      $$UsersTableCreateCompanionBuilder,
      $$UsersTableUpdateCompanionBuilder,
      (UserRow, BaseReferences<_$AppDatabase, $UsersTable, UserRow>),
      UserRow,
      PrefetchHooks Function()
    >;
typedef $$KeyValuesTableCreateCompanionBuilder =
    KeyValuesCompanion Function({
      required String key,
      required String value,
      Value<int> rowid,
    });
typedef $$KeyValuesTableUpdateCompanionBuilder =
    KeyValuesCompanion Function({
      Value<String> key,
      Value<String> value,
      Value<int> rowid,
    });

class $$KeyValuesTableFilterComposer
    extends Composer<_$AppDatabase, $KeyValuesTable> {
  $$KeyValuesTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnFilters(column),
  );
}

class $$KeyValuesTableOrderingComposer
    extends Composer<_$AppDatabase, $KeyValuesTable> {
  $$KeyValuesTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$KeyValuesTableAnnotationComposer
    extends Composer<_$AppDatabase, $KeyValuesTable> {
  $$KeyValuesTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get key =>
      $composableBuilder(column: $table.key, builder: (column) => column);

  GeneratedColumn<String> get value =>
      $composableBuilder(column: $table.value, builder: (column) => column);
}

class $$KeyValuesTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $KeyValuesTable,
          KeyValueRow,
          $$KeyValuesTableFilterComposer,
          $$KeyValuesTableOrderingComposer,
          $$KeyValuesTableAnnotationComposer,
          $$KeyValuesTableCreateCompanionBuilder,
          $$KeyValuesTableUpdateCompanionBuilder,
          (
            KeyValueRow,
            BaseReferences<_$AppDatabase, $KeyValuesTable, KeyValueRow>,
          ),
          KeyValueRow,
          PrefetchHooks Function()
        > {
  $$KeyValuesTableTableManager(_$AppDatabase db, $KeyValuesTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$KeyValuesTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$KeyValuesTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$KeyValuesTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> key = const Value.absent(),
                Value<String> value = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => KeyValuesCompanion(key: key, value: value, rowid: rowid),
          createCompanionCallback:
              ({
                required String key,
                required String value,
                Value<int> rowid = const Value.absent(),
              }) => KeyValuesCompanion.insert(
                key: key,
                value: value,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$KeyValuesTable, KeyValueRow>(table),
                  BaseReferences<_$AppDatabase, $KeyValuesTable, KeyValueRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$KeyValuesTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $KeyValuesTable,
      KeyValueRow,
      $$KeyValuesTableFilterComposer,
      $$KeyValuesTableOrderingComposer,
      $$KeyValuesTableAnnotationComposer,
      $$KeyValuesTableCreateCompanionBuilder,
      $$KeyValuesTableUpdateCompanionBuilder,
      (
        KeyValueRow,
        BaseReferences<_$AppDatabase, $KeyValuesTable, KeyValueRow>,
      ),
      KeyValueRow,
      PrefetchHooks Function()
    >;
typedef $$DprsTableCreateCompanionBuilder =
    DprsCompanion Function({
      required String clientUuid,
      Value<int?> serverId,
      required int projectId,
      Value<int?> siteId,
      required String reportDate,
      Value<String?> weather,
      Value<String?> summary,
      Value<String?> hindrances,
      Value<String> status,
      Value<bool> localDraft,
      Value<int?> createdBy,
      Value<String?> createdByName,
      required List<ManpowerLine> manpower,
      required List<MachineryLine> machinery,
      required List<WorkLine> workItems,
      Value<String?> returnRemark,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });
typedef $$DprsTableUpdateCompanionBuilder =
    DprsCompanion Function({
      Value<String> clientUuid,
      Value<int?> serverId,
      Value<int> projectId,
      Value<int?> siteId,
      Value<String> reportDate,
      Value<String?> weather,
      Value<String?> summary,
      Value<String?> hindrances,
      Value<String> status,
      Value<bool> localDraft,
      Value<int?> createdBy,
      Value<String?> createdByName,
      Value<List<ManpowerLine>> manpower,
      Value<List<MachineryLine>> machinery,
      Value<List<WorkLine>> workItems,
      Value<String?> returnRemark,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });

class $$DprsTableFilterComposer extends Composer<_$AppDatabase, $DprsTable> {
  $$DprsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get siteId => $composableBuilder(
    column: $table.siteId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get reportDate => $composableBuilder(
    column: $table.reportDate,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get weather => $composableBuilder(
    column: $table.weather,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get summary => $composableBuilder(
    column: $table.summary,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get hindrances => $composableBuilder(
    column: $table.hindrances,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get localDraft => $composableBuilder(
    column: $table.localDraft,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdBy => $composableBuilder(
    column: $table.createdBy,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get createdByName => $composableBuilder(
    column: $table.createdByName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<List<ManpowerLine>, List<ManpowerLine>, String>
  get manpower => $composableBuilder(
    column: $table.manpower,
    builder: (column) => ColumnWithTypeConverterFilters(column),
  );

  ColumnWithTypeConverterFilters<
    List<MachineryLine>,
    List<MachineryLine>,
    String
  >
  get machinery => $composableBuilder(
    column: $table.machinery,
    builder: (column) => ColumnWithTypeConverterFilters(column),
  );

  ColumnWithTypeConverterFilters<List<WorkLine>, List<WorkLine>, String>
  get workItems => $composableBuilder(
    column: $table.workItems,
    builder: (column) => ColumnWithTypeConverterFilters(column),
  );

  ColumnFilters<String> get returnRemark => $composableBuilder(
    column: $table.returnRemark,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$DprsTableOrderingComposer extends Composer<_$AppDatabase, $DprsTable> {
  $$DprsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get siteId => $composableBuilder(
    column: $table.siteId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get reportDate => $composableBuilder(
    column: $table.reportDate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get weather => $composableBuilder(
    column: $table.weather,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get summary => $composableBuilder(
    column: $table.summary,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get hindrances => $composableBuilder(
    column: $table.hindrances,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get localDraft => $composableBuilder(
    column: $table.localDraft,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdBy => $composableBuilder(
    column: $table.createdBy,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get createdByName => $composableBuilder(
    column: $table.createdByName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get manpower => $composableBuilder(
    column: $table.manpower,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get machinery => $composableBuilder(
    column: $table.machinery,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get workItems => $composableBuilder(
    column: $table.workItems,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get returnRemark => $composableBuilder(
    column: $table.returnRemark,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$DprsTableAnnotationComposer
    extends Composer<_$AppDatabase, $DprsTable> {
  $$DprsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => column,
  );

  GeneratedColumn<int> get serverId =>
      $composableBuilder(column: $table.serverId, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<int> get siteId =>
      $composableBuilder(column: $table.siteId, builder: (column) => column);

  GeneratedColumn<String> get reportDate => $composableBuilder(
    column: $table.reportDate,
    builder: (column) => column,
  );

  GeneratedColumn<String> get weather =>
      $composableBuilder(column: $table.weather, builder: (column) => column);

  GeneratedColumn<String> get summary =>
      $composableBuilder(column: $table.summary, builder: (column) => column);

  GeneratedColumn<String> get hindrances => $composableBuilder(
    column: $table.hindrances,
    builder: (column) => column,
  );

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<bool> get localDraft => $composableBuilder(
    column: $table.localDraft,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdBy =>
      $composableBuilder(column: $table.createdBy, builder: (column) => column);

  GeneratedColumn<String> get createdByName => $composableBuilder(
    column: $table.createdByName,
    builder: (column) => column,
  );

  GeneratedColumnWithTypeConverter<List<ManpowerLine>, String> get manpower =>
      $composableBuilder(column: $table.manpower, builder: (column) => column);

  GeneratedColumnWithTypeConverter<List<MachineryLine>, String> get machinery =>
      $composableBuilder(column: $table.machinery, builder: (column) => column);

  GeneratedColumnWithTypeConverter<List<WorkLine>, String> get workItems =>
      $composableBuilder(column: $table.workItems, builder: (column) => column);

  GeneratedColumn<String> get returnRemark => $composableBuilder(
    column: $table.returnRemark,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$DprsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $DprsTable,
          DprRow,
          $$DprsTableFilterComposer,
          $$DprsTableOrderingComposer,
          $$DprsTableAnnotationComposer,
          $$DprsTableCreateCompanionBuilder,
          $$DprsTableUpdateCompanionBuilder,
          (DprRow, BaseReferences<_$AppDatabase, $DprsTable, DprRow>),
          DprRow,
          PrefetchHooks Function()
        > {
  $$DprsTableTableManager(_$AppDatabase db, $DprsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$DprsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$DprsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$DprsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clientUuid = const Value.absent(),
                Value<int?> serverId = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<int?> siteId = const Value.absent(),
                Value<String> reportDate = const Value.absent(),
                Value<String?> weather = const Value.absent(),
                Value<String?> summary = const Value.absent(),
                Value<String?> hindrances = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<bool> localDraft = const Value.absent(),
                Value<int?> createdBy = const Value.absent(),
                Value<String?> createdByName = const Value.absent(),
                Value<List<ManpowerLine>> manpower = const Value.absent(),
                Value<List<MachineryLine>> machinery = const Value.absent(),
                Value<List<WorkLine>> workItems = const Value.absent(),
                Value<String?> returnRemark = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => DprsCompanion(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                siteId: siteId,
                reportDate: reportDate,
                weather: weather,
                summary: summary,
                hindrances: hindrances,
                status: status,
                localDraft: localDraft,
                createdBy: createdBy,
                createdByName: createdByName,
                manpower: manpower,
                machinery: machinery,
                workItems: workItems,
                returnRemark: returnRemark,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clientUuid,
                Value<int?> serverId = const Value.absent(),
                required int projectId,
                Value<int?> siteId = const Value.absent(),
                required String reportDate,
                Value<String?> weather = const Value.absent(),
                Value<String?> summary = const Value.absent(),
                Value<String?> hindrances = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<bool> localDraft = const Value.absent(),
                Value<int?> createdBy = const Value.absent(),
                Value<String?> createdByName = const Value.absent(),
                required List<ManpowerLine> manpower,
                required List<MachineryLine> machinery,
                required List<WorkLine> workItems,
                Value<String?> returnRemark = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => DprsCompanion.insert(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                siteId: siteId,
                reportDate: reportDate,
                weather: weather,
                summary: summary,
                hindrances: hindrances,
                status: status,
                localDraft: localDraft,
                createdBy: createdBy,
                createdByName: createdByName,
                manpower: manpower,
                machinery: machinery,
                workItems: workItems,
                returnRemark: returnRemark,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$DprsTable, DprRow>(table),
                  BaseReferences<_$AppDatabase, $DprsTable, DprRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$DprsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $DprsTable,
      DprRow,
      $$DprsTableFilterComposer,
      $$DprsTableOrderingComposer,
      $$DprsTableAnnotationComposer,
      $$DprsTableCreateCompanionBuilder,
      $$DprsTableUpdateCompanionBuilder,
      (DprRow, BaseReferences<_$AppDatabase, $DprsTable, DprRow>),
      DprRow,
      PrefetchHooks Function()
    >;
typedef $$DprPhotosTableCreateCompanionBuilder =
    DprPhotosCompanion Function({
      required String clientUuid,
      Value<int?> serverId,
      required String dprClientUuid,
      Value<String?> relativePath,
      Value<String?> caption,
      Value<DateTime?> takenAt,
      Value<DateTime?> createdAt,
      Value<int> rowid,
    });
typedef $$DprPhotosTableUpdateCompanionBuilder =
    DprPhotosCompanion Function({
      Value<String> clientUuid,
      Value<int?> serverId,
      Value<String> dprClientUuid,
      Value<String?> relativePath,
      Value<String?> caption,
      Value<DateTime?> takenAt,
      Value<DateTime?> createdAt,
      Value<int> rowid,
    });

class $$DprPhotosTableFilterComposer
    extends Composer<_$AppDatabase, $DprPhotosTable> {
  $$DprPhotosTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get dprClientUuid => $composableBuilder(
    column: $table.dprClientUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get relativePath => $composableBuilder(
    column: $table.relativePath,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get caption => $composableBuilder(
    column: $table.caption,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get takenAt => $composableBuilder(
    column: $table.takenAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$DprPhotosTableOrderingComposer
    extends Composer<_$AppDatabase, $DprPhotosTable> {
  $$DprPhotosTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get dprClientUuid => $composableBuilder(
    column: $table.dprClientUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get relativePath => $composableBuilder(
    column: $table.relativePath,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get caption => $composableBuilder(
    column: $table.caption,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get takenAt => $composableBuilder(
    column: $table.takenAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$DprPhotosTableAnnotationComposer
    extends Composer<_$AppDatabase, $DprPhotosTable> {
  $$DprPhotosTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => column,
  );

  GeneratedColumn<int> get serverId =>
      $composableBuilder(column: $table.serverId, builder: (column) => column);

  GeneratedColumn<String> get dprClientUuid => $composableBuilder(
    column: $table.dprClientUuid,
    builder: (column) => column,
  );

  GeneratedColumn<String> get relativePath => $composableBuilder(
    column: $table.relativePath,
    builder: (column) => column,
  );

  GeneratedColumn<String> get caption =>
      $composableBuilder(column: $table.caption, builder: (column) => column);

  GeneratedColumn<DateTime> get takenAt =>
      $composableBuilder(column: $table.takenAt, builder: (column) => column);

  GeneratedColumn<DateTime> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);
}

class $$DprPhotosTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $DprPhotosTable,
          DprPhotoRow,
          $$DprPhotosTableFilterComposer,
          $$DprPhotosTableOrderingComposer,
          $$DprPhotosTableAnnotationComposer,
          $$DprPhotosTableCreateCompanionBuilder,
          $$DprPhotosTableUpdateCompanionBuilder,
          (
            DprPhotoRow,
            BaseReferences<_$AppDatabase, $DprPhotosTable, DprPhotoRow>,
          ),
          DprPhotoRow,
          PrefetchHooks Function()
        > {
  $$DprPhotosTableTableManager(_$AppDatabase db, $DprPhotosTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$DprPhotosTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$DprPhotosTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$DprPhotosTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clientUuid = const Value.absent(),
                Value<int?> serverId = const Value.absent(),
                Value<String> dprClientUuid = const Value.absent(),
                Value<String?> relativePath = const Value.absent(),
                Value<String?> caption = const Value.absent(),
                Value<DateTime?> takenAt = const Value.absent(),
                Value<DateTime?> createdAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => DprPhotosCompanion(
                clientUuid: clientUuid,
                serverId: serverId,
                dprClientUuid: dprClientUuid,
                relativePath: relativePath,
                caption: caption,
                takenAt: takenAt,
                createdAt: createdAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clientUuid,
                Value<int?> serverId = const Value.absent(),
                required String dprClientUuid,
                Value<String?> relativePath = const Value.absent(),
                Value<String?> caption = const Value.absent(),
                Value<DateTime?> takenAt = const Value.absent(),
                Value<DateTime?> createdAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => DprPhotosCompanion.insert(
                clientUuid: clientUuid,
                serverId: serverId,
                dprClientUuid: dprClientUuid,
                relativePath: relativePath,
                caption: caption,
                takenAt: takenAt,
                createdAt: createdAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$DprPhotosTable, DprPhotoRow>(table),
                  BaseReferences<_$AppDatabase, $DprPhotosTable, DprPhotoRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$DprPhotosTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $DprPhotosTable,
      DprPhotoRow,
      $$DprPhotosTableFilterComposer,
      $$DprPhotosTableOrderingComposer,
      $$DprPhotosTableAnnotationComposer,
      $$DprPhotosTableCreateCompanionBuilder,
      $$DprPhotosTableUpdateCompanionBuilder,
      (
        DprPhotoRow,
        BaseReferences<_$AppDatabase, $DprPhotosTable, DprPhotoRow>,
      ),
      DprPhotoRow,
      PrefetchHooks Function()
    >;
typedef $$MeasurementsTableCreateCompanionBuilder =
    MeasurementsCompanion Function({
      required String clientUuid,
      Value<int?> serverId,
      required int projectId,
      required int boqItemId,
      Value<int?> workOrderId,
      required String measuredOn,
      Value<String?> description,
      Value<String?> location,
      Value<Qty?> nos,
      Value<Qty?> length,
      Value<Qty?> breadth,
      Value<Qty?> depth,
      Value<bool> isDeduction,
      required Qty qty,
      Value<Qty?> serverQty,
      Value<String> status,
      Value<bool> deviation,
      Value<String?> remarks,
      Value<String?> returnRemark,
      Value<int?> createdBy,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });
typedef $$MeasurementsTableUpdateCompanionBuilder =
    MeasurementsCompanion Function({
      Value<String> clientUuid,
      Value<int?> serverId,
      Value<int> projectId,
      Value<int> boqItemId,
      Value<int?> workOrderId,
      Value<String> measuredOn,
      Value<String?> description,
      Value<String?> location,
      Value<Qty?> nos,
      Value<Qty?> length,
      Value<Qty?> breadth,
      Value<Qty?> depth,
      Value<bool> isDeduction,
      Value<Qty> qty,
      Value<Qty?> serverQty,
      Value<String> status,
      Value<bool> deviation,
      Value<String?> remarks,
      Value<String?> returnRemark,
      Value<int?> createdBy,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });

class $$MeasurementsTableFilterComposer
    extends Composer<_$AppDatabase, $MeasurementsTable> {
  $$MeasurementsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get boqItemId => $composableBuilder(
    column: $table.boqItemId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get workOrderId => $composableBuilder(
    column: $table.workOrderId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get measuredOn => $composableBuilder(
    column: $table.measuredOn,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get location => $composableBuilder(
    column: $table.location,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<Qty?, Qty, String> get nos =>
      $composableBuilder(
        column: $table.nos,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnWithTypeConverterFilters<Qty?, Qty, String> get length =>
      $composableBuilder(
        column: $table.length,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnWithTypeConverterFilters<Qty?, Qty, String> get breadth =>
      $composableBuilder(
        column: $table.breadth,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnWithTypeConverterFilters<Qty?, Qty, String> get depth =>
      $composableBuilder(
        column: $table.depth,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<bool> get isDeduction => $composableBuilder(
    column: $table.isDeduction,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<Qty, Qty, String> get qty =>
      $composableBuilder(
        column: $table.qty,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnWithTypeConverterFilters<Qty?, Qty, String> get serverQty =>
      $composableBuilder(
        column: $table.serverQty,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get deviation => $composableBuilder(
    column: $table.deviation,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get remarks => $composableBuilder(
    column: $table.remarks,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get returnRemark => $composableBuilder(
    column: $table.returnRemark,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdBy => $composableBuilder(
    column: $table.createdBy,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$MeasurementsTableOrderingComposer
    extends Composer<_$AppDatabase, $MeasurementsTable> {
  $$MeasurementsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get boqItemId => $composableBuilder(
    column: $table.boqItemId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get workOrderId => $composableBuilder(
    column: $table.workOrderId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get measuredOn => $composableBuilder(
    column: $table.measuredOn,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get location => $composableBuilder(
    column: $table.location,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get nos => $composableBuilder(
    column: $table.nos,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get length => $composableBuilder(
    column: $table.length,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get breadth => $composableBuilder(
    column: $table.breadth,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get depth => $composableBuilder(
    column: $table.depth,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get isDeduction => $composableBuilder(
    column: $table.isDeduction,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get qty => $composableBuilder(
    column: $table.qty,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get serverQty => $composableBuilder(
    column: $table.serverQty,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get deviation => $composableBuilder(
    column: $table.deviation,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get remarks => $composableBuilder(
    column: $table.remarks,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get returnRemark => $composableBuilder(
    column: $table.returnRemark,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdBy => $composableBuilder(
    column: $table.createdBy,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$MeasurementsTableAnnotationComposer
    extends Composer<_$AppDatabase, $MeasurementsTable> {
  $$MeasurementsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => column,
  );

  GeneratedColumn<int> get serverId =>
      $composableBuilder(column: $table.serverId, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<int> get boqItemId =>
      $composableBuilder(column: $table.boqItemId, builder: (column) => column);

  GeneratedColumn<int> get workOrderId => $composableBuilder(
    column: $table.workOrderId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get measuredOn => $composableBuilder(
    column: $table.measuredOn,
    builder: (column) => column,
  );

  GeneratedColumn<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => column,
  );

  GeneratedColumn<String> get location =>
      $composableBuilder(column: $table.location, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Qty?, String> get nos =>
      $composableBuilder(column: $table.nos, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Qty?, String> get length =>
      $composableBuilder(column: $table.length, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Qty?, String> get breadth =>
      $composableBuilder(column: $table.breadth, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Qty?, String> get depth =>
      $composableBuilder(column: $table.depth, builder: (column) => column);

  GeneratedColumn<bool> get isDeduction => $composableBuilder(
    column: $table.isDeduction,
    builder: (column) => column,
  );

  GeneratedColumnWithTypeConverter<Qty, String> get qty =>
      $composableBuilder(column: $table.qty, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Qty?, String> get serverQty =>
      $composableBuilder(column: $table.serverQty, builder: (column) => column);

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<bool> get deviation =>
      $composableBuilder(column: $table.deviation, builder: (column) => column);

  GeneratedColumn<String> get remarks =>
      $composableBuilder(column: $table.remarks, builder: (column) => column);

  GeneratedColumn<String> get returnRemark => $composableBuilder(
    column: $table.returnRemark,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdBy =>
      $composableBuilder(column: $table.createdBy, builder: (column) => column);

  GeneratedColumn<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$MeasurementsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $MeasurementsTable,
          MeasurementRow,
          $$MeasurementsTableFilterComposer,
          $$MeasurementsTableOrderingComposer,
          $$MeasurementsTableAnnotationComposer,
          $$MeasurementsTableCreateCompanionBuilder,
          $$MeasurementsTableUpdateCompanionBuilder,
          (
            MeasurementRow,
            BaseReferences<_$AppDatabase, $MeasurementsTable, MeasurementRow>,
          ),
          MeasurementRow,
          PrefetchHooks Function()
        > {
  $$MeasurementsTableTableManager(_$AppDatabase db, $MeasurementsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$MeasurementsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$MeasurementsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$MeasurementsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clientUuid = const Value.absent(),
                Value<int?> serverId = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<int> boqItemId = const Value.absent(),
                Value<int?> workOrderId = const Value.absent(),
                Value<String> measuredOn = const Value.absent(),
                Value<String?> description = const Value.absent(),
                Value<String?> location = const Value.absent(),
                Value<Qty?> nos = const Value.absent(),
                Value<Qty?> length = const Value.absent(),
                Value<Qty?> breadth = const Value.absent(),
                Value<Qty?> depth = const Value.absent(),
                Value<bool> isDeduction = const Value.absent(),
                Value<Qty> qty = const Value.absent(),
                Value<Qty?> serverQty = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<bool> deviation = const Value.absent(),
                Value<String?> remarks = const Value.absent(),
                Value<String?> returnRemark = const Value.absent(),
                Value<int?> createdBy = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => MeasurementsCompanion(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                boqItemId: boqItemId,
                workOrderId: workOrderId,
                measuredOn: measuredOn,
                description: description,
                location: location,
                nos: nos,
                length: length,
                breadth: breadth,
                depth: depth,
                isDeduction: isDeduction,
                qty: qty,
                serverQty: serverQty,
                status: status,
                deviation: deviation,
                remarks: remarks,
                returnRemark: returnRemark,
                createdBy: createdBy,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clientUuid,
                Value<int?> serverId = const Value.absent(),
                required int projectId,
                required int boqItemId,
                Value<int?> workOrderId = const Value.absent(),
                required String measuredOn,
                Value<String?> description = const Value.absent(),
                Value<String?> location = const Value.absent(),
                Value<Qty?> nos = const Value.absent(),
                Value<Qty?> length = const Value.absent(),
                Value<Qty?> breadth = const Value.absent(),
                Value<Qty?> depth = const Value.absent(),
                Value<bool> isDeduction = const Value.absent(),
                required Qty qty,
                Value<Qty?> serverQty = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<bool> deviation = const Value.absent(),
                Value<String?> remarks = const Value.absent(),
                Value<String?> returnRemark = const Value.absent(),
                Value<int?> createdBy = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => MeasurementsCompanion.insert(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                boqItemId: boqItemId,
                workOrderId: workOrderId,
                measuredOn: measuredOn,
                description: description,
                location: location,
                nos: nos,
                length: length,
                breadth: breadth,
                depth: depth,
                isDeduction: isDeduction,
                qty: qty,
                serverQty: serverQty,
                status: status,
                deviation: deviation,
                remarks: remarks,
                returnRemark: returnRemark,
                createdBy: createdBy,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$MeasurementsTable, MeasurementRow>(table),
                  BaseReferences<
                    _$AppDatabase,
                    $MeasurementsTable,
                    MeasurementRow
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$MeasurementsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $MeasurementsTable,
      MeasurementRow,
      $$MeasurementsTableFilterComposer,
      $$MeasurementsTableOrderingComposer,
      $$MeasurementsTableAnnotationComposer,
      $$MeasurementsTableCreateCompanionBuilder,
      $$MeasurementsTableUpdateCompanionBuilder,
      (
        MeasurementRow,
        BaseReferences<_$AppDatabase, $MeasurementsTable, MeasurementRow>,
      ),
      MeasurementRow,
      PrefetchHooks Function()
    >;
typedef $$IssuesTableCreateCompanionBuilder =
    IssuesCompanion Function({
      required String clientUuid,
      Value<int?> serverId,
      required int projectId,
      Value<int?> siteId,
      required String title,
      Value<String?> description,
      Value<String> priority,
      Value<String> status,
      Value<String?> locationNote,
      Value<int?> assignedTo,
      Value<int?> raisedBy,
      Value<String?> dueDate,
      Value<DateTime?> resolvedAt,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });
typedef $$IssuesTableUpdateCompanionBuilder =
    IssuesCompanion Function({
      Value<String> clientUuid,
      Value<int?> serverId,
      Value<int> projectId,
      Value<int?> siteId,
      Value<String> title,
      Value<String?> description,
      Value<String> priority,
      Value<String> status,
      Value<String?> locationNote,
      Value<int?> assignedTo,
      Value<int?> raisedBy,
      Value<String?> dueDate,
      Value<DateTime?> resolvedAt,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });

class $$IssuesTableFilterComposer
    extends Composer<_$AppDatabase, $IssuesTable> {
  $$IssuesTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get siteId => $composableBuilder(
    column: $table.siteId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get title => $composableBuilder(
    column: $table.title,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get priority => $composableBuilder(
    column: $table.priority,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get locationNote => $composableBuilder(
    column: $table.locationNote,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get assignedTo => $composableBuilder(
    column: $table.assignedTo,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get raisedBy => $composableBuilder(
    column: $table.raisedBy,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get dueDate => $composableBuilder(
    column: $table.dueDate,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get resolvedAt => $composableBuilder(
    column: $table.resolvedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$IssuesTableOrderingComposer
    extends Composer<_$AppDatabase, $IssuesTable> {
  $$IssuesTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get siteId => $composableBuilder(
    column: $table.siteId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get title => $composableBuilder(
    column: $table.title,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get priority => $composableBuilder(
    column: $table.priority,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get locationNote => $composableBuilder(
    column: $table.locationNote,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get assignedTo => $composableBuilder(
    column: $table.assignedTo,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get raisedBy => $composableBuilder(
    column: $table.raisedBy,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get dueDate => $composableBuilder(
    column: $table.dueDate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get resolvedAt => $composableBuilder(
    column: $table.resolvedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$IssuesTableAnnotationComposer
    extends Composer<_$AppDatabase, $IssuesTable> {
  $$IssuesTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => column,
  );

  GeneratedColumn<int> get serverId =>
      $composableBuilder(column: $table.serverId, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<int> get siteId =>
      $composableBuilder(column: $table.siteId, builder: (column) => column);

  GeneratedColumn<String> get title =>
      $composableBuilder(column: $table.title, builder: (column) => column);

  GeneratedColumn<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => column,
  );

  GeneratedColumn<String> get priority =>
      $composableBuilder(column: $table.priority, builder: (column) => column);

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<String> get locationNote => $composableBuilder(
    column: $table.locationNote,
    builder: (column) => column,
  );

  GeneratedColumn<int> get assignedTo => $composableBuilder(
    column: $table.assignedTo,
    builder: (column) => column,
  );

  GeneratedColumn<int> get raisedBy =>
      $composableBuilder(column: $table.raisedBy, builder: (column) => column);

  GeneratedColumn<String> get dueDate =>
      $composableBuilder(column: $table.dueDate, builder: (column) => column);

  GeneratedColumn<DateTime> get resolvedAt => $composableBuilder(
    column: $table.resolvedAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$IssuesTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $IssuesTable,
          IssueRow,
          $$IssuesTableFilterComposer,
          $$IssuesTableOrderingComposer,
          $$IssuesTableAnnotationComposer,
          $$IssuesTableCreateCompanionBuilder,
          $$IssuesTableUpdateCompanionBuilder,
          (IssueRow, BaseReferences<_$AppDatabase, $IssuesTable, IssueRow>),
          IssueRow,
          PrefetchHooks Function()
        > {
  $$IssuesTableTableManager(_$AppDatabase db, $IssuesTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$IssuesTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$IssuesTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$IssuesTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clientUuid = const Value.absent(),
                Value<int?> serverId = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<int?> siteId = const Value.absent(),
                Value<String> title = const Value.absent(),
                Value<String?> description = const Value.absent(),
                Value<String> priority = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<String?> locationNote = const Value.absent(),
                Value<int?> assignedTo = const Value.absent(),
                Value<int?> raisedBy = const Value.absent(),
                Value<String?> dueDate = const Value.absent(),
                Value<DateTime?> resolvedAt = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => IssuesCompanion(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                siteId: siteId,
                title: title,
                description: description,
                priority: priority,
                status: status,
                locationNote: locationNote,
                assignedTo: assignedTo,
                raisedBy: raisedBy,
                dueDate: dueDate,
                resolvedAt: resolvedAt,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clientUuid,
                Value<int?> serverId = const Value.absent(),
                required int projectId,
                Value<int?> siteId = const Value.absent(),
                required String title,
                Value<String?> description = const Value.absent(),
                Value<String> priority = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<String?> locationNote = const Value.absent(),
                Value<int?> assignedTo = const Value.absent(),
                Value<int?> raisedBy = const Value.absent(),
                Value<String?> dueDate = const Value.absent(),
                Value<DateTime?> resolvedAt = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => IssuesCompanion.insert(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                siteId: siteId,
                title: title,
                description: description,
                priority: priority,
                status: status,
                locationNote: locationNote,
                assignedTo: assignedTo,
                raisedBy: raisedBy,
                dueDate: dueDate,
                resolvedAt: resolvedAt,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$IssuesTable, IssueRow>(table),
                  BaseReferences<_$AppDatabase, $IssuesTable, IssueRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$IssuesTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $IssuesTable,
      IssueRow,
      $$IssuesTableFilterComposer,
      $$IssuesTableOrderingComposer,
      $$IssuesTableAnnotationComposer,
      $$IssuesTableCreateCompanionBuilder,
      $$IssuesTableUpdateCompanionBuilder,
      (IssueRow, BaseReferences<_$AppDatabase, $IssuesTable, IssueRow>),
      IssueRow,
      PrefetchHooks Function()
    >;
typedef $$AttendanceMarksTableCreateCompanionBuilder =
    AttendanceMarksCompanion Function({
      required String clientUuid,
      Value<int?> serverId,
      required int projectId,
      required int workerId,
      required String attendanceDate,
      required String status,
      required Hours otHours,
      Value<int?> markedBy,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });
typedef $$AttendanceMarksTableUpdateCompanionBuilder =
    AttendanceMarksCompanion Function({
      Value<String> clientUuid,
      Value<int?> serverId,
      Value<int> projectId,
      Value<int> workerId,
      Value<String> attendanceDate,
      Value<String> status,
      Value<Hours> otHours,
      Value<int?> markedBy,
      Value<DateTime?> clientUpdatedAt,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });

class $$AttendanceMarksTableFilterComposer
    extends Composer<_$AppDatabase, $AttendanceMarksTable> {
  $$AttendanceMarksTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get workerId => $composableBuilder(
    column: $table.workerId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get attendanceDate => $composableBuilder(
    column: $table.attendanceDate,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<Hours, Hours, String> get otHours =>
      $composableBuilder(
        column: $table.otHours,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<int> get markedBy => $composableBuilder(
    column: $table.markedBy,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$AttendanceMarksTableOrderingComposer
    extends Composer<_$AppDatabase, $AttendanceMarksTable> {
  $$AttendanceMarksTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get workerId => $composableBuilder(
    column: $table.workerId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get attendanceDate => $composableBuilder(
    column: $table.attendanceDate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get otHours => $composableBuilder(
    column: $table.otHours,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get markedBy => $composableBuilder(
    column: $table.markedBy,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$AttendanceMarksTableAnnotationComposer
    extends Composer<_$AppDatabase, $AttendanceMarksTable> {
  $$AttendanceMarksTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => column,
  );

  GeneratedColumn<int> get serverId =>
      $composableBuilder(column: $table.serverId, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<int> get workerId =>
      $composableBuilder(column: $table.workerId, builder: (column) => column);

  GeneratedColumn<String> get attendanceDate => $composableBuilder(
    column: $table.attendanceDate,
    builder: (column) => column,
  );

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Hours, String> get otHours =>
      $composableBuilder(column: $table.otHours, builder: (column) => column);

  GeneratedColumn<int> get markedBy =>
      $composableBuilder(column: $table.markedBy, builder: (column) => column);

  GeneratedColumn<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$AttendanceMarksTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $AttendanceMarksTable,
          AttendanceMarkRow,
          $$AttendanceMarksTableFilterComposer,
          $$AttendanceMarksTableOrderingComposer,
          $$AttendanceMarksTableAnnotationComposer,
          $$AttendanceMarksTableCreateCompanionBuilder,
          $$AttendanceMarksTableUpdateCompanionBuilder,
          (
            AttendanceMarkRow,
            BaseReferences<
              _$AppDatabase,
              $AttendanceMarksTable,
              AttendanceMarkRow
            >,
          ),
          AttendanceMarkRow,
          PrefetchHooks Function()
        > {
  $$AttendanceMarksTableTableManager(
    _$AppDatabase db,
    $AttendanceMarksTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$AttendanceMarksTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$AttendanceMarksTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$AttendanceMarksTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clientUuid = const Value.absent(),
                Value<int?> serverId = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<int> workerId = const Value.absent(),
                Value<String> attendanceDate = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<Hours> otHours = const Value.absent(),
                Value<int?> markedBy = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => AttendanceMarksCompanion(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                workerId: workerId,
                attendanceDate: attendanceDate,
                status: status,
                otHours: otHours,
                markedBy: markedBy,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clientUuid,
                Value<int?> serverId = const Value.absent(),
                required int projectId,
                required int workerId,
                required String attendanceDate,
                required String status,
                required Hours otHours,
                Value<int?> markedBy = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => AttendanceMarksCompanion.insert(
                clientUuid: clientUuid,
                serverId: serverId,
                projectId: projectId,
                workerId: workerId,
                attendanceDate: attendanceDate,
                status: status,
                otHours: otHours,
                markedBy: markedBy,
                clientUpdatedAt: clientUpdatedAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$AttendanceMarksTable, AttendanceMarkRow>(table),
                  BaseReferences<
                    _$AppDatabase,
                    $AttendanceMarksTable,
                    AttendanceMarkRow
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$AttendanceMarksTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $AttendanceMarksTable,
      AttendanceMarkRow,
      $$AttendanceMarksTableFilterComposer,
      $$AttendanceMarksTableOrderingComposer,
      $$AttendanceMarksTableAnnotationComposer,
      $$AttendanceMarksTableCreateCompanionBuilder,
      $$AttendanceMarksTableUpdateCompanionBuilder,
      (
        AttendanceMarkRow,
        BaseReferences<_$AppDatabase, $AttendanceMarksTable, AttendanceMarkRow>,
      ),
      AttendanceMarkRow,
      PrefetchHooks Function()
    >;
typedef $$PettyCashEntriesTableCreateCompanionBuilder =
    PettyCashEntriesCompanion Function({
      required String clientUuid,
      Value<int?> serverId,
      required int walletId,
      Value<int?> projectId,
      Value<String> type,
      required Money amount,
      Value<String?> category,
      Value<String?> description,
      required String entryDate,
      Value<int?> createdBy,
      Value<bool> hasBill,
      Value<String?> billRelativePath,
      Value<bool> countedInBalance,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });
typedef $$PettyCashEntriesTableUpdateCompanionBuilder =
    PettyCashEntriesCompanion Function({
      Value<String> clientUuid,
      Value<int?> serverId,
      Value<int> walletId,
      Value<int?> projectId,
      Value<String> type,
      Value<Money> amount,
      Value<String?> category,
      Value<String?> description,
      Value<String> entryDate,
      Value<int?> createdBy,
      Value<bool> hasBill,
      Value<String?> billRelativePath,
      Value<bool> countedInBalance,
      Value<DateTime?> updatedAt,
      Value<int> rowid,
    });

class $$PettyCashEntriesTableFilterComposer
    extends Composer<_$AppDatabase, $PettyCashEntriesTable> {
  $$PettyCashEntriesTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get walletId => $composableBuilder(
    column: $table.walletId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get type => $composableBuilder(
    column: $table.type,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<Money, Money, String> get amount =>
      $composableBuilder(
        column: $table.amount,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<String> get category => $composableBuilder(
    column: $table.category,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get entryDate => $composableBuilder(
    column: $table.entryDate,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdBy => $composableBuilder(
    column: $table.createdBy,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get hasBill => $composableBuilder(
    column: $table.hasBill,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get billRelativePath => $composableBuilder(
    column: $table.billRelativePath,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get countedInBalance => $composableBuilder(
    column: $table.countedInBalance,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$PettyCashEntriesTableOrderingComposer
    extends Composer<_$AppDatabase, $PettyCashEntriesTable> {
  $$PettyCashEntriesTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get serverId => $composableBuilder(
    column: $table.serverId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get walletId => $composableBuilder(
    column: $table.walletId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get type => $composableBuilder(
    column: $table.type,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get amount => $composableBuilder(
    column: $table.amount,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get category => $composableBuilder(
    column: $table.category,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get entryDate => $composableBuilder(
    column: $table.entryDate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdBy => $composableBuilder(
    column: $table.createdBy,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get hasBill => $composableBuilder(
    column: $table.hasBill,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get billRelativePath => $composableBuilder(
    column: $table.billRelativePath,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get countedInBalance => $composableBuilder(
    column: $table.countedInBalance,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$PettyCashEntriesTableAnnotationComposer
    extends Composer<_$AppDatabase, $PettyCashEntriesTable> {
  $$PettyCashEntriesTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clientUuid => $composableBuilder(
    column: $table.clientUuid,
    builder: (column) => column,
  );

  GeneratedColumn<int> get serverId =>
      $composableBuilder(column: $table.serverId, builder: (column) => column);

  GeneratedColumn<int> get walletId =>
      $composableBuilder(column: $table.walletId, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<String> get type =>
      $composableBuilder(column: $table.type, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Money, String> get amount =>
      $composableBuilder(column: $table.amount, builder: (column) => column);

  GeneratedColumn<String> get category =>
      $composableBuilder(column: $table.category, builder: (column) => column);

  GeneratedColumn<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => column,
  );

  GeneratedColumn<String> get entryDate =>
      $composableBuilder(column: $table.entryDate, builder: (column) => column);

  GeneratedColumn<int> get createdBy =>
      $composableBuilder(column: $table.createdBy, builder: (column) => column);

  GeneratedColumn<bool> get hasBill =>
      $composableBuilder(column: $table.hasBill, builder: (column) => column);

  GeneratedColumn<String> get billRelativePath => $composableBuilder(
    column: $table.billRelativePath,
    builder: (column) => column,
  );

  GeneratedColumn<bool> get countedInBalance => $composableBuilder(
    column: $table.countedInBalance,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$PettyCashEntriesTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $PettyCashEntriesTable,
          PettyCashEntryRow,
          $$PettyCashEntriesTableFilterComposer,
          $$PettyCashEntriesTableOrderingComposer,
          $$PettyCashEntriesTableAnnotationComposer,
          $$PettyCashEntriesTableCreateCompanionBuilder,
          $$PettyCashEntriesTableUpdateCompanionBuilder,
          (
            PettyCashEntryRow,
            BaseReferences<
              _$AppDatabase,
              $PettyCashEntriesTable,
              PettyCashEntryRow
            >,
          ),
          PettyCashEntryRow,
          PrefetchHooks Function()
        > {
  $$PettyCashEntriesTableTableManager(
    _$AppDatabase db,
    $PettyCashEntriesTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$PettyCashEntriesTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$PettyCashEntriesTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$PettyCashEntriesTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clientUuid = const Value.absent(),
                Value<int?> serverId = const Value.absent(),
                Value<int> walletId = const Value.absent(),
                Value<int?> projectId = const Value.absent(),
                Value<String> type = const Value.absent(),
                Value<Money> amount = const Value.absent(),
                Value<String?> category = const Value.absent(),
                Value<String?> description = const Value.absent(),
                Value<String> entryDate = const Value.absent(),
                Value<int?> createdBy = const Value.absent(),
                Value<bool> hasBill = const Value.absent(),
                Value<String?> billRelativePath = const Value.absent(),
                Value<bool> countedInBalance = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => PettyCashEntriesCompanion(
                clientUuid: clientUuid,
                serverId: serverId,
                walletId: walletId,
                projectId: projectId,
                type: type,
                amount: amount,
                category: category,
                description: description,
                entryDate: entryDate,
                createdBy: createdBy,
                hasBill: hasBill,
                billRelativePath: billRelativePath,
                countedInBalance: countedInBalance,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clientUuid,
                Value<int?> serverId = const Value.absent(),
                required int walletId,
                Value<int?> projectId = const Value.absent(),
                Value<String> type = const Value.absent(),
                required Money amount,
                Value<String?> category = const Value.absent(),
                Value<String?> description = const Value.absent(),
                required String entryDate,
                Value<int?> createdBy = const Value.absent(),
                Value<bool> hasBill = const Value.absent(),
                Value<String?> billRelativePath = const Value.absent(),
                Value<bool> countedInBalance = const Value.absent(),
                Value<DateTime?> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => PettyCashEntriesCompanion.insert(
                clientUuid: clientUuid,
                serverId: serverId,
                walletId: walletId,
                projectId: projectId,
                type: type,
                amount: amount,
                category: category,
                description: description,
                entryDate: entryDate,
                createdBy: createdBy,
                hasBill: hasBill,
                billRelativePath: billRelativePath,
                countedInBalance: countedInBalance,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$PettyCashEntriesTable, PettyCashEntryRow>(table),
                  BaseReferences<
                    _$AppDatabase,
                    $PettyCashEntriesTable,
                    PettyCashEntryRow
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$PettyCashEntriesTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $PettyCashEntriesTable,
      PettyCashEntryRow,
      $$PettyCashEntriesTableFilterComposer,
      $$PettyCashEntriesTableOrderingComposer,
      $$PettyCashEntriesTableAnnotationComposer,
      $$PettyCashEntriesTableCreateCompanionBuilder,
      $$PettyCashEntriesTableUpdateCompanionBuilder,
      (
        PettyCashEntryRow,
        BaseReferences<
          _$AppDatabase,
          $PettyCashEntriesTable,
          PettyCashEntryRow
        >,
      ),
      PettyCashEntryRow,
      PrefetchHooks Function()
    >;
typedef $$ApprovalsInboxTableCreateCompanionBuilder =
    ApprovalsInboxCompanion Function({
      Value<int> id,
      required String docType,
      required String status,
      required String title,
      Value<int?> projectId,
      Value<String?> projectCode,
      Value<String?> projectName,
      Value<int?> submittedById,
      Value<String?> submittedByName,
      Value<DateTime?> submittedAt,
      Value<int?> currentLevel,
      required List<String> levels,
      Value<String?> currentRole,
      Value<String?> currentRoleLabel,
      Value<bool> canAct,
      required Map<String, dynamic> document,
      required List<dynamic> steps,
    });
typedef $$ApprovalsInboxTableUpdateCompanionBuilder =
    ApprovalsInboxCompanion Function({
      Value<int> id,
      Value<String> docType,
      Value<String> status,
      Value<String> title,
      Value<int?> projectId,
      Value<String?> projectCode,
      Value<String?> projectName,
      Value<int?> submittedById,
      Value<String?> submittedByName,
      Value<DateTime?> submittedAt,
      Value<int?> currentLevel,
      Value<List<String>> levels,
      Value<String?> currentRole,
      Value<String?> currentRoleLabel,
      Value<bool> canAct,
      Value<Map<String, dynamic>> document,
      Value<List<dynamic>> steps,
    });

class $$ApprovalsInboxTableFilterComposer
    extends Composer<_$AppDatabase, $ApprovalsInboxTable> {
  $$ApprovalsInboxTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get docType => $composableBuilder(
    column: $table.docType,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get title => $composableBuilder(
    column: $table.title,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get projectCode => $composableBuilder(
    column: $table.projectCode,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get projectName => $composableBuilder(
    column: $table.projectName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get submittedById => $composableBuilder(
    column: $table.submittedById,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get submittedByName => $composableBuilder(
    column: $table.submittedByName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get submittedAt => $composableBuilder(
    column: $table.submittedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get currentLevel => $composableBuilder(
    column: $table.currentLevel,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<List<String>, List<String>, String>
  get levels => $composableBuilder(
    column: $table.levels,
    builder: (column) => ColumnWithTypeConverterFilters(column),
  );

  ColumnFilters<String> get currentRole => $composableBuilder(
    column: $table.currentRole,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get currentRoleLabel => $composableBuilder(
    column: $table.currentRoleLabel,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get canAct => $composableBuilder(
    column: $table.canAct,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<
    Map<String, dynamic>,
    Map<String, dynamic>,
    String
  >
  get document => $composableBuilder(
    column: $table.document,
    builder: (column) => ColumnWithTypeConverterFilters(column),
  );

  ColumnWithTypeConverterFilters<List<dynamic>, List<dynamic>, String>
  get steps => $composableBuilder(
    column: $table.steps,
    builder: (column) => ColumnWithTypeConverterFilters(column),
  );
}

class $$ApprovalsInboxTableOrderingComposer
    extends Composer<_$AppDatabase, $ApprovalsInboxTable> {
  $$ApprovalsInboxTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get docType => $composableBuilder(
    column: $table.docType,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get title => $composableBuilder(
    column: $table.title,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get projectCode => $composableBuilder(
    column: $table.projectCode,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get projectName => $composableBuilder(
    column: $table.projectName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get submittedById => $composableBuilder(
    column: $table.submittedById,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get submittedByName => $composableBuilder(
    column: $table.submittedByName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get submittedAt => $composableBuilder(
    column: $table.submittedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get currentLevel => $composableBuilder(
    column: $table.currentLevel,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get levels => $composableBuilder(
    column: $table.levels,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get currentRole => $composableBuilder(
    column: $table.currentRole,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get currentRoleLabel => $composableBuilder(
    column: $table.currentRoleLabel,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get canAct => $composableBuilder(
    column: $table.canAct,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get document => $composableBuilder(
    column: $table.document,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get steps => $composableBuilder(
    column: $table.steps,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$ApprovalsInboxTableAnnotationComposer
    extends Composer<_$AppDatabase, $ApprovalsInboxTable> {
  $$ApprovalsInboxTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get docType =>
      $composableBuilder(column: $table.docType, builder: (column) => column);

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<String> get title =>
      $composableBuilder(column: $table.title, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<String> get projectCode => $composableBuilder(
    column: $table.projectCode,
    builder: (column) => column,
  );

  GeneratedColumn<String> get projectName => $composableBuilder(
    column: $table.projectName,
    builder: (column) => column,
  );

  GeneratedColumn<int> get submittedById => $composableBuilder(
    column: $table.submittedById,
    builder: (column) => column,
  );

  GeneratedColumn<String> get submittedByName => $composableBuilder(
    column: $table.submittedByName,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get submittedAt => $composableBuilder(
    column: $table.submittedAt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get currentLevel => $composableBuilder(
    column: $table.currentLevel,
    builder: (column) => column,
  );

  GeneratedColumnWithTypeConverter<List<String>, String> get levels =>
      $composableBuilder(column: $table.levels, builder: (column) => column);

  GeneratedColumn<String> get currentRole => $composableBuilder(
    column: $table.currentRole,
    builder: (column) => column,
  );

  GeneratedColumn<String> get currentRoleLabel => $composableBuilder(
    column: $table.currentRoleLabel,
    builder: (column) => column,
  );

  GeneratedColumn<bool> get canAct =>
      $composableBuilder(column: $table.canAct, builder: (column) => column);

  GeneratedColumnWithTypeConverter<Map<String, dynamic>, String> get document =>
      $composableBuilder(column: $table.document, builder: (column) => column);

  GeneratedColumnWithTypeConverter<List<dynamic>, String> get steps =>
      $composableBuilder(column: $table.steps, builder: (column) => column);
}

class $$ApprovalsInboxTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $ApprovalsInboxTable,
          ApprovalRow,
          $$ApprovalsInboxTableFilterComposer,
          $$ApprovalsInboxTableOrderingComposer,
          $$ApprovalsInboxTableAnnotationComposer,
          $$ApprovalsInboxTableCreateCompanionBuilder,
          $$ApprovalsInboxTableUpdateCompanionBuilder,
          (
            ApprovalRow,
            BaseReferences<_$AppDatabase, $ApprovalsInboxTable, ApprovalRow>,
          ),
          ApprovalRow,
          PrefetchHooks Function()
        > {
  $$ApprovalsInboxTableTableManager(
    _$AppDatabase db,
    $ApprovalsInboxTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$ApprovalsInboxTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$ApprovalsInboxTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$ApprovalsInboxTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<String> docType = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<String> title = const Value.absent(),
                Value<int?> projectId = const Value.absent(),
                Value<String?> projectCode = const Value.absent(),
                Value<String?> projectName = const Value.absent(),
                Value<int?> submittedById = const Value.absent(),
                Value<String?> submittedByName = const Value.absent(),
                Value<DateTime?> submittedAt = const Value.absent(),
                Value<int?> currentLevel = const Value.absent(),
                Value<List<String>> levels = const Value.absent(),
                Value<String?> currentRole = const Value.absent(),
                Value<String?> currentRoleLabel = const Value.absent(),
                Value<bool> canAct = const Value.absent(),
                Value<Map<String, dynamic>> document = const Value.absent(),
                Value<List<dynamic>> steps = const Value.absent(),
              }) => ApprovalsInboxCompanion(
                id: id,
                docType: docType,
                status: status,
                title: title,
                projectId: projectId,
                projectCode: projectCode,
                projectName: projectName,
                submittedById: submittedById,
                submittedByName: submittedByName,
                submittedAt: submittedAt,
                currentLevel: currentLevel,
                levels: levels,
                currentRole: currentRole,
                currentRoleLabel: currentRoleLabel,
                canAct: canAct,
                document: document,
                steps: steps,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required String docType,
                required String status,
                required String title,
                Value<int?> projectId = const Value.absent(),
                Value<String?> projectCode = const Value.absent(),
                Value<String?> projectName = const Value.absent(),
                Value<int?> submittedById = const Value.absent(),
                Value<String?> submittedByName = const Value.absent(),
                Value<DateTime?> submittedAt = const Value.absent(),
                Value<int?> currentLevel = const Value.absent(),
                required List<String> levels,
                Value<String?> currentRole = const Value.absent(),
                Value<String?> currentRoleLabel = const Value.absent(),
                Value<bool> canAct = const Value.absent(),
                required Map<String, dynamic> document,
                required List<dynamic> steps,
              }) => ApprovalsInboxCompanion.insert(
                id: id,
                docType: docType,
                status: status,
                title: title,
                projectId: projectId,
                projectCode: projectCode,
                projectName: projectName,
                submittedById: submittedById,
                submittedByName: submittedByName,
                submittedAt: submittedAt,
                currentLevel: currentLevel,
                levels: levels,
                currentRole: currentRole,
                currentRoleLabel: currentRoleLabel,
                canAct: canAct,
                document: document,
                steps: steps,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$ApprovalsInboxTable, ApprovalRow>(table),
                  BaseReferences<
                    _$AppDatabase,
                    $ApprovalsInboxTable,
                    ApprovalRow
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$ApprovalsInboxTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $ApprovalsInboxTable,
      ApprovalRow,
      $$ApprovalsInboxTableFilterComposer,
      $$ApprovalsInboxTableOrderingComposer,
      $$ApprovalsInboxTableAnnotationComposer,
      $$ApprovalsInboxTableCreateCompanionBuilder,
      $$ApprovalsInboxTableUpdateCompanionBuilder,
      (
        ApprovalRow,
        BaseReferences<_$AppDatabase, $ApprovalsInboxTable, ApprovalRow>,
      ),
      ApprovalRow,
      PrefetchHooks Function()
    >;
typedef $$OutboxTableCreateCompanionBuilder =
    OutboxCompanion Function({
      Value<int> id,
      required OutboxType type,
      required String wireUuid,
      required String recordUuid,
      required String groupUuid,
      required int projectId,
      required String payload,
      Value<DateTime?> clientUpdatedAt,
      Value<OutboxStatus> status,
      Value<String?> filePath,
      Value<String?> parentUuid,
      Value<String> title,
      Value<String?> subtitle,
      Value<String?> resultStatus,
      Value<String?> attentionCode,
      Value<String?> attentionReason,
      Value<String?> attentionErrors,
      Value<int> attempts,
      Value<int> notFoundCount,
      Value<DateTime?> lastAttemptAt,
      required DateTime createdAt,
      Value<DateTime?> doneAt,
    });
typedef $$OutboxTableUpdateCompanionBuilder =
    OutboxCompanion Function({
      Value<int> id,
      Value<OutboxType> type,
      Value<String> wireUuid,
      Value<String> recordUuid,
      Value<String> groupUuid,
      Value<int> projectId,
      Value<String> payload,
      Value<DateTime?> clientUpdatedAt,
      Value<OutboxStatus> status,
      Value<String?> filePath,
      Value<String?> parentUuid,
      Value<String> title,
      Value<String?> subtitle,
      Value<String?> resultStatus,
      Value<String?> attentionCode,
      Value<String?> attentionReason,
      Value<String?> attentionErrors,
      Value<int> attempts,
      Value<int> notFoundCount,
      Value<DateTime?> lastAttemptAt,
      Value<DateTime> createdAt,
      Value<DateTime?> doneAt,
    });

class $$OutboxTableFilterComposer
    extends Composer<_$AppDatabase, $OutboxTable> {
  $$OutboxTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<OutboxType, OutboxType, String> get type =>
      $composableBuilder(
        column: $table.type,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<String> get wireUuid => $composableBuilder(
    column: $table.wireUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get recordUuid => $composableBuilder(
    column: $table.recordUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get groupUuid => $composableBuilder(
    column: $table.groupUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get payload => $composableBuilder(
    column: $table.payload,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<OutboxStatus, OutboxStatus, String>
  get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnWithTypeConverterFilters(column),
  );

  ColumnFilters<String> get filePath => $composableBuilder(
    column: $table.filePath,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get parentUuid => $composableBuilder(
    column: $table.parentUuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get title => $composableBuilder(
    column: $table.title,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get subtitle => $composableBuilder(
    column: $table.subtitle,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get resultStatus => $composableBuilder(
    column: $table.resultStatus,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get attentionCode => $composableBuilder(
    column: $table.attentionCode,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get attentionReason => $composableBuilder(
    column: $table.attentionReason,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get attentionErrors => $composableBuilder(
    column: $table.attentionErrors,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get notFoundCount => $composableBuilder(
    column: $table.notFoundCount,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get lastAttemptAt => $composableBuilder(
    column: $table.lastAttemptAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get doneAt => $composableBuilder(
    column: $table.doneAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$OutboxTableOrderingComposer
    extends Composer<_$AppDatabase, $OutboxTable> {
  $$OutboxTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get type => $composableBuilder(
    column: $table.type,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get wireUuid => $composableBuilder(
    column: $table.wireUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get recordUuid => $composableBuilder(
    column: $table.recordUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get groupUuid => $composableBuilder(
    column: $table.groupUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get projectId => $composableBuilder(
    column: $table.projectId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get payload => $composableBuilder(
    column: $table.payload,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get filePath => $composableBuilder(
    column: $table.filePath,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get parentUuid => $composableBuilder(
    column: $table.parentUuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get title => $composableBuilder(
    column: $table.title,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get subtitle => $composableBuilder(
    column: $table.subtitle,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get resultStatus => $composableBuilder(
    column: $table.resultStatus,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get attentionCode => $composableBuilder(
    column: $table.attentionCode,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get attentionReason => $composableBuilder(
    column: $table.attentionReason,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get attentionErrors => $composableBuilder(
    column: $table.attentionErrors,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get notFoundCount => $composableBuilder(
    column: $table.notFoundCount,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get lastAttemptAt => $composableBuilder(
    column: $table.lastAttemptAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get doneAt => $composableBuilder(
    column: $table.doneAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$OutboxTableAnnotationComposer
    extends Composer<_$AppDatabase, $OutboxTable> {
  $$OutboxTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumnWithTypeConverter<OutboxType, String> get type =>
      $composableBuilder(column: $table.type, builder: (column) => column);

  GeneratedColumn<String> get wireUuid =>
      $composableBuilder(column: $table.wireUuid, builder: (column) => column);

  GeneratedColumn<String> get recordUuid => $composableBuilder(
    column: $table.recordUuid,
    builder: (column) => column,
  );

  GeneratedColumn<String> get groupUuid =>
      $composableBuilder(column: $table.groupUuid, builder: (column) => column);

  GeneratedColumn<int> get projectId =>
      $composableBuilder(column: $table.projectId, builder: (column) => column);

  GeneratedColumn<String> get payload =>
      $composableBuilder(column: $table.payload, builder: (column) => column);

  GeneratedColumn<DateTime> get clientUpdatedAt => $composableBuilder(
    column: $table.clientUpdatedAt,
    builder: (column) => column,
  );

  GeneratedColumnWithTypeConverter<OutboxStatus, String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<String> get filePath =>
      $composableBuilder(column: $table.filePath, builder: (column) => column);

  GeneratedColumn<String> get parentUuid => $composableBuilder(
    column: $table.parentUuid,
    builder: (column) => column,
  );

  GeneratedColumn<String> get title =>
      $composableBuilder(column: $table.title, builder: (column) => column);

  GeneratedColumn<String> get subtitle =>
      $composableBuilder(column: $table.subtitle, builder: (column) => column);

  GeneratedColumn<String> get resultStatus => $composableBuilder(
    column: $table.resultStatus,
    builder: (column) => column,
  );

  GeneratedColumn<String> get attentionCode => $composableBuilder(
    column: $table.attentionCode,
    builder: (column) => column,
  );

  GeneratedColumn<String> get attentionReason => $composableBuilder(
    column: $table.attentionReason,
    builder: (column) => column,
  );

  GeneratedColumn<String> get attentionErrors => $composableBuilder(
    column: $table.attentionErrors,
    builder: (column) => column,
  );

  GeneratedColumn<int> get attempts =>
      $composableBuilder(column: $table.attempts, builder: (column) => column);

  GeneratedColumn<int> get notFoundCount => $composableBuilder(
    column: $table.notFoundCount,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get lastAttemptAt => $composableBuilder(
    column: $table.lastAttemptAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);

  GeneratedColumn<DateTime> get doneAt =>
      $composableBuilder(column: $table.doneAt, builder: (column) => column);
}

class $$OutboxTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $OutboxTable,
          OutboxRow,
          $$OutboxTableFilterComposer,
          $$OutboxTableOrderingComposer,
          $$OutboxTableAnnotationComposer,
          $$OutboxTableCreateCompanionBuilder,
          $$OutboxTableUpdateCompanionBuilder,
          (OutboxRow, BaseReferences<_$AppDatabase, $OutboxTable, OutboxRow>),
          OutboxRow,
          PrefetchHooks Function()
        > {
  $$OutboxTableTableManager(_$AppDatabase db, $OutboxTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$OutboxTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$OutboxTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$OutboxTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<OutboxType> type = const Value.absent(),
                Value<String> wireUuid = const Value.absent(),
                Value<String> recordUuid = const Value.absent(),
                Value<String> groupUuid = const Value.absent(),
                Value<int> projectId = const Value.absent(),
                Value<String> payload = const Value.absent(),
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<OutboxStatus> status = const Value.absent(),
                Value<String?> filePath = const Value.absent(),
                Value<String?> parentUuid = const Value.absent(),
                Value<String> title = const Value.absent(),
                Value<String?> subtitle = const Value.absent(),
                Value<String?> resultStatus = const Value.absent(),
                Value<String?> attentionCode = const Value.absent(),
                Value<String?> attentionReason = const Value.absent(),
                Value<String?> attentionErrors = const Value.absent(),
                Value<int> attempts = const Value.absent(),
                Value<int> notFoundCount = const Value.absent(),
                Value<DateTime?> lastAttemptAt = const Value.absent(),
                Value<DateTime> createdAt = const Value.absent(),
                Value<DateTime?> doneAt = const Value.absent(),
              }) => OutboxCompanion(
                id: id,
                type: type,
                wireUuid: wireUuid,
                recordUuid: recordUuid,
                groupUuid: groupUuid,
                projectId: projectId,
                payload: payload,
                clientUpdatedAt: clientUpdatedAt,
                status: status,
                filePath: filePath,
                parentUuid: parentUuid,
                title: title,
                subtitle: subtitle,
                resultStatus: resultStatus,
                attentionCode: attentionCode,
                attentionReason: attentionReason,
                attentionErrors: attentionErrors,
                attempts: attempts,
                notFoundCount: notFoundCount,
                lastAttemptAt: lastAttemptAt,
                createdAt: createdAt,
                doneAt: doneAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required OutboxType type,
                required String wireUuid,
                required String recordUuid,
                required String groupUuid,
                required int projectId,
                required String payload,
                Value<DateTime?> clientUpdatedAt = const Value.absent(),
                Value<OutboxStatus> status = const Value.absent(),
                Value<String?> filePath = const Value.absent(),
                Value<String?> parentUuid = const Value.absent(),
                Value<String> title = const Value.absent(),
                Value<String?> subtitle = const Value.absent(),
                Value<String?> resultStatus = const Value.absent(),
                Value<String?> attentionCode = const Value.absent(),
                Value<String?> attentionReason = const Value.absent(),
                Value<String?> attentionErrors = const Value.absent(),
                Value<int> attempts = const Value.absent(),
                Value<int> notFoundCount = const Value.absent(),
                Value<DateTime?> lastAttemptAt = const Value.absent(),
                required DateTime createdAt,
                Value<DateTime?> doneAt = const Value.absent(),
              }) => OutboxCompanion.insert(
                id: id,
                type: type,
                wireUuid: wireUuid,
                recordUuid: recordUuid,
                groupUuid: groupUuid,
                projectId: projectId,
                payload: payload,
                clientUpdatedAt: clientUpdatedAt,
                status: status,
                filePath: filePath,
                parentUuid: parentUuid,
                title: title,
                subtitle: subtitle,
                resultStatus: resultStatus,
                attentionCode: attentionCode,
                attentionReason: attentionReason,
                attentionErrors: attentionErrors,
                attempts: attempts,
                notFoundCount: notFoundCount,
                lastAttemptAt: lastAttemptAt,
                createdAt: createdAt,
                doneAt: doneAt,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$OutboxTable, OutboxRow>(table),
                  BaseReferences<_$AppDatabase, $OutboxTable, OutboxRow>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$OutboxTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $OutboxTable,
      OutboxRow,
      $$OutboxTableFilterComposer,
      $$OutboxTableOrderingComposer,
      $$OutboxTableAnnotationComposer,
      $$OutboxTableCreateCompanionBuilder,
      $$OutboxTableUpdateCompanionBuilder,
      (OutboxRow, BaseReferences<_$AppDatabase, $OutboxTable, OutboxRow>),
      OutboxRow,
      PrefetchHooks Function()
    >;

class $AppDatabaseManager {
  final _$AppDatabase _db;
  $AppDatabaseManager(this._db);
  $$ProjectsTableTableManager get projects =>
      $$ProjectsTableTableManager(_db, _db.projects);
  $$SitesTableTableManager get sites =>
      $$SitesTableTableManager(_db, _db.sites);
  $$BoqItemsTableTableManager get boqItems =>
      $$BoqItemsTableTableManager(_db, _db.boqItems);
  $$WorkersTableTableManager get workers =>
      $$WorkersTableTableManager(_db, _db.workers);
  $$WalletsTableTableManager get wallets =>
      $$WalletsTableTableManager(_db, _db.wallets);
  $$UsersTableTableManager get users =>
      $$UsersTableTableManager(_db, _db.users);
  $$KeyValuesTableTableManager get keyValues =>
      $$KeyValuesTableTableManager(_db, _db.keyValues);
  $$DprsTableTableManager get dprs => $$DprsTableTableManager(_db, _db.dprs);
  $$DprPhotosTableTableManager get dprPhotos =>
      $$DprPhotosTableTableManager(_db, _db.dprPhotos);
  $$MeasurementsTableTableManager get measurements =>
      $$MeasurementsTableTableManager(_db, _db.measurements);
  $$IssuesTableTableManager get issues =>
      $$IssuesTableTableManager(_db, _db.issues);
  $$AttendanceMarksTableTableManager get attendanceMarks =>
      $$AttendanceMarksTableTableManager(_db, _db.attendanceMarks);
  $$PettyCashEntriesTableTableManager get pettyCashEntries =>
      $$PettyCashEntriesTableTableManager(_db, _db.pettyCashEntries);
  $$ApprovalsInboxTableTableManager get approvalsInbox =>
      $$ApprovalsInboxTableTableManager(_db, _db.approvalsInbox);
  $$OutboxTableTableManager get outbox =>
      $$OutboxTableTableManager(_db, _db.outbox);
}
