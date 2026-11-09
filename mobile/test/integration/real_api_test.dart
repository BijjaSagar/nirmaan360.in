// Runs the real offline stack against a real local Laravel server.
//
// Skipped by default. Use only against local demo data, never production:
//   php artisan migrate --seed && php artisan serve --port=8361
//   flutter test test/integration/real_api_test.dart \
//     --dart-define=REAL_API_URL=http://127.0.0.1:8361/api/v1
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/session/token_store.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/clock.dart';
import 'package:nirmaan360/core/sync/sync_status.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

const _url = String.fromEnvironment('REAL_API_URL');
const _unreachable = 'http://127.0.0.1:9/api/v1';

// A 1×1 PNG, enough to pass the server's image validation.
const _pixel =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

String _indiaToday() {
  final now = DateTime.now().toUtc().add(const Duration(hours: 5, minutes: 30));
  String two(int v) => v.toString().padLeft(2, '0');
  return '${now.year}-${two(now.month)}-${two(now.day)}';
}

List<Map<String, dynamic>> _withUuid(List<dynamic> rows, String uuid) => rows
    .cast<Map<String, dynamic>>()
    .where((row) => row['client_uuid'] == uuid)
    .toList();

void main() {
  test(
    'entries made offline reach the real server exactly once',
    () async {
      final temp = Directory.systemTemp.createTempSync('n360_real_api');
      addTearDown(() => temp.deleteSync(recursive: true));
      final photo = File('${temp.path}/site.png')
        ..writeAsBytesSync(base64Decode(_pixel));

      final db = AppDatabase.forTesting(NativeDatabase.memory());
      final tokens = InMemoryTokenStore();
      final dio = Dio();
      final api = DioNirmaanApi(
        readToken: tokens.read,
        baseUrl: _url,
        dio: dio,
      );
      final services = AppServices.forTesting(
        db: db,
        api: api,
        tokens: tokens,
        attachments: DirectoryAttachmentStore(() async => temp.path),
      );

      final signIn = await services.auth.signIn(
        email: 'engineer@demo.test',
        password: 'password',
      );
      expect(signIn.firstSync.outcome, SyncOutcome.success);

      final project = (await services.projects.watchProjects().first).first;
      await services.projects.selectProject(project.id);
      final boq = await services.projects.watchBoqItems(project.id).first;
      expect(boq, isNotEmpty, reason: 'first pull should fill the BOQ');
      final today = _indiaToday();

      // Network off. Every entry below is made with the server unreachable.
      dio.options.baseUrl = _unreachable;

      final measurement = await services.measurements.create(
        MeasurementInput(
          projectId: project.id,
          boqItemId: boq.first.item.id,
          measuredOn: today,
          nos: Qty.parse('2'),
          length: Qty.parse('10'),
          breadth: Qty.parse('5'),
          depth: Qty.parse('0.5'),
          location: 'Real API test',
        ),
      );
      await services.measurements.sendForApproval(measurement);

      final report = await services.dpr.saveDraft(
        DprInput(
          projectId: project.id,
          reportDate: today,
          weather: 'Clear',
          manpower: const [ManpowerLine(trade: 'Mason', count: 3)],
          workItems: [
            WorkLine(boqItemId: boq.first.item.id, qty: Qty.parse('1.5')),
          ],
        ),
      );
      await services.dpr.addPhoto(
        dprClientUuid: report,
        sourcePath: photo.path,
      );
      await services.dpr.send(report);

      final issue = await services.issues.raise(
        projectId: project.id,
        title: 'Real API test issue',
        priority: 'low',
      );

      final muster = await services.attendance.watchDay(project.id, today).first;
      expect(muster, isNotEmpty);
      await services.attendance.mark(
        projectId: project.id,
        workerId: muster.first.worker.id,
        date: today,
        status: 'overtime',
        otHours: Hours.parse('2'),
      );

      final wallets = await services.pettyCash.watchWallets(project.id).first;
      expect(wallets, isNotEmpty);
      final expense = await services.pettyCash.addExpense(
        walletId: wallets.first.wallet.id,
        amount: Money.parse('123.45'),
        entryDate: today,
        category: 'Fuel',
        billSourcePath: photo.path,
      );

      Future<List<OutboxRow>> outbox() => db.select(db.outbox).get();

      final queued = await outbox();
      expect(queued.length, greaterThanOrEqualTo(8));

      await services.syncCenter.syncNow();
      expect(
        (await outbox()).every((row) => row.status == OutboxStatus.pending),
        isTrue,
        reason: 'with no network, everything stays queued',
      );

      // Network back, but the reply to the first push is lost: the server has
      // applied the batch and the phone does not know that.
      dio.options.baseUrl = _url;
      var dropNextPushReply = true;
      dio.interceptors.add(
        InterceptorsWrapper(
          onResponse: (response, handler) {
            if (dropNextPushReply &&
                response.requestOptions.path.endsWith('/sync/push')) {
              dropNextPushReply = false;
              handler.reject(
                DioException(
                  requestOptions: response.requestOptions,
                  type: DioExceptionType.connectionError,
                ),
              );
              return;
            }
            handler.next(response);
          },
        ),
      );

      for (var i = 0; i < 12; i++) {
        await services.syncCenter.syncNow();
        final rows = await outbox();
        if (rows.every((row) => row.status == OutboxStatus.done)) break;
        await Future<void>.delayed(const Duration(seconds: 3));
      }

      expect(dropNextPushReply, isFalse, reason: 'the lost reply must happen');
      final finished = await outbox();
      final notDone = finished
          .where((row) => row.status != OutboxStatus.done)
          .map((r) => '${r.type.name}: ${r.status.name} ${r.attentionReason}');
      expect(notDone, isEmpty);

      final server = await api.pull();
      final measurements = _withUuid(
        server['measurements'] as List<dynamic>,
        measurement,
      );
      expect(measurements, hasLength(1));
      expect(measurements.single['qty'], '50.000');
      expect(measurements.single['status'], 'IN_REVIEW');

      final reports = _withUuid(server['dprs'] as List<dynamic>, report);
      expect(reports, hasLength(1));
      expect(reports.single['status'], 'IN_REVIEW');
      expect(reports.single['photos'] as List<dynamic>, hasLength(1));

      expect(
        _withUuid(server['issues'] as List<dynamic>, issue),
        hasLength(1),
      );

      final entries = _withUuid(
        server['petty_cash_entries'] as List<dynamic>,
        expense,
      );
      expect(entries, hasLength(1));
      expect(entries.single['amount'], '123.45');
      expect(entries.single['has_bill'], isTrue);

      final marks = (server['attendance'] as List<dynamic>)
          .cast<Map<String, dynamic>>()
          .where(
            (row) =>
                row['worker_id'] == muster.first.worker.id &&
                row['attendance_date'] == today,
          )
          .toList();
      expect(marks, hasLength(1));
      expect(marks.single['status'], 'overtime');
      expect(marks.single['ot_hours'], '2.0');

      // A replay of an already delivered record creates nothing.
      final replay = finished.firstWhere(
        (row) => row.type == OutboxType.measurement,
      );
      final replayed = await api.push([
        PushItem(
          type: 'measurement',
          clientUuid: replay.wireUuid,
          projectId: replay.projectId,
          clientUpdatedAt: toWireTimestamp(replay.clientUpdatedAt!),
          data: jsonDecode(replay.payload) as Map<String, dynamic>,
        ),
      ]);
      expect(replayed.single.status, 'duplicate');

      // ignore: avoid_print
      print('UUIDS ${jsonEncode([measurement, report, issue, expense])}');
      await services.dispose();
    },
    skip: _url.isEmpty ? 'Set --dart-define=REAL_API_URL to run' : null,
    timeout: const Timeout(Duration(minutes: 4)),
  );
}
