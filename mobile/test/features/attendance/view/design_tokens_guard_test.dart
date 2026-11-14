// The attendance and petty cash screens take their sizes, colours and motion
// from the design system. These checks read the source, so a stray number is
// caught the day it is typed, not the day a phone shows it too big.
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Every Dart file of the two features.
List<File> _sources() {
  return <String>['lib/features/attendance', 'lib/features/petty_cash']
      .expand(
        (dir) => Directory(dir)
            .listSync(recursive: true)
            .whereType<File>()
            .where((file) => file.path.endsWith('.dart')),
      )
      .toList();
}

/// The lines of [file] without comments, so a note about a rule is not
/// mistaken for breaking it.
Iterable<MapEntry<int, String>> _code(File file) sync* {
  final List<String> lines = file.readAsLinesSync();
  for (var i = 0; i < lines.length; i++) {
    final String line = lines[i];
    if (line.trimLeft().startsWith('//')) continue;
    yield MapEntry<int, String>(i + 1, line);
  }
}

void _expectNone(RegExp pattern, String what) {
  final List<String> found = <String>[
    for (final file in _sources())
      for (final line in _code(file))
        if (pattern.hasMatch(line.value))
          '${file.path}:${line.key}  ${line.value.trim()}',
  ];
  expect(found, isEmpty, reason: '$what:\n${found.join('\n')}');
}

void main() {
  test('no text is sized by hand', () {
    _expectNone(RegExp(r'fontSize\s*:'), 'use the N360Type tokens as they are');
  });

  test('no icon is sized by a bare number', () {
    _expectNone(
      RegExp(r'\bsize:\s*\d'),
      'use N360IconSize (or N360Size for a tile) instead of a number',
    );
  });

  test('sheets, spinners and colours come from the design system', () {
    _expectNone(RegExp(r'showModalBottomSheet'), 'use showLedgerSheet');
    _expectNone(RegExp(r'CircularProgressIndicator'), 'use LedgerSkeleton');
    _expectNone(RegExp(r'\bColor\(0x|\bColors\.'), 'use N360Colors');
    _expectNone(RegExp(r'BackdropFilter|ImageFilter'), 'no blur anywhere');
  });

  test('the flat design: no shadows, gradients, tints or old faces', () {
    _expectNone(RegExp(r'BoxShadow|paintSoftShadow|shadow\s*:'), 'no shadows');
    _expectNone(RegExp(r'N360Gradients|LinearGradient'), 'no gradients');
    _expectNone(RegExp(r'goldTint'), 'no tinted wash on totals');
    _expectNone(
      RegExp(r'Poppins|DMMono|N360Type\.(poppins|mono)\b'),
      'one face: Mukta, through N360Type',
    );
    _expectNone(
      RegExp(r'Radius\.circular\('),
      'radii come from N360Radius, which stops at 12',
    );
  });
}
