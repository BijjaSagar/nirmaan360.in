// Renders a contractor payment in the approvals screen to PNG with real fonts,
// for a person to judge.
//
// flutter test test/features/approvals/view/contractor_payment_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../measurement/view/book_test_kit.dart';
import 'contractor_payment_approval_test.dart' show payment;

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  if (_dir.isEmpty) return;

  Future<void> settle(WidgetTester tester) async {
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 800));
    await pumpDrift(tester, 10);
  }

  for (final String locale in <String>['en', 'mr']) {
    for (final (String name, Map<String, dynamic> override) in <(String, Map<String, dynamic>)>[
      ('plain', <String, dynamic>{'needed': false, 'allowed': false, 'message': null}),
      (
        'owner',
        <String, dynamic>{
          'needed': true,
          'allowed': true,
          'message':
              'Approving this payment would make it 80% paid against 70% work done, 10 points ahead, beyond '
              "this project's limit of 5 points. Only the owner can approve it, with a written reason.",
        },
      ),
    ]) {
      testWidgets('contractor payment $name ($locale)', (tester) async {
        final FakeApi api = FakeApi();
        api.approvalInbox.add(payment(40, ownerOverride: override));
        final TestApp app = await bookApp(api: api);
        addTearDown(app.dispose);
        await pumpBook(tester, app, start: Routes.approvalDetail(40), locale: locale, pixelRatio: 2.5);
        await settle(tester);
        await tester.runAsync(() => capturePng(tester, _dir, '${locale}_payment_${name}_top'));

        await tester.drag(find.byType(Scrollable).first, const Offset(0, -700));
        await settle(tester);
        await tester.runAsync(() => capturePng(tester, _dir, '${locale}_payment_${name}_bottom'));

        if (name == 'plain') {
          await tester.tap(find.byKey(const ValueKey<String>('approval-reject')));
          await settle(tester);
          await tester.runAsync(() => capturePng(tester, _dir, '${locale}_payment_reject_sheet'));
        } else {
          await tester.tap(find.byKey(const ValueKey<String>('approval-approve')));
          await settle(tester);
          await tester.runAsync(() => capturePng(tester, _dir, '${locale}_payment_override_sheet'));
        }
      });
    }
  }
}
