import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../core/app_services.dart';
import '../../core/config/app_config.dart';
import '../../core/design/design.dart';
import '../../l10n/generated/app_localizations.dart';

/// The website's terms page.
const String kTermsPath = '/terms';

/// The website's privacy policy.
const String kPrivacyPath = '/privacy';

/// Opens [path] on the website in the phone's browser. When the phone cannot,
/// a sheet shows the address with a button to copy it, so nothing is a dead end.
Future<void> openSitePage(BuildContext context, String path) async {
  final String url = '${AppConfig.siteUrl}$path';
  final bool opened = await context.read<AppServices>().openLink(Uri.parse(url));
  if (opened || !context.mounted) return;
  await showLedgerSheet<void>(
    context,
    isScrollControlled: true,
    builder: (context) => _CopyLinkSheet(url: url),
  );
}

class _CopyLinkSheet extends StatelessWidget {
  const _CopyLinkSheet({required this.url});

  final String url;

  Future<void> _copy(BuildContext context) async {
    final AppLocalizations t = AppLocalizations.of(context);
    await Clipboard.setData(ClipboardData(text: url));
    if (!context.mounted) return;
    showPaperToast(context, t.shellLinkCopied, kind: PaperToastKind.success);
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations t = AppLocalizations.of(context);
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(t.shellLinkFailedTitle, style: N360Type.fit(N360Type.title, t.shellLinkFailedTitle)),
            const SizedBox(height: N360Space.xs),
            Text(
              t.shellLinkFailedBody,
              style: N360Type.fit(N360Type.body, t.shellLinkFailedBody).copyWith(color: N360Colors.ink2),
            ),
            const SizedBox(height: N360Space.sm),
            SelectableText(url, style: N360Type.body),
            const SizedBox(height: N360Space.lg),
            GloveButton(
              label: t.shellCopyLink,
              icon: Icons.copy,
              large: true,
              fullWidth: true,
              onPressed: () => _copy(context),
            ),
          ],
        ),
      ),
    );
  }
}
