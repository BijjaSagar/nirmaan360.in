import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../measurement/view/widgets/book_kit.dart';

/// Asks why the document is going back. Returns the remark, or null when the
/// user changes their mind. A remark is required: the sender must know what to fix.
Future<String?> showSendBackSheet(BuildContext context, {required String docType}) {
  return showLedgerSheet<String>(context, isScrollControlled: true, builder: (_) => _SendBackSheet(docType: docType));
}

/// Asks why the document is rejected, the same way. A rejected document is
/// closed for good, and the sheet says so.
Future<String?> showRejectSheet(BuildContext context, {required String docType}) {
  return showLedgerSheet<String>(
    context,
    isScrollControlled: true,
    builder: (_) => _SendBackSheet(docType: docType, reject: true),
  );
}

class _SendBackSheet extends StatefulWidget {
  const _SendBackSheet({required this.docType, this.reject = false});

  final String docType;
  final bool reject;

  @override
  State<_SendBackSheet> createState() => _SendBackSheetState();
}

class _SendBackSheetState extends State<_SendBackSheet> {
  final TextEditingController _remark = TextEditingController();
  String? _chosen;

  static const int _limit = 500;

  @override
  void initState() {
    super.initState();
    _remark.addListener(_changed);
  }

  void _changed() {
    if (_chosen != null && _remark.text != _chosen) _chosen = null;
    setState(() {});
  }

  @override
  void dispose() {
    _remark.dispose();
    super.dispose();
  }

  List<String> _reasons(AppLocalizations l) => switch (widget.docType) {
    'contractor' when widget.reject => <String>[
      l.approvalsReasonDuplicate,
      l.approvalsReasonWorkNotDone,
      l.approvalsReasonPaymentAhead,
    ],
    'contractor' => <String>[
      l.approvalsReasonWorkNotDone,
      l.approvalsReasonPaymentAhead,
      l.approvalsReasonBillMissing,
      l.approvalsReasonAmounts,
    ],
    'vendor_bill' when widget.reject => <String>[l.approvalsReasonDuplicate, l.approvalsReasonVbNotReceived],
    'vendor_bill' => <String>[l.approvalsReasonAmounts, l.approvalsReasonVbTax, l.approvalsReasonBillMissing],
    'indent' when widget.reject => <String>[l.approvalsReasonIndentNotNeeded, l.approvalsReasonIndentStock],
    'indent' => <String>[l.approvalsReasonQuantityHigh, l.approvalsReasonIndentMaterial, l.approvalsReasonIndentStock],
    'rfi' when widget.reject => <String>[l.approvalsReasonRfiAnswered],
    'rfi' => <String>[l.approvalsReasonRfiUnclear, l.approvalsReasonRfiDrawing],
    'measurement' => <String>[
      l.approvalsReasonDimensions,
      l.approvalsReasonWrongItem,
      l.approvalsReasonQuantityHigh,
      l.approvalsReasonLocation,
    ],
    'dpr' => <String>[l.approvalsReasonManpower, l.approvalsReasonWorkDetail, l.approvalsReasonPhotos],
    'ra_bill' => <String>[l.approvalsReasonAmounts, l.approvalsReasonPeriod, l.approvalsReasonDeductions],
    'bill_note' => <String>[l.approvalsReasonAmounts, l.approvalsReasonNote],
    _ => <String>[l.approvalsReasonDimensions, l.approvalsReasonAmounts],
  };

  void _choose(String reason) {
    _chosen = reason;
    _remark.value = TextEditingValue(
      text: reason,
      selection: TextSelection.collapsed(offset: reason.length),
    );
  }

  void _send() {
    final String remark = _remark.text.trim();
    if (remark.isNotEmpty) Navigator.of(context).pop(remark);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MediaQueryData media = MediaQuery.of(context);
    final List<String> reasons = _reasons(l);
    final bool ready = _remark.text.trim().isNotEmpty;

    return Padding(
      padding: EdgeInsets.only(bottom: media.viewInsets.bottom),
      child: SingleChildScrollView(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(widget.reject ? l.approvalsRejectTitle : l.approvalsSendBackTitle, style: N360Type.title),
            const SizedBox(height: N360Space.xs),
            Text(widget.reject ? l.approvalsRejectHelp : l.approvalsSendBackHelp, style: N360Type.body),
            const SizedBox(height: N360Space.md),
            for (final String reason in reasons) ...<Widget>[
              ChoiceTile(centered: false, label: reason, selected: _chosen == reason, onTap: () => _choose(reason)),
              const SizedBox(height: N360Space.sm),
            ],
            const SizedBox(height: N360Space.xs),
            GloveTextField(
              label: l.approvalsRemarkLabel,
              hint: widget.reject ? l.approvalsRejectHint : l.approvalsRemarkHint,
              controller: _remark,
              minLines: 2,
              maxLines: 3,
              inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(_limit)],
            ),
            const SizedBox(height: N360Space.md),
            GloveButton(
              key: ValueKey<String>(widget.reject ? 'approval-reject-confirm' : 'approval-send-back-confirm'),
              label: widget.reject ? l.approvalsReject : l.approvalsSendBack,
              icon: widget.reject ? Icons.block : Icons.reply,
              kind: widget.reject ? GloveButtonKind.danger : GloveButtonKind.primary,
              large: true,
              fullWidth: true,
              onPressed: ready ? _send : null,
            ),
          ],
        ),
      ),
    );
  }
}
