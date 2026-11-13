// What the document readers (the AI that reads a photographed page) answer.
//
// Every shape here mirrors the server on branch feat/ai-features:
// app/Services/DocumentReading/{ReaderAvailability,ReadStatus,ReadQuote}.php and
// app/Http/Controllers/DocumentReading/DocumentReadController.php. See
// docs/CONTRACT.md, "Document readers". Credits stay strings, as the server
// sends them (four places after the point), so nothing is rounded on the phone.

/// The AI features the app asks about, by the name the server uses in `can`.
abstract final class ReaderFeature {
  /// Scanned or photographed BOQ pages (F1).
  static const String boqScan = 'boq_scan';

  /// A delivery challan or weighbridge slip on the material form (F2).
  static const String slipChallan = 'slip_challan';

  /// Spoken notes turned into a daily report draft (F8).
  static const String voiceDpr = 'voice_dpr';
}

/// Whether the company has agreed to the current notice for sending documents.
class ReaderConsent {
  const ReaderConsent({required this.enabled, required this.version, required this.current});

  factory ReaderConsent.fromJson(Map<String, dynamic> json) => ReaderConsent(
    enabled: json['enabled'] == true,
    version: json['version'] as String?,
    current: json['current'] == true,
  );

  static const ReaderConsent none = ReaderConsent(enabled: false, version: null, current: false);

  final bool enabled;
  final String? version;

  /// Switched on, and at the notice's current version.
  final bool current;
}

/// `GET /document-reading/status`: whether any AI button may be shown to this
/// person, and which. The server answers it on every plan, so the app can tell
/// why a reader is not offered.
class ReaderAvailability {
  const ReaderAvailability({
    required this.released,
    required this.connected,
    required this.plan,
    required this.consent,
    required this.can,
    required this.creditsFree,
    required this.voiceOnDeviceOnly,
  });

  factory ReaderAvailability.fromJson(Map<String, dynamic> json) {
    final Object? can = json['can'];
    final Object? consent = json['consent'];
    final Object? voice = json['voice'];
    return ReaderAvailability(
      released: json['released'] == true,
      connected: json['connected'] == true,
      plan: json['plan'] == true,
      consent: consent is Map ? ReaderConsent.fromJson(consent.cast<String, dynamic>()) : ReaderConsent.none,
      can: can is Map
          ? <String, bool>{for (final MapEntry<dynamic, dynamic> entry in can.entries) '${entry.key}': entry.value == true}
          : const <String, bool>{},
      creditsFree: json['credits_free']?.toString(),
      // Fails closed: unless the server says speech stays on the phone, voice is not offered.
      voiceOnDeviceOnly: voice is Map && voice['on_device_only'] == true,
    );
  }

  /// Nothing offered. What the app assumes before it has asked, or when it cannot ask.
  static const ReaderAvailability off = ReaderAvailability(
    released: false,
    connected: false,
    plan: false,
    consent: ReaderConsent.none,
    can: <String, bool>{},
    creditsFree: null,
    voiceOnDeviceOnly: false,
  );

  /// The platform has released the readers for this company.
  final bool released;

  /// A key is saved and the provider is taking calls.
  final bool connected;

  /// The company's plan includes the AI features.
  final bool plan;
  final ReaderConsent consent;

  /// Per feature, by [ReaderFeature] name: may this person use it.
  final Map<String, bool> can;

  /// The company's free credits, only for people who may open the credits page.
  final String? creditsFree;
  final bool voiceOnDeviceOnly;

  /// Whether to show the button for [feature]. A company that has not agreed to
  /// the notice still sees it; starting a read then answers `consent_required`.
  bool offers(String feature) => released && connected && plan && (can[feature] ?? false);

  /// The button is shown, but the company must agree to the notice first.
  bool needsConsent(String feature) => offers(feature) && !consent.current;
}

/// A read's status values, as `document_reads.status`.
abstract final class ReadState {
  static const String draft = 'draft';
  static const String preparing = 'preparing';
  static const String prepared = 'prepared';
  static const String quoted = 'quoted';
  static const String running = 'running';
  static const String paused = 'paused';
  static const String ready = 'ready';
  static const String failed = 'failed';
  static const String confirmed = 'confirmed';
  static const String discarded = 'discarded';
  static const String cancelled = 'cancelled';
  static const String expired = 'expired';

  /// The server is working on the read: watch it.
  static const Set<String> working = <String>{preparing, running};

  /// Nothing more will happen to the read.
  static const Set<String> closed = <String>{confirmed, discarded, cancelled, expired};
}

/// Which part of a page a picture holds. A cut-off page is read again as two
/// halves that overlap.
enum ReadPagePart {
  full('full'),
  top('top'),
  bottom('bottom');

  const ReadPagePart(this.wire);

  final String wire;
}

/// A page that failed, and what can be done about it.
class FailedReadPage {
  const FailedReadPage({
    required this.id,
    required this.pageNo,
    required this.pageTo,
    required this.pass,
    required this.part,
    required this.reason,
    required this.canRetry,
    required this.canSplit,
    required this.canStronger,
    required this.needsPicture,
  });

  factory FailedReadPage.fromJson(Map<String, dynamic> json) => FailedReadPage(
    id: (json['id'] as num).toInt(),
    pageNo: (json['page_no'] as num).toInt(),
    pageTo: (json['page_to'] as num?)?.toInt(),
    pass: json['pass'] as String? ?? 'main',
    part: json['part'] as String? ?? ReadPagePart.full.wire,
    reason: json['reason'] as String?,
    canRetry: json['can_retry'] == true,
    canSplit: json['can_split'] == true,
    canStronger: json['can_stronger'] == true,
    needsPicture: json['needs_picture'] == true,
  );

  /// The page row, for `retry` and `split`.
  final int id;
  final int pageNo;

  /// The last page of a run of text pages; null for a picture.
  final int? pageTo;
  final String pass;
  final String part;

  /// Why it failed, such as `cut_off`, `timeout` or `invalid_output`.
  final String? reason;

  /// It may be read again.
  final bool canRetry;

  /// It may be read again as two halves.
  final bool canSplit;

  /// It may be read again with the stronger model.
  final bool canStronger;
  final bool needsPicture;
}

/// A page left out when the read was started, that can still be read.
class LeftOutReadPage {
  const LeftOutReadPage({required this.id, required this.pageNo});

  factory LeftOutReadPage.fromJson(Map<String, dynamic> json) =>
      LeftOutReadPage(id: (json['id'] as num).toInt(), pageNo: (json['page_no'] as num).toInt());

  final int id;
  final int pageNo;
}

/// `GET /document-reads/{read}`, and the answer to start, resume, retry, split
/// and cancel: where a read stands.
class DocumentReadStatus {
  const DocumentReadStatus({
    required this.id,
    required this.kind,
    required this.status,
    required this.pauseReason,
    required this.error,
    required this.message,
    required this.pagesTotal,
    required this.pagesRead,
    required this.pagesFailed,
    required this.pagesLeft,
    required this.storedPages,
    required this.creditsUsed,
    required this.creditsBurned,
    required this.creditsCap,
    required this.etaMinutes,
    required this.stalled,
    required this.canCancel,
    required this.canResume,
    required this.failedPages,
    required this.leftOutPages,
  });

  factory DocumentReadStatus.fromJson(Map<String, dynamic> json) {
    List<Map<String, dynamic>> rows(Object? value) => value is List
        ? <Map<String, dynamic>>[for (final Object? row in value) if (row is Map) row.cast<String, dynamic>()]
        : const <Map<String, dynamic>>[];
    final Object? stored = json['stored_pages'];
    return DocumentReadStatus(
      id: (json['id'] as num).toInt(),
      kind: json['kind'] as String? ?? '',
      status: json['status'] as String? ?? ReadState.draft,
      pauseReason: json['pause_reason'] as String?,
      error: json['error'] as String?,
      message: json['message'] as String?,
      pagesTotal: (json['pages_total'] as num?)?.toInt() ?? 0,
      pagesRead: (json['pages_read'] as num?)?.toInt() ?? 0,
      pagesFailed: (json['pages_failed'] as num?)?.toInt() ?? 0,
      pagesLeft: (json['pages_left'] as num?)?.toInt() ?? 0,
      storedPages: stored is List ? <int>[for (final Object? n in stored) if (n is num) n.toInt()] : const <int>[],
      creditsUsed: json['credits_used']?.toString() ?? '0',
      creditsBurned: json['credits_burned']?.toString() ?? '0',
      creditsCap: json['credits_cap']?.toString() ?? '0',
      etaMinutes: (json['eta_minutes'] as num?)?.toInt(),
      stalled: json['stalled'] == true,
      canCancel: json['can_cancel'] == true,
      canResume: json['can_resume'] == true,
      failedPages: rows(json['failed_pages']).map(FailedReadPage.fromJson).toList(),
      leftOutPages: rows(json['left_out_pages']).map(LeftOutReadPage.fromJson).toList(),
    );
  }

  final int id;

  /// `boq`, `slip`, `bill` and so on: which feature's read this is.
  final String kind;

  /// One of [ReadState].
  final String status;

  /// Why a paused read stopped, such as `credit_cap` or `many_failures`.
  final String? pauseReason;
  final String? error;

  /// The server's sentence for a pause or an ending that was not a reading.
  final String? message;
  final int pagesTotal;
  final int pagesRead;
  final int pagesFailed;
  final int pagesLeft;

  /// Pages whose full picture the server holds. An interrupted upload resumes
  /// by sending the pages missing from this list.
  final List<int> storedPages;
  final String creditsUsed;
  final String creditsBurned;
  final String creditsCap;

  /// A guide while running; null otherwise.
  final int? etaMinutes;

  /// Running for a while and nothing sent to the reader yet: cancelling costs nothing.
  final bool stalled;
  final bool canCancel;
  final bool canResume;
  final List<FailedReadPage> failedPages;
  final List<LeftOutReadPage> leftOutPages;

  /// The server is working on it, so the phone keeps asking.
  bool get isWorking => ReadState.working.contains(status);

  /// Ready to check, or ended with nothing read.
  bool get isFinished => status == ReadState.ready || status == ReadState.failed;

  bool get isPaused => status == ReadState.paused;

  bool get isClosed => ReadState.closed.contains(status);

  /// Pages up to [count] whose picture still has to be sent.
  List<int> missingPages(int count) => <int>[
    for (int page = 1; page <= count; page++)
      if (!storedPages.contains(page)) page,
  ];
}

/// `POST /document-reads/{read}/pages`: the page picture as the server stored it.
class StoredReadPage {
  const StoredReadPage({
    required this.id,
    required this.pageNo,
    required this.part,
    required this.width,
    required this.height,
    required this.tokens,
  });

  factory StoredReadPage.fromJson(Map<String, dynamic> json) => StoredReadPage(
    id: (json['id'] as num?)?.toInt(),
    pageNo: (json['page_no'] as num).toInt(),
    part: json['part'] as String? ?? ReadPagePart.full.wire,
    width: (json['width'] as num?)?.toInt() ?? 0,
    height: (json['height'] as num?)?.toInt() ?? 0,
    tokens: (json['tokens'] as num?)?.toInt() ?? 0,
  );

  final int? id;
  final int pageNo;
  final String part;

  /// After the server turned it upright and resized it.
  final int width;
  final int height;

  /// What the picture costs the reader to look at, in tokens.
  final int tokens;
}

/// `POST /document-reads/{read}/quote`: the price before anything is sent.
class ReadQuote {
  const ReadQuote({
    required this.pages,
    required this.estimate,
    required this.cap,
    required this.capFloor,
    required this.companyMax,
    required this.rupees,
    required this.minutes,
    required this.free,
    required this.enough,
    required this.pagesThatFit,
    required this.basis,
  });

  factory ReadQuote.fromJson(Map<String, dynamic> json) => ReadQuote(
    pages: (json['pages'] as num?)?.toInt() ?? 0,
    estimate: json['estimate']?.toString() ?? '0',
    cap: json['cap']?.toString() ?? '0',
    capFloor: json['cap_floor']?.toString() ?? '0',
    companyMax: json['company_max']?.toString() ?? '0',
    rupees: json['rupees']?.toString(),
    minutes: (json['minutes'] as num?)?.toInt() ?? 0,
    free: json['free']?.toString(),
    enough: json['enough'] == true,
    pagesThatFit: (json['pages_that_fit'] as num?)?.toInt() ?? 0,
    basis: json['basis'] as String? ?? 'default',
  );

  final int pages;

  /// Credits the read is expected to use.
  final String estimate;

  /// The suggested most the read may use; sent back with `start`.
  final String cap;

  /// The lowest cap the server accepts.
  final String capFloor;
  final String companyMax;

  /// The estimate in rupees, only for people who may open the credits page.
  final String? rupees;
  final int minutes;

  /// Free credits, for the same people. Everyone else relies on [enough].
  final String? free;
  final bool enough;
  final int pagesThatFit;

  /// `default` until the server has measured enough reads of this kind.
  final String basis;
}
