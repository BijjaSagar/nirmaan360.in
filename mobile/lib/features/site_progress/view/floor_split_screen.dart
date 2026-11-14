import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/floor_setup_cubit.dart';
import '../data/floor_setup_repository.dart';
import 'floor_setup_screen.dart';

/// Shares whole BOQ lines equally across the chosen floors and segments, the
/// way the website does: the last floor takes the rounding remainder, and what
/// these lines had planned on other floors is replaced. Online only. Needs
/// `progress.manage`.
class FloorSplitScreen extends StatelessWidget {
  const FloorSplitScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final int? projectId = context.select((AppGate gate) => gate.state.currentProject?.id);
    if (projectId == null) return const Scaffold(body: LedgerPaper(child: SizedBox.expand()));
    return BlocProvider<FloorSplitCubit>(
      key: ValueKey<int>(projectId),
      create: (context) => FloorSplitCubit(floorSetupRepository(context), projectId)..load(),
      child: const _SplitView(),
    );
  }
}

class _SplitView extends StatefulWidget {
  const _SplitView();

  @override
  State<_SplitView> createState() => _SplitViewState();
}

class _SplitViewState extends State<_SplitView> {
  String? _problem;

  Future<void> _submit() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final FloorSplitCubit cubit = context.read<FloorSplitCubit>();
    final SplitMissing? missing = cubit.missing;
    if (missing != null) {
      setState(() => _problem = missing == SplitMissing.floor ? l.floorSplitNeedFloor : l.floorSplitNeedLine);
      return;
    }
    setState(() => _problem = null);
    final (int? count, SetupProblem? problem) = await cubit.submit();
    if (!mounted) return;
    if (problem != null) {
      setState(() => _problem = setupProblemText(l, problem));
      return;
    }
    if (count == null) return;
    N360Haptics.success();
    showPaperToast(context, l.floorSplitDone(count), kind: PaperToastKind.success, icon: Icons.task_alt);
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<FloorSplitCubit, FloorSplitState>(
            builder: (context, state) {
              final FloorSplitCubit cubit = context.read<FloorSplitCubit>();
              final FloorSetup? setup = state.setup;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.floorSplitTitle),
                  Expanded(
                    child: setup == null
                        ? switch (state.load) {
                            SetupLoad.offline => SetupLoadProblem(title: l.floorSetupOffline, onRetry: cubit.load),
                            SetupLoad.failed => SetupLoadProblem(title: l.floorSetupLoadFailed, onRetry: cubit.load),
                            _ => Padding(
                              padding: const EdgeInsets.all(N360Space.gutter),
                              child: LedgerSkeletonList(lines: 4, semanticsLabel: l.floorSetupLoading),
                            ),
                          }
                        : _SplitForm(state: state, setup: setup, problem: _problem, onChanged: () => setState(() => _problem = null)),
                  ),
                  if (setup != null)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('split-submit'),
                        label: l.floorSplitSubmit,
                        icon: Icons.call_split,
                        large: true,
                        fullWidth: true,
                        loading: state.busy,
                        onPressed: state.busy ? null : _submit,
                      ),
                    ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _SplitForm extends StatelessWidget {
  const _SplitForm({required this.state, required this.setup, required this.problem, required this.onChanged});

  final FloorSplitState state;
  final FloorSetup setup;
  final String? problem;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final FloorSplitCubit cubit = context.read<FloorSplitCubit>();
    final bool busy = state.busy;
    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        Text(l.floorSplitExplain, style: N360Type.fit(N360Type.body, l.floorSplitExplain).copyWith(color: N360Colors.ink2)),
        const SizedBox(height: N360Space.md),
        if (problem != null) ...<Widget>[
          ErrorSlip(key: const ValueKey<String>('split-problem'), title: problem!),
          const SizedBox(height: N360Space.md),
        ],
        Text(l.floorSplitFloors, style: N360Type.eyebrowFor(l.floorSplitFloors)),
        const SizedBox(height: N360Space.xs),
        LedgerCard(
          padding: EdgeInsets.zero,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              for (final (int index, FloorChoice choice) in setup.choices.indexed) ...<Widget>[
                if (index > 0) const RuledDivider(indent: N360Space.card),
                _CheckRow(
                  key: ValueKey<String>('split-floor-${choice.floor.id}'),
                  label: choice.label,
                  checked: state.floorIds.contains(choice.floor.id),
                  onTap: busy
                      ? null
                      : () {
                          cubit.toggleFloor(choice.floor.id);
                          onChanged();
                        },
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: N360Space.lg),
        Text(l.floorSplitLines, style: N360Type.eyebrowFor(l.floorSplitLines)),
        const SizedBox(height: N360Space.xs),
        LedgerCard(
          padding: EdgeInsets.zero,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              _CheckRow(
                key: const ValueKey<String>('split-all-lines'),
                label: l.floorSplitAllLines,
                checked: state.allItems,
                onTap: busy
                    ? null
                    : () {
                        cubit.setAllItems(!state.allItems);
                        onChanged();
                      },
              ),
              if (!state.allItems)
                for (final ProgressItem item in setup.items) ...<Widget>[
                  const RuledDivider(indent: N360Space.card),
                  _CheckRow(
                    key: ValueKey<String>('split-line-${item.id}'),
                    label: '${item.code} ${item.description}'.trim(),
                    detail: '${item.qty.format()} ${item.unit}',
                    checked: state.itemIds.contains(item.id),
                    onTap: busy
                        ? null
                        : () {
                            cubit.toggleItem(item.id);
                            onChanged();
                          },
                  ),
                ],
            ],
          ),
        ),
      ],
    );
  }
}

/// One row with a tick box, read out as a checkbox.
class _CheckRow extends StatelessWidget {
  const _CheckRow({super.key, required this.label, required this.checked, required this.onTap, this.detail});

  final String label;
  final String? detail;
  final bool checked;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      checked: checked,
      enabled: onTap != null,
      label: detail == null ? label : '$label, $detail',
      button: true,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: N360Size.tap),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.sm),
            child: Row(
              children: <Widget>[
                Icon(
                  checked ? Icons.check_box : Icons.check_box_outline_blank,
                  size: N360IconSize.lg,
                  color: checked ? N360Colors.primary : N360Colors.ink2,
                ),
                const SizedBox(width: N360Space.md),
                Expanded(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(label, style: N360Type.fit(N360Type.bodyStrong, label)),
                      if (detail != null)
                        Text(detail!, style: N360Type.fit(N360Type.caption, detail!).copyWith(color: N360Colors.ink2)),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
