import 'package:flutter/foundation.dart';

/// A push message, as far as the app cares: what to show, and what it points to.
///
/// FCM sends every `data` value as text, so [data] is a map of strings. Parsing
/// it never throws; a value of any other type is read as its text.
@immutable
class PushMessage {
  const PushMessage({this.title, this.body, this.data = const <String, String>{}});

  /// Builds a message from whatever the plugin gave, tolerating every shape.
  factory PushMessage.fromParts({Object? title, Object? body, Object? data}) {
    return PushMessage(
      title: _text(title),
      body: _text(body),
      data: data is Map
          ? <String, String>{
              for (final MapEntry<Object?, Object?> entry in data.entries)
                if (entry.key is String && entry.value != null) entry.key! as String: entry.value.toString(),
            }
          : const <String, String>{},
    );
  }

  final String? title;
  final String? body;
  final Map<String, String> data;

  /// Something worth showing the person: a title or a body.
  bool get hasText => (title?.isNotEmpty ?? false) || (body?.isNotEmpty ?? false);

  static String? _text(Object? value) {
    if (value == null) return null;
    final String text = value.toString().trim();
    return text.isEmpty ? null : text;
  }

  // The text can name a person or a document: keep it out of logs.
  @override
  String toString() => 'PushMessage(<hidden>)';
}
