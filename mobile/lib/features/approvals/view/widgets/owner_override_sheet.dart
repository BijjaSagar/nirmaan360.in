import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/approval_repository.dart';

/// Asks the owner why a contractor payment may go ahead of the work done.
/// Returns the reason, or null when the owner changes their mind. The server
/// wants at least a sentence, and keeps it with the payment and in the audit log.
Future<String?> showOwnerOverrideSheet(BuildContext context, {String? message}) {
  return showLedgerSheet<String>(
    context,
    isScrollControlled: true,
    builder: (_) => _OwnerOverrideSheet(message: message),
  );
}

class _OwnerOverrideSheet extends StatefulWidget {
  const _OwnerOverrideSheet({this.message});

  final String? message;

  @override
  State<_OwnerOverrideSheet> createState() => _OwnerOverrideSheetState();
}

class _OwnerOverrideSheetState extends State<_OwnerOverrideSheet> {
  final TextEditingController _reason = TextEditingController();

  /// The server takes up to 1,000 characters and counts every vowel sign and
  /// joiner of Devanagari as one, so the field stops well short of that.
  static const int _limit = 500;

  @override
  void initState() {
    super.initState();
    _reason.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MediaQueryData media = MediaQuery.of(context);
    final String text = _reason.text.trim();
    final bool ready = OwnerOverride.isSentence(text);
    final String? message = widget.message;

    return Padding(
      padding: EdgeInsets.only(bottom: media.viewInsets.bottom),
      child: SingleChildScrollView(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(l.approvalsPayOverrideReasonTitle, style: N360Type.title),
            const SizedBox(height: N360Space.xs),
            if (message != null) ...<Widget>[
              Text(message, style: N360Type.body.copyWith(color: N360Colors.stampRed)),
              const SizedBox(height: N360Space.xs),
            ],
            Text(l.approvalsPayOverrideReasonHelp, style: N360Type.body),
            const SizedBox(height: N360Space.md),
            GloveTextField(
              key: const ValueKey<String>('approval-override-reason'),
              label: l.approvalsPayOverrideReasonLabel,
              hint: l.approvalsPayOverrideShort,
              controller: _reason,
              minLines: 2,
              maxLines: 4,
              inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(_limit)],
            ),
            if (text.isNotEmpty && !ready) ...<Widget>[
              const SizedBox(height: N360Space.xxs),
              Text(l.approvalsPayOverrideShort, style: N360Type.caption.copyWith(color: N360Colors.laterite)),
            ],
            const SizedBox(height: N360Space.md),
            GloveButton(
              key: const ValueKey<String>('approval-override-confirm'),
              label: l.approvalsPayOverrideApprove,
              icon: Icons.task_alt,
              large: true,
              fullWidth: true,
              onPressed: ready ? () => Navigator.of(context).pop(text) : null,
            ),
          ],
        ),
      ),
    );
  }
}
