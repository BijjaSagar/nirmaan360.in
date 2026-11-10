// Inspections, machine logs, goods receipts and store issues against a real local Laravel server.
//
// Skipped by default. Use only against local demo data, never production:
//   php artisan migrate --seed && php artisan serve --port=8361
//   (the company needs checklists and machines; ChecklistService::installStandard
//    and AssetService::create make them from tinker)
//   flutter test test/integration/field_tools_real_api_test.dart \
//     --dart-define=REAL_API_URL=http://127.0.0.1:8361/api/v1
import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/token_store.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/sync_status.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/ids.dart';
import 'package:nirmaan360/features/inspections/data/inspection_repository.dart';
import 'package:nirmaan360/features/material_control/data/material_repository.dart';

const _url = String.fromEnvironment('REAL_API_URL');
const _unreachable = 'http://127.0.0.1:9/api/v1';

void main() {
  test(
    'an inspection filled in offline reaches the real server once, though the reply is lost',
    () async {
      final db = AppDatabase.forTesting(NativeDatabase.memory());
      final tokens = InMemoryTokenStore();
      final dio = Dio();
      final api = DioNirmaanApi(readToken: tokens.read, baseUrl: _url, dio: dio);
      final services = AppServices.forTesting(
        db: db,
        api: api,
        tokens: tokens,
        attachments: InMemoryAttachmentStore(),
      );
      addTearDown(services.dispose);

      final signIn = await services.auth.signIn(email: 'engineer@demo.test', password: 'password');
      expect(signIn.firstSync.outcome, SyncOutcome.success);
      final project = (await services.projects.watchProjects().first).first;
      await services.projects.selectProject(project.id);
      await services.syncCenter.syncNow();

      final seen = (await services.inspections.readOverview(project.id))!;
      expect(seen.refused, isFalse);
      final template = seen.templates.firstWhere((t) => t.name == 'PPE checklist');
      expect(template.items, isNotEmpty);

      // Network off. The inspection is made and finished with the server unreachable.
      dio.options.baseUrl = _unreachable;
      var draft = await services.inspections.startDraft(projectId: project.id, template: template);
      for (var position = 1; position <= draft.items.length; position++) {
        draft = draft.withMark(position, result: position == 2 ? ItemResult.fail : ItemResult.pass, remark: position == 2 ? 'Real API test: no shoes' : null);
      }
      draft = draft.copyWith(location: 'Real API gate');
      await services.inspections.saveDraft(draft);
      await services.inspections.finish(draft);
      await services.syncCenter.syncNow();
      expect((await db.select(db.outbox).get()).single.status, OutboxStatus.pending);

      // Network back, but the reply to the post is lost: the server has the
      // inspection and the phone does not know it.
      dio.options.baseUrl = _url;
      var dropNextReply = true;
      dio.interceptors.add(
        InterceptorsWrapper(
          onResponse: (response, handler) {
            if (dropNextReply && response.requestOptions.method == 'POST' && response.requestOptions.path.endsWith('/inspections')) {
              dropNextReply = false;
              handler.reject(DioException(requestOptions: response.requestOptions, type: DioExceptionType.connectionError));
              return;
            }
            handler.next(response);
          },
        ),
      );
      for (var i = 0; i < 6; i++) {
        await services.syncCenter.syncNow();
        if ((await db.select(db.outbox).get()).every((r) => r.status == OutboxStatus.done)) break;
        await Future<void>.delayed(const Duration(seconds: 2));
      }

      expect(dropNextReply, isFalse, reason: 'the lost reply must happen');
      final row = (await db.select(db.outbox).get()).single;
      expect(row.status, OutboxStatus.done, reason: '${row.attentionReason}');

      final page = await api.inspections(project.id);
      final mine = (page['data'] as List).cast<Map<String, dynamic>>().where((r) => r['client_uuid'] == draft.uuid).toList();
      expect(mine, hasLength(1));
      expect(mine.single['status'], 'failed');
      expect(mine.single['location'], 'Real API gate');

      final detail = (await services.inspections.readOverview(project.id))!.find(draft.uuid)!;
      expect(detail.items, hasLength(draft.items.length));
      expect(detail.items[1].result, ItemResult.fail);
      expect(detail.items[1].remark, 'Real API test: no shoes');
      expect(detail.notSent, isFalse);
    },
    skip: _url.isEmpty ? 'set REAL_API_URL to run against a local server' : null,
    timeout: const Timeout(Duration(minutes: 3)),
  );

  test(
    'a machine log typed offline reaches the real server once, though the reply is lost',
    () async {
      final db = AppDatabase.forTesting(NativeDatabase.memory());
      final tokens = InMemoryTokenStore();
      final dio = Dio();
      final api = DioNirmaanApi(readToken: tokens.read, baseUrl: _url, dio: dio);
      final services = AppServices.forTesting(
        db: db,
        api: api,
        tokens: tokens,
        attachments: InMemoryAttachmentStore(),
      );
      addTearDown(services.dispose);

      final signIn = await services.auth.signIn(email: 'engineer@demo.test', password: 'password');
      expect(signIn.firstSync.outcome, SyncOutcome.success);
      final project = (await services.projects.watchProjects().first).first;
      await services.projects.selectProject(project.id);
      await services.syncCenter.syncNow();

      final seen = (await services.machines.readOverview(project.id))!;
      expect(seen.refused, isFalse);
      final jcb = seen.machines.firstWhere((m) => m.meterIsHours);

      // A day in the last two months that this run has to itself, so a second run
      // does not meet the server's 24 hours a day for one machine.
      final day = DateTime.now().toUtc().add(const Duration(hours: 5, minutes: 30)).subtract(Duration(days: 3 + DateTime.now().microsecond % 55));
      String two(int v) => v.toString().padLeft(2, '0');
      final date = '${day.year}-${two(day.month)}-${two(day.day)}';

      // Network off. The log is typed with the server unreachable, from readings only.
      dio.options.baseUrl = _unreachable;
      final uuid = newUuid();
      await services.machines.log(
        uuid: uuid,
        projectId: project.id,
        machine: jcb,
        date: date,
        openingReading: Qty.parse('100'),
        closingReading: Qty.parse('106.5'),
        operatorName: 'Real API test',
      );
      await services.syncCenter.syncNow();
      expect((await db.select(db.outbox).get()).single.status, OutboxStatus.pending);

      dio.options.baseUrl = _url;
      var dropNextReply = true;
      dio.interceptors.add(
        InterceptorsWrapper(
          onResponse: (response, handler) {
            if (dropNextReply && response.requestOptions.method == 'POST' && response.requestOptions.path.endsWith('/machine-logs')) {
              dropNextReply = false;
              handler.reject(DioException(requestOptions: response.requestOptions, type: DioExceptionType.connectionError));
              return;
            }
            handler.next(response);
          },
        ),
      );
      for (var i = 0; i < 6; i++) {
        await services.syncCenter.syncNow();
        if ((await db.select(db.outbox).get()).every((r) => r.status == OutboxStatus.done)) break;
        await Future<void>.delayed(const Duration(seconds: 2));
      }

      expect(dropNextReply, isFalse, reason: 'the lost reply must happen');
      final row = (await db.select(db.outbox).get()).single;
      expect(row.status, OutboxStatus.done, reason: '${row.attentionReason}');

      final page = await api.machineLogs(project.id);
      final mine = (page['data'] as List).cast<Map<String, dynamic>>().where((r) => r['client_uuid'] == uuid).toList();
      expect(mine, hasLength(1));
      expect(mine.single['hours'], '6.500', reason: 'the server worked the hours out from the readings');
      expect(mine.single['operator'], 'Real API test');

      final log = (await services.machines.readOverview(project.id))!.logs.firstWhere((l) => l.clientUuid == uuid);
      expect(log.hours, Hours.parse('6.5'));
      expect(log.notSent, isFalse);
    },
    skip: _url.isEmpty ? 'set REAL_API_URL to run against a local server' : null,
    timeout: const Timeout(Duration(minutes: 3)),
  );

  test(
    'a receipt against an order and an issue from a store, typed offline, each reach the real server once',
    () async {
      final db = AppDatabase.forTesting(NativeDatabase.memory());
      final tokens = InMemoryTokenStore();
      final dio = Dio();
      final api = DioNirmaanApi(readToken: tokens.read, baseUrl: _url, dio: dio);
      final services = AppServices.forTesting(
        db: db,
        api: api,
        tokens: tokens,
        attachments: InMemoryAttachmentStore(),
      );
      addTearDown(services.dispose);

      final signIn = await services.auth.signIn(email: 'engineer@demo.test', password: 'password');
      expect(signIn.firstSync.outcome, SyncOutcome.success);
      final project = (await services.projects.watchProjects().first).first;
      await services.projects.selectProject(project.id);
      await services.syncCenter.syncNow();

      final seen = (await services.material.readOverview(project.id))!;
      final order = seen.ordersDue.first;
      final line = order.due.first;
      final boq = (await services.material.readBoqLines(project.id)).first;
      final stockBefore = seen.stockIn(order.storeId ?? seen.stores.first.id, line.materialId);

      // Network off. A bag is accepted and one turned away, then a bag is issued.
      dio.options.baseUrl = _unreachable;
      final receiptUuid = await services.material.receiveAgainstOrder(
        projectId: project.id,
        order: order,
        date: services.context.today,
        challanNo: 'REAL-1',
        lines: <ReceiptEntry>[
          ReceiptEntry(orderLineId: line.id, materialId: line.materialId, unitId: line.unitId, qty: Qty.parse('1'), rejected: Qty.parse('1')),
        ],
        title: order.number,
      );
      final issueUuid = await services.material.issueFromStore(
        projectId: project.id,
        storeId: order.storeId ?? seen.stores.first.id,
        date: services.context.today,
        issuedTo: 'Real API test',
        lines: <IssueEntry>[IssueEntry(materialId: line.materialId, unitId: line.unitId, qty: Qty.parse('1'), boqItemId: boq.id)],
        title: 'Real API issue',
      );
      await services.syncCenter.syncNow();
      expect((await db.select(db.outbox).get()).every((r) => r.status == OutboxStatus.pending), isTrue);

      // Network back, but the reply to each post is lost once.
      dio.options.baseUrl = _url;
      var dropped = 0;
      dio.interceptors.add(
        InterceptorsWrapper(
          onResponse: (response, handler) {
            final path = response.requestOptions.path;
            if (dropped < 2 && response.requestOptions.method == 'POST' && (path.endsWith('/grns') || path.endsWith('/material-issues'))) {
              dropped++;
              handler.reject(DioException(requestOptions: response.requestOptions, type: DioExceptionType.connectionError));
              return;
            }
            handler.next(response);
          },
        ),
      );
      for (var i = 0; i < 8; i++) {
        await services.syncCenter.syncNow();
        if ((await db.select(db.outbox).get()).every((r) => r.status == OutboxStatus.done)) break;
        await Future<void>.delayed(const Duration(seconds: 2));
      }

      expect(dropped, 2, reason: 'a reply of each must be lost');
      for (final row in await db.select(db.outbox).get()) {
        expect(row.status, OutboxStatus.done, reason: '${row.attentionReason}');
      }

      final receipts = (await api.procurementReceipts(project.id)).where((r) => r['client_uuid'] == receiptUuid).toList();
      expect(receipts, hasLength(1));
      final received = (receipts.single['items'] as List).cast<Map<String, dynamic>>().single;
      expect(received['qty'], '1.000');
      expect(received['rejected_qty'], '1.000');
      final issues = (await api.materialConsumption(project.id)).where((r) => r['client_uuid'] == issueUuid).toList();
      expect(issues, hasLength(1));
      expect(issues.single['issued_to'], 'Real API test');

      // One bag in and one bag out: the stock is where it began, and the order owes one bag less.
      final after = (await services.material.readOverview(project.id))!;
      expect(after.stockIn(order.storeId ?? seen.stores.first.id, line.materialId), stockBefore);
      expect(after.order(order.id)!.line(line.id)!.remaining, line.remaining - Qty.parse('1'));
      expect(after.receipts.where((r) => r.clientUuid == receiptUuid), hasLength(1));
      expect(after.unsent, 0);
    },
    skip: _url.isEmpty ? 'set REAL_API_URL to run against a local server' : null,
    timeout: const Timeout(Duration(minutes: 3)),
  );
}
