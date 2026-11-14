import 'dart:typed_data';

import '../../../core/api/api_failure.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import 'material_loop_models.dart';
import 'material_repository.dart';

export 'material_loop_models.dart';

/// The online half of material: answering a flag, a challan in full, its bill,
/// a short found later, the bills list and wastage against the BOQ norms.
///
/// Nothing here waits in the outbox. A reason is written by someone standing on
/// site, a bill is a photo in their hand, and a short is checked against the
/// challan as it is now, so each is sent at once or not at all. Every write that
/// creates a record carries a `client_uuid` the caller keeps across retries, so a
/// reply lost on the way is answered with the first record, not a second one.
class MaterialLoopRepository {
  MaterialLoopRepository(this._ctx, this._material);

  final DataContext _ctx;
  final MaterialRepository _material;

  String get today => _ctx.today;

  /// After a write, the material page is read again so the flag, the stock and
  /// the short show at once. A failed read leaves the last copy in place.
  Future<void> _refreshOverview(int? projectId) async {
    if (projectId == null) return;
    try {
      await _material.refresh(projectId);
    } on ApiFailure {
      // The write went through; the page catches up on its next read.
    }
  }

  /// Why a flagged gap exists. The server keeps the gap as it stood, so the
  /// flag opens again if it grows.
  Future<void> answerFlag({
    required int projectId,
    required int materialId,
    required String flagType,
    required String reason,
    required String clientUuid,
  }) async {
    await _ctx.api.postMaterialReason(projectId, <String, dynamic>{
      'material_id': materialId,
      'flag_type': flagType,
      'reason': reason,
      'client_uuid': clientUuid,
    });
    await _refreshOverview(projectId);
  }

  Future<ChallanDetail> challan(int challanId) async => ChallanDetail.fromJson(await _ctx.api.deliveryChallan(challanId));

  Future<Uint8List> challanPhoto(int challanId) => _ctx.api.deliveryChallanPhoto(challanId);

  /// The bill file. The server sends it only to `money.view`.
  Future<Uint8List> billFile(int challanId) => _ctx.api.challanBillBytes(challanId);

  /// The supplier's bill. [filePath] is a photo of it; [number], [date] and
  /// [amount] are sent only by someone who may see them, and the server reads
  /// them only from `money.view`.
  Future<ChallanDetail> attachBill({
    required int? projectId,
    required int challanId,
    String? filePath,
    String? number,
    String? date,
    Money? amount,
  }) async {
    final Map<String, dynamic> reply = await _ctx.api.attachChallanBill(challanId, <String, dynamic>{
      'bill_no': ?number,
      'bill_date': ?date,
      'bill_amount': ?amount?.toWire(),
    }, filePath: filePath);
    // The Challans tab marks a bill as pending, so it is read again too.
    await _refreshOverview(projectId);
    return ChallanDetail.fromJson(reply);
  }

  /// Less arrived than the challan said, found after it was taken into stock.
  /// The server takes the shortage out of stock and counts it against the supplier.
  Future<ChallanDetail> reportShort({
    required int? projectId,
    required int challanId,
    required Qty qty,
    required String foundOn,
    String? note,
    required String clientUuid,
  }) async {
    _ctx.assertNotFuture(foundOn, 'found_on');
    final Map<String, dynamic> reply = await _ctx.api.postChallanShort(challanId, <String, dynamic>{
      'short_qty': qty.toWire(),
      'found_on': foundOn,
      'note': ?note,
      'client_uuid': clientUuid,
    });
    await _refreshOverview(projectId);
    final Object? challan = reply['challan'];
    return challan is Map ? ChallanDetail.fromJson(challan.cast<String, dynamic>()) : this.challan(challanId);
  }

  Future<BillsPage> bills(int projectId, {bool attached = false, int page = 1}) async =>
      BillsPage.fromJson(await _ctx.api.challanBills(projectId, attached: attached, page: page));

  Future<WastageReport> wastage(int projectId, {WastageBasis basis = WastageBasis.measured}) async =>
      WastageReport.fromJson(await _ctx.api.materialReconciliation(projectId, basis: basis.wire));
}
