import 'dart:convert';

import 'package:equatable/equatable.dart';

import '../../../core/db/app_database.dart';

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? <Map<String, dynamic>>[
        for (final Object? row in value)
          if (row is Map) row.cast<String, dynamic>(),
      ]
    : const <Map<String, dynamic>>[];

String? _text(Object? raw) {
  final String? text = raw?.toString();
  return text == null || text.trim().isEmpty ? null : text;
}

/// The state of one item, as `config('modules.qhs.inspection_results')` names it.
/// An item has no result until someone marks it, and an inspection cannot be
/// completed while any item is still `pending`. "Not applicable" counts as a result.
enum ItemResult {
  pending('pending'),
  pass('pass'),
  fail('fail'),
  na('na');

  const ItemResult(this.wire);

  final String wire;

  /// A result this app does not know is shown as no result, never as a pass.
  static ItemResult fromWire(Object? raw) {
    for (final ItemResult result in values) {
      if (result.wire == raw) return result;
    }
    return ItemResult.pending;
  }
}

/// `status` of an inspection. A completed one (`passed` or `failed`) is locked:
/// the server answers 409 to any change.
abstract final class InspectionStatus {
  static const inProgress = 'in_progress';
  static const passed = 'passed';
  static const failed = 'failed';
}

/// The longest remark and location the server takes.
const int kInspectionRemarkMax = 255;
const int kInspectionLocationMax = 160;

/// A checklist the company has set up, with the check points an inspection copies.
class ChecklistTemplate extends Equatable {
  const ChecklistTemplate({
    required this.id,
    required this.name,
    required this.category,
    required this.items,
    this.updatedAt,
  });

  /// Null when the row has no id, which nothing could be started from.
  static ChecklistTemplate? tryFromJson(Map<String, dynamic> json) {
    final int? id = (json['id'] as num?)?.toInt();
    if (id == null) return null;
    final Object? items = json['items'];
    return ChecklistTemplate(
      id: id,
      name: _text(json['name']) ?? '',
      category: _text(json['category']) ?? 'general',
      items: items is List ? <String>[for (final Object? item in items) item.toString()] : const <String>[],
      updatedAt: _text(json['updated_at']),
    );
  }

  final int id;
  final String name;
  final String category;
  final List<String> items;
  final String? updatedAt;

  @override
  List<Object?> get props => <Object?>[id, name, category, items, updatedAt];
}

/// One check point of an inspection and its result.
class InspectionItem extends Equatable {
  const InspectionItem({
    required this.position,
    required this.text,
    this.id,
    this.result = ItemResult.pending,
    this.remark,
  });

  factory InspectionItem.fromJson(Map<String, dynamic> json) => InspectionItem(
    id: (json['id'] as num?)?.toInt(),
    position: (json['position'] as num?)?.toInt() ?? 0,
    text: _text(json['text']) ?? '',
    result: ItemResult.fromWire(json['result']),
    remark: _text(json['remark']),
  );

  final int? id;
  final int position;
  final String text;
  final ItemResult result;
  final String? remark;

  @override
  List<Object?> get props => <Object?>[id, position, text, result, remark];
}

/// An inspection: one that came from the server, or one finished on this phone
/// that is still waiting to be sent ([unsent]).
class Inspection extends Equatable {
  const Inspection({
    required this.projectId,
    required this.title,
    required this.inspectedOn,
    required this.status,
    this.id,
    this.clientUuid,
    this.templateId,
    this.category,
    this.location,
    this.completedAt,
    this.updatedAt,
    this.items = const <InspectionItem>[],
    this.unsent,
  });

  factory Inspection.fromJson(Map<String, dynamic> json) {
    final List<InspectionItem> items = <InspectionItem>[
      for (final Map<String, dynamic> row in _rows(json['items'])) InspectionItem.fromJson(row),
    ]..sort((a, b) => a.position.compareTo(b.position));
    return Inspection(
      id: (json['id'] as num?)?.toInt(),
      clientUuid: _text(json['client_uuid']),
      projectId: (json['project_id'] as num?)?.toInt() ?? 0,
      templateId: (json['checklist_template_id'] as num?)?.toInt(),
      title: _text(json['title']) ?? '',
      category: _text(json['category']),
      location: _text(json['location']),
      inspectedOn: _text(json['inspected_on']) ?? '',
      status: _text(json['status']) ?? InspectionStatus.inProgress,
      completedAt: _text(json['completed_at']),
      updatedAt: _text(json['updated_at']),
      items: items,
    );
  }

  /// A finished inspection that has not reached the server yet, worked out
  /// from the payload that waits in the outbox. Never throws: a damaged payload
  /// still shows as a row with a title, so the person can see it and discard it.
  factory Inspection.fromOutbox(OutboxRow row) {
    Map<String, dynamic> data = const <String, dynamic>{};
    try {
      final Object? decoded = jsonDecode(row.payload);
      if (decoded is Map) data = decoded.cast<String, dynamic>();
    } on FormatException {
      // Shown by its title alone.
    }
    final Object? local = data['local'];
    final Map<String, dynamic> kept = local is Map ? local.cast<String, dynamic>() : const <String, dynamic>{};
    final Object? texts = kept['items'];
    final Map<int, Map<String, dynamic>> marks = <int, Map<String, dynamic>>{
      for (final Map<String, dynamic> mark in _rows(data['results']))
        if ((mark['position'] as num?) != null) (mark['position'] as num).toInt(): mark,
    };
    final List<InspectionItem> items = <InspectionItem>[
      if (texts is List)
        for (final (int i, Object? text) in texts.indexed)
          InspectionItem(
            position: i + 1,
            text: text.toString(),
            result: ItemResult.fromWire(marks[i + 1]?['result']),
            remark: _text(marks[i + 1]?['remark']),
          ),
    ];
    final bool failed = items.any((InspectionItem item) => item.result == ItemResult.fail);
    return Inspection(
      clientUuid: row.wireUuid,
      projectId: row.projectId,
      templateId: (data['checklist_template_id'] as num?)?.toInt(),
      title: _text(data['title']) ?? _text(kept['template_name']) ?? row.title,
      category: _text(kept['category']),
      location: _text(data['location']),
      inspectedOn: _text(data['inspected_on']) ?? '',
      // A preview: the server works out the final word when it completes it.
      status: failed ? InspectionStatus.failed : InspectionStatus.passed,
      items: items,
      unsent: row,
    );
  }

  final int? id;
  final String? clientUuid;
  final int projectId;
  final int? templateId;
  final String title;
  final String? category;
  final String? location;

  /// `YYYY-MM-DD`.
  final String inspectedOn;
  final String status;
  final String? completedAt;
  final String? updatedAt;

  /// Empty for a row from the list, which carries no items. Read from the
  /// inspection itself when it is opened.
  final List<InspectionItem> items;

  /// The outbox row of an inspection that has not been sent.
  final OutboxRow? unsent;

  bool get notSent => unsent != null;
  bool get needsAttention => unsent?.status == OutboxStatus.attention;
  bool get isOpen => status == InspectionStatus.inProgress;
  bool get isFailed => status == InspectionStatus.failed;

  int count(ItemResult result) => items.where((InspectionItem item) => item.result == result).length;

  /// The key a route carries: the server's id, else the phone's own uuid.
  String get key => id != null ? '$id' : (clientUuid ?? '');

  @override
  List<Object?> get props => <Object?>[
    id,
    clientUuid,
    projectId,
    templateId,
    title,
    category,
    location,
    inspectedOn,
    status,
    completedAt,
    updatedAt,
    items,
    unsent,
  ];
}

/// What this phone last read for a project: the checklists, the recent
/// inspections and the items of the latest few.
class InspectionOverview extends Equatable {
  const InspectionOverview({
    this.templates = const <ChecklistTemplate>[],
    this.inspections = const <Inspection>[],
    this.details = const <int, Inspection>{},
    this.more = false,
    this.refused = false,
    this.fetchedAt,
  });

  factory InspectionOverview.fromJson(Map<String, dynamic> json) {
    final Object? details = json['details'];
    return InspectionOverview(
      refused: json['refused'] == true,
      templates: <ChecklistTemplate>[
        for (final Map<String, dynamic> row in _rows(json['templates']))
          ?ChecklistTemplate.tryFromJson(row),
      ],
      inspections: <Inspection>[for (final Map<String, dynamic> row in _rows(json['inspections'])) Inspection.fromJson(row)],
      details: <int, Inspection>{
        if (details is Map)
          for (final MapEntry<Object?, Object?> entry in details.entries)
            if (int.tryParse('${entry.key}') != null && entry.value is Map)
              int.parse('${entry.key}'): Inspection.fromJson((entry.value as Map).cast<String, dynamic>()),
      },
      more: json['more'] == true,
      fetchedAt: DateTime.tryParse(json['fetched_at']?.toString() ?? ''),
    );
  }

  final List<ChecklistTemplate> templates;
  final List<Inspection> inspections;

  /// By the server's id. The latest few, with their items.
  final Map<int, Inspection> details;

  /// The list holds the latest page only.
  final bool more;

  /// The server said this role or plan does not have inspections.
  final bool refused;
  final DateTime? fetchedAt;

  ChecklistTemplate? template(int id) {
    for (final ChecklistTemplate row in templates) {
      if (row.id == id) return row;
    }
    return null;
  }

  int get unsent => inspections.where((Inspection row) => row.notSent).length;

  /// A row by the key a route carries: a server id, or the uuid of one waiting.
  Inspection? find(String key) {
    final int? id = int.tryParse(key);
    for (final Inspection row in inspections) {
      if (id != null ? row.id == id : row.clientUuid == key) return details[row.id] ?? row;
    }
    return null;
  }

  /// Inspections on this phone go on top, newest first. One the server has
  /// already stored is not shown twice while its reply is being resent.
  InspectionOverview withUnsent(List<OutboxRow> rows) {
    final Set<String?> known = <String?>{for (final Inspection row in inspections) row.clientUuid};
    final List<Inspection> waiting = <Inspection>[
      for (final OutboxRow row in rows.reversed)
        if (row.type == OutboxType.inspection && !known.contains(row.wireUuid)) Inspection.fromOutbox(row),
    ];
    if (waiting.isEmpty) return this;
    return InspectionOverview(
      templates: templates,
      inspections: <Inspection>[...waiting, ...inspections],
      details: details,
      more: more,
      refused: refused,
      fetchedAt: fetchedAt,
    );
  }

  @override
  List<Object?> get props => <Object?>[templates, inspections, details, more, refused, fetchedAt];
}

/// What was marked on one item of a draft.
class DraftMark extends Equatable {
  const DraftMark({this.result = ItemResult.pending, this.remark = ''});

  final ItemResult result;

  /// Kept when the result changes, so a slip of the thumb loses no words. It is
  /// only sent, and only required, for a failed item.
  final String remark;

  @override
  List<Object?> get props => <Object?>[result, remark];
}

/// An inspection being filled in on this phone. Nothing about it is on the
/// server until it is finished, so it lives here and survives the app closing.
class InspectionDraft extends Equatable {
  const InspectionDraft({
    required this.uuid,
    required this.projectId,
    required this.templateId,
    required this.templateName,
    required this.category,
    required this.items,
    required this.inspectedOn,
    required this.startedAt,
    this.templateUpdatedAt,
    this.location = '',
    this.marks = const <int, DraftMark>{},
  });

  factory InspectionDraft.fromJson(Map<String, dynamic> json) {
    final Object? marks = json['marks'];
    final Object? items = json['items'];
    return InspectionDraft(
      uuid: json['uuid'].toString(),
      projectId: (json['project_id'] as num).toInt(),
      templateId: (json['template_id'] as num).toInt(),
      templateName: _text(json['template_name']) ?? '',
      category: _text(json['category']) ?? 'general',
      templateUpdatedAt: _text(json['template_updated_at']),
      items: items is List ? <String>[for (final Object? item in items) item.toString()] : const <String>[],
      inspectedOn: _text(json['inspected_on']) ?? '',
      startedAt: _text(json['started_at']) ?? '',
      location: json['location']?.toString() ?? '',
      marks: <int, DraftMark>{
        if (marks is Map)
          for (final MapEntry<Object?, Object?> entry in marks.entries)
            if (int.tryParse('${entry.key}') != null && entry.value is Map)
              int.parse('${entry.key}'): DraftMark(
                result: ItemResult.fromWire((entry.value as Map)['result']),
                remark: (entry.value as Map)['remark']?.toString() ?? '',
              ),
      },
    );
  }

  final String uuid;
  final int projectId;
  final int templateId;
  final String templateName;
  final String category;

  /// The check points as they were when this was started. The server numbers its
  /// items by position, so these are what the results are checked against.
  final List<String> items;
  final String? templateUpdatedAt;
  final String inspectedOn;
  final String startedAt;
  final String location;

  /// By position, starting at 1.
  final Map<int, DraftMark> marks;

  ItemResult resultAt(int position) => marks[position]?.result ?? ItemResult.pending;

  String remarkAt(int position) => marks[position]?.remark ?? '';

  /// Positions with no result yet.
  List<int> get unmarked => <int>[
    for (var position = 1; position <= items.length; position++)
      if (resultAt(position) == ItemResult.pending) position,
  ];

  /// Failed items with nothing written about what is wrong. The server refuses these.
  List<int> get needRemark => <int>[
    for (var position = 1; position <= items.length; position++)
      if (resultAt(position) == ItemResult.fail && remarkAt(position).trim().isEmpty) position,
  ];

  int get markedCount => items.length - unmarked.length;
  bool get hasFailure => marks.values.any((DraftMark mark) => mark.result == ItemResult.fail);

  /// Every item has a result and every failure says why.
  bool get isReady => items.isNotEmpty && unmarked.isEmpty && needRemark.isEmpty;

  InspectionDraft copyWith({String? location, String? inspectedOn, Map<int, DraftMark>? marks}) => InspectionDraft(
    uuid: uuid,
    projectId: projectId,
    templateId: templateId,
    templateName: templateName,
    category: category,
    items: items,
    templateUpdatedAt: templateUpdatedAt,
    inspectedOn: inspectedOn ?? this.inspectedOn,
    startedAt: startedAt,
    location: location ?? this.location,
    marks: marks ?? this.marks,
  );

  InspectionDraft withMark(int position, {ItemResult? result, String? remark}) {
    final DraftMark was = marks[position] ?? const DraftMark();
    return copyWith(
      marks: <int, DraftMark>{
        ...marks,
        position: DraftMark(result: result ?? was.result, remark: remark ?? was.remark),
      },
    );
  }

  /// The body of the one call that starts the inspection with every result and
  /// completes it. `local` is what the phone shows while it waits, and the
  /// checklist as copied, which the engine compares with the server's before
  /// sending. The engine takes it out of the call.
  Map<String, dynamic> toPayload() {
    final String place = location.trim();
    return <String, dynamic>{
      'checklist_template_id': templateId,
      'location': place.isEmpty ? null : place,
      'inspected_on': inspectedOn,
      'results': <Map<String, dynamic>>[
        for (var position = 1; position <= items.length; position++)
          <String, dynamic>{
            'position': position,
            'result': resultAt(position).wire,
            if (resultAt(position) == ItemResult.fail) 'remark': remarkAt(position).trim(),
          },
      ],
      'complete': true,
      'local': <String, dynamic>{
        'template_name': templateName,
        'category': category,
        'items': items,
        'template_updated_at': templateUpdatedAt,
      },
    };
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
    'uuid': uuid,
    'project_id': projectId,
    'template_id': templateId,
    'template_name': templateName,
    'category': category,
    'template_updated_at': templateUpdatedAt,
    'items': items,
    'inspected_on': inspectedOn,
    'started_at': startedAt,
    'location': location,
    'marks': <String, Object?>{
      for (final MapEntry<int, DraftMark> entry in marks.entries)
        '${entry.key}': <String, String>{'result': entry.value.result.wire, 'remark': entry.value.remark},
    },
  };

  @override
  List<Object?> get props => <Object?>[
    uuid,
    projectId,
    templateId,
    templateName,
    category,
    items,
    templateUpdatedAt,
    inspectedOn,
    startedAt,
    location,
    marks,
  ];
}
