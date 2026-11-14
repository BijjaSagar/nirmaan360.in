import 'package:flutter/material.dart';

import '../../../../app/widgets/press_row.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/project_detail.dart';

/// One phase as it is being typed: a name, and the amount as keypad text.
class PhaseDraft {
  PhaseDraft({String name = '', this.amount = ''}) : name = TextEditingController(text: name);

  /// Keeps a row's own field when an earlier row is removed.
  final Key key = UniqueKey();
  final TextEditingController name;
  String amount;
  bool nameMissing = false;
  bool amountMissing = false;

  Money get value => Money.tryParse(amount) ?? Money.zero;

  bool get isBlank => name.text.trim().isEmpty && Money.tryParse(amount) == null;
}

/// What the person settled on: one figure, or the value split into phases.
class ValuePlan {
  const ValuePlan({this.single, this.phases = const <ValuePhase>[]});

  /// The one figure, when the value is not split. Null for none.
  final Money? single;

  /// The phases, in order. Empty when the value is one figure.
  final List<ValuePhase> phases;
}

/// The contract value on the project form: one figure, or with "Add phase"
/// a list of phases whose shares of the total are worked out as they are typed.
class ValuePlanController extends ChangeNotifier {
  bool _split = false;
  String _single = '';
  final List<PhaseDraft> _rows = <PhaseDraft>[];

  /// True once the value is entered phase by phase.
  bool get split => _split;

  /// The one figure as keypad text, while not split.
  String get single => _single;

  List<PhaseDraft> get rows => List<PhaseDraft>.unmodifiable(_rows);

  List<Money> get amounts => <Money>[for (final PhaseDraft row in _rows) row.value];

  Money get total => amounts.fold(Money.zero, (sum, amount) => sum + amount);

  /// Each phase's share of the total, as "20.00". Null while the total is nothing.
  List<String?> get shares => phaseShares(amounts);

  /// Fills the editor from the server: phases when there are any, else the one figure.
  void load({Money? value, List<ValuePhase> phases = const <ValuePhase>[]}) {
    _clearRows();
    _split = phases.isNotEmpty;
    _single = phases.isEmpty && value != null && !value.isZero ? value.toWire() : '';
    for (final ValuePhase phase in phases) {
      _rows.add(PhaseDraft(name: phase.name, amount: phase.amount.toWire()));
    }
    notifyListeners();
  }

  void setSingle(String text) {
    _single = text;
    notifyListeners();
  }

  /// The first "Add phase" turns the one figure into the first phase and adds
  /// a second; later ones add a phase at the end.
  void addPhase() {
    if (!_split) {
      _split = true;
      _rows.add(PhaseDraft(amount: _single));
      _single = '';
    }
    _rows.add(PhaseDraft());
    notifyListeners();
  }

  /// Taking the last phase away leaves the value empty and not split.
  void removePhase(PhaseDraft row) {
    _rows.remove(row);
    row.name.dispose();
    if (_rows.isEmpty) _split = false;
    notifyListeners();
  }

  void setAmount(PhaseDraft row, String text) {
    row
      ..amount = text
      ..amountMissing = false;
    notifyListeners();
  }

  void nameChanged(PhaseDraft row) {
    if (!row.nameMissing) return;
    row.nameMissing = false;
    notifyListeners();
  }

  /// The plan to send, or null after marking each phase that still needs a
  /// name or an amount. A phase left wholly blank is dropped.
  ValuePlan? read() {
    if (!_split) {
      final Money? value = Money.tryParse(_single);
      return ValuePlan(single: value == null || value.isZero ? null : value);
    }
    final List<PhaseDraft> filled = <PhaseDraft>[for (final PhaseDraft row in _rows) if (!row.isBlank) row];
    bool ok = true;
    for (final PhaseDraft row in filled) {
      row
        ..nameMissing = row.name.text.trim().isEmpty
        ..amountMissing = Money.tryParse(row.amount) == null;
      if (row.nameMissing || row.amountMissing) ok = false;
    }
    if (!ok) {
      notifyListeners();
      return null;
    }
    return ValuePlan(
      phases: <ValuePhase>[for (final PhaseDraft row in filled) ValuePhase(name: row.name.text.trim(), amount: row.value)],
    );
  }

  void _clearRows() {
    for (final PhaseDraft row in _rows) {
      row.name.dispose();
    }
    _rows.clear();
  }

  @override
  void dispose() {
    _clearRows();
    super.dispose();
  }
}

/// The contract value section of the project form.
class ValuePlanEditor extends StatelessWidget {
  const ValuePlanEditor({
    super.key,
    required this.controller,
    required this.enabled,
    this.errors = const <String, String>{},
  });

  final ValuePlanController controller;
  final bool enabled;

  /// The server's first message per field: `contract_value`, `phases` and `phases.N.name`.
  final Map<String, String> errors;

  Future<void> _enter(BuildContext context, {required String title, required String initial, required ValueChanged<String> onDone}) async {
    FocusScope.of(context).unfocus();
    final String? result = await showLedgerSheet<String>(
      context,
      isScrollControlled: true,
      builder: (context) => ValueKeypadSheet(title: title, initial: initial),
    );
    if (result != null) onDone(result);
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final Money? single = Money.tryParse(controller.single);
        final String? general = errors['contract_value'] ?? errors['phases'];
        final Widget add = GloveButton(
          key: const ValueKey<String>('project-add-phase'),
          label: t.projectPhaseAdd,
          icon: Icons.add,
          kind: GloveButtonKind.secondary,
          fullWidth: true,
          onPressed: enabled ? controller.addPhase : null,
        );

        if (!controller.split) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              ProjectTapField(
                label: t.shellProjectValue,
                value: single == null || single.isZero ? null : single.inr(),
                placeholder: t.shellProjectValueEmpty,
                icon: Icons.currency_rupee,
                errorText: general,
                onTap: enabled
                    ? () => _enter(context, title: t.shellProjectValue, initial: controller.single, onDone: controller.setSingle)
                    : null,
              ),
              const SizedBox(height: N360Space.sm),
              add,
            ],
          );
        }

        final List<PhaseDraft> rows = controller.rows;
        final List<String?> shares = controller.shares;
        final String total = controller.total.inr();
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(t.shellProjectValue, maxLines: 2, style: N360Type.fit(N360Type.label, t.shellProjectValue)),
            const SizedBox(height: N360Space.xxs),
            Text(t.projectPhasesHint, style: N360Type.fit(N360Type.caption, t.projectPhasesHint).copyWith(color: N360Colors.ink2)),
            const SizedBox(height: N360Space.sm),
            for (final (int i, PhaseDraft row) in rows.indexed) ...<Widget>[
              _PhaseCard(
                key: row.key,
                number: i + 1,
                row: row,
                share: shares[i],
                enabled: enabled,
                nameError: row.nameMissing ? t.projectPhaseNameRequired : errors['phases.$i.name'],
                amountError: row.amountMissing ? t.projectPhaseAmountRequired : errors['phases.$i.amount'],
                onNameChanged: () => controller.nameChanged(row),
                onAmount: () => _enter(
                  context,
                  title: row.name.text.trim().isEmpty ? t.projectPhaseNumbered(i + 1) : row.name.text.trim(),
                  initial: row.amount,
                  onDone: (text) => controller.setAmount(row, text),
                ),
                onRemove: () => controller.removePhase(row),
              ),
              const SizedBox(height: N360Space.sm),
            ],
            add,
            const SizedBox(height: N360Space.md),
            Semantics(
              container: true,
              label: '${t.projectPhaseTotal}, $total',
              child: ExcludeSemantics(
                child: Row(
                  children: <Widget>[
                    Expanded(child: Text(t.projectPhaseTotal, style: N360Type.fit(N360Type.bodyStrong, t.projectPhaseTotal))),
                    Text(total, key: const ValueKey<String>('project-phase-total'), style: N360Type.figure),
                  ],
                ),
              ),
            ),
            if (general != null) ...<Widget>[
              const SizedBox(height: N360Space.xs),
              Text(general, style: N360Type.fit(N360Type.caption, general).copyWith(color: N360Colors.stampRed, fontWeight: FontWeight.w600)),
            ],
          ],
        );
      },
    );
  }
}

/// One phase: its number and share, its name, its amount and a way to take it out.
class _PhaseCard extends StatelessWidget {
  const _PhaseCard({
    super.key,
    required this.number,
    required this.row,
    required this.share,
    required this.enabled,
    required this.nameError,
    required this.amountError,
    required this.onNameChanged,
    required this.onAmount,
    required this.onRemove,
  });

  final int number;
  final PhaseDraft row;
  final String? share;
  final bool enabled;
  final String? nameError;
  final String? amountError;
  final VoidCallback onNameChanged;
  final VoidCallback onAmount;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String heading = t.projectPhaseNumbered(number);
    final String shareText = share == null ? '—' : '$share%';
    final Money? amount = Money.tryParse(row.amount);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.md,
        border: Border.all(color: N360Colors.rule),
      ),
      child: Padding(
        padding: const EdgeInsets.all(N360Space.tile),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(child: Text(heading, style: N360Type.fit(N360Type.bodyStrong, heading))),
                Semantics(
                  label: t.projectPhaseShare(shareText),
                  child: ExcludeSemantics(
                    child: Text(
                      shareText,
                      key: ValueKey<String>('project-phase-share-$number'),
                      style: N360Type.figure.copyWith(color: N360Colors.laterite),
                    ),
                  ),
                ),
                const SizedBox(width: N360Space.xs),
                PressTarget(
                  onTap: enabled ? onRemove : null,
                  semanticsLabel: t.projectPhaseRemove(heading),
                  builder: (context, depth, child) => Transform.scale(scale: pressedScale(depth), child: child),
                  child: const Icon(Icons.close, size: N360IconSize.lg, color: N360Colors.stampRed),
                ),
              ],
            ),
            const SizedBox(height: N360Space.xs),
            GloveTextField(
              label: t.projectPhaseName,
              hint: t.projectPhaseNameHint,
              controller: row.name,
              enabled: enabled,
              textCapitalization: TextCapitalization.words,
              textInputAction: TextInputAction.done,
              errorText: nameError,
              onChanged: (_) => onNameChanged(),
            ),
            const SizedBox(height: N360Space.sm),
            ProjectTapField(
              label: t.projectPhaseAmount,
              value: amount?.inr(),
              placeholder: t.shellProjectValueEmpty,
              icon: Icons.currency_rupee,
              errorText: amountError,
              onTap: enabled ? onAmount : null,
            ),
          ],
        ),
      ),
    );
  }
}

/// A field that opens a picker: label above, value in a box the same size as
/// a text field.
class ProjectTapField extends StatelessWidget {
  const ProjectTapField({
    super.key,
    required this.label,
    required this.value,
    required this.placeholder,
    required this.icon,
    required this.onTap,
    this.errorText,
  });

  final String label;
  final String? value;
  final String placeholder;
  final IconData icon;
  final VoidCallback? onTap;
  final String? errorText;

  @override
  Widget build(BuildContext context) {
    final String? shown = value;
    final String? error = errorText;
    final String text = shown ?? placeholder;
    final Widget box = DecoratedBox(
      decoration: BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.md,
        border: Border.all(color: error == null ? N360Colors.ruleStrong : N360Colors.stampRed, width: error == null ? 1 : 2),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md),
          child: Row(
            children: <Widget>[
              Icon(icon, size: N360IconSize.lg, color: N360Colors.ink2),
              const SizedBox(width: N360Space.sm),
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    text,
                    maxLines: 1,
                    style: N360Type.fit(N360Type.bodyLarge, text)
                        .copyWith(color: shown == null ? N360Colors.inkLabel : N360Colors.ink),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );

    final Widget field = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, maxLines: 2, style: N360Type.fit(N360Type.label, label)),
        const SizedBox(height: N360Space.xs),
        box,
        if (error != null) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          Text(
            error,
            style: N360Type.fit(N360Type.caption, error).copyWith(color: N360Colors.stampRed, fontWeight: FontWeight.w600),
          ),
        ],
      ],
    );

    final tap = onTap;
    return Semantics(
      container: true,
      button: true,
      enabled: tap != null,
      label: '$label, $text${error == null ? '' : '. $error'}',
      onTap: tap,
      child: ExcludeSemantics(
        child: tap == null ? field : PressRow(onTap: tap, child: field),
      ),
    );
  }
}

/// An amount, entered on the glove keypad. Pops the keypad text.
class ValueKeypadSheet extends StatefulWidget {
  const ValueKeypadSheet({super.key, required this.title, required this.initial});

  final String title;
  final String initial;

  @override
  State<ValueKeypadSheet> createState() => _ValueKeypadSheetState();
}

class _ValueKeypadSheetState extends State<ValueKeypadSheet> {
  late String _text = widget.initial;

  String get _clean => _text.endsWith('.') ? _text.substring(0, _text.length - 1) : _text;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    // A trailing point is unfinished typing, not part of the figure.
    final Money amount = Money.tryParse(_clean) ?? Money.zero;
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(widget.title, style: N360Type.fit(N360Type.heading, widget.title)),
            Align(
              alignment: Alignment.centerRight,
              child: FittedBox(
                fit: BoxFit.scaleDown,
                child: RollingMoney(value: amount.inr(), style: N360Type.figureHero),
              ),
            ),
            const SizedBox(height: N360Space.sm),
            GloveKeypad(
              value: _text,
              decimalPlaces: 2,
              maxIntegerDigits: 11,
              onChanged: (next) => setState(() => _text = next),
              onNext: () => Navigator.of(context).pop(_clean),
              nextLabel: t.commonDone,
              nextIcon: Icons.check,
              backspaceLabel: t.designKeypadDelete,
            ),
          ],
        ),
      ),
    );
  }
}
