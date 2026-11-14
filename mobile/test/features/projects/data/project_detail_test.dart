import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/projects/data/project_detail.dart';

List<Money> money(List<String> amounts) => amounts.map(Money.parse).toList();

String sum(List<String?> shares) =>
    shares.fold(Money.zero, (Money total, share) => total + Money.parse(share!)).toWire();

void main() {
  group('phaseShares, as the server works them out', () {
    test('exact shares are given as they are', () {
      expect(phaseShares(money(['4000000', '6000000', '10000000'])), ['20.00', '30.00', '50.00']);
      expect(phaseShares(money(['1250000.50'])), ['100.00']);
    });

    test('thirds add up to a hundred, the first taking the spare hundredth', () {
      final shares = phaseShares(money(['100', '100', '100']));
      expect(shares, ['33.34', '33.33', '33.33']);
      expect(sum(shares), '100.00');
    });

    test('the spare hundredths go to the largest remainders, the earlier first on a tie', () {
      expect(phaseShares(money(['1', '2', '4'])), ['14.29', '28.57', '57.14']);
      expect(phaseShares(money(['3', '2', '2'])), ['42.86', '28.57', '28.57']);
      expect(phaseShares(money(['1', '1', '1', '1', '1', '1'])), ['16.67', '16.67', '16.67', '16.67', '16.66', '16.66']);
    });

    test('many uneven phases always add up to exactly a hundred', () {
      final shares = phaseShares(money(['1234567.89', '0.01', '99.99', '7777777.77', '3', '0', '42424242.42', '15.5']));
      expect(sum(shares), '100.00');
      expect(shares[5], '0.00');
      for (final share in shares) {
        expect(share, matches(RegExp(r'^\d{1,3}\.\d{2}$')));
      }
    });

    test('a zero total has no shares', () {
      expect(phaseShares(money(['0', '0.00'])), [null, null]);
      expect(phaseShares(const <Money>[]), isEmpty);
    });

    test('the largest value a project holds is still exact', () {
      expect(phaseShares(money(['9999999999998.99', '1'])), ['100.00', '0.00']);
      expect(phaseShares(money(['4999999999999.99', '5000000000000.00'])), ['50.00', '50.00']);
    });
  });

  group('ProjectDetail', () {
    test('reads the phases and the value', () {
      final detail = ProjectDetail.fromJson(<String, dynamic>{
        'id': 7,
        'code': 'KT-B',
        'name': 'Kalyani Tower B',
        'location': 'Baner',
        'start_date': '2026-11-01',
        'end_date': null,
        'contract_value': '20000000.50',
        'phases': [
          {'id': 1, 'name': 'Foundation', 'amount': '4000000.00', 'share_pct': '20.00'},
          {'id': 2, 'name': 'Slab', 'amount': '16000000.50', 'share_pct': '80.00'},
        ],
      });

      expect(detail.seesMoney, isTrue);
      expect(detail.contractValue, Money.parse('20000000.50'));
      expect(detail.phases, [
        ValuePhase(name: 'Foundation', amount: Money.parse('4000000')),
        ValuePhase(name: 'Slab', amount: Money.parse('16000000.50')),
      ]);
      expect(detail.phases!.first.toWire(), {'name': 'Foundation', 'amount': '4000000.00'});
    });

    test('says when the server shows no money', () {
      final detail = ProjectDetail.fromJson(<String, dynamic>{'id': 7, 'code': 'KT-B', 'name': 'Kalyani Tower B', 'contract_value': null, 'phases': null});

      expect(detail.seesMoney, isFalse);
      expect(detail.contractValue, isNull);
    });
  });
}
