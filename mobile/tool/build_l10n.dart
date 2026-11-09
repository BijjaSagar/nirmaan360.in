// Merges every l10n/*.json fragment into lib/l10n/app_{en,mr,hi}.arb.
//
// A fragment keeps all three languages for a key side by side, so a string
// cannot ship with a language missing:
//
//   { "dprTitle": { "en": "Daily report", "mr": "दैनिक अहवाल", "hi": "दैनिक रिपोर्ट" },
//     "syncPending": { "en": "{count} waiting", "mr": "{count} बाकी", "hi": "{count} बाकी",
//                      "placeholders": { "count": { "type": "int" } } } }
//
// Run from mobile/:  dart run tool/build_l10n.dart && flutter gen-l10n
import 'dart:convert';
import 'dart:io';

const locales = ['en', 'mr', 'hi'];

void main() {
  final fragments = Directory('l10n')
      .listSync()
      .whereType<File>()
      .where((f) => f.path.endsWith('.json'))
      .toList()
    ..sort((a, b) => a.path.compareTo(b.path));

  final merged = <String, Map<String, dynamic>>{};
  final origin = <String, String>{};
  final problems = <String>[];

  for (final file in fragments) {
    final Map<String, dynamic> entries;
    try {
      entries = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
    } on FormatException catch (e) {
      problems.add('${file.path}: invalid JSON (${e.message})');
      continue;
    }
    entries.forEach((key, raw) {
      final value = raw as Map<String, dynamic>;
      if (merged.containsKey(key)) {
        problems.add('Duplicate key "$key" in ${file.path} and ${origin[key]}');
        return;
      }
      for (final locale in locales) {
        final text = value[locale];
        if (text is! String || text.isEmpty) {
          problems.add('Key "$key" in ${file.path} has no "$locale" text');
        }
      }
      merged[key] = value;
      origin[key] = file.path;
    });
  }

  if (problems.isNotEmpty) {
    stderr.writeln(problems.join('\n'));
    exit(1);
  }

  final keys = merged.keys.toList()..sort();
  const encoder = JsonEncoder.withIndent('  ');

  for (final locale in locales) {
    final arb = <String, dynamic>{'@@locale': locale};
    for (final key in keys) {
      arb[key] = merged[key]![locale];
      final placeholders = merged[key]!['placeholders'];
      if (locale == 'en' && placeholders != null) {
        arb['@$key'] = {'placeholders': placeholders};
      }
    }
    File('lib/l10n/app_$locale.arb').writeAsStringSync('${encoder.convert(arb)}\n');
  }

  stdout.writeln('Wrote ${keys.length} keys from ${fragments.length} fragments.');
}
