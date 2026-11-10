import 'package:flutter/material.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../data/tender_repository.dart';

/// `yyyy-mm-dd` for a picked day.
String isoDay(DateTime day) {
  String two(int value) => value.toString().padLeft(2, '0');
  return '${day.year}-${two(day.month)}-${two(day.day)}';
}

/// The server's own words for a refusal, or a plain line when there are none.
String failureText(AppLocalizations l, Object error) => switch (error) {
  OfflineFailure() => l.tenderOfflineTitle,
  ValidationFailure(:final errors, :final message) =>
    errors.values.expand((List<String> list) => list).firstOrNull ?? message,
  ServerFailure(:final message) when message.trim().isNotEmpty => message,
  _ => l.tenderSaveFailed,
};

/// A sheet that grows over the keyboard.
class _SheetFrame extends StatelessWidget {
  const _SheetFrame({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.9),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(title, style: N360Type.fit(N360Type.heading, title)),
              const SizedBox(height: N360Space.md),
              ...children,
            ],
          ),
        ),
      ),
    );
  }
}

/// Starts a tender for one BOQ line. Pops true once the server has it.
class NewTenderSheet extends StatefulWidget {
  const NewTenderSheet({
    super.key,
    required this.repository,
    required this.boqItemId,
    required this.suggestedTitle,
    required this.today,
  });

  final TenderRepository repository;
  final int boqItemId;
  final String suggestedTitle;
  final String today;

  @override
  State<NewTenderSheet> createState() => _NewTenderSheetState();
}

class _NewTenderSheetState extends State<NewTenderSheet> {
  late final TextEditingController _title = TextEditingController(
    text: widget.suggestedTitle.length > 160 ? widget.suggestedTitle.substring(0, 160) : widget.suggestedTitle,
  );
  final TextEditingController _scope = TextEditingController();
  String? _due;
  bool _sending = false;
  bool _titleMissing = false;
  String? _problem;

  @override
  void dispose() {
    _title.dispose();
    _scope.dispose();
    super.dispose();
  }

  Future<void> _pickDue() async {
    final DateTime today = DateTime.parse(widget.today);
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: DateTime.tryParse(_due ?? '') ?? today.add(const Duration(days: 7)),
      firstDate: today,
      lastDate: DateTime(today.year + 3),
    );
    if (picked == null || !mounted) return;
    setState(() => _due = isoDay(picked));
  }

  Future<void> _send() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final String title = _title.text.trim();
    if (title.isEmpty) {
      setState(() => _titleMissing = true);
      return;
    }
    setState(() {
      _sending = true;
      _problem = null;
    });
    try {
      await widget.repository.startTender(
        widget.boqItemId,
        title: title,
        scope: _scope.text.trim().isEmpty ? null : _scope.text.trim(),
        dueOn: _due,
      );
      if (!mounted) return;
      N360Haptics.success();
      showPaperToast(context, l.tenderStarted, kind: PaperToastKind.success);
      Navigator.of(context).pop(true);
    } on Object catch (error) {
      if (!mounted) return;
      N360Haptics.warning();
      setState(() {
        _sending = false;
        _problem = failureText(l, error);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? due = _due;
    return _SheetFrame(
      title: l.tenderNewTitle,
      children: <Widget>[
        GloveTextField(
          key: const ValueKey<String>('tender-new-title'),
          label: l.tenderFieldTitle,
          controller: _title,
          maxLines: 2,
          errorText: _titleMissing ? l.tenderTitleRequired : null,
          onChanged: (_) {
            if (_titleMissing) setState(() => _titleMissing = false);
          },
        ),
        const SizedBox(height: N360Space.lg),
        GloveTextField(
          key: const ValueKey<String>('tender-new-scope'),
          label: l.tenderFieldScope,
          helper: l.tenderOptional,
          controller: _scope,
          maxLines: 3,
        ),
        const SizedBox(height: N360Space.lg),
        FieldBlock(
          label: l.tenderFieldDue,
          child: Wrap(
            spacing: N360Space.sm,
            runSpacing: N360Space.sm,
            children: <Widget>[
              IntrinsicWidth(
                child: N360Chip(label: l.tenderNoDate, selected: due == null, onTap: () => setState(() => _due = null)),
              ),
              IntrinsicWidth(
                child: N360Chip(
                  key: const ValueKey<String>('tender-new-due'),
                  label: due == null ? l.tenderPickDate : dayLabel(context, due, widget.today),
                  selected: due != null,
                  onTap: _pickDue,
                ),
              ),
            ],
          ),
        ),
        if (_problem != null) ...<Widget>[
          const SizedBox(height: N360Space.md),
          ErrorSlip(title: _problem!),
        ],
        const SizedBox(height: N360Space.lg),
        GloveButton(
          key: const ValueKey<String>('tender-new-send'),
          label: l.tenderStart,
          icon: Icons.campaign_outlined,
          large: true,
          fullWidth: true,
          loading: _sending,
          onPressed: _sending ? null : _send,
        ),
      ],
    );
  }
}

/// Adds a subcontractor to the company. Pops the new [Subcontractor].
class NewContractorSheet extends StatefulWidget {
  const NewContractorSheet({super.key, required this.repository, this.initialName = ''});

  final TenderRepository repository;
  final String initialName;

  @override
  State<NewContractorSheet> createState() => _NewContractorSheetState();
}

class _NewContractorSheetState extends State<NewContractorSheet> {
  late final TextEditingController _name = TextEditingController(text: widget.initialName);
  final TextEditingController _phone = TextEditingController();
  final TextEditingController _person = TextEditingController();
  bool _sending = false;
  bool _nameMissing = false;
  String? _problem;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _person.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final String name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _nameMissing = true);
      return;
    }
    setState(() {
      _sending = true;
      _problem = null;
    });
    String? optional(TextEditingController controller) => controller.text.trim().isEmpty ? null : controller.text.trim();
    try {
      final Subcontractor added = await widget.repository.addSubcontractor(
        name: name,
        phone: optional(_phone),
        contactPerson: optional(_person),
      );
      if (!mounted) return;
      N360Haptics.success();
      Navigator.of(context).pop(added);
    } on Object catch (error) {
      if (!mounted) return;
      N360Haptics.warning();
      setState(() {
        _sending = false;
        _problem = failureText(l, error);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return _SheetFrame(
      title: l.tenderNewContractorTitle,
      children: <Widget>[
        GloveTextField(
          key: const ValueKey<String>('contractor-new-name'),
          label: l.tenderContractorName,
          controller: _name,
          errorText: _nameMissing ? l.tenderContractorNameRequired : null,
          onChanged: (_) {
            if (_nameMissing) setState(() => _nameMissing = false);
          },
        ),
        const SizedBox(height: N360Space.lg),
        GloveTextField(
          key: const ValueKey<String>('contractor-new-phone'),
          label: l.tenderContractorPhone,
          helper: l.tenderOptional,
          controller: _phone,
          keyboardType: TextInputType.phone,
        ),
        const SizedBox(height: N360Space.lg),
        GloveTextField(label: l.tenderContractorPerson, helper: l.tenderOptional, controller: _person),
        if (_problem != null) ...<Widget>[
          const SizedBox(height: N360Space.md),
          ErrorSlip(title: _problem!),
        ],
        const SizedBox(height: N360Space.lg),
        GloveButton(
          key: const ValueKey<String>('contractor-new-send'),
          label: l.tenderContractorSave,
          icon: Icons.person_add_alt,
          large: true,
          fullWidth: true,
          loading: _sending,
          onPressed: _sending ? null : _send,
        ),
      ],
    );
  }
}
