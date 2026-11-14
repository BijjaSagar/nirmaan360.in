import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';

import 'test_helpers.dart';

void main() {
  setUpAll(loadRealFonts);

  Widget bar({
    String? title = 'Petty cash',
    String? subtitle,
    bool showBack = true,
    VoidCallback? onBack,
    Widget? trailing,
  }) {
    return SizedBox(
      width: 360,
      child: N360TopBar(
        backLabel: 'Back',
        title: title,
        subtitle: subtitle,
        showBack: showBack,
        onBack: onBack,
        trailing: trailing,
      ),
    );
  }

  testWidgets('the back tile carries its word and a 56dp target', (tester) async {
    var popped = 0;
    await pumpDesign(tester, bar(onBack: () => popped++));

    expect(find.text('Back'), findsOneWidget);
    expect(find.byIcon(Icons.arrow_back), findsOneWidget);
    final Size target = tester.getSize(find.ancestor(of: find.text('Back'), matching: find.byType(PressTarget)).first);
    expect(target.height, greaterThanOrEqualTo(N360Size.tap));
    expect(target.width, greaterThanOrEqualTo(N360Size.tap));

    await tester.tap(find.text('Back'));
    expect(popped, 1);
  });

  testWidgets('the title stays centred whether or not there is a way back', (tester) async {
    await pumpDesign(tester, bar(onBack: () {}, subtitle: 'KT-B'));
    final Rect withBack = tester.getRect(find.text('Petty cash'));

    await pumpDesign(tester, bar(showBack: false, subtitle: 'KT-B'));
    expect(find.text('Back'), findsNothing);
    final Rect withoutBack = tester.getRect(find.text('Petty cash'));

    expect(withBack.center.dx, closeTo(withoutBack.center.dx, 0.5));
    expect(withBack.center.dx, closeTo(180, 1));
    expect(find.text('KT-B'), findsOneWidget);
  });

  testWidgets('a header tile never shows an icon without its word', (tester) async {
    var opened = 0;
    await pumpDesign(
      tester,
      bar(
        showBack: false,
        trailing: N360TopTile(icon: Icons.settings_outlined, label: 'Settings', onTap: () => opened++),
      ),
    );

    expect(find.text('Settings'), findsOneWidget);
    final Size target = tester.getSize(find.byType(PressTarget));
    expect(target.height, greaterThanOrEqualTo(N360Size.tap));
    expect(target.width, greaterThanOrEqualTo(N360Size.tap));
    expect(tester.getRect(find.text('Settings')).right, lessThanOrEqualTo(360));

    await tester.tap(find.text('Settings'));
    expect(opened, 1);
  });

  testWidgets('a Marathi word fits the tile at a larger text size', (tester) async {
    await pumpDesign(
      tester,
      bar(
        onBack: () {},
        trailing: N360TopTile(icon: Icons.settings_outlined, label: 'सेटिंग्ज', onTap: () {}),
      ),
      textScale: 1.3,
    );
    expect(tester.takeException(), isNull);
    expect(find.text('सेटिंग्ज'), findsOneWidget);
    expect(find.text('Back'), findsOneWidget);
  });

  testWidgets('with no title the bar still keeps its height', (tester) async {
    await pumpDesign(tester, bar(title: null, onBack: () {}));
    expect(tester.getSize(find.byType(N360TopBar)).height, greaterThanOrEqualTo(N360Size.tap));
  });
}
