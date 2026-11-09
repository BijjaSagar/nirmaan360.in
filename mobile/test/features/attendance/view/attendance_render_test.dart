// Renders the attendance screens to PNG with real fonts, for a person to judge.
//
// flutter test test/features/attendance/view/attendance_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
//
// Without the define the test is skipped, so a normal run writes nothing.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import 'field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

const List<(String, String)> _crew = <(String, String)>[
  ('Ramesh Pawar', 'Mason'),
  ('Dinesh Patil', 'Mason'),
  ('Prakash More', 'Mason'),
  ('Sanjay Shinde', 'Mason'),
  ('Rajesh Kale', 'Mason'),
  ('Mahadev Jadhav', 'Mason'),
  ('Anil Gaikwad', 'Helper'),
  ('Sunil Kamble', 'Helper'),
  ('Ganesh Bhosale', 'Helper'),
  ('Vijay Chavan', 'Helper'),
  ('Sachin Mane', 'Helper'),
  ('Santosh Salunkhe', 'Helper'),
  ('Rohit Pawar', 'Helper'),
  ('Ashok Thorat', 'Carpenter'),
  ('Mahesh Yadav', 'Carpenter'),
  ('Sandeep Kokate', 'Carpenter'),
  ('Vinod Shelar', 'Bar bender'),
  ('Tukaram Gawade', 'Bar bender'),
  ('Balu Waghmare', 'Bar bender'),
  ('Nitin Sawant', 'Bar bender'),
  ('Kishor Dhere', 'Electrician'),
  ('Amol Lokhande', 'Electrician'),
  ('Shankar Kadam', 'Plumber'),
  ('Dattatray Bhoir', 'Plumber'),
  ('Manoj Ghadge', 'Painter'),
  ('Sagar Nikam', 'Painter'),
  ('Yuvraj Pandit', 'Welder'),
  ('Harish Rane', 'Operator'),
  ('Suryakant Devkar', 'Supervisor'),
  ('Pankaj Mhaske', 'Supervisor'),
  ('Lahu Gaware', 'Helper'),
  ('Ramdas Ubale', 'Helper'),
];

Future<TestApp> _seededMuster() async {
  final app = await TestApp.create();
  var id = 10;
  for (final (name, trade) in _crew) {
    app.api.addWorker2(id: id++, name: name, trade: trade);
  }
  await app.sync();
  return app;
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  if (_dir.isEmpty) return;

  testWidgets('capture muster', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 780) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final app = await _seededMuster();
    addTearDown(app.dispose);

    final attendance = app.services.attendance;
    final today = app.services.context.today;
    Future<void> mark(int id, String status, {Hours? ot}) =>
        attendance.mark(projectId: 1, workerId: id, date: today, status: status, otHours: ot);

    await mark(10, 'present');
    await mark(11, 'overtime', ot: Hours.fromInt(3));
    await mark(12, 'half');
    await mark(13, 'present');
    await mark(15, 'absent');

    for (final (name, locale) in <(String, Locale)>[('en', const Locale('en')), ('mr', const Locale('mr'))]) {
      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen(), locale: locale));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 700));
      await tester.runAsync(() => capturePng(tester, _dir, '${name}_muster'));

      // Scrolled, so the pinned totals show above the rows.
      await tester.drag(find.byType(CustomScrollView), const Offset(0, -520));
      await tester.pump(const Duration(milliseconds: 500));
      await tester.runAsync(() => capturePng(tester, _dir, '${name}_muster_scrolled'));

      // A second, very different day of the same roster.
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('capture add worker sheet', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 780) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final app = await _seededMuster();
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.text('Add worker'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await tester.runAsync(() => capturePng(tester, _dir, 'en_add_worker_details'));

    await tester.enterText(find.byType(TextField), 'Ramesh Pawar');
    await tester.pump();
    await tester.tap(find.text('Mason').last);
    await tester.pump();
    await tester.tap(find.text('Next'));
    await tester.pump(const Duration(milliseconds: 300));
    await tester.tap(find.text('6'));
    await tester.pump();
    await tester.tap(find.text('5').last);
    await tester.pump();
    await tester.tap(find.text('0').last);
    await tester.pump(const Duration(milliseconds: 500));
    await tester.runAsync(() => capturePng(tester, _dir, 'en_add_worker_wage'));

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('capture loading and empty muster', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 780) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final app = await _seededMuster();
    addTearDown(app.dispose);

    // The first frame, before the muster has been read: the skeleton.
    await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
    await tester.runAsync(() => capturePng(tester, _dir, 'en_muster_loading'));
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();

    final empty = await TestApp.create(seed: false);
    addTearDown(empty.dispose);
    empty.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
    await empty.sync();
    for (final (name, locale) in <(String, Locale)>[('en', const Locale('en')), ('mr', const Locale('mr'))]) {
      await tester.pumpWidget(fieldApp(empty.services, const AttendanceScreen(), locale: locale));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 600));
      await tester.runAsync(() => capturePng(tester, _dir, '${name}_muster_empty'));
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('capture add worker sheet offline and in Marathi', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 780) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final app = await _seededMuster();
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen(), locale: const Locale('mr')));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.text('कामगार जोडा'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await tester.runAsync(() => capturePng(tester, _dir, 'mr_add_worker_details'));
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));

    final offline = await _seededMuster();
    addTearDown(offline.dispose);
    offline.connectivity.setOnline(false);
    await tester.pumpWidget(fieldApp(offline.services, const AttendanceScreen()));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.text('Add worker'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await tester.runAsync(() => capturePng(tester, _dir, 'en_add_worker_offline'));
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });
}
