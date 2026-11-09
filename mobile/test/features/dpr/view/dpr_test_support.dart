import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../support/test_app.dart';

const String today = '2026-11-09';
const String yesterday = '2026-11-08';

/// The server's real vocabulary, so the sheet shows all ten trades.
const List<String> allTrades = <String>[
  'Mason',
  'Helper',
  'Carpenter',
  'Bar bender',
  'Electrician',
  'Plumber',
  'Painter',
  'Welder',
  'Operator',
  'Supervisor',
];

/// A fake server with realistic BOQ lines and every trade.
FakeApi demoApi({Uint8List? photoBytes}) {
  final api = _DemoApi(photoBytes)..meta['trades'] = allTrades;
  api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
  api.addSite(id: 1, projectId: 1, name: 'Tower B');
  api.addBoqItem(id: 1, projectId: 1, code: '2.1', qty: '620.000');
  api.boqItems.single.addAll(<String, dynamic>{
    'description': 'M25 RCC in slab and beams, sixth floor',
    'balance_qty': '555.000',
  });
  api.addBoqItem(id: 2, projectId: 1, code: '3.4', qty: '48.000', unit: 'MT');
  api.boqItems.last.addAll(<String, dynamic>{
    'description': 'Fe-500 reinforcement steel, cut and bent',
    'balance_qty': '31.250',
  });
  api.addBoqItem(id: 3, projectId: 1, code: '5.2', qty: '940.000', unit: 'sqm');
  api.boqItems.last.addAll(<String, dynamic>{
    'description': 'Brick masonry 230 mm in cement mortar 1:6',
    'balance_qty': '612.500',
  });
  api.addWallet(id: 1, projectId: 1, balance: '12500.00', userId: 4);
  return api;
}

class _DemoApi extends FakeApi {
  _DemoApi(this._photo);

  final Uint8List? _photo;

  @override
  Future<Uint8List> dprPhotoBytes(int photoId) async => _photo ?? await super.dprPhotoBytes(photoId);
}

/// Mounts [home] in the real theme with real translations and shared paper.
Widget hostApp(
  AppServices services,
  Widget home, {
  Locale locale = const Locale('en'),
  bool disableAnimations = true,
  double textScale = 1,
}) {
  return RepositoryProvider<AppServices>.value(
    value: services,
    child: MaterialApp(
      theme: buildN360Theme(),
      debugShowCheckedModeBanner: false,
      locale: locale,
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const <LocalizationsDelegate<dynamic>>[
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, navigator) => MediaQuery(
        data: MediaQuery.of(context).copyWith(
          disableAnimations: disableAnimations,
          textScaler: TextScaler.linear(textScale),
        ),
        child: LedgerPaper.root(child: navigator),
      ),
      home: home,
    ),
  );
}

/// Sets a phone-sized test window.
void usePhone(WidgetTester tester, {double width = 360, double height = 800, double ratio = 2.5}) {
  tester.view.devicePixelRatio = ratio;
  tester.view.physicalSize = Size(width, height) * ratio;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

/// Lets the camera return [path] without a device.
void stubCamera(WidgetTester tester, {String? path = '/camera/shot.jpg'}) {
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.flutter.io/image_picker'),
    (call) async => path,
  );
  addTearDown(() {
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('plugins.flutter.io/image_picker'),
      null,
    );
  });
}

/// A small generated "site photo", so renders show a real thumbnail.
Future<Uint8List> fakeSitePhoto() async {
  final recorder = ui.PictureRecorder();
  final canvas = Canvas(recorder);
  const double w = 300;
  const double h = 300;
  canvas.drawRect(
    const Rect.fromLTWH(0, 0, w, h),
    Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: <Color>[Color(0xFF9DBFD6), Color(0xFFDDD2BE), Color(0xFF8D7A62)],
      ).createShader(const Rect.fromLTWH(0, 0, w, h)),
  );
  for (var i = 0; i < 6; i++) {
    canvas.drawRect(
      Rect.fromLTWH(30.0 + i * 42, 110 + (i.isEven ? 0 : 18), 30, h - 110),
      Paint()..color = const Color(0xFF6C6A65),
    );
  }
  canvas.drawRect(const Rect.fromLTWH(0, 250, w, 8), Paint()..color = const Color(0xFFB4451F));
  final ui.Picture picture = recorder.endRecording();
  final ui.Image image = await picture.toImage(300, 300);
  final ByteData? data = await image.toByteData(format: ui.ImageByteFormat.png);
  image.dispose();
  picture.dispose();
  return data!.buffer.asUint8List();
}
