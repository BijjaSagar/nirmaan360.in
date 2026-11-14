import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/floor_setup_cubit.dart';
import '../data/floor_setup_repository.dart';

/// The repository the set-up screens share: straight to the server, nothing kept.
FloorSetupRepository floorSetupRepository(BuildContext context) {
  final AppServices services = context.read<AppServices>();
  return FloorSetupRepository(services.api, services.progress);
}

/// What a refused or failed write says on the screen.
String setupProblemText(AppLocalizations l, SetupProblem problem) => switch (problem.kind) {
  SetupProblemKind.offline => l.floorSetupOffline,
  SetupProblemKind.refused => problem.message ?? l.floorSetupFailed,
  SetupProblemKind.forbidden => l.floorSetupForbidden,
  SetupProblemKind.failed => l.floorSetupFailed,
};

/// A screen that could not read from the server: why, and a way to try again.
class SetupLoadProblem extends StatelessWidget {
  const SetupLoadProblem({super.key, required this.title, required this.onRetry});

  final String title;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return SingleChildScrollView(
      child: EmptyLedger(
        title: title,
        animated: false,
        action: GloveButton(
          key: const ValueKey<String>('setup-retry'),
          label: l.floorSetupRetry,
          icon: Icons.refresh,
          kind: GloveButtonKind.secondary,
          onPressed: onRetry,
        ),
      ),
    );
  }
}

/// Floors and segments of the project: add, rename, split a floor into
/// segments, plan quantities, the time frame, and for the company owner,
/// remove. Online only. Needs `progress.manage`.
class FloorSetupScreen extends StatelessWidget {
  const FloorSetupScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final int? projectId = context.select((AppGate gate) => gate.state.currentProject?.id);
    if (projectId == null) return const Scaffold(body: LedgerPaper(child: SizedBox.expand()));
    return BlocProvider<FloorSetupCubit>(
      key: ValueKey<int>(projectId),
      create: (context) => FloorSetupCubit(floorSetupRepository(context), projectId)..load(),
      child: const _SetupView(),
    );
  }
}

class _SetupView extends StatelessWidget {
  const _SetupView();

  Future<void> _addFloor(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final FloorSetupCubit cubit = context.read<FloorSetupCubit>();
    final String? name = await showLedgerSheet<String>(
      context,
      isScrollControlled: true,
      builder: (_) => _NameSheet(
        title: l.floorSetupAddFloor,
        save: (String name) => cubit.addFloor(name),
      ),
    );
    if (name == null || !context.mounted) return;
    showPaperToast(context, l.floorSetupAdded(name), kind: PaperToastKind.success, icon: Icons.task_alt);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? code = context.select((AppGate gate) => gate.state.currentProject?.code);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              FieldTopBar(title: l.floorSetupTitle, subtitle: code),
              const Expanded(child: _SetupBody()),
              BlocBuilder<FloorSetupCubit, FloorSetupState>(
                buildWhen: (a, b) => a.busy != b.busy || (a.setup == null) != (b.setup == null),
                builder: (context, state) {
                  if (state.setup == null) return const SizedBox.shrink();
                  return FieldActionBar(
                    child: GloveButton(
                      key: const ValueKey<String>('floor-add'),
                      label: l.floorSetupAddFloor,
                      icon: Icons.add,
                      large: true,
                      fullWidth: true,
                      onPressed: state.busy ? null : () => _addFloor(context),
                    ),
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}

enum _FloorAction { plan, segments, rename, delete }

class _SetupBody extends StatelessWidget {
  const _SetupBody();

  /// Shows what can be done with a floor, then does it. Everything hangs off
  /// this screen's own context, which outlives a row the change removes.
  Future<void> _open(BuildContext context, FloorChoice choice) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final FloorSetupCubit cubit = context.read<FloorSetupCubit>();
    final bool isOwner = context.read<AppServices>().auth.isOwner;
    final ProgressFloor floor = choice.floor;
    final _FloorAction? action = await showLedgerSheet<_FloorAction>(
      context,
      isScrollControlled: true,
      builder: (_) => _ActionsSheet(choice: choice, isOwner: isOwner),
    );
    if (action == null || !context.mounted) return;

    void done(String message) => showPaperToast(context, message, kind: PaperToastKind.success, icon: Icons.task_alt);

    switch (action) {
      case _FloorAction.plan:
        await context.push(Routes.floorPlan(floor.id));
        if (context.mounted) await cubit.load();
      case _FloorAction.rename:
        final String? name = await showLedgerSheet<String>(
          context,
          isScrollControlled: true,
          builder: (_) => _NameSheet(
            title: l.floorSetupRenameTitle(floor.name),
            initial: floor.name,
            save: (String name) => cubit.rename(floor.id, name),
          ),
        );
        if (name != null && context.mounted) done(l.floorSetupRenamed);
      case _FloorAction.segments:
        final int? added = await showLedgerSheet<int>(
          context,
          isScrollControlled: true,
          builder: (_) => _SegmentsSheet(floor: floor, cubit: cubit),
        );
        if (added != null && added > 0 && context.mounted) done(l.floorSetupSegmentsAdded(added));
      case _FloorAction.delete:
        final bool? removed = await showLedgerSheet<bool>(
          context,
          isScrollControlled: true,
          builder: (_) => _DeleteSheet(choice: choice, cubit: cubit),
        );
        if (removed == true && context.mounted) {
          N360Haptics.success();
          done(l.floorSetupDeleted(choice.label));
        }
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final BuildContext screen = context;
    return BlocBuilder<FloorSetupCubit, FloorSetupState>(
      builder: (context, state) {
        final FloorSetupCubit cubit = context.read<FloorSetupCubit>();
        final FloorSetup? setup = state.setup;
        if (setup == null) {
          return switch (state.load) {
            SetupLoad.offline => SetupLoadProblem(title: l.floorSetupOffline, onRetry: cubit.load),
            SetupLoad.failed => SetupLoadProblem(title: l.floorSetupLoadFailed, onRetry: cubit.load),
            _ => Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(lines: 4, semanticsLabel: l.floorSetupLoading),
            ),
          };
        }
        return LedgerRefresh(
          onRefresh: cubit.load,
          semanticsLabel: l.floorSetupLoading,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
            children: <Widget>[
              _TimeFrameCard(frame: setup.timeFrame, busy: state.busy),
              const SizedBox(height: N360Space.md),
              if (setup.floors.isEmpty)
                EmptyLedger(title: l.floorSetupEmptyTitle, message: l.floorSetupEmptyMessage, animated: false)
              else ...<Widget>[
                LedgerCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      for (final (int index, FloorChoice choice) in setup.choices.indexed) ...<Widget>[
                        if (index > 0) RuledDivider(indent: choice.isFlat ? N360Space.card * 2 : N360Space.card),
                        _FloorTile(choice: choice, onTap: state.busy ? null : () => _open(screen, choice)),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: N360Space.md),
                GloveButton(
                  key: const ValueKey<String>('floor-share'),
                  label: l.floorSetupShare,
                  icon: Icons.call_split,
                  kind: GloveButtonKind.secondary,
                  fullWidth: true,
                  onPressed: state.busy
                      ? null
                      : () async {
                          await context.push(Routes.floorSplit);
                          if (context.mounted) await cubit.load();
                        },
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}

class _FloorTile extends StatelessWidget {
  const _FloorTile({required this.choice, required this.onTap});

  final FloorChoice choice;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProgressFloor floor = choice.floor;
    final String detail = <String>[
      if (floor.children.isNotEmpty) l.floorSetupSegmentCount(floor.children.length),
      floor.targets > 0 ? l.floorSetupPlannedCount(floor.targets) : l.floorSetupNotPlanned,
    ].join(' · ');
    return Padding(
      padding: EdgeInsets.only(left: choice.isFlat ? N360Space.card : 0),
      child: LedgerListTile(
        key: ValueKey<String>('setup-floor-${floor.id}'),
        title: floor.name,
        subtitle: detail,
        leading: Icon(
          choice.isFlat ? Icons.subdirectory_arrow_right : Icons.layers_outlined,
          size: N360IconSize.lg,
          color: N360Colors.ink2,
        ),
        trailing: Text(percentText(floor.percent), style: N360Type.figureSmall),
        onTap: onTap,
      ),
    );
  }
}

/// What can be done with one floor or segment. Pops with the choice.
class _ActionsSheet extends StatelessWidget {
  const _ActionsSheet({required this.choice, required this.isOwner});

  final FloorChoice choice;
  final bool isOwner;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    void choose(_FloorAction action) => Navigator.of(context).pop(action);
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(choice.label, style: N360Type.fit(N360Type.title, choice.label)),
              const SizedBox(height: N360Space.md),
              GloveButton(
                key: const ValueKey<String>('floor-action-plan'),
                label: l.floorSetupPlan,
                icon: Icons.straighten,
                fullWidth: true,
                onPressed: () => choose(_FloorAction.plan),
              ),
              const SizedBox(height: N360Space.sm),
              // A segment sits under a floor, never under another segment.
              if (!choice.isFlat) ...<Widget>[
                GloveButton(
                  key: const ValueKey<String>('floor-action-segments'),
                  label: l.floorSetupAddSegments,
                  icon: Icons.call_split,
                  kind: GloveButtonKind.secondary,
                  fullWidth: true,
                  onPressed: () => choose(_FloorAction.segments),
                ),
                const SizedBox(height: N360Space.sm),
              ],
              GloveButton(
                key: const ValueKey<String>('floor-action-rename'),
                label: l.floorSetupRename,
                icon: Icons.edit_outlined,
                kind: GloveButtonKind.secondary,
                fullWidth: true,
                onPressed: () => choose(_FloorAction.rename),
              ),
              // Removing is for the company owner. Nobody else is shown the
              // button; the server refuses them in any case.
              if (isOwner) ...<Widget>[
                const SizedBox(height: N360Space.lg),
                Text(
                  l.floorSetupOwnerNote,
                  style: N360Type.fit(N360Type.caption, l.floorSetupOwnerNote).copyWith(color: N360Colors.ink2),
                ),
                const SizedBox(height: N360Space.sm),
                GloveButton(
                  key: const ValueKey<String>('floor-action-delete'),
                  label: l.floorSetupDelete,
                  icon: Icons.delete_outline,
                  kind: GloveButtonKind.danger,
                  fullWidth: true,
                  onPressed: () => choose(_FloorAction.delete),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// A sheet that grows over the keyboard.
class _SheetFrame extends StatelessWidget {
  const _SheetFrame({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding.add(EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom)),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(title, style: N360Type.fit(N360Type.title, title)),
              const SizedBox(height: N360Space.md),
              ...children,
            ],
          ),
        ),
      ),
    );
  }
}

/// Takes one name and saves it. Stays open with the reason when the server
/// refuses; pops with the name when it went through.
class _NameSheet extends StatefulWidget {
  const _NameSheet({required this.title, required this.save, this.initial});

  final String title;
  final String? initial;
  final Future<SetupProblem?> Function(String name) save;

  @override
  State<_NameSheet> createState() => _NameSheetState();
}

class _NameSheetState extends State<_NameSheet> {
  late final TextEditingController _name = TextEditingController(text: widget.initial);
  String? _error;
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final String name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _error = l.floorSetupNameRequired);
      return;
    }
    if (_saving) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    final SetupProblem? problem = await widget.save(name);
    if (!mounted) return;
    if (problem == null) {
      Navigator.of(context).pop(name);
      return;
    }
    setState(() {
      _saving = false;
      _error = setupProblemText(l, problem);
    });
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return _SheetFrame(
      title: widget.title,
      children: <Widget>[
        GloveTextField(
          key: const ValueKey<String>('floor-name'),
          label: l.floorSetupFloorName,
          hint: l.floorSetupFloorNameHint,
          controller: _name,
          enabled: !_saving,
          errorText: _error,
          textCapitalization: TextCapitalization.sentences,
          textInputAction: TextInputAction.done,
          inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(80)],
          onChanged: (_) {
            if (_error != null) setState(() => _error = null);
          },
          onSubmitted: (_) => _save(),
        ),
        const SizedBox(height: N360Space.lg),
        GloveButton(
          key: const ValueKey<String>('floor-name-save'),
          label: l.floorSetupSave,
          icon: Icons.check,
          fullWidth: true,
          loading: _saving,
          onPressed: _saving ? null : _save,
        ),
      ],
    );
  }
}

/// Splits a floor into segments: one name per line. Names the server refuses
/// stay in the box with the reason, so they can be put right and sent again.
class _SegmentsSheet extends StatefulWidget {
  const _SegmentsSheet({required this.floor, required this.cubit});

  final ProgressFloor floor;
  final FloorSetupCubit cubit;

  @override
  State<_SegmentsSheet> createState() => _SegmentsSheetState();
}

class _SegmentsSheetState extends State<_SegmentsSheet> {
  final TextEditingController _names = TextEditingController();
  String? _error;
  bool _saving = false;
  int _added = 0;

  @override
  void dispose() {
    _names.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final List<String> names = <String>{
      for (final String line in _names.text.split('\n'))
        if (line.trim().isNotEmpty) line.trim(),
    }.toList();
    if (names.isEmpty) {
      setState(() => _error = l.floorSetupNameRequired);
      return;
    }
    if (_saving) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    final SegmentsOutcome outcome = await widget.cubit.addSegments(widget.floor.id, names);
    if (!mounted) return;
    _added += outcome.added.length;
    if (outcome.refused.isEmpty) {
      Navigator.of(context).pop(_added);
      return;
    }
    // Everything not added stays in the box: the names refused, and any the
    // phone never got to after losing the connection.
    _names.text = names.where((String name) => !outcome.added.contains(name)).join('\n');
    setState(() {
      _saving = false;
      _error = '${l.floorSetupSegmentsNotAdded(outcome.refused.keys.join(', '))}\n${setupProblemText(l, outcome.refused.values.first)}';
    });
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (bool didPop, Object? result) {
        if (!didPop) Navigator.of(context).pop(_added);
      },
      child: _SheetFrame(
        title: l.floorSetupSegmentsTitle(widget.floor.name),
        children: <Widget>[
          GloveTextField(
            key: const ValueKey<String>('floor-segments'),
            label: l.floorSetupSegmentsLabel,
            hint: l.floorSetupSegmentsHint,
            controller: _names,
            enabled: !_saving,
            errorText: _error,
            minLines: 3,
            maxLines: 8,
            keyboardType: TextInputType.multiline,
            textCapitalization: TextCapitalization.sentences,
            onChanged: (_) {
              if (_error != null) setState(() => _error = null);
            },
          ),
          const SizedBox(height: N360Space.lg),
          GloveButton(
            key: const ValueKey<String>('floor-segments-save'),
            label: l.floorSetupAddSegments,
            icon: Icons.call_split,
            fullWidth: true,
            loading: _saving,
            onPressed: _saving ? null : _save,
          ),
        ],
      ),
    );
  }
}

/// Names the floor, says what removing does, and removes it when confirmed.
/// Stays open with the server's reason when it refuses.
class _DeleteSheet extends StatefulWidget {
  const _DeleteSheet({required this.choice, required this.cubit});

  final FloorChoice choice;
  final FloorSetupCubit cubit;

  @override
  State<_DeleteSheet> createState() => _DeleteSheetState();
}

class _DeleteSheetState extends State<_DeleteSheet> {
  String? _error;
  bool _removing = false;

  Future<void> _remove() async {
    final AppLocalizations l = AppLocalizations.of(context);
    if (_removing) return;
    setState(() {
      _removing = true;
      _error = null;
    });
    final SetupProblem? problem = await widget.cubit.delete(widget.choice.floor.id);
    if (!mounted) return;
    if (problem == null) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _removing = false;
      _error = problem.kind == SetupProblemKind.forbidden ? l.floorSetupOwnerNote : setupProblemText(l, problem);
    });
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return _SheetFrame(
      title: l.floorSetupDeleteTitle(widget.choice.label),
      children: <Widget>[
        Text(
          l.floorSetupDeleteBody,
          style: N360Type.fit(N360Type.body, l.floorSetupDeleteBody).copyWith(color: N360Colors.ink2),
        ),
        if (_error != null) ...<Widget>[
          const SizedBox(height: N360Space.md),
          ErrorSlip(key: const ValueKey<String>('floor-delete-problem'), title: _error!),
        ],
        const SizedBox(height: N360Space.lg),
        GloveButton(
          key: const ValueKey<String>('floor-delete-confirm'),
          label: l.floorSetupDeleteConfirm,
          icon: Icons.delete_outline,
          kind: GloveButtonKind.danger,
          fullWidth: true,
          loading: _removing,
          onPressed: _removing ? null : _remove,
        ),
        const SizedBox(height: N360Space.sm),
        GloveButton(
          key: const ValueKey<String>('floor-delete-keep'),
          label: l.floorSetupDeleteKeep,
          kind: GloveButtonKind.secondary,
          fullWidth: true,
          onPressed: _removing ? null : () => Navigator.of(context).pop(false),
        ),
      ],
    );
  }
}

/// The project's time frame: when it starts, how many days it is meant to
/// take, and the deadline. Site progress compares time used with work done.
class _TimeFrameCard extends StatelessWidget {
  const _TimeFrameCard({required this.frame, required this.busy});

  final TimeFrame frame;
  final bool busy;

  Future<void> _edit(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final FloorSetupCubit cubit = context.read<FloorSetupCubit>();
    final bool? saved = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (_) => _TimeFrameSheet(frame: frame, cubit: cubit),
    );
    if (saved != true || !context.mounted) return;
    showPaperToast(context, l.floorSetupTimeFrameSaved, kind: PaperToastKind.success, icon: Icons.task_alt);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    String day(String? iso) => iso == null ? '—' : AppFormat.dayMonthYear(iso, locale);
    final String summary = frame.isEmpty
        ? l.floorSetupTimeFrameNone
        : l.floorSetupTimeFrameSummary(day(frame.startDate), frame.plannedDays?.toString() ?? '—', day(frame.deadlineDate));
    return LedgerCard(
      padding: EdgeInsets.zero,
      child: LedgerListTile(
        key: const ValueKey<String>('setup-time-frame'),
        title: l.floorSetupTimeFrame,
        subtitle: summary,
        leading: const Icon(Icons.event_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
        onTap: busy ? null : () => _edit(context),
      ),
    );
  }
}

class _TimeFrameSheet extends StatefulWidget {
  const _TimeFrameSheet({required this.frame, required this.cubit});

  final TimeFrame frame;
  final FloorSetupCubit cubit;

  @override
  State<_TimeFrameSheet> createState() => _TimeFrameSheetState();
}

class _TimeFrameSheetState extends State<_TimeFrameSheet> {
  late String? _start = widget.frame.startDate;
  late String? _deadline = widget.frame.deadlineDate;
  late final TextEditingController _days = TextEditingController(text: widget.frame.plannedDays?.toString() ?? '');
  String? _daysError;
  String? _deadlineError;
  String? _error;
  bool _saving = false;

  @override
  void dispose() {
    _days.dispose();
    super.dispose();
  }

  String _iso(DateTime day) =>
      '${day.year}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';

  Future<void> _pick({required bool start}) async {
    final DateTime today = DateTime.now();
    final DateTime? first = start ? null : DateTime.tryParse(_start ?? '');
    final DateTime initial = DateTime.tryParse((start ? _start : _deadline) ?? '') ?? first ?? today;
    final DateTime earliest = first ?? DateTime(today.year - 10);
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: initial.isBefore(earliest) ? earliest : initial,
      firstDate: earliest,
      lastDate: DateTime(today.year + 15),
    );
    if (picked == null || !mounted) return;
    setState(() {
      if (start) {
        _start = _iso(picked);
      } else {
        _deadline = _iso(picked);
      }
      _deadlineError = null;
      _error = null;
    });
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final String typed = _days.text.trim();
    final int? days = typed.isEmpty ? null : int.tryParse(typed);
    if (typed.isNotEmpty && (days == null || days < 1 || days > 5000)) {
      setState(() => _daysError = l.floorSetupDaysInvalid);
      return;
    }
    if (_saving) return;
    setState(() {
      _saving = true;
      _error = null;
      _deadlineError = null;
    });
    final SetupProblem? problem = await widget.cubit.saveTimeFrame(
      TimeFrame(startDate: _start, plannedDays: days, deadlineDate: _deadline),
    );
    if (!mounted) return;
    if (problem == null) {
      Navigator.of(context).pop(true);
      return;
    }
    final String text = setupProblemText(l, problem);
    setState(() {
      _saving = false;
      switch (problem.field) {
        case 'deadline_date':
          _deadlineError = text;
        case 'planned_days':
          _daysError = text;
        default:
          _error = text;
      }
    });
  }

  Widget _date({required String id, required String label, required String? value, required bool start, String? error}) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final String text = value == null ? l.floorSetupChooseDate : AppFormat.dayMonthYear(value, locale);
    return FieldBlock(
      label: label,
      error: error,
      child: Row(
        children: <Widget>[
          Expanded(
            child: FieldPickerTile(
              key: ValueKey<String>(id),
              icon: Icons.calendar_month_outlined,
              text: text,
              semanticsLabel: '$label: $text',
              muted: value == null,
              onTap: _saving ? () {} : () => _pick(start: start),
            ),
          ),
          if (value != null) ...<Widget>[
            const SizedBox(width: N360Space.sm),
            GloveButton(
              key: ValueKey<String>('$id-clear'),
              label: l.floorSetupClearDate,
              kind: GloveButtonKind.ghost,
              onPressed: _saving
                  ? null
                  : () => setState(() {
                      if (start) {
                        _start = null;
                      } else {
                        _deadline = null;
                      }
                    }),
            ),
          ],
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return _SheetFrame(
      title: l.floorSetupTimeFrame,
      children: <Widget>[
        _date(id: 'time-start', label: l.floorSetupStart, value: _start, start: true),
        const SizedBox(height: N360Space.md),
        GloveTextField(
          key: const ValueKey<String>('time-days'),
          label: l.floorSetupDays,
          controller: _days,
          enabled: !_saving,
          errorText: _daysError,
          keyboardType: TextInputType.number,
          inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(4)],
          onChanged: (_) {
            if (_daysError != null) setState(() => _daysError = null);
          },
        ),
        const SizedBox(height: N360Space.md),
        _date(id: 'time-deadline', label: l.floorSetupDeadline, value: _deadline, start: false, error: _deadlineError),
        if (_error != null) ...<Widget>[
          const SizedBox(height: N360Space.md),
          ErrorSlip(key: const ValueKey<String>('time-problem'), title: _error!),
        ],
        const SizedBox(height: N360Space.lg),
        GloveButton(
          key: const ValueKey<String>('time-save'),
          label: l.floorSetupSave,
          icon: Icons.check,
          fullWidth: true,
          loading: _saving,
          onPressed: _saving ? null : _save,
        ),
      ],
    );
  }
}
