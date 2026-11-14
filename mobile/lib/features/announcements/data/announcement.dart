import 'package:equatable/equatable.dart';

import '../../../core/api/nirmaan_api.dart';

/// One broadcast or reminder sent to this person, written in the language asked for.
class Announcement extends Equatable {
  const Announcement({
    required this.id,
    required this.title,
    required this.body,
    required this.from,
    required this.sentAt,
    this.imageUrl,
    this.repeats = false,
  });

  /// Null for a row the app cannot use: no id, or no title.
  static Announcement? fromJson(Map<String, dynamic> json) {
    final Object? rawId = json['id'];
    final int? id = rawId is int ? rawId : int.tryParse('${rawId ?? ''}');
    final String title = (json['title'] ?? '').toString().trim();
    if (id == null || id <= 0 || title.isEmpty) return null;
    final String? image = json['image_url']?.toString();
    return Announcement(
      id: id,
      title: title,
      body: (json['body'] ?? '').toString(),
      from: (json['from'] ?? '').toString(),
      sentAt: DateTime.tryParse('${json['sent_at'] ?? ''}')?.toLocal(),
      imageUrl: image != null && _picture.hasMatch(image) ? image : null,
      repeats: json['repeats'] == true,
    );
  }

  final int id;
  final String title;
  final String body;

  /// The company that sent it, or Nirmaan360 for the platform's own.
  final String from;

  /// When it last reached this person. A reminder moves this each time.
  final DateTime? sentAt;

  /// A public picture, fetched without the sign-in token.
  final String? imageUrl;

  /// A daily or weekly reminder rather than a one-off.
  final bool repeats;

  static final RegExp _picture = RegExp(r'^https?://', caseSensitive: false);

  @override
  List<Object?> get props => <Object?>[id, title, body, from, sentAt, imageUrl, repeats];
}

/// One page of the list, and whether there is another after it.
class AnnouncementPage extends Equatable {
  const AnnouncementPage({required this.items, required this.page, required this.hasMore});

  factory AnnouncementPage.fromJson(Map<String, dynamic> json) {
    final Object? rows = json['data'];
    final int page = _int(json['current_page']) ?? 1;
    final int last = _int(json['last_page']) ?? page;
    return AnnouncementPage(
      items: <Announcement>[
        if (rows is List)
          for (final Object? row in rows)
            if (row is Map) ?Announcement.fromJson(row.cast<String, dynamic>()),
      ],
      page: page,
      hasMore: page < last,
    );
  }

  final List<Announcement> items;
  final int page;
  final bool hasMore;

  static int? _int(Object? value) => value is int ? value : int.tryParse('${value ?? ''}');

  @override
  List<Object?> get props => <Object?>[items, page, hasMore];
}

/// Reads what was announced to the person signed in. Online only: the server
/// keeps the list, since it decides who each one reached. Throws `ApiFailure`.
class AnnouncementsRepository {
  /// [portal] answers whether the person is a contractor login, which reads
  /// the same list under `/contractor-portal`.
  const AnnouncementsRepository(this._api, {required this._portal});

  final NirmaanApi _api;
  final bool Function() _portal;

  Future<AnnouncementPage> page(int page, {required String locale}) async =>
      AnnouncementPage.fromJson(await _api.announcements(page: page, locale: locale, portal: _portal()));
}
