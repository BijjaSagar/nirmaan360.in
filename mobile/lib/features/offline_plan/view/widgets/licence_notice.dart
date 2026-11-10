import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/routes.dart';
import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/offline/offline_plan.dart';
import '../../../../l10n/generated/app_localizations.dart';
import 'licence_card.dart';

/// One calm card about the offline licence, for Home and the screens that add
/// or change something.
///
/// In the last week it says the date and what to do. Once the licence has
/// ended it says that nothing can be added or changed, and a tap opens the
/// page that renews it. A company on any other plan sees nothing at all: it is
/// an empty box, not a hidden one.
///
/// It reads the plan itself rather than the gate, so any screen can carry it.
class LicenceNotice extends StatelessWidget {
  const LicenceNotice({
    super.key,
    this.padding = const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.xs),
    this.endedOnly = false,
  });

  final EdgeInsetsGeometry padding;

  /// For the licence page, which already shows the end date: say something only
  /// when entries are blocked.
  final bool endedOnly;

  @override
  Widget build(BuildContext context) {
    final OfflinePlan plan = context.read<AppServices>().offlinePlan;
    return StreamBuilder<LicenceStatus>(
      stream: plan.licenceChanges,
      initialData: plan.licence,
      builder: (context, snapshot) {
        final LicenceStatus licence = snapshot.data ?? LicenceStatus.none;
        final bool ended = licence.entriesBlocked;
        if (!ended && (endedOnly || !licence.isEndingSoon)) return const SizedBox.shrink();
        return Padding(padding: padding, child: _Card(licence: licence));
      },
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.licence});

  final LicenceStatus licence;

  @override
  Widget build(BuildContext context) {
    final bool ended = licence.entriesBlocked;
    final AppLocalizations t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final DateTime? end = licence.endsAt;
    final String date = end == null ? '' : licenceDate(end, locale);
    final String text = !ended
        ? t.offlineNoticeEnding(date)
        : licence.planSyncs
        ? t.offlineNoticeUpload
        : licence.hasNoLicence
        ? t.offlineNoticeNoLicence
        : end == null
        ? t.offlineNoticeEndedNoDate
        : t.offlineNoticeEnded(date);

    return LedgerCard(
      key: const ValueKey<String>('licence-notice'),
      semanticsLabel: text,
      onTap: () => context.push(ended ? Routes.licenceEnded : Routes.offlinePlan),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(
            ended ? Icons.lock_outline : Icons.schedule,
            size: N360IconSize.lg,
            color: ended ? N360Colors.stampRed : N360Colors.ink2,
          ),
          const SizedBox(width: N360Space.sm),
          Expanded(child: Text(text, style: N360Type.fit(N360Type.body, text))),
        ],
      ),
    );
  }
}
