import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api/nirmaan_api.dart';
import '../../core/config/app_config.dart';
import '../../core/session/current_user.dart';

/// Hands [params] to the phone's share sheet.
typedef ShareOut = Future<void> Function(ShareParams params);

/// The website's DPR link, as `POST /dprs/{id}/share-link` answers it.
@immutable
class DprShareLink {
  const DprShareLink({required this.url, required this.text, required this.linkDays});

  factory DprShareLink.fromJson(Map<String, dynamic> json) => DprShareLink(
    url: json['url'] as String,
    text: (json['text'] as String?) ?? json['url'] as String,
    linkDays: (json['link_days'] as num?)?.toInt() ?? 7,
  );

  /// The signed `/shared/dprs/{id}` link: it opens that one PDF and nothing else.
  final String url;

  /// The website's WhatsApp message, ending in [url].
  final String text;

  /// How many days the link works.
  final int linkDays;
}

/// The DPR PDF, its WhatsApp link and the measurement sheet, taken from the
/// server and handed to another app. Online only: the files are made on the
/// server, written to the app's temporary folder ([AppConfig.reportFolder],
/// cleared at sign-out) and never to the database.
class ReportShare {
  ReportShare(this._api);

  final NirmaanApi _api;

  /// Where the files go. Tests point it at their own folder.
  @visibleForTesting
  static Future<String> Function() tempDirectory = () async => (await getTemporaryDirectory()).path;

  /// How things leave the app. Tests swap it to see what was shared.
  @visibleForTesting
  static ShareOut shareOut = (ShareParams params) async {
    await SharePlus.instance.share(params);
  };

  /// Puts both seams back, for a test's tear-down.
  @visibleForTesting
  static void resetForTesting() {
    tempDirectory = () async => (await getTemporaryDirectory()).path;
    shareOut = (ShareParams params) async {
      await SharePlus.instance.share(params);
    };
  }

  /// The website shows these buttons only on a plan with reports. A contractor
  /// login never reaches DPRs or the measurement book.
  static bool allowed(CurrentUser? user, String permission) =>
      user != null && !user.isContractor && user.features.contains('reports') && user.can(permission);

  /// Downloads the DPR's PDF and offers it to another app.
  Future<void> shareDprPdf({required int dprId, required String reportDate, String? subject, Rect? origin}) async {
    final Uint8List bytes = await _api.dprPdfBytes(dprId);
    final File file = await _write('DPR-$reportDate-$dprId.pdf', bytes);
    await shareOut(
      ShareParams(
        files: <XFile>[XFile(file.path, mimeType: 'application/pdf')],
        fileNameOverrides: <String>[p.basename(file.path)],
        subject: subject,
        sharePositionOrigin: origin,
      ),
    );
  }

  /// Asks for the website's signed link and offers its message, link and all,
  /// to another app (WhatsApp first on most phones).
  Future<DprShareLink> shareDprLink({required int dprId, String? locale, String? subject, Rect? origin}) async {
    final DprShareLink link = DprShareLink.fromJson(await _api.dprShareLink(dprId, locale: locale));
    await shareOut(ShareParams(text: link.text, subject: subject, sharePositionOrigin: origin));
    return link;
  }

  /// Downloads the measurement sheet for [from] to [to] (`YYYY-MM-DD`) and
  /// offers it to another app.
  Future<void> shareMeasurementSheet({
    required int projectId,
    required String from,
    required String to,
    String? subject,
    Rect? origin,
  }) async {
    final Uint8List bytes = await _api.measurementSheetBytes(projectId, from: from, to: to);
    final String name = 'measurement-sheet-$projectId-${from.replaceAll('-', '')}-${to.replaceAll('-', '')}.pdf';
    final File file = await _write(name, bytes);
    await shareOut(
      ShareParams(
        files: <XFile>[XFile(file.path, mimeType: 'application/pdf')],
        fileNameOverrides: <String>[name],
        subject: subject,
        sharePositionOrigin: origin,
      ),
    );
  }

  Future<File> _write(String name, Uint8List bytes) async {
    final Directory dir = Directory(p.join(await tempDirectory(), AppConfig.reportFolder));
    await dir.create(recursive: true);
    final String safe = name.replaceAll(RegExp(r'[^A-Za-z0-9._-]+'), '-');
    final File file = File(p.join(dir.path, safe));
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }
}

/// Where the share sheet should point from on a tablet: the widget that was
/// tapped. iPads need it; phones ignore it.
Rect? shareOriginOf(BuildContext context) {
  final RenderObject? box = context.findRenderObject();
  if (box is! RenderBox || !box.hasSize) return null;
  return box.localToGlobal(Offset.zero) & box.size;
}
