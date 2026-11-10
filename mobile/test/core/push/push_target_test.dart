import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/push/push_message.dart';
import 'package:nirmaan360/core/push/push_target.dart';

PushRouter _router({
  bool Function(Uri)? allows,
  Map<(PushKind, int), String> known = const <(PushKind, int), String>{},
  bool lookupThrows = false,
}) {
  return PushRouter(
    allows: allows ?? (_) => true,
    lookup: (kind, id) async {
      if (lookupThrows) throw StateError('database closed');
      return known[(kind, id)];
    },
  );
}

void main() {
  group('reading a payload', () {
    test('type and id name the thing', () {
      expect(PushTarget.parse(<String, String>{'type': 'approval', 'id': '7'}), const PushTarget(PushKind.approval, id: 7));
      expect(PushTarget.parse(<String, String>{'type': 'dpr', 'id': '12'}), const PushTarget(PushKind.report, id: 12));
      expect(PushTarget.parse(<String, String>{'type': 'report', 'id': '12'}), const PushTarget(PushKind.report, id: 12));
      expect(PushTarget.parse(<String, String>{'type': 'measurement', 'id': '3'}), const PushTarget(PushKind.measurement, id: 3));
      expect(PushTarget.parse(<String, String>{'type': 'issue', 'id': '9', 'project_id': '2'}), const PushTarget(PushKind.issue, id: 9, projectId: 2));
      expect(PushTarget.parse(<String, String>{'type': 'alert'}), const PushTarget(PushKind.alert));
    });

    test('the server\'s own event names point at the right kind, without an id', () {
      expect(PushTarget.parse(<String, String>{'event': 'approval.waiting', 'url': 'https://example.test/approvals'}), const PushTarget(PushKind.approval));
      expect(PushTarget.parse(<String, String>{'event': 'issue.assigned'}), const PushTarget(PushKind.issue));
      expect(PushTarget.parse(<String, String>{'event': 'alert.stock_low'}), const PushTarget(PushKind.alert));
    });

    test('type wins over event, and case and spaces do not matter', () {
      expect(PushTarget.parse(<String, String>{'type': ' Issue ', 'event': 'approval.waiting', 'id': '4'}), const PushTarget(PushKind.issue, id: 4));
    });

    test('anything unknown is no target', () {
      expect(PushTarget.parse(const <String, String>{}), isNull);
      expect(PushTarget.parse(<String, String>{'type': 'rocket', 'id': '1'}), isNull);
      expect(PushTarget.parse(<String, String>{'event': 'company.export_ready'}), isNull);
      expect(PushTarget.parse(<String, String>{'type': ''}), isNull);
    });

    test('a bad id is dropped, the kind stays', () {
      for (final String bad in <String>['', 'abc', '-3', '0', '1.5', '99999999999999999999', '2147483648', ' ', 'NaN']) {
        expect(PushTarget.parse(<String, String>{'type': 'approval', 'id': bad}), const PushTarget(PushKind.approval), reason: 'id "$bad"');
      }
      expect(PushTarget.parse(<String, String>{'type': 'approval', 'id': ' 8 '}), const PushTarget(PushKind.approval, id: 8));
    });

    test('a message of any shape reads without throwing', () {
      final PushMessage odd = PushMessage.fromParts(title: 42, body: <String>['x'], data: <Object?, Object?>{'type': 'issue', 'id': 5, 7: 'x', 'none': null});
      expect(odd.title, '42');
      expect(odd.data, <String, String>{'type': 'issue', 'id': '5'});
      expect(PushTarget.fromMessage(odd), const PushTarget(PushKind.issue, id: 5));
      expect(PushMessage.fromParts(data: 'not a map').data, isEmpty);
      expect(PushMessage.fromParts().hasText, isFalse);
      expect(PushMessage.fromParts(title: '  ').hasText, isFalse);
    });

    test('a message never prints its text', () {
      expect(const PushMessage(title: 'Approval needed: Slab 4', body: 'Sent by Sachin').toString(), isNot(contains('Slab')));
    });
  });

  group('where a target leads', () {
    test('an approval opens its page, or the inbox with no id', () async {
      expect(await _router().pathFor(const PushTarget(PushKind.approval, id: 7)), Routes.approvalDetail(7));
      expect(await _router().pathFor(const PushTarget(PushKind.approval)), Routes.approvals);
    });

    test('a returned report, measurement or issue opens the record this phone holds', () async {
      final PushRouter router = _router(
        known: <(PushKind, int), String>{
          (PushKind.report, 12): 'dpr-uuid',
          (PushKind.measurement, 3): 'm-uuid',
          (PushKind.issue, 9): 'i-uuid',
        },
      );
      expect(await router.pathFor(const PushTarget(PushKind.report, id: 12)), Routes.dprDetail('dpr-uuid'));
      expect(await router.pathFor(const PushTarget(PushKind.measurement, id: 3)), Routes.measurementDetail('m-uuid'));
      expect(await router.pathFor(const PushTarget(PushKind.issue, id: 9)), Routes.issueDetail('i-uuid'));
    });

    test('a record not pulled yet, or no id, opens its list', () async {
      expect(await _router().pathFor(const PushTarget(PushKind.report, id: 12)), Routes.dprList);
      expect(await _router().pathFor(const PushTarget(PushKind.report)), Routes.dprList);
      expect(await _router().pathFor(const PushTarget(PushKind.measurement, id: 3)), Routes.measurements);
      expect(await _router().pathFor(const PushTarget(PushKind.issue, id: 9)), Routes.issues);
    });

    test('an alert opens the alerts tab of the site page', () async {
      expect(await _router().pathFor(const PushTarget(PushKind.alert)), '${Routes.siteDashboard}?tab=alerts');
    });

    test('no target, and a lookup that fails, fall back to Home', () async {
      expect(await _router().pathFor(null), Routes.home);
      expect(await _router(lookupThrows: true).pathFor(const PushTarget(PushKind.issue, id: 9)), Routes.home);
    });

    test('a person who cannot open the screen lands on Home', () async {
      final PushRouter router = _router(allows: (uri) => !uri.path.startsWith(Routes.approvals));
      expect(await router.pathFor(const PushTarget(PushKind.approval, id: 7)), Routes.home);
      expect(await router.pathFor(const PushTarget(PushKind.approval)), Routes.home);
      expect(await router.pathFor(const PushTarget(PushKind.issue)), Routes.issues);
    });

    test('the gate is asked about the whole location, query included', () async {
      final List<Uri> asked = <Uri>[];
      await _router(allows: (uri) {
        asked.add(uri);
        return true;
      }).pathFor(const PushTarget(PushKind.alert));
      expect(asked.single.path, Routes.siteDashboard);
      expect(asked.single.queryParameters['tab'], 'alerts');
    });
  });
}
