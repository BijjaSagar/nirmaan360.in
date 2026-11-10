// The offline plan against a real local Laravel server, on local data only.
//
// Skipped by default. Needs a company on the offline plan made with tinker, as
// tests/Feature/OfflinePlan/BuildsOfflineCompany.php does:
//   flutter test test/integration/offline_plan_real_api_test.dart \
//     --dart-define=REAL_API_URL=http://127.0.0.1:8361/api/v1 \
//     --dart-define=OFFLINE_OWNER=owner@example.test \
//     --dart-define=OFFLINE_PASSWORD=...
//
// The test changes that company's plan through tinker, so it needs the PHP
// checkout one folder up. It must never point at production.
import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/offline/licence_token.dart';
import 'package:nirmaan360/core/offline/offline_plan.dart';
import 'package:nirmaan360/core/session/token_store.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/clock.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/offline_plan/data/offline_upload.dart';

const _url = String.fromEnvironment('REAL_API_URL');
const _owner = String.fromEnvironment('OFFLINE_OWNER');
const _password = String.fromEnvironment('OFFLINE_PASSWORD');

const _pixel =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

String _indiaDaysAgo(int days) {
  final now = DateTime.now().toUtc().add(const Duration(hours: 5, minutes: 30)).subtract(Duration(days: days));
  String two(int v) => v.toString().padLeft(2, '0');
  return '${now.year}-${two(now.month)}-${two(now.day)}';
}

/// Runs PHP against the same local database the server uses. Prints only what
/// [code] echoes.
Future<String> _tinker(String code) async {
  final result = await Process.run('php', <String>['artisan', 'tinker', '--execute=$code'], workingDirectory: Directory.current.parent.path);
  expect(result.exitCode, 0, reason: '${result.stdout}${result.stderr}');
  return result.stdout.toString().trim();
}

String _companyCode(String body) =>
    "\$u = App\\Models\\User::where('email', '$_owner')->firstOrFail(); \$o = \$u->organization; $body";

Future<void> _setPlan(String plan) =>
    _tinker(_companyCode("\$o->update(['plan' => '$plan', 'plan_status' => 'active']);"));

void main() {
  test(
    'activate, verify, refuse, then ask, upload and sync on a real server',
    () async {
      expect(_url, contains('127.0.0.1'), reason: 'local only');
      final temp = Directory.systemTemp.createTempSync('n360_offline_real');
      addTearDown(() => temp.deleteSync(recursive: true));
      final photo = File('${temp.path}/site.png')..writeAsBytesSync(base64Decode(_pixel));

      await _setPlan('offline');
      await _tinker(_companyCode(r"App\Models\DeviceLicence::where('organization_id', $o->id)->delete();"));

      final db = AppDatabase.forTesting(NativeDatabase.memory());
      final tokens = InMemoryTokenStore();
      final api = DioNirmaanApi(readToken: tokens.read, baseUrl: _url);
      final services = AppServices.forTesting(
        db: db,
        api: api,
        tokens: tokens,
        clock: const SystemClock(),
        attachments: DirectoryAttachmentStore(() async => temp.path),
        tempDirectory: () async => temp.path,
      );
      addTearDown(services.dispose);

      await services.auth.signIn(email: _owner, password: _password);

      // Activated, with a token that really verifies.
      final OfflinePlan plan = services.offlinePlan;
      expect(plan.isLocalOnly, isTrue);
      final OfflinePlanState held = plan.state!;
      expect(held.hasToken, isTrue);
      final String deviceId = (await db.readMeta(MetaKey.offlineDeviceId))!;
      final claims = await verifyLicenceToken(held.token!, publicKeyBase64: held.publicKey!, deviceId: deviceId);
      expect(claims?.plan, 'offline');
      expect(claims?.features, containsAll(<String>['site', 'measurement', 'labour', 'petty_cash']));
      expect(claims?.expiresAt.isAfter(DateTime.now()), isTrue);

      // A corrupted copy does not verify.
      expect(await verifyLicenceToken('${held.token!}x', publicKeyBase64: held.publicKey!, deviceId: deviceId), isNull);

      // Blocked before the network, and refused by the server if it got there.
      await expectLater(api.pull(), throwsA(isA<OfflineFailure>()));
      final guard = api.blocksPath;
      api.blocksPath = null;
      await expectLater(api.pull(), throwsA(isA<OfflinePlanRefused>()));
      api.blocksPath = guard;

      // Refresh, and a second phone over the limit.
      await plan.check(force: true);
      expect(plan.state?.issue, LicenceIssue.none);
      await expectLater(
        api.activateDevice(deviceId: 'qa-other-phone-1', deviceName: 'Other'),
        throwsA(isA<ServerFailure>().having((e) => e.code, 'code', 'device_limit').having((e) => e.data?['manage_url'], 'manage', contains('/offline/devices'))),
      );

      // A site built on the phone alone.
      final String today = _indiaDaysAgo(0);
      final String day = _indiaDaysAgo(1);
      final stamp = DateTime.now().millisecondsSinceEpoch;
      final project = await services.projects.createProject(name: 'Offline real $stamp', location: 'Pune', contractValue: Money.parse('1500000'));
      expect(project.id, lessThan(0));
      await services.projects.selectProject(project.id);
      final line = await services.projects.addBoqItem(projectId: project.id, description: 'RCC slab', unit: 'cum', qty: Qty.parse('50'), rate: Money.parse('6200'));
      final worker = await services.attendance.addWorker(name: 'Real Worker $stamp', trade: 'Mason', dailyWage: Money.parse('750'));
      final wallet = await services.pettyCash.createWallet(projectId: project.id, name: 'Site cash', userId: services.session.user?.id);
      await services.pettyCash.addTopUp(walletId: wallet.id, amount: Money.parse('5000'), entryDate: day);
      final String expense = await services.pettyCash.addExpense(
        walletId: wallet.id,
        amount: Money.parse('120'),
        entryDate: day,
        category: 'Food',
        billSourcePath: photo.path,
      );
      await services.attendance.mark(projectId: project.id, workerId: worker.id, date: day, status: 'present');
      await services.measurements.create(
        MeasurementInput(projectId: project.id, boqItemId: line.id, measuredOn: day, nos: Qty.fromInt(2), length: Qty.parse('3.5')),
      );
      final String dpr = await services.dpr.saveDraft(
        DprInput(projectId: project.id, reportDate: today, weather: 'Clear', summary: 'Local', workItems: <WorkLine>[WorkLine(boqItemId: line.id, qty: Qty.parse('1'))]),
      );
      await services.dpr.addPhoto(dprClientUuid: dpr, sourcePath: photo.path);
      await services.dpr.send(dpr);

      final export = await services.exportBackup();
      expect(export.photos, 2);

      // Sync does nothing.
      expect((await services.sync.syncNow()).isSuccess, isTrue);
      expect(await services.context.outbox.unsentCount(), greaterThan(0));

      // The company moves to an online plan. Nothing goes up until a yes.
      await _setPlan('business');
      await plan.check(force: true);
      expect(plan.state?.mode, OfflineMode.asking);
      expect(await _tinker(_companyCode(r"echo App\Models\Project::withoutGlobalScopes()->where('organization_id', $o->id)->where('name', 'Offline real "
          "$stamp"
          r"')->count();")), '0');

      final OfflineUploader uploader = services.offlineUploader;
      final Map<String, String> uuids = <String, String>{};
      for (final row in await db.select(db.keyValues).get()) {
        if (row.key.startsWith('offline.record.')) uuids[row.key] = (jsonDecode(row.value) as Map)['uuid'] as String;
      }

      final UploadReport report = await uploader.run();
      expect(report.isReady, isTrue, reason: '${report.stop}: ${report.detail}');
      await uploader.finish(services.sync);

      expect(plan.isLocalOnly, isFalse);
      expect(await services.context.outbox.unsentCount(), 0, reason: 'the queue emptied through the normal sync');
      expect(await db.customSelect('SELECT COUNT(*) AS c FROM projects WHERE id < 0').getSingle().then((r) => r.read<int>('c')), 0);

      // The server has each record, once.
      final String counts = await _tinker(
        _companyCode(
          r"$p = App\Models\Project::withoutGlobalScopes()->where('organization_id', $o->id)->where('name', 'Offline real "
          "$stamp"
          r"')->get(); $id = $p->first()->id; "
          r"echo implode(',', [$p->count(), App\Models\BoqItem::withoutGlobalScopes()->where('project_id', $id)->count(), App\Models\PettyCashWallet::withoutGlobalScopes()->where('project_id', $id)->count(), App\Models\MeasurementEntry::withoutGlobalScopes()->where('project_id', $id)->count(), App\Models\Dpr::withoutGlobalScopes()->where('project_id', $id)->count(), App\Models\PettyCashEntry::withoutGlobalScopes()->whereIn('petty_cash_wallet_id', App\Models\PettyCashWallet::withoutGlobalScopes()->where('project_id', $id)->pluck('id'))->count(), App\Models\Attendance::withoutGlobalScopes()->where('project_id', $id)->count()]);",
        ),
      );
      expect(counts, '1,1,1,1,1,2,1');

      // Sending the same upload ids again finds the same rows.
      final project2 = await db.select(db.projects).get();
      final int serverProject = project2.firstWhere((p) => p.name.startsWith('Offline real $stamp')).id;
      final repeat = await api.createProject(clientUuid: uuids[MetaKey.offlineRecord(LocalRecordKind.project, project.id)]!, name: 'Offline real $stamp');
      expect((repeat['id'] as num).toInt(), serverProject);
      final repeatLine = await api.addBoqItem(
        projectId: serverProject,
        clientUuid: uuids[MetaKey.offlineRecord(LocalRecordKind.boqItem, line.id)]!,
        description: 'RCC slab',
        unit: 'cum',
        qty: '50',
        rate: '6200',
      );
      expect(await db.select(db.boqItems).get().then((rows) => rows.map((r) => r.id)), contains((repeatLine['id'] as num).toInt()));

      expect(expense, isNotEmpty);
      await _setPlan('offline');
    },
    skip: _url.isEmpty || _owner.isEmpty ? 'Set REAL_API_URL, OFFLINE_OWNER and OFFLINE_PASSWORD to run.' : false,
    timeout: const Timeout(Duration(minutes: 3)),
  );
}
