import 'package:flutter/material.dart';

import '../design.dart';

/// Four real screens, with the content the owner saw on his phone, built from
/// the design system so a change to the system can be judged on them.
///
/// [proposed] switches the few compositions that the proposal changes at the
/// screen level (noted on each); everything else comes from the system itself.
enum ScreenMock { home, entry, list, form }

class ScreenMocks extends StatelessWidget {
  const ScreenMocks({super.key, required this.screen, this.proposed = false});

  final ScreenMock screen;
  final bool proposed;

  @override
  Widget build(BuildContext context) {
    return switch (screen) {
      ScreenMock.home => _Home(proposed: proposed),
      ScreenMock.entry => _Entry(proposed: proposed),
      ScreenMock.list => _IssueList(proposed: proposed),
      ScreenMock.form => _IssueForm(proposed: proposed),
    };
  }
}

// Home ------------------------------------------------------------------------

class _Home extends StatelessWidget {
  const _Home({required this.proposed});

  final bool proposed;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      bottomNavigationBar: N360BottomBar(
        currentIndex: 0,
        onTap: (_) {},
        items: const <N360BarItem>[
          N360BarItem(icon: Icons.home_outlined, label: 'Home'),
          N360BarItem(icon: Icons.architecture, label: 'Measure'),
          N360BarItem(icon: Icons.inbox_outlined, label: 'Outbox'),
        ],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              _HomeHeader(proposed: proposed),
              const SizedBox(height: N360Space.md),
              _Returned(proposed: proposed),
              const SizedBox(height: N360Space.cardGap),
              const _ProgressCard(),
              const SizedBox(height: N360Space.cardGap),
              Row(
                children: <Widget>[
                  Expanded(child: StatTile(title: 'Daily report', value: 'In review', caption: 'today', icon: Icons.hourglass_bottom, onTap: () {})),
                  const SizedBox(width: N360Space.cardGap),
                  Expanded(child: StatTile(title: 'Attendance', value: '9/9', caption: 'marked', icon: Icons.groups_outlined, onTap: () {})),
                ],
              ),
              const SizedBox(height: N360Space.cardGap),
              Row(
                children: <Widget>[
                  Expanded(child: StatTile(title: 'Measure', value: '3', caption: 'today', icon: Icons.architecture, onTap: () {})),
                  const SizedBox(width: N360Space.cardGap),
                  Expanded(child: StatTile(title: 'Expenses', value: '₹496.90', caption: 'today', icon: Icons.account_balance_wallet_outlined, onTap: () {})),
                ],
              ),
              const SizedBox(height: N360Space.cardGap),
              StatTile(title: 'Issues', value: '5', caption: 'open', icon: Icons.warning_amber_outlined, onTap: () {}),
            ],
          ),
        ),
      ),
    );
  }
}

class _HomeHeader extends StatelessWidget {
  const _HomeHeader({required this.proposed});

  final bool proposed;

  @override
  Widget build(BuildContext context) {
    final Widget name = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text('Kalyani Tower B', style: N360Type.title),
        Text('KT-B · Baner, Pune', style: N360Type.caption),
      ],
    );
    if (proposed) {
      // Proposed: no logo tile and no labelled icon wells. The project name is
      // the header; sync state is a quiet tag; settings is a plain icon target.
      return Row(
        children: <Widget>[
          Expanded(child: name),
          SyncPill(state: SyncVisualState.synced, label: 'All sent', onTap: () {}),
          const SizedBox(width: N360Space.xs),
          IconTile(icon: Icons.settings_outlined, semanticLabel: 'Settings', onTap: () {}),
        ],
      );
    }
    return Row(
      children: <Widget>[
        const IconTile(icon: Icons.apartment, semanticLabel: 'Nirmaan360', highlight: true),
        const SizedBox(width: N360Space.md),
        Expanded(child: name),
        Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            IconTile(icon: Icons.cloud_done_outlined, semanticLabel: 'All sent', onTap: () {}),
            Text('All sent', style: N360Type.caption.copyWith(color: N360Colors.mossInk)),
          ],
        ),
        const SizedBox(width: N360Space.xs),
        Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            IconTile(icon: Icons.settings_outlined, semanticLabel: 'Settings', onTap: () {}),
            Text('Settings', style: N360Type.caption),
          ],
        ),
      ],
    );
  }
}

class _Returned extends StatelessWidget {
  const _Returned({required this.proposed});

  final bool proposed;

  @override
  Widget build(BuildContext context) {
    if (proposed) {
      // Proposed: a returned item is a list row with a quiet status, not a
      // stamped card with its own gold action tile.
      return LedgerCard(
        padding: EdgeInsets.zero,
        onTap: () {},
        semanticsLabel: 'Returned: Measurement 2.3, fix it',
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
          child: Row(
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Row(
                      children: <Widget>[
                        const RubberStamp(kind: StampKind.returned, label: 'Returned', size: StampSize.small),
                        const SizedBox(width: N360Space.sm),
                        Expanded(child: Text('Measurement 2.3 · 7.680 cum', style: N360Type.bodyStrong)),
                      ],
                    ),
                    const SizedBox(height: N360Space.xs),
                    Text('Deduct the door openings and resubmit.', style: N360Type.body.copyWith(color: N360Colors.ink2)),
                  ],
                ),
              ),
              const SizedBox(width: N360Space.sm),
              Text('Fix', style: N360Type.label.copyWith(color: N360Colors.laterite)),
              const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.laterite),
            ],
          ),
        ),
      );
    }
    return LedgerCard(
      child: Row(
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    const RubberStamp(kind: StampKind.returned, label: 'Returned', size: StampSize.small),
                    const SizedBox(width: N360Space.sm),
                    Expanded(child: Text('Measurement 2.3 · 7.680 cum', style: N360Type.bodyStrong)),
                  ],
                ),
                const SizedBox(height: N360Space.xs),
                Text('Deduct the door openings and resubmit.', style: N360Type.body),
              ],
            ),
          ),
          const SizedBox(width: N360Space.sm),
          Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              IconTile(icon: Icons.edit_outlined, semanticLabel: 'Fix', highlight: true, onTap: () {}),
              Text('Fix', style: N360Type.caption.copyWith(color: N360Colors.laterite, fontWeight: FontWeight.w700)),
            ],
          ),
        ],
      ),
    );
  }
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard();

  @override
  Widget build(BuildContext context) {
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text('Construction progress', style: N360Type.heading),
                    Text('By value of approved work', style: N360Type.caption),
                    const SizedBox(height: N360Space.sm),
                    const RollingNumber(value: '2.44', suffix: '%', style: N360Type.figureHero, animateEntry: true),
                    const SizedBox(height: N360Space.sm),
                    Row(
                      children: <Widget>[
                        Expanded(child: _Meta(label: 'End', value: '9 Jan 2028')),
                        Expanded(child: _Meta(label: 'Days left', value: '426')),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(width: N360Space.sm),
              const SizedBox(width: 128, child: BuildingProgressArt(fraction: 0.0244, animateEntry: false)),
            ],
          ),
          const SizedBox(height: N360Space.sm),
          const HatchedProgressBar(fraction: 0.0244, markerLabel: 'Today'),
        ],
      ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: N360Type.caption),
        Text(value, style: N360Type.label),
      ],
    );
  }
}

// Measurement entry ------------------------------------------------------------

class _Entry extends StatelessWidget {
  const _Entry({required this.proposed});

  final bool proposed;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: <Widget>[
                  IconTile(icon: Icons.arrow_back, semanticLabel: 'Back', onTap: () {}),
                  const SizedBox(width: N360Space.sm),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text('Item 1.2 · cum', style: N360Type.caption),
                        Text('PCC M15 below foundations', style: N360Type.bodyStrong, maxLines: 1, overflow: TextOverflow.ellipsis),
                        Text.rich(TextSpan(children: <InlineSpan>[
                          TextSpan(text: 'BOQ ', style: N360Type.caption),
                          TextSpan(text: '140.000', style: N360Type.figureSmall),
                          TextSpan(text: '   Balance ', style: N360Type.caption),
                          TextSpan(text: '140.000', style: N360Type.figureSmall),
                        ])),
                      ],
                    ),
                  ),
                  Text('Change', style: N360Type.label.copyWith(color: N360Colors.laterite)),
                  const Icon(Icons.expand_more, size: N360IconSize.md, color: N360Colors.laterite),
                ],
              ),
              const SizedBox(height: N360Space.md),
              Row(
                children: <Widget>[
                  Expanded(child: DimensionTile(label: 'Nos', value: '2', onTap: () {})),
                  const SizedBox(width: 6),
                  Expanded(child: DimensionTile(label: '× L', unit: 'm', value: '10', onTap: () {})),
                  const SizedBox(width: 6),
                  Expanded(child: DimensionTile(label: '× B', unit: 'm', value: '5', onTap: () {})),
                  const SizedBox(width: 6),
                  Expanded(child: DimensionTile(label: '× D', unit: 'm', value: '0.5', active: true, onTap: () {})),
                ],
              ),
              const SizedBox(height: N360Space.sm),
              Row(
                children: <Widget>[
                  Expanded(child: GloveButton(label: 'Add', icon: Icons.add, fullWidth: true, onPressed: () {})),
                  const SizedBox(width: N360Space.sm),
                  Expanded(child: GloveButton(label: 'Deduct', icon: Icons.remove, kind: GloveButtonKind.secondary, fullWidth: true, onPressed: () {})),
                  const SizedBox(width: N360Space.sm),
                  GloveButton(label: 'Details', icon: Icons.notes, kind: GloveButtonKind.ghost, onPressed: () {}),
                ],
              ),
              const SizedBox(height: N360Space.sm),
              Text("Figures on this phone are a preview. The server's figures are final.", style: N360Type.caption),
              const Spacer(),
              LedgerCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text('2 × 10 × 5 × 0.5 = 50.000 cum', style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
                    const SizedBox(height: N360Space.xs),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: <Widget>[
                        const Expanded(
                          child: RollingQty(value: '50.000', unit: 'cum', style: N360Type.figureHero),
                        ),
                        const SquarePercent(fraction: 0.36, label: '36%'),
                      ],
                    ),
                    const SizedBox(height: N360Space.sm),
                    const HatchedProgressBar(fraction: 0.36, height: 18),
                    const SizedBox(height: N360Space.sm),
                    Row(
                      children: <Widget>[
                        Text('Balance after this', style: N360Type.bodyStrong),
                        const SizedBox(width: 6),
                        Text('preview', style: N360Type.caption),
                        const Spacer(),
                        Text('90.000', style: N360Type.figure),
                        const SizedBox(width: 4),
                        Text('cum', style: N360Type.caption),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: N360Space.sm),
              Row(
                children: <Widget>[
                  Expanded(child: GloveButton(label: 'Send for approval', kind: GloveButtonKind.secondary, fullWidth: true, onPressed: () {})),
                  const SizedBox(width: N360Space.sm),
                  Expanded(child: GloveButton(label: 'Save', fullWidth: true, onPressed: () {})),
                ],
              ),
              const SizedBox(height: N360Space.sm),
              GloveKeypad(value: '0.5', onChanged: (_) {}, onNext: () {}, nextLabel: 'Next', backspaceLabel: 'Delete'),
            ],
          ),
        ),
      ),
    );
  }
}

// Issues list ------------------------------------------------------------------

class _Issue {
  const _Issue(this.title, this.priority, this.where, this.due, this.who);

  final String title;
  final String priority;
  final String? where;
  final String? due;
  final String? who;
}

const List<_Issue> _open = <_Issue>[
  _Issue('Shuttering plates short for Level 5', 'critical', 'Store yard', 'Due 10 Nov', 'Sachin More'),
  _Issue('Honeycombing at column C7, Level 3', 'high', 'Grid C7', 'Due 12 Nov', 'Sachin More'),
  _Issue('Rebar cover short at beam B4', 'low', null, 'No due date', 'Not assigned'),
  _Issue('Water logging near batching plant', 'low', 'East gate', 'No due date', 'Not assigned'),
];

const List<_Issue> _inProgress = <_Issue>[
  _Issue('Seepage at lift pit retaining wall', 'medium', 'Lift pit, basement', 'Due 16 Nov', 'Prakash More'),
];

class _IssueList extends StatelessWidget {
  const _IssueList({required this.proposed});

  final bool proposed;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Row(
                      children: <Widget>[
                        IconTile(icon: Icons.arrow_back, semanticLabel: 'Back', onTap: () {}),
                        Expanded(
                          child: Column(
                            children: <Widget>[
                              Text('Issues', style: N360Type.title),
                              Text('KT-B', style: N360Type.caption),
                            ],
                          ),
                        ),
                        const SizedBox(width: N360Size.tap),
                      ],
                    ),
                    const SizedBox(height: N360Space.sm),
                    SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        children: <Widget>[
                          N360Chip(label: 'All', count: '5', selected: true, onTap: () {}),
                          const SizedBox(width: N360Space.sm),
                          N360Chip(label: 'Open', count: '4', icon: Icons.radio_button_unchecked, selected: false, onTap: () {}),
                          const SizedBox(width: N360Space.sm),
                          N360Chip(label: 'In progress', count: '1', icon: Icons.timelapse, selected: false, onTap: () {}),
                          const SizedBox(width: N360Space.sm),
                          N360Chip(label: 'Resolved', icon: Icons.task_alt, selected: false, onTap: () {}),
                        ],
                      ),
                    ),
                    const SizedBox(height: N360Space.md),
                    _IssueGroup(title: 'Open', icon: Icons.radio_button_unchecked, issues: _open, proposed: proposed),
                    const SizedBox(height: N360Space.md),
                    _IssueGroup(title: 'In progress', icon: Icons.timelapse, issues: _inProgress, proposed: proposed),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
              child: GloveButton(label: 'Raise issue', icon: Icons.add, large: true, fullWidth: true, onPressed: () {}),
            ),
          ],
        ),
      ),
    );
  }
}

class _IssueGroup extends StatelessWidget {
  const _IssueGroup({required this.title, required this.icon, required this.issues, required this.proposed});

  final String title;
  final IconData icon;
  final List<_Issue> issues;
  final bool proposed;

  @override
  Widget build(BuildContext context) {
    if (proposed) {
      // Proposed: one white list per group, structured rows with hairlines,
      // the priority as a quiet pill, the meta on one line.
      return LedgerList(
        header: Row(
          children: <Widget>[
            Expanded(child: Text(title, style: N360Type.heading)),
            Text('${issues.length}', style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
          ],
        ),
        children: <Widget>[
          for (final issue in issues)
            LedgerListTile(
              title: issue.title,
              subtitle: <String?>[issue.where, issue.due, issue.who].whereType<String>().join(' · '),
              status: StatusPill(kind: _IssueRow._priorityKind(issue.priority), label: _IssueRow._word(issue.priority)),
              onTap: () {},
            ),
        ],
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.xs, 0, N360Space.xs, N360Space.sm),
          child: Row(
            children: <Widget>[
              Icon(icon, size: N360IconSize.md, color: N360Colors.ink2),
              const SizedBox(width: N360Space.sm),
              Expanded(child: Text(title, style: N360Type.heading)),
              Text('${issues.length}', style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
            ],
          ),
        ),
        LedgerCard(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.xs),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              for (final (i, issue) in issues.indexed) ...<Widget>[
                _IssueRow(issue: issue, proposed: proposed),
                if (i < issues.length - 1) const RuledDivider(),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _IssueRow extends StatelessWidget {
  const _IssueRow({required this.issue, required this.proposed});

  final _Issue issue;
  final bool proposed;

  @override
  Widget build(BuildContext context) {
    final Pictogram p = N360Pictograms.priority(issue.priority);
    final String word = _word(issue.priority);
    final Widget body = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(issue.title, style: N360Type.bodyStrong),
        const SizedBox(height: 2),
        if (issue.where != null) _MetaLine(Icons.place_outlined, issue.where!),
        if (issue.due != null) _MetaLine(Icons.calendar_today_outlined, issue.due!),
        if (issue.who != null) _MetaLine(Icons.person_outline, issue.who!),
      ],
    );
    return PressTarget(
      onTap: () {},
      semanticsLabel: '${issue.title}, $word',
      builder: (context, depth, child) => Padding(
        padding: const EdgeInsets.symmetric(vertical: N360Space.sm + 2),
        child: child,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (proposed) ...<Widget>[
            // Proposed: priority is a small straight pill in the row, not a
            // coloured tile with its own word beneath.
            Padding(
              padding: const EdgeInsets.only(top: 1),
              child: RubberStamp(kind: _priorityKind(issue.priority), label: word, size: StampSize.small),
            ),
            const SizedBox(width: N360Space.sm + 2),
          ] else ...<Widget>[
            _PriorityMark(icon: p.icon, word: word, priority: issue.priority),
            const SizedBox(width: N360Space.md),
          ],
          Expanded(child: body),
          const Padding(
            padding: EdgeInsets.only(top: 2),
            child: Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.ink3),
          ),
        ],
      ),
    );
  }

  static String _word(String p) => switch (p) {
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        _ => 'Low',
      };

  static StampKind _priorityKind(String p) => switch (p) {
        'critical' => StampKind.rejected,
        'high' => StampKind.returned,
        'medium' => StampKind.inReview,
        _ => StampKind.draft,
      };
}

class _MetaLine extends StatelessWidget {
  const _MetaLine(this.icon, this.text);

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 1),
      child: Row(
        children: <Widget>[
          Icon(icon, size: N360IconSize.xs, color: N360Colors.ink2),
          const SizedBox(width: 4),
          Text(text, style: N360Type.caption),
        ],
      ),
    );
  }
}

/// The current look: a coloured square with the word beneath.
class _PriorityMark extends StatelessWidget {
  const _PriorityMark({required this.icon, required this.word, required this.priority});

  final IconData icon;
  final String word;
  final String priority;

  @override
  Widget build(BuildContext context) {
    final bool critical = priority == 'critical';
    final Color ink = critical ? N360Colors.sheet : N360Colors.ink;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SizedBox.square(
          dimension: N360Size.tile,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: critical ? N360Colors.accent : N360Colors.paper2,
              borderRadius: N360Radius.md,
            ),
            child: Icon(icon, size: N360IconSize.lg, color: ink),
          ),
        ),
        const SizedBox(height: 2),
        Text(
          word,
          style: N360Type.caption.copyWith(
            color: critical ? N360Colors.stampRed : N360Colors.laterite,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    );
  }
}

// Issue form -------------------------------------------------------------------

class _IssueForm extends StatefulWidget {
  const _IssueForm({required this.proposed});

  final bool proposed;

  @override
  State<_IssueForm> createState() => _IssueFormState();
}

class _IssueFormState extends State<_IssueForm> {
  final TextEditingController _title = TextEditingController(text: 'Shuttering plates short for Level 5');
  final TextEditingController _where = TextEditingController(text: 'Store yard');
  final TextEditingController _who = TextEditingController(text: 'Sachin More');
  final TextEditingController _due = TextEditingController(text: '10 Nov 2026');

  @override
  void dispose() {
    _title.dispose();
    _where.dispose();
    _who.dispose();
    _due.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final List<PictoOption<String>> priorities = <PictoOption<String>>[
      for (final (p, w) in <(Pictogram, String)>[
        (N360Pictograms.priority('low'), 'Low'),
        (N360Pictograms.priority('medium'), 'Medium'),
        (N360Pictograms.priority('high'), 'High'),
        (N360Pictograms.priority('critical'), 'Critical'),
      ])
        PictoOption<String>(value: p.key, icon: p.icon, label: w),
    ];
    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Row(
                      children: <Widget>[
                        IconTile(icon: Icons.arrow_back, semanticLabel: 'Back', onTap: () {}),
                        Expanded(
                          child: Column(
                            children: <Widget>[
                              Text('Raise issue', style: N360Type.title),
                              Text('KT-B', style: N360Type.caption),
                            ],
                          ),
                        ),
                        const SizedBox(width: N360Size.tap),
                      ],
                    ),
                    const SizedBox(height: N360Space.md),
                    GloveTextField(label: 'What is wrong?', controller: _title, hint: 'Say what you saw'),
                    const SizedBox(height: N360Space.md),
                    GloveTextField(label: 'Where on site?', controller: _where, hint: 'Grid, floor or yard', prefixIcon: Icons.place_outlined),
                    const SizedBox(height: N360Space.md),
                    const SectionLabel('Priority'),
                    const SizedBox(height: N360Space.xs),
                    PictoChoice<String>(options: priorities, value: 'critical', columns: 4, onChanged: (_) {}),
                    const SizedBox(height: N360Space.md),
                    GloveTextField(
                      label: 'Assign to',
                      controller: _who,
                      prefixIcon: Icons.person_outline,
                      suffix: const Icon(Icons.expand_more, size: N360IconSize.lg, color: N360Colors.ink2),
                    ),
                    const SizedBox(height: N360Space.md),
                    GloveTextField(label: 'Due date', controller: _due, prefixIcon: Icons.calendar_today_outlined),
                    const SizedBox(height: N360Space.md),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: GloveButton(label: 'Add photo', icon: Icons.camera_alt_outlined, kind: GloveButtonKind.ghost, onPressed: () {}),
                    ),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
              child: GloveButton(label: 'Raise issue', icon: Icons.check, large: true, fullWidth: true, onPressed: () {}),
            ),
          ],
        ),
      ),
    );
  }
}
