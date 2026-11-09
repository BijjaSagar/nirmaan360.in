import 'package:flutter/material.dart';

import '../../../l10n/generated/app_localizations.dart';
import '../design.dart';

/// One page of the gallery. Each is laid out for a 360dp phone.
enum GallerySection {
  page,
  type,
  stamps,
  ledger,
  buttons,
  entry,
  muster,
  home,
  choices,
  outbox,
  moments,
}

/// Every component on one scrollable sheet, used to judge the system by eye.
/// Pass [section] to show a single page, as the screenshot test does.
class DesignGallery extends StatelessWidget {
  const DesignGallery({super.key, this.section, this.marathi = false});

  final GallerySection? section;
  final bool marathi;

  _Copy get _c => _Copy(marathi);

  Widget _build(BuildContext context, GallerySection s) => switch (s) {
        GallerySection.page => _SheetPage(_c),
        GallerySection.type => _TypePage(_c),
        GallerySection.stamps => _StampsPage(_c),
        GallerySection.ledger => _LedgerPage(_c),
        GallerySection.buttons => _ButtonsPage(_c),
        GallerySection.entry => _EntryPage(_c),
        GallerySection.muster => _MusterPage(_c),
        GallerySection.home => _HomePage(_c),
        GallerySection.choices => _ChoicesPage(_c),
        GallerySection.outbox => _OutboxPage(_c),
        GallerySection.moments => _MomentsPage(_c),
      };

  @override
  Widget build(BuildContext context) {
    final GallerySection? single = section;
    if (single != null) {
      final Widget body = _build(context, single);
      return Scaffold(
        body: SafeArea(
          child: single == GallerySection.page ? body : SingleChildScrollView(child: body),
        ),
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
          const SizedBox(height: N360Space.md),
          ...children,
        ],
      ),
    );
  }
}

/// A whole page in the app's voice: double red margin, line numbers beside it,
/// ledger figures ruled off on the right.
class _SheetPage extends StatelessWidget {
  const _SheetPage(this.c);

  final _Copy c;

  @override
  Widget build(BuildContext context) {
    final List<(String, String, String)> lines = <(String, String, String)>[
      (c.t('Excavation in earth', 'मातीतील खोदकाम'), 'F1 – F8', '42.500'),
      (c.t('PCC 1:4:8 below footings', 'फूटिंगखाली पीसीसी १:४:८'), 'F1 – F8', '6.750'),
      (c.t('M25 slab, sixth floor', 'एम२५ स्लॅब, सहावा मजला'), c.t('East wing', 'पूर्व विंग'), '15.000'),
      (c.t('Fe-500 reinforcement', 'फे-५०० सळई'), c.t('Grid C', 'ग्रिड C'), '1.240'),
      (c.t('Brick masonry, 230 mm', 'विटांचे बांधकाम, २३० मिमी'), c.t('Floor 5', 'पाचवा मजला'), '28.300'),
    ];
    return LedgerPaper(
      marginRule: true,
      child: Padding(
        padding: const EdgeInsets.only(left: N360Space.marginText, right: N360Space.gutter, top: N360Space.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            SheetHeader(
              eyebrow: c.t('MB-04 · Folio 12', 'एमबी-०४ · पान १२'),
              title: c.t('Kalyani Tower B', 'कल्याणी टॉवर बी'),
              trailing: RubberStamp(
                kind: StampKind.inReview,
                label: c.t('In review', 'तपासणीत'),
                size: StampSize.small,
              ),
            ),
            const SizedBox(height: N360Space.sm),
            for (var i = 0; i < lines.length; i++)
              StaggeredRise(
                index: i,
                child: _MarginedLine(number: '${i + 1}', line: lines[i]),
              ),
            const SizedBox(height: N360Space.xs),
            LedgerRow(
              label: c.t('Total this folio', 'या पानाची बेरीज'),
              valueWidget: const RollingQty(value: '93.790', unit: 'cum'),
              total: true,
            ),
            const SizedBox(height: N360Space.lg),
            GloveButton(
              label: c.t('Send for approval', 'मंजुरीसाठी पाठवा'),
              icon: Icons.approval,
              fullWidth: true,
              large: true,
              onPressed: () {},
            ),
          ],
        ),
      ),
    );
  }
}

class _MarginedLine extends StatelessWidget {
  const _MarginedLine({required this.number, required this.line});

  final String number;
  final (String, String, String) line;

  @override
  Widget build(BuildContext context) {
    final (title, place, qty) = line;
    return Stack(
      clipBehavior: Clip.none,
      children: <Widget>[
        LedgerRow(label: title, hint: place, value: qty),
        Positioned(
          left: -N360Space.marginText + 8,
          top: 12,
          width: 26,
          child: Text(
            number,
            textAlign: TextAlign.right,
            style: N360Type.figureSmall.copyWith(color: N360Colors.stampRed),
          ),
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
      eyebrow: c.t('MB-04 · Folio 12', 'एमबी-०४ · पान १२'),
      title: c.t('Measurement book', 'मोजमाप पुस्तिका'),
      children: <Widget>[
        Text(c.t('Daily site report', 'दैनिक साइट अहवाल'), style: N360Type.display),
        Text(c.t('Slab casting, floor 6', 'स्लॅब कास्टिंग, सहावा मजला'), style: N360Type.heading),
        const SizedBox(height: N360Space.sm),
        Text(
          c.t(
            'Concrete reached the east wing at 11:40. Rebar checked and passed by the structural engineer.',
            'पूर्व विंगमध्ये काँक्रीट ११:४० ला पोहोचले. स्ट्रक्चरल इंजिनिअरने सळई तपासून मंजूर केली.',
          ),
          style: N360Type.body,
        ),
        const SizedBox(height: N360Space.sm),
        const RuledDivider(),
        const SizedBox(height: N360Space.sm),
        Text('₹1,23,456.50', style: N360Type.figureHero),
        Text('620.000 cum', style: N360Type.figureLarge),
        Text('Nos 2 × 10.000 × 5.000 × 0.500', style: N360Type.figure),
        const SizedBox(height: N360Space.md),
        SectionLabel(c.t('Outdoor colours', 'रंग')),
        const SizedBox(height: N360Space.xs),
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
      N360Colors.stampRed,
      N360Colors.laterite,
      N360Colors.haldi,
      N360Colors.moss,
      N360Colors.neel,
    ];
    return Row(
      children: <Widget>[
        for (final colour in colours)
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(right: 3),
              child: SizedBox(height: 32, child: ColoredBox(color: colour)),
            ),
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
        Wrap(
          spacing: N360Space.md,
          runSpacing: N360Space.xs,
          children: <Widget>[
            for (final (kind, label) in kinds) RubberStamp(kind: kind, label: label),
          ],
        ),
        const SizedBox(height: N360Space.md),
        LedgerCard(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.xs),
          child: Column(
            children: <Widget>[
              LedgerRow(
                label: c.t('DPR · 9 Nov', 'डीपीआर · ९ नोव्हें'),
                hint: c.t('Slab casting, floor 6', 'स्लॅब कास्टिंग, सहावा मजला'),
                valueWidget: RubberStamp(
                  kind: StampKind.approved,
                  label: c.t('Approved', 'मंजूर'),
                  size: StampSize.small,
                ),
              ),
              LedgerRow(
                label: c.t('MB 2.1 · 15.000 cum', 'एमबी २.१ · १५.००० घमी'),
                hint: c.t('Footing F12', 'फूटिंग F12'),
                valueWidget: RubberStamp(
                  kind: StampKind.inReview,
                  label: c.t('In review', 'तपासणीत'),
                  size: StampSize.small,
                ),
              ),
              LedgerRow(
                label: c.t('Issue · Formwork', 'समस्या · फॉर्मवर्क'),
                hint: c.t('East wing, grid C', 'पूर्व विंग, ग्रिड C'),
                ruled: false,
                valueWidget: RubberStamp(
                  kind: StampKind.returned,
                  label: c.t('Returned', 'परत पाठवले'),
                  size: StampSize.small,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: N360Space.sm),
        Center(
          child: RubberStamp(
            kind: StampKind.approved,
            label: c.t('Approved', 'मंजूर'),
            size: StampSize.hero,
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
      eyebrow: c.t('Sheet', 'पान'),
      title: c.t('Ledger surfaces', 'लेजर'),
      children: <Widget>[
        LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              SheetHeader(
                eyebrow: c.t('Item 2.1 · M25 slab', 'बाब २.१ · M25 स्लॅब'),
                title: c.t('Footing F12', 'फूटिंग F12'),
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
              LedgerRow(
                label: c.t('Quantity', 'परिमाण'),
                value: '50.000 cum',
                total: true,
              ),
            ],
          ),
        ),
        const SizedBox(height: N360Space.md),
        SectionLabel(c.t('Cash book', 'रोख वही')),
        LedgerCard(
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: <Widget>[
              Expanded(child: Text(c.t('Petty cash balance', 'किरकोळ रोख शिल्लक'), style: N360Type.body)),
              const SizedBox(width: N360Space.sm),
              const RollingMoney(value: '12,500.00', style: N360Type.figureLarge),
            ],
          ),
        ),
        const SizedBox(height: N360Space.md),
        const RuledDivider(drawIn: true, color: N360Colors.ink, thickness: 1.5),
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
                icon: Icons.camera_alt,
                fullWidth: true,
                onPressed: () {},
              ),
            ),
            const SizedBox(width: N360Space.sm),
            Expanded(
              child: GloveButton(
                label: c.t('Unavailable', 'उपलब्ध नाही'),
                fullWidth: true,
                onPressed: null,
              ),
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
    final List<String> labels = <String>['NOS', '× L', '× B', '× D'];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, 0),
          child: SheetHeader(
            eyebrow: c.t('Item 2.1 · cum', 'बाब २.१ · घमी'),
            title: c.t('New measurement', 'नवीन मोजमाप'),
            trailing: RubberStamp(kind: StampKind.draft, label: c.t('Draft', 'मसुदा'), size: StampSize.small),
          ),
        ),
        const SizedBox(height: N360Space.md),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: Row(
            children: <Widget>[
              for (var i = 0; i < 4; i++) ...<Widget>[
                if (i > 0) const SizedBox(width: 4),
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
            padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.sm),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: <Widget>[
                Text(c.t('Quantity', 'परिमाण'), style: N360Type.heading),
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
      eyebrow: c.t('Muster roll · 9 Nov', 'हजेरी · ९ नोव्हें'),
      title: c.t('Today\'s attendance', 'आजची हजेरी'),
      children: <Widget>[
        for (final (name, trade, value, icon) in workers) ...<Widget>[
          Row(
            children: <Widget>[
              Icon(icon, size: 22, color: N360Colors.ink2),
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
          const SizedBox(height: 2),
          HajiriSegment(value: value, onChanged: (_) {}),
          const SizedBox(height: N360Space.md),
        ],
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
              Row(
                children: <Widget>[
                  Expanded(child: ActionTile(icon: Icons.architecture, label: c.t('Measure', 'मोजमाप'), onTap: () {})),
                  const SizedBox(width: N360Space.sm),
                  Expanded(child: ActionTile(icon: Icons.assignment, label: c.t('Daily report', 'दैनिक अहवाल'), onTap: () {}, badge: '2')),
                ],
              ),
              const SizedBox(height: N360Space.sm),
              Row(
                children: <Widget>[
                  Expanded(child: ActionTile(icon: Icons.groups, label: c.t('Attendance', 'हजेरी'), onTap: () {})),
                  const SizedBox(width: N360Space.sm),
                  Expanded(child: ActionTile(icon: Icons.report_problem_outlined, label: c.t('Issues', 'समस्या'), onTap: () {}, badge: '5')),
                ],
              ),
              const SizedBox(height: N360Space.sm),
              Row(
                children: <Widget>[
                  Expanded(child: ActionTile(icon: Icons.account_balance_wallet, label: c.t('Petty cash', 'किरकोळ रोख'), onTap: () {})),
                  const SizedBox(width: N360Space.sm),
                  Expanded(child: ActionTile(icon: Icons.approval, label: c.t('Approvals', 'मंजुरी'), onTap: () {}, badge: '12')),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: N360Space.md),
        N360BottomBar(
          currentIndex: 0,
          onTap: (_) {},
          items: <N360BarItem>[
            N360BarItem(icon: Icons.home_outlined, label: c.t('Home', 'मुख्य')),
            N360BarItem(icon: Icons.architecture, label: c.t('Measure', 'मोजमाप')),
            N360BarItem(icon: Icons.approval, label: c.t('Approvals', 'मंजुरी')),
            N360BarItem(icon: Icons.inbox_outlined, label: c.t('Outbox', 'आउटबॉक्स')),
          ],
        ),
      ],
    );
  }
}

class _ChoicesPage extends StatelessWidget {
  const _ChoicesPage(this.c);

  final _Copy c;

  String _l(Pictogram p) => lookupAppLocalizations(Locale(c.mr ? 'mr' : 'en')).pictogramLabel(p);

  @override
  Widget build(BuildContext context) {
    return _Page(
      eyebrow: c.t('Choices', 'पर्याय'),
      title: c.t('Pictures first', 'आधी चित्र'),
      children: <Widget>[
        SectionLabel(c.t('Weather', 'हवामान')),
        const SizedBox(height: N360Space.xs),
        PictoChoice<String>(
          value: 'Rain',
          columns: 5,
          onChanged: (_) {},
          options: <PictoOption<String>>[
            for (final p in N360Pictograms.weatherKinds)
              PictoOption<String>(value: p.key, icon: p.icon, label: _l(p)),
          ],
        ),
        const SizedBox(height: N360Space.md),
        SectionLabel(c.t('Priority', 'प्राधान्य')),
        const SizedBox(height: N360Space.xs),
        PictoChoice<String>(
          value: 'high',
          columns: 4,
          onChanged: (_) {},
          options: <PictoOption<String>>[
            for (final p in N360Pictograms.issuePriorities)
              PictoOption<String>(value: p.key, icon: p.icon, label: _l(p)),
          ],
        ),
        const SizedBox(height: N360Space.md),
        SectionLabel(c.t('Expense', 'खर्च')),
        const SizedBox(height: N360Space.xs),
        PictoChoice<String>(
          value: 'Fuel',
          columns: 4,
          onChanged: (_) {},
          options: <PictoOption<String>>[
            for (final p in N360Pictograms.expenseCategories)
              PictoOption<String>(value: p.key, icon: p.icon, label: _l(p)),
          ],
        ),
        const SizedBox(height: N360Space.md),
        SectionLabel(c.t('Trade', 'काम')),
        const SizedBox(height: N360Space.xs),
        PictoChoice<String>(
          value: 'Bar bender',
          columns: 4,
          onChanged: (_) {},
          options: <PictoOption<String>>[
            for (final p in N360Pictograms.trades)
              PictoOption<String>(value: p.key, icon: p.icon, label: _l(p)),
          ],
        ),
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
              id: 'a',
              title: c.t('Measurement 2.1', 'मोजमाप २.१'),
              subtitle: '15.000 cum · 9 Nov',
              status: SlipStatus.sent,
              statusLabel: c.t('Sent', 'पाठवले'),
              icon: Icons.architecture,
            ),
            SlipData(
              id: 'b',
              title: c.t('Daily report', 'दैनिक अहवाल'),
              subtitle: c.t('9 Nov · 3 photos', '९ नोव्हें · ३ फोटो'),
              status: SlipStatus.sending,
              statusLabel: c.t('Sending', 'पाठवत आहे'),
              icon: Icons.assignment,
            ),
            SlipData(
              id: 'c',
              title: c.t('Fuel ₹2,450.00', 'इंधन ₹2,450.00'),
              status: SlipStatus.waiting,
              statusLabel: c.t('Waiting', 'बाकी'),
              icon: Icons.local_gas_station,
            ),
            SlipData(
              id: 'd',
              title: c.t('Attendance, 12 workers', 'हजेरी, १२ कामगार'),
              status: SlipStatus.attention,
              statusLabel: c.t('Needs a look', 'तपासा'),
              icon: Icons.groups,
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
      title: c.t('Messages on paper', 'कागदावर संदेश'),
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
          action: GloveButton(
            label: c.t('Add measurement', 'मोजमाप जोडा'),
            icon: Icons.add,
            onPressed: () {},
          ),
        ),
      ],
    );
  }
}
