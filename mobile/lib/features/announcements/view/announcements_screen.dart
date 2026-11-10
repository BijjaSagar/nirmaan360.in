import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/announcements_cubit.dart';
import '../data/announcement.dart';

/// News and reminders sent to this person by their company or by Nirmaan360,
/// newest first, each with its picture when it has one. A push of type
/// `announcement` opens this page with [highlightId], which is marked.
/// Online only: the server keeps the list.
class AnnouncementsScreen extends StatelessWidget {
  const AnnouncementsScreen({super.key, this.highlightId});

  final int? highlightId;

  @override
  Widget build(BuildContext context) {
    final AppServices services = context.read<AppServices>();
    final AppGate gate = context.read<AppGate>();
    final String locale = Localizations.localeOf(context).languageCode;
    return BlocProvider<AnnouncementsCubit>(
      create: (_) =>
          AnnouncementsCubit(AnnouncementsRepository(services.context.api, portal: () => gate.state.isContractor), locale: locale)..load(),
      child: _AnnouncementsView(highlightId: highlightId),
    );
  }
}

class _AnnouncementsView extends StatelessWidget {
  const _AnnouncementsView({required this.highlightId});

  final int? highlightId;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocListener<AnnouncementsCubit, AnnouncementsState>(
      listenWhen: (before, now) => now.quietFailures > before.quietFailures,
      listener: (context, state) => showPaperToast(
        context,
        state.failure is OfflineFailure ? l.announcementsOfflineMessage : l.announcementsFailedMessage,
        kind: PaperToastKind.info,
      ),
      child: Scaffold(
        body: LedgerPaper(
          child: SafeArea(
            top: false,
            bottom: false,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                FieldTopBar(title: l.announcementsTitle),
                Expanded(
                  child: BlocBuilder<AnnouncementsCubit, AnnouncementsState>(
                    builder: (context, state) => LedgerRefresh(
                      onRefresh: () => context.read<AnnouncementsCubit>().load(),
                      semanticsLabel: l.announcementsLoading,
                      child: ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                        children: _children(context, l, state),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _children(BuildContext context, AppLocalizations l, AnnouncementsState state) {
    final AnnouncementsCubit cubit = context.read<AnnouncementsCubit>();
    if (state.load == AnnouncementsLoad.loading) {
      return <Widget>[LedgerSkeletonList(lines: 3, semanticsLabel: l.announcementsLoading)];
    }
    if (state.load == AnnouncementsLoad.failed) {
      final bool offline = state.failure is OfflineFailure;
      return <Widget>[
        EmptyLedger(
          title: offline ? l.announcementsOfflineTitle : l.announcementsFailedTitle,
          message: offline ? l.announcementsOfflineMessage : l.announcementsFailedMessage,
        ),
        const SizedBox(height: N360Space.md),
        GloveButton(
          key: const ValueKey<String>('announcements-retry'),
          label: l.announcementsRetry,
          kind: GloveButtonKind.secondary,
          icon: Icons.refresh,
          fullWidth: true,
          onPressed: cubit.load,
        ),
      ];
    }
    if (state.items.isEmpty) {
      return <Widget>[EmptyLedger(title: l.announcementsEmptyTitle, message: l.announcementsEmptyMessage)];
    }
    return <Widget>[
      for (final Announcement item in state.items) ...<Widget>[
        AnnouncementCard(key: ValueKey<String>('announcement-${item.id}'), announcement: item, highlighted: item.id == highlightId),
        const SizedBox(height: N360Space.cardGap),
      ],
      if (state.hasMore)
        GloveButton(
          key: const ValueKey<String>('announcements-more'),
          label: l.announcementsMore,
          kind: GloveButtonKind.secondary,
          icon: Icons.expand_more,
          fullWidth: true,
          onPressed: state.loadingMore ? null : cubit.loadMore,
        ),
    ];
  }
}

/// One announcement: its picture across the top, the title, who sent it and
/// when, and the whole message, never cut short.
class AnnouncementCard extends StatelessWidget {
  const AnnouncementCard({super.key, required this.announcement, this.highlighted = false});

  final Announcement announcement;

  /// The one a tapped notification was about.
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final DateTime? sentAt = announcement.sentAt;
    final String when = sentAt == null ? '' : AppFormat.dayMonthYearTime(sentAt, locale);
    final String meta = <String>[if (announcement.from.isNotEmpty) announcement.from, if (when.isNotEmpty) when].join(' · ');
    final String? image = announcement.imageUrl;
    return DecoratedBox(
      position: DecorationPosition.foreground,
      decoration: BoxDecoration(
        borderRadius: N360Radius.md,
        border: highlighted ? Border.all(color: N360Colors.primary, width: 2) : null,
      ),
      child: LedgerCard(
        padding: EdgeInsets.zero,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (image != null)
              ClipRRect(
                borderRadius: const BorderRadius.vertical(top: Radius.circular(N360Radius.medium)),
                child: AspectRatio(
                  aspectRatio: 2,
                  child: Image.network(
                    image,
                    key: ValueKey<String>('announcement-image-${announcement.id}'),
                    fit: BoxFit.cover,
                    semanticLabel: announcement.title,
                    loadingBuilder: (context, child, progress) => progress == null ? child : const ColoredBox(color: N360Colors.paper2),
                    errorBuilder: (context, error, stack) => const ColoredBox(
                      color: N360Colors.paper2,
                      child: Center(
                        child: Icon(Icons.image_not_supported_outlined, color: N360Colors.ink2, size: N360IconSize.lg),
                      ),
                    ),
                  ),
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(N360Space.card),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (announcement.repeats) ...<Widget>[
                    Text(l.announcementsReminder, style: N360Type.eyebrowFor(l.announcementsReminder).copyWith(color: N360Colors.primary)),
                    const SizedBox(height: N360Space.xxs),
                  ],
                  Text(announcement.title, style: N360Type.fit(N360Type.heading, announcement.title)),
                  if (meta.isNotEmpty) ...<Widget>[
                    const SizedBox(height: N360Space.xxs),
                    Text(meta, style: N360Type.fit(N360Type.caption, meta).copyWith(color: N360Colors.ink2)),
                  ],
                  if (announcement.body.isNotEmpty) ...<Widget>[
                    const SizedBox(height: N360Space.sm),
                    Text(announcement.body, style: N360Type.fit(N360Type.body, announcement.body)),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
