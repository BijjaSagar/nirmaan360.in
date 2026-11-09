import 'package:flutter/material.dart';

import '../../../l10n/generated/app_localizations.dart';
import '../design.dart';

/// One page of the gallery. Each is laid out for a 360dp phone.
enum GallerySection {
  progress,
  plan,
  installed,
  art,
  home,
  ledger,
  stamps,
  buttons,
  entry,
  muster,
  choices,
  outbox,
  moments,
  type,
}

/// Every component on one scrollable page, used to judge the system by eye.
/// Pass [section] to show a single page, as the screenshot test does.
class DesignGallery extends StatelessWidget {
  const DesignGallery({super.key, this.section, this.marathi = false});

  final GallerySection? section;
  final bool marathi;

  _Copy get _c => _Copy(marathi);

  Widget _build(BuildContext context, GallerySection s) => switch (s) {
        GallerySection.progress => _ProgressPage(_c),
        GallerySection.plan => _PlanPage(_c),
        GallerySection.installed => _InstalledPage(_c),
        GallerySection.art => _ArtPage(_c),
        GallerySection.home => _HomePage(_c),
        GallerySection.ledger => _LedgerPage(_c),
        GallerySection.stamps => _StampsPage(_c),
        GallerySection.buttons => _ButtonsPage(_c),
        GallerySection.entry => _EntryPage(_c),
        GallerySection.muster => _MusterPage(_c),
        GallerySection.choices => _ChoicesPage(_c),
        GallerySection.outbox => _OutboxPage(_c),
        GallerySection.moments => _MomentsPage(_c),
        GallerySection.type => _TypePage(_c),
      };

  N360BottomBar _bar() => N360BottomBar(
        currentIndex: 0,
        onTap: (_) {},
        items: <N360BarItem>[
          N360BarItem(icon: Icons.home_outlined, label: _c.t('Home', 'मुख्य')),
          N360BarItem(icon: Icons.architecture, label: _c.t('Measure', 'मोजमाप')),
          N360BarItem(icon: Icons.groups_outlined, label: _c.t('Muster', 'हजेरी')),
          N360BarItem(icon: Icons.approval_outlined, label: _c.t('Approve', 'मंजुरी'), badge: '4'),
          N360BarItem(icon: Icons.inbox_outlined, label: _c.t('Outbox', 'आउटबॉक्स')),
        ],
      );

  @override
  Widget build(BuildContext context) {
    final GallerySection? single = section;
    if (single != null) {
      final bool showBar = single == GallerySection.progress || single == GallerySection.home;
      return Scaffold(
        bottomNavigationBar: showBar ? _bar() : null,
        body: SafeArea(child: SingleChildScrollView(child: _build(context, single))),
      );
    }
    return Scaffold(
      body: SafeArea(
        child: ListView(
          children: <Widget>[
            for (final s in GallerySection.values) _build(context, s),
          ],
        ),
      ),
    );
  }
}

class _Copy {
  const _Copy(this.mr);

  final bool mr;

  String t(String en, String marathi) => mr ? marathi : en;
}

class _Page extends StatelessWidget {
  const _Page({required this.eyebrow, required this.title, required this.children});

  final String eyebrow;
  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SheetHeader(eyebrow: eyebrow, title: title),
          const SizedBox(height: N360Space.xs),
          ...children,
        ],
      ),
    );
  }
}

/// The reference's first screen: headline figure, hatched bar, building card.
class _ProgressPage extends StatelessWidget {
  const _ProgressPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              IconTile(icon: Icons.apartment, semanticLabel: c.t('Project', 'प्रकल्प'), highlight: true),
              const SizedBox(width: N360Space.md),
              Expanded(child: Text(c.t('Kalyani Tower B', 'कल्याणी टॉवर बी'), style: N360Type.title)),
              IconTile(icon: Icons.notifications_outlined, semanticLabel: c.t('Alerts', 'सूचना'), badge: '3', onTap: () {}),
            ],
          ),
          const SizedBox(height: N360Space.md),
          LedgerCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Expanded(child: Text(c.t('Construction progress', 'बांधकामाची प्रगती'), style: N360Type.heading)),
                    IconTile(icon: Icons.calendar_month_outlined, semanticLabel: c.t('Dates', 'तारखा'), onTap: () {}),
                  ],
                ),
                Text('70.50%', style: N360Type.figureHero),
                const SizedBox(height: N360Space.xs),
                Row(
                  children: <Widget>[
                    Expanded(child: _Caption(label: c.t('End', 'शेवट'), value: '2026/01/11')),
                    _Caption(label: c.t('Total', 'एकूण'), value: c.t('234 delay', '234 उशीर'), alignEnd: true),
                  ],
                ),
                const SizedBox(height: N360Space.sm),
                HatchedProgressBar(fraction: 0.705, markerLabel: c.t('Today', 'आज')),
              ],
            ),
          ),
          const SizedBox(height: N360Space.md),
          LedgerCard(
            padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.md, N360Space.md, N360Space.xs),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    IconTile(icon: Icons.apartment_outlined, semanticLabel: c.t('Building', 'इमारत')),
                    const SizedBox(width: N360Space.md),
                    Expanded(child: Text(c.t('Building plan', 'इमारतीचा आराखडा'), style: N360Type.heading)),
                    IconTile(icon: Icons.calendar_month_outlined, semanticLabel: c.t('Dates', 'तारखा'), onTap: () {}),
                  ],
                ),
                const BuildingProgressArt(fraction: 0.705),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Caption extends StatelessWidget {
  const _Caption({required this.label, required this.value, this.alignEnd = false});

  final String label;
  final String value;
  final bool alignEnd;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: alignEnd ? CrossAxisAlignment.end : CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: N360Type.caption),
        Text(value, style: N360Type.heading.copyWith(fontSize: 15, height: 1.3)),
      ],
    );
  }
}

/// The reference's middle screen.
class _PlanPage extends StatelessWidget {
  const _PlanPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              IconTile(icon: Icons.arrow_back, semanticLabel: c.t('Back', 'मागे'), onTap: () {}),
              Expanded(
                child: Text(
                  c.t('Construction plan', 'बांधकाम आराखडा'),
                  textAlign: TextAlign.center,
                  style: N360Type.heading,
                ),
              ),
              IconTile(icon: Icons.more_horiz, semanticLabel: c.t('More', 'आणखी'), onTap: () {}),
            ],
          ),
          const SizedBox(height: N360Space.sm),
          const BuildingProgressArt(fraction: 0.42),
          const SizedBox(height: N360Space.sm),
          Row(
            children: <Widget>[
              Expanded(
                child: StatTile(
                  title: c.t('Finishing work', 'फिनिशिंग'),
                  value: '67%',
                  caption: '25 Nov, 2026',
                  onTap: () {},
                ),
              ),
              const SizedBox(width: N360Space.sm + 2),
              Expanded(
                child: StatTile(
                  title: c.t('Structural work', 'स्ट्रक्चरल काम'),
                  value: '42%',
                  caption: '26 May, 2026',
                  emphasized: true,
                  onTap: () {},
                ),
              ),
            ],
          ),
          const SizedBox(height: N360Space.sm + 2),
          LedgerCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    IconTile(icon: Icons.calendar_month_outlined, semanticLabel: c.t('Date', 'तारीख')),
                    const SizedBox(width: N360Space.md),
                    Expanded(child: Text('28.10.2026', style: N360Type.heading.copyWith(fontSize: 19))),
                    IconTile(icon: Icons.north_east, semanticLabel: c.t('Open', 'उघडा'), onTap: () {}),
                  ],
                ),
                const SizedBox(height: N360Space.sm),
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: <Widget>[
                    _Caption(label: c.t('Unit range', 'युनिट'), value: '2–46', alignEnd: true),
                    const SizedBox(width: N360Space.xl),
                    _Caption(label: c.t('Distance', 'अंतर'), value: '366 m', alignEnd: true),
                  ],
                ),
                const SizedBox(height: N360Space.sm),
                const TickGauge(fraction: 0.62),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The reference's third screen: a list of soft rows with square percents.
class _InstalledPage extends StatelessWidget {
  const _InstalledPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    final List<(String, String, double)> rows = <(String, String, double)>[
      (c.t('Design phase', 'डिझाइन टप्पा'), c.t('May 2026 – Mar 2027', 'मे 2026 – मार्च 2027'), 0.9),
      (c.t('Electrical systems', 'विद्युत प्रणाली'), c.t('Apr 2026 – Mar 2027', 'एप्रिल 2026 – मार्च 2027'), 0.85),
      (c.t('Fire safety', 'अग्निसुरक्षा'), c.t('Mar 2026 – Mar 2027', 'मार्च 2026 – मार्च 2027'), 0.75),
      (c.t('Plumbing', 'प्लंबिंग'), c.t('Mar 2026 – Mar 2027', 'मार्च 2026 – मार्च 2027'), 0.7),
    ];
    return _Page(
      eyebrow: c.t('Site', 'साइट'),
      title: c.t('Planned progress', 'नियोजित प्रगती'),
      children: <Widget>[
        LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Expanded(child: Text(c.t('Installed', 'बसवलेले'), style: N360Type.heading)),
                  Text(c.t('See all', 'सर्व पहा'), style: N360Type.caption),
                ],
              ),
              const SizedBox(height: N360Space.sm),
              for (final (name, dates, f) in rows)
                Padding(
                  padding: const EdgeInsets.only(bottom: N360Space.sm),
                  child: Row(
                    children: <Widget>[
                      IconTile(icon: Icons.add, semanticLabel: name, onTap: () {}),
                      const SizedBox(width: N360Space.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text(name, style: N360Type.bodyStrong),
                            Text(dates, style: N360Type.caption),
                          ],
                        ),
                      ),
                      SquarePercent(fraction: f, label: '${(f * 100).round()}%'),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ArtPage extends StatelessWidget {
  const _ArtPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Home graphic', 'मुख्य चित्र'),
      title: c.t('Building progress', 'इमारतीची प्रगती'),
      children: const <Widget>[
        BuildingProgressArt(fraction: 1),
        SizedBox(height: N360Space.sm),
        Row(
          children: <Widget>[
            Expanded(child: BuildingProgressArt(fraction: 0.0)),
            SizedBox(width: N360Space.sm),
            Expanded(child: BuildingProgressArt(fraction: 0.36)),
          ],
        ),
        SizedBox(height: N360Space.sm),
        Center(child: SizedBox(width: 150, child: BuildingProgressArt(fraction: 0.62))),
      ],
    );
  }
}

class _HomePage extends StatelessWidget {
  const _HomePage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        OfflineBanner(
          label: c.t('Offline', 'ऑफलाइन'),
          detail: c.t('Entries wait on phone.', 'नोंदी फोनवर राहतील.'),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.sm, 0),
          child: Row(
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    SectionLabel(c.t('Kalyani Tower B', 'कल्याणी टॉवर बी')),
                    Text(c.t('Sachin More', 'सचिन मोरे'), style: N360Type.title),
                  ],
                ),
              ),
              SyncPill(
                state: SyncVisualState.pending,
                count: 3,
                label: c.t('waiting', 'बाकी'),
                onTap: () {},
              ),
            ],
          ),
        ),
        const SizedBox(height: N360Space.sm),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: Column(
            children: <Widget>[
              for (final pair in <List<(IconData, String, String?)>>[
                <(IconData, String, String?)>[
                  (Icons.architecture, c.t('Measure', 'मोजमाप'), null),
                  (Icons.assignment_outlined, c.t('Daily report', 'दैनिक अहवाल'), '2'),
                ],
                <(IconData, String, String?)>[
                  (Icons.groups_outlined, c.t('Attendance', 'हजेरी'), null),
                  (Icons.report_problem_outlined, c.t('Issues', 'समस्या'), '5'),
                ],
                <(IconData, String, String?)>[
                  (Icons.account_balance_wallet_outlined, c.t('Petty cash', 'किरकोळ रोख'), null),
                  (Icons.approval_outlined, c.t('Approvals', 'मंजुरी'), '12'),
                ],
              ]) ...<Widget>[
                Row(
                  children: <Widget>[
                    Expanded(child: ActionTile(icon: pair[0].$1, label: pair[0].$2, badge: pair[0].$3, onTap: () {})),
                    const SizedBox(width: N360Space.sm + 2),
                    Expanded(child: ActionTile(icon: pair[1].$1, label: pair[1].$2, badge: pair[1].$3, onTap: () {})),
                  ],
                ),
                const SizedBox(height: N360Space.sm + 2),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _LedgerPage extends StatelessWidget {
  const _LedgerPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Item 2.1 · M25 slab', 'बाब 2.1 · M25 स्लॅब'),
      title: c.t('Footing F12', 'फूटिंग F12'),
      children: <Widget>[
        LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              SheetHeader(
                eyebrow: c.t('Measurement book', 'मोजमाप पुस्तिका'),
                title: c.t('Sixth floor, east', 'सहावा मजला, पूर्व'),
                trailing: RubberStamp(
                  kind: StampKind.queued,
                  label: c.t('On phone', 'फोनवर'),
                  size: StampSize.small,
                ),
              ),
              LedgerRow(label: c.t('Nos', 'संख्या'), value: '2.000'),
              LedgerRow(label: c.t('Length', 'लांबी'), value: '10.000 m'),
              LedgerRow(label: c.t('Breadth', 'रुंदी'), value: '5.000 m'),
              LedgerRow(label: c.t('Depth', 'खोली'), value: '0.500 m', ruled: false),
              const SizedBox(height: N360Space.xs),
              LedgerRow(label: c.t('Quantity', 'परिमाण'), value: '50.000 cum', total: true),
            ],
          ),
        ),
        const SizedBox(height: N360Space.md),
        SectionLabel(c.t('Cash book', 'रोख वही')),
        const SizedBox(height: N360Space.xs),
        LedgerCard(
          child: Row(
            children: <Widget>[
              Expanded(child: Text(c.t('Petty cash balance', 'किरकोळ रोख शिल्लक'), style: N360Type.body)),
              const SizedBox(width: N360Space.sm),
              const RollingMoney(value: '12,500.00', style: N360Type.figureLarge),
            ],
          ),
        ),
        const SizedBox(height: N360Space.md),
        ProgressRule(semanticsLabel: c.t('Syncing', 'पाठवत आहे')),
        const SizedBox(height: N360Space.md),
        ErrorSlip(
          title: c.t('Could not send photo', 'फोटो पाठवता आला नाही'),
          message: c.t(
            'The file is larger than the server allows. It is safe on your phone.',
            'फाइल सर्व्हरच्या मर्यादेपेक्षा मोठी आहे. ती तुमच्या फोनवर सुरक्षित आहे.',
          ),
          actionLabel: c.t('Try again', 'पुन्हा प्रयत्न करा'),
          onAction: () {},
        ),
      ],
    );
  }
}

class _StampsPage extends StatelessWidget {
  const _StampsPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    final List<(StampKind, String)> kinds = <(StampKind, String)>[
      (StampKind.draft, c.t('Draft', 'मसुदा')),
      (StampKind.inReview, c.t('In review', 'तपासणीत')),
      (StampKind.approved, c.t('Approved', 'मंजूर')),
      (StampKind.returned, c.t('Returned', 'परत पाठवले')),
      (StampKind.rejected, c.t('Rejected', 'नाकारले')),
      (StampKind.queued, c.t('On phone', 'फोनवर')),
      (StampKind.synced, c.t('Sent', 'पाठवले')),
    ];
    return _Page(
      eyebrow: c.t('Stamps', 'शिक्के'),
      title: c.t('Status in ink', 'शाईतील स्थिती'),
      children: <Widget>[
        LedgerCard(
          child: Wrap(
            spacing: N360Space.sm,
            runSpacing: N360Space.xs,
            children: <Widget>[
              for (final (kind, label) in kinds) RubberStamp(kind: kind, label: label),
            ],
          ),
        ),
        const SizedBox(height: N360Space.md),
        LedgerCard(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.lg, vertical: N360Space.xs),
          child: Column(
            children: <Widget>[
              LedgerRow(
                label: c.t('DPR · 9 Nov', 'डीपीआर · 9 नोव्हें'),
                hint: c.t('Slab casting, floor 6', 'स्लॅब कास्टिंग, सहावा मजला'),
                valueWidget: RubberStamp(kind: StampKind.approved, label: c.t('Approved', 'मंजूर'), size: StampSize.small),
              ),
              LedgerRow(
                label: c.t('MB 2.1 · 15.000 cum', 'एमबी 2.1 · 15.000 घमी'),
                hint: c.t('Footing F12', 'फूटिंग F12'),
                valueWidget: RubberStamp(kind: StampKind.inReview, label: c.t('In review', 'तपासणीत'), size: StampSize.small),
              ),
              LedgerRow(
                label: c.t('Issue · Formwork', 'समस्या · फॉर्मवर्क'),
                hint: c.t('East wing, grid C', 'पूर्व विंग, ग्रिड C'),
                ruled: false,
                valueWidget: RubberStamp(kind: StampKind.returned, label: c.t('Returned', 'परत पाठवले'), size: StampSize.small),
              ),
            ],
          ),
        ),
        const SizedBox(height: N360Space.sm),
        Center(
          child: RubberStamp(kind: StampKind.approved, label: c.t('Approved', 'मंजूर'), size: StampSize.hero),
        ),
      ],
    );
  }
}

class _ButtonsPage extends StatelessWidget {
  const _ButtonsPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Controls', 'नियंत्रणे'),
      title: c.t('For gloved hands', 'हातमोज्यांसाठी'),
      children: <Widget>[
        GloveButton(
          label: c.t('Save and submit', 'जतन करा आणि पाठवा'),
          icon: Icons.check,
          fullWidth: true,
          large: true,
          onPressed: () {},
        ),
        const SizedBox(height: N360Space.md),
        Row(
          children: <Widget>[
            Expanded(
              child: GloveButton(
                label: c.t('Return', 'परत पाठवा'),
                kind: GloveButtonKind.secondary,
                icon: Icons.undo,
                fullWidth: true,
                onPressed: () {},
              ),
            ),
            const SizedBox(width: N360Space.sm),
            Expanded(
              child: GloveButton(
                label: c.t('Reject', 'नाकारा'),
                kind: GloveButtonKind.danger,
                icon: Icons.close,
                fullWidth: true,
                onPressed: () {},
              ),
            ),
          ],
        ),
        const SizedBox(height: N360Space.md),
        GloveButton(
          label: c.t('Sending to office', 'कार्यालयाकडे पाठवत आहे'),
          loading: true,
          fullWidth: true,
          onPressed: () {},
        ),
        const SizedBox(height: N360Space.md),
        Row(
          children: <Widget>[
            Expanded(
              child: GloveButton(
                label: c.t('Attach bill', 'बिल जोडा'),
                kind: GloveButtonKind.ghost,
                icon: Icons.camera_alt_outlined,
                fullWidth: true,
                onPressed: () {},
              ),
            ),
            const SizedBox(width: N360Space.sm),
            Expanded(
              child: GloveButton(label: c.t('Unavailable', 'उपलब्ध नाही'), fullWidth: true, onPressed: null),
            ),
          ],
        ),
        const SizedBox(height: N360Space.md),
        GloveStepper(
          value: 12,
          onChanged: (_) {},
          label: c.t('Masons on site today', 'आज साइटवर गवंडी'),
          icon: Icons.foundation,
        ),
        const SizedBox(height: N360Space.md),
        GloveTextField(
          label: c.t('Where on site?', 'साइटवर कोठे?'),
          hint: c.t('Grid C, east wing', 'ग्रिड C, पूर्व विंग'),
          helper: c.t('Say which wing or grid line.', 'कोणती विंग किंवा ग्रिड लाइन ते लिहा.'),
        ),
        const SizedBox(height: N360Space.md),
        GloveTextField(
          label: c.t('Amount', 'रक्कम'),
          errorText: c.t('Enter an amount above zero', 'शून्यापेक्षा जास्त रक्कम भरा'),
        ),
      ],
    );
  }
}

class _EntryPage extends StatefulWidget {
  const _EntryPage(this.c);

  final _Copy c;

  @override
  State<_EntryPage> createState() => _EntryPageState();
}

class _EntryPageState extends State<_EntryPage> {
  final List<String> _values = <String>['2', '10.000', '5.500', ''];
  int _active = 3;

  @override
  Widget build(BuildContext context) {
    final _Copy c = widget.c;
    final List<String> labels = <String>['Nos', '× L', '× B', '× D'];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, 0),
          child: SheetHeader(
            eyebrow: c.t('Item 2.1 · cum', 'बाब 2.1 · घमी'),
            title: c.t('New measurement', 'नवीन मोजमाप'),
            trailing: RubberStamp(kind: StampKind.draft, label: c.t('Draft', 'मसुदा'), size: StampSize.small),
          ),
        ),
        const SizedBox(height: N360Space.sm),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: Row(
            children: <Widget>[
              for (var i = 0; i < 4; i++) ...<Widget>[
                if (i > 0) const SizedBox(width: 6),
                Expanded(
                  child: DimensionTile(
                    label: labels[i],
                    unit: i == 0 ? null : 'm',
                    value: _values[i],
                    active: _active == i,
                    onTap: () => setState(() => _active = i),
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: N360Space.md),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: LedgerCard(
            child: Row(
              children: <Widget>[
                Expanded(child: Text(c.t('Quantity', 'परिमाण'), style: N360Type.heading)),
                const RollingQty(value: '110.000', unit: 'cum', style: N360Type.figureLarge),
              ],
            ),
          ),
        ),
        const SizedBox(height: N360Space.md),
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.gutter),
          child: GloveKeypad(
            value: _values[_active],
            onChanged: (v) => setState(() => _values[_active] = v),
            onNext: () => setState(() => _active = (_active + 1) % 4),
            nextLabel: c.t('Next', 'पुढे'),
            backspaceLabel: c.t('Delete', 'हटवा'),
          ),
        ),
      ],
    );
  }
}

class _MusterPage extends StatelessWidget {
  const _MusterPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    final List<(String, String, HajiriValue?, IconData)> workers = <(String, String, HajiriValue?, IconData)>[
      (c.t('Ramesh Pawar', 'रमेश पवार'), c.t('Mason', 'गवंडी'), HajiriValue.present, N360Pictograms.trade('Mason').icon),
      (c.t('Sunil Kamble', 'सुनील कांबळे'), c.t('Helper', 'मदतनीस'), HajiriValue.half, N360Pictograms.trade('Helper').icon),
      (c.t('Dinesh Patil', 'दिनेश पाटील'), c.t('Bar bender', 'बार बेंडर'), HajiriValue.overtime, N360Pictograms.trade('Bar bender').icon),
      (c.t('Mahesh Yadav', 'महेश यादव'), c.t('Carpenter', 'सुतार'), HajiriValue.absent, N360Pictograms.trade('Carpenter').icon),
      (c.t('Prakash More', 'प्रकाश मोरे'), c.t('Electrician', 'इलेक्ट्रिशियन'), null, N360Pictograms.trade('Electrician').icon),
    ];
    return _Page(
      eyebrow: c.t('Muster roll · 9 Nov', 'हजेरी · 9 नोव्हें'),
      title: c.t("Today's attendance", 'आजची हजेरी'),
      children: <Widget>[
        LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              for (final (name, trade, value, icon) in workers) ...<Widget>[
                Row(
                  children: <Widget>[
                    Icon(icon, size: 22, color: N360Colors.inkLabel),
                    const SizedBox(width: N360Space.sm),
                    Expanded(
                      child: Text.rich(
                        TextSpan(
                          text: name,
                          style: N360Type.bodyStrong,
                          children: <InlineSpan>[
                            TextSpan(text: '   $trade', style: N360Type.caption),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: N360Space.xs),
                HajiriSegment(value: value, onChanged: (_) {}),
                const SizedBox(height: N360Space.md),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _ChoicesPage extends StatelessWidget {
  const _ChoicesPage(this.c);

  final _Copy c;

  String _l(Pictogram p) => lookupAppLocalizations(Locale(c.mr ? 'mr' : 'en')).pictogramLabel(p);

  Widget _choice(String label, List<Pictogram> options, String value, int columns) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SectionLabel(label),
        const SizedBox(height: N360Space.xs),
        PictoChoice<String>(
          value: value,
          columns: columns,
          onChanged: (_) {},
          options: <PictoOption<String>>[
            for (final p in options) PictoOption<String>(value: p.key, icon: p.icon, label: _l(p)),
          ],
        ),
        const SizedBox(height: N360Space.md),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Choices', 'पर्याय'),
      title: c.t('Pictures first', 'आधी चित्र'),
      children: <Widget>[
        _choice(c.t('Weather', 'हवामान'), N360Pictograms.weatherKinds, 'Rain', 5),
        _choice(c.t('Priority', 'प्राधान्य'), N360Pictograms.issuePriorities, 'high', 4),
        _choice(c.t('Expense', 'खर्च'), N360Pictograms.expenseCategories, 'Fuel', 4),
        _choice(c.t('Trade', 'काम'), N360Pictograms.trades, 'Bar bender', 4),
      ],
    );
  }
}

class _OutboxPage extends StatelessWidget {
  const _OutboxPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Outbox', 'आउटबॉक्स'),
      title: c.t('Slips in the tray', 'ट्रेमधील स्लिपा'),
      children: <Widget>[
        ProgressRule(semanticsLabel: c.t('Syncing', 'पाठवत आहे')),
        const SizedBox(height: N360Space.sm),
        Wrap(
          spacing: N360Space.sm,
          runSpacing: 0,
          children: <Widget>[
            SyncPill(state: SyncVisualState.synced, label: c.t('All sent', 'सर्व पाठवले'), onTap: () {}),
            SyncPill(state: SyncVisualState.pending, count: 7, label: c.t('waiting', 'बाकी'), onTap: () {}),
            SyncPill(state: SyncVisualState.syncing, count: 4, label: c.t('sending', 'पाठवत आहे'), onTap: () {}),
            SyncPill(state: SyncVisualState.offline, label: c.t('Offline', 'ऑफलाइन'), onTap: () {}),
            SyncPill(state: SyncVisualState.attention, count: 1, label: c.t('needs a look', 'तपासा'), onTap: () {}),
          ],
        ),
        const SizedBox(height: N360Space.sm),
        SlipStack(
          count: 8,
          trayLabel: c.t('on phone', 'फोनवर'),
          slips: <SlipData>[
            SlipData(
              id: 'b',
              title: c.t('Daily report', 'दैनिक अहवाल'),
              subtitle: c.t('9 Nov · 3 photos', '9 नोव्हें · 3 फोटो'),
              status: SlipStatus.sending,
              statusLabel: c.t('Sending', 'पाठवत आहे'),
              icon: Icons.assignment_outlined,
            ),
            SlipData(
              id: 'c',
              title: c.t('Fuel ₹2,450.00', 'इंधन ₹2,450.00'),
              status: SlipStatus.waiting,
              statusLabel: c.t('Waiting', 'बाकी'),
              icon: Icons.local_gas_station_outlined,
            ),
            SlipData(
              id: 'd',
              title: c.t('Attendance, 12 workers', 'हजेरी, 12 कामगार'),
              status: SlipStatus.attention,
              statusLabel: c.t('Needs a look', 'तपासा'),
              icon: Icons.groups_outlined,
            ),
          ],
        ),
      ],
    );
  }
}

class _MomentsPage extends StatelessWidget {
  const _MomentsPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Feedback', 'सूचना'),
      title: c.t('Messages', 'संदेश'),
      children: <Widget>[
        PaperToast(message: c.t('Saved on phone', 'फोनवर जतन केले'), kind: PaperToastKind.success),
        const SizedBox(height: N360Space.md),
        PaperToast(
          message: c.t('Not sent yet. It will go when signal returns.', 'अजून पाठवले नाही. नेटवर्क आल्यावर जाईल.'),
          kind: PaperToastKind.warning,
        ),
        const SizedBox(height: N360Space.sm),
        EmptyLedger(
          title: c.t('No measurements yet', 'अजून मोजमाप नाही'),
          message: c.t('Your first entry starts this book.', 'तुमची पहिली नोंद या पुस्तिकेची सुरुवात करेल.'),
          action: GloveButton(label: c.t('Add measurement', 'मोजमाप जोडा'), icon: Icons.add, onPressed: () {}),
        ),
      ],
    );
  }
}

class _TypePage extends StatelessWidget {
  const _TypePage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Type', 'टंक'),
      title: c.t('Headings and figures', 'शीर्षके आणि आकडे'),
      children: <Widget>[
        Text(c.t('Daily site report', 'दैनिक साइट अहवाल'), style: N360Type.display),
        Text(c.t('Slab casting, floor 6', 'स्लॅब कास्टिंग, सहावा मजला'), style: N360Type.heading),
        const SizedBox(height: N360Space.sm),
        Text(
          c.t(
            'Concrete reached the east wing at 11:40. Rebar checked and passed by the structural engineer.',
            'पूर्व विंगमध्ये काँक्रीट 11:40 ला पोहोचले. स्ट्रक्चरल इंजिनिअरने सळई तपासून मंजूर केली.',
          ),
          style: N360Type.body,
        ),
        const SizedBox(height: N360Space.md),
        Text('₹1,23,456.50', style: N360Type.figureHero.copyWith(fontSize: 36)),
        Text('620.000 cum', style: N360Type.figureLarge),
        Text('2 × 10.000 × 5.000', style: N360Type.figure),
        const SizedBox(height: N360Space.md),
        const _Swatches(),
      ],
    );
  }
}

class _Swatches extends StatelessWidget {
  const _Swatches();

  @override
  Widget build(BuildContext context) {
    const List<Color> colours = <Color>[
      N360Colors.ink,
      N360Colors.ink2,
      N360Colors.inkLabel,
      N360Colors.laterite,
      N360Colors.orange,
      N360Colors.amber,
      N360Colors.peach,
      N360Colors.moss,
    ];
    return Row(
      children: <Widget>[
        for (final colour in colours)
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(right: 4),
              child: SizedBox(
                height: 34,
                child: DecoratedBox(
                  decoration: BoxDecoration(color: colour, borderRadius: N360Radius.sm),
                ),
              ),
            ),
          ),
      ],
    );
  }
}
