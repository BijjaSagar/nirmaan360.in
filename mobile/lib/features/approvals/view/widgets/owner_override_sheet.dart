import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/approval_repository.dart';

/// What came of sending the owner's reason.
sealed class OverrideOutcome {
  const OverrideOutcome();
}

/// The server took it: the payment is approved.
class OverrideAccepted extends OverrideOutcome {
  const OverrideAccepted();
}

/// Not taken. [message] is shown beside the reason, which stays as typed: the
/// server's own words for a refused reason, or why the decision was not sent.
class OverrideRefused extends OverrideOutcome {
  const OverrideRefused(this.message);

  final String message;
}

/// Nothing more to do here, as when someone else has decided the payment. The
/// sheet closes and the page behind says what happened.
class OverrideAbandoned extends OverrideOutcome {
  const OverrideAbandoned();
}

/// Asks the owner why a contractor payment may go ahead of the work done, and
/// sends it. The server wants at least a sentence (20 characters and 4 words)
/// and keeps it with the payment and in the audit log, so the sheet says the
/// rule before anything is sent. It stays open until the server has answered:
/// a refusal shows beside the reason and nothing typed is lost.
///
/// Returns true once the payment is approved.
Future<bool?> showOwnerOverrideSheet(
  BuildContext context, {
  required Future<OverrideOutcome> Function(String reason) submit,
  String? message,
}) {
  return showLedgerSheet<bool>(
    context,
    isScrollControlled: true,
    builder: (_) => _OwnerOverrideSheet(message: message, submit: submit),
  );
}

class _OwnerOverrideSheet extends StatefulWidget {
  const _OwnerOverrideSheet({required this.submit, this.message});

  final String? message;
  final Future<OverrideOutcome> Function(String reason) submit;

  @override
  State<_OwnerOverrideSheet> createState() => _OwnerOverrideSheetState();
}

class _OwnerOverrideSheetState extends State<_OwnerOverrideSheet> {
  final TextEditingController _reason = TextEditingController();
  bool _sending = false;

  /// Why the last try was not taken, and the text it was about.
  String? _refusal;
  String? _refusedText;

  /// The server takes up to 1,000 characters and counts every vowel sign and
  /// joiner of Devanagari as one, so the field stops well short of that.
  static const int _limit = 500;

  @override
  void initState() {
    super.initState();
    _reason.addListener(_changed);
  }

  /// A refusal is about the words it was given. Once they change, it goes.
  void _changed() {
    if (_refusal != null && _reason.text.trim() != _refusedText) {
      _refusal = null;
      _refusedText = null;
    }
    setState(() {});
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final String text = _reason.text.trim();
    if (_sending || !OwnerOverride.isSentence(text)) return;
    setState(() {
      _sending = true;
      _refusal = null;
      _refusedText = null;
    });
    final OverrideOutcome outcome = await widget.submit(text);
    if (!mounted) return;
    switch (outcome) {
      case OverrideAccepted():
        Navigator.of(context).pop(true);
      case OverrideAbandoned():
        Navigator.of(context).pop(false);
      case OverrideRefused(:final String message):
        setState(() {
          _sending = false;
          _refusal = message;
          _refusedText = text;
        });
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MediaQueryData media = MediaQuery.of(context);
    final String text = _reason.text.trim();
    final bool ready = OwnerOverride.isSentence(text);
    final bool tooShort = text.isNotEmpty && !ready;
    final String? message = widget.message;

    return PopScope(
      // The answer is on its way: leaving now would lose sight of it.
      canPop: !_sending,
      child: Padding(
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
                controller: _reason,
                minLines: 2,
                maxLines: 4,
                enabled: !_sending,
                errorText: _refusal,
                inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(_limit)],
              ),
              const SizedBox(height: N360Space.xxs),
              // The rule, on the page from the start, and in the warning colour while the words fall short of it.
              Text(
                l.approvalsPayOverrideShort,
                key: const ValueKey<String>('approval-override-rule'),
                style: N360Type.caption.copyWith(
                  color: tooShort ? N360Colors.laterite : N360Colors.ink2,
                  fontWeight: tooShort ? FontWeight.w600 : null,
                ),
              ),
              const SizedBox(height: N360Space.md),
              GloveButton(
                key: const ValueKey<String>('approval-override-confirm'),
                label: l.approvalsPayOverrideApprove,
                icon: Icons.task_alt,
                large: true,
                fullWidth: true,
                loading: _sending,
                onPressed: ready && !_sending ? _send : null,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
