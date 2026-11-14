import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show SiteRow, WorkerRow;
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/selfie_cubit.dart';
import '../bloc/selfie_sensors.dart';
import '../data/selfie_repository.dart';
import 'widgets/field_kit.dart';

/// Marks a worker present from a photo taken at the site. The worker's agreement
/// comes first, the photo second, and the phone notes the place when the photo is taken.
class SelfieScreen extends StatelessWidget {
  const SelfieScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<SelfieCubit>(
      create: (context) => SelfieCubit(context.read<AppServices>()),
      child: const _SelfieView(),
    );
  }
}

class _SelfieView extends StatelessWidget {
  const _SelfieView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      backgroundColor: N360Colors.sheet,
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocConsumer<SelfieCubit, SelfieState>(
            listenWhen: (a, b) => a.resultNumber != b.resultNumber && b.result != null,
            listener: (context, state) {
              if (state.result!.result == SelfieResult.refused) {
                N360Haptics.warning();
              } else {
                N360Haptics.success();
              }
            },
            builder: (context, state) {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.selfieTitle, subtitle: state.projectCode),
                  Expanded(child: _body(context, l, state)),
                  if (state.step == SelfieStep.consent)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('selfie-agree'),
                        label: l.selfieConsentAgree,
                        icon: Icons.check,
                        large: true,
                        fullWidth: true,
                        onPressed: context.read<SelfieCubit>().agree,
                      ),
                    ),
                  if (state.step == SelfieStep.form)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('selfie-send'),
                        label: l.selfieSend,
                        icon: Icons.check,
                        large: true,
                        fullWidth: true,
                        loading: state.submitting,
                        onPressed: state.submitting || state.locating ? null : () => context.read<SelfieCubit>().submit(),
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

  Widget _body(BuildContext context, AppLocalizations l, SelfieState state) {
    switch (state.step) {
      case SelfieStep.loading:
        return Padding(
          padding: const EdgeInsets.all(N360Space.gutter),
          child: LedgerSkeletonList(lines: 3, semanticsLabel: l.attendanceLoading),
        );
      case SelfieStep.noProject:
        return EmptyLedger(title: l.progressNoProjectTitle, message: l.progressNoProjectMessage);
      case SelfieStep.noSite:
        return EmptyLedger(title: l.selfieNoSiteTitle, message: l.selfieNoSiteMessage);
      case SelfieStep.needsNetwork:
        return EmptyLedger(
          title: l.selfieConsentTitle,
          message: l.selfieConsentNeedsNetwork,
          action: GloveButton(
            key: const ValueKey<String>('selfie-consent-retry'),
            label: l.commonRetry,
            icon: Icons.refresh,
            onPressed: context.read<SelfieCubit>().retryConsent,
          ),
        );
      case SelfieStep.consent:
        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.lg, N360Space.gutter, N360Space.sm),
          child: _ConsentCard(consent: state.consent!, asking: true),
        );
      case SelfieStep.form:
        return SingleChildScrollView(
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.lg, N360Space.gutter, N360Space.sm),
          child: _Form(state: state),
        );
    }
  }
}

/// The server's consent note, word for word, and its version.
class _ConsentCard extends StatelessWidget {
  const _ConsentCard({required this.consent, required this.asking});

  final SelfieConsent consent;

  /// Before the first photo, when the worker has still to agree.
  final bool asking;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.selfieConsentTitle, style: N360Type.fit(N360Type.heading, l.selfieConsentTitle)),
          if (asking) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(l.selfieConsentAsk, style: N360Type.fit(N360Type.caption, l.selfieConsentAsk)),
          ],
          const SizedBox(height: N360Space.md),
          Text(consent.text, key: const ValueKey<String>('selfie-consent-text'), style: N360Type.fit(N360Type.body, consent.text)),
          const SizedBox(height: N360Space.md),
          Text(
            l.selfieConsentVersion(consent.version),
            style: N360Type.fit(N360Type.caption, l.selfieConsentVersion(consent.version)),
          ),
        ],
      ),
    );
  }
}

class _Form extends StatelessWidget {
  const _Form({required this.state});

  final SelfieState state;

  Future<void> _pickWorker(BuildContext context) async {
    final SelfieCubit cubit = context.read<SelfieCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.selfieWorkerSearch,
      emptyTitle: l.materialSearchNone,
      options: <FieldPickOption>[
        for (final WorkerRow worker in state.workers) FieldPickOption(id: worker.id, title: worker.name, subtitle: worker.trade),
      ],
    );
    if (id != null) cubit.setWorker(id);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final SelfieCubit cubit = context.read<SelfieCubit>();
    const Widget gap = SizedBox(height: N360Space.lg);
    final WorkerRow? worker = state.worker;
    final SelfieOutcome? result = state.result;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (result != null) ...<Widget>[_ResultCard(result: result), gap] else ...<Widget>[
          Text(l.selfieIntro, style: N360Type.fit(N360Type.caption, l.selfieIntro)),
          gap,
        ],
        FieldBlock(
          label: l.selfieWorker,
          error: state.problem == SelfieProblem.workerRequired ? l.selfieWorkerRequired : null,
          child: FieldPickerTile(
            key: const ValueKey<String>('selfie-worker'),
            icon: Icons.person_outline,
            text: worker?.name ?? l.selfieWorkerChoose,
            muted: worker == null,
            semanticsLabel: l.selfieWorker,
            onTap: () => _pickWorker(context),
          ),
        ),
        if (state.sites.length > 1) ...<Widget>[
          gap,
          FieldBlock(
            label: l.selfieSite,
            child: Wrap(
              spacing: N360Space.sm,
              children: <Widget>[
                for (final SiteRow site in state.sites)
                  IntrinsicWidth(
                    child: N360Chip(
                      key: ValueKey<String>('selfie-site-${site.id}'),
                      label: site.name,
                      selected: state.siteId == site.id,
                      onTap: () => cubit.setSite(site.id),
                    ),
                  ),
              ],
            ),
          ),
        ],
        gap,
        _PhotoBlock(state: state),
        gap,
        GloveButton(
          key: const ValueKey<String>('selfie-read-consent'),
          label: l.selfieConsentRead,
          icon: Icons.description_outlined,
          kind: GloveButtonKind.ghost,
          onPressed: () => showLedgerSheet<void>(
            context,
            isScrollControlled: true,
            builder: (sheet) => SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
                child: SingleChildScrollView(child: _ConsentCard(consent: state.consent!, asking: false)),
              ),
            ),
          ),
        ),
        if (state.problem == SelfieProblem.saveFailed) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          ErrorSlip(title: l.selfieFailed),
        ],
      ],
    );
  }
}

/// The photo, and what the phone noted about where it was taken.
class _PhotoBlock extends StatelessWidget {
  const _PhotoBlock({required this.state});

  final SelfieState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final SelfieCubit cubit = context.read<SelfieCubit>();
    final String? photo = state.photoPath;
    final SelfieFix? fix = state.fix;
    final String? missing = switch (state.problem) {
      SelfieProblem.photoRequired => l.selfiePhotoRequired,
      SelfieProblem.locationRequired => l.selfieLocationFailed,
      _ => null,
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (photo != null)
          ClipRRect(
            borderRadius: N360Radius.md,
            child: SizedBox(
              height: 200,
              child: Image.file(
                File(photo),
                key: const ValueKey<String>('selfie-photo'),
                fit: BoxFit.cover,
                cacheWidth: 720,
                errorBuilder: (context, error, stack) => ColoredBox(
                  color: N360Colors.paper,
                  child: Center(child: Icon(Icons.photo_outlined, size: N360IconSize.xl, color: N360Colors.ink2)),
                ),
              ),
            ),
          ),
        if (photo != null) const SizedBox(height: N360Space.sm),
        if (state.locating)
          _Line(icon: Icons.my_location, text: l.selfieLocating)
        else if (fix != null)
          _Line(icon: Icons.place_outlined, text: l.selfieLocationNote(fix.accuracy.split('.').first))
        else if (state.locationProblem != null)
          _Line(icon: Icons.location_off_outlined, text: _problemText(l, state.locationProblem!), warn: true),
        if (missing != null) _Line(icon: Icons.info_outline, text: missing, warn: true),
        const SizedBox(height: N360Space.sm),
        GloveButton(
          key: const ValueKey<String>('selfie-take'),
          label: photo == null ? l.selfieTakePhoto : l.selfieRetake,
          icon: Icons.photo_camera_outlined,
          kind: photo == null ? GloveButtonKind.primary : GloveButtonKind.secondary,
          fullWidth: true,
          onPressed: state.locating || state.submitting ? null : cubit.takePhoto,
        ),
        if (photo == null) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          Text(l.selfieLocationWhy, style: N360Type.fit(N360Type.caption, l.selfieLocationWhy)),
        ],
      ],
    );
  }

  static String _problemText(AppLocalizations l, LocationProblem problem) => switch (problem) {
    LocationProblem.off => l.selfieLocationOff,
    LocationProblem.denied || LocationProblem.deniedForever => l.selfieLocationDenied,
    LocationProblem.failed => l.selfieLocationFailed,
  };
}

class _Line extends StatelessWidget {
  const _Line({required this.icon, required this.text, this.warn = false});

  final IconData icon;
  final String text;
  final bool warn;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icon, size: N360IconSize.md, color: warn ? N360Colors.laterite : N360Colors.ink2),
          const SizedBox(width: N360Space.xs),
          Expanded(
            child: Text(
              text,
              style: N360Type.fit(N360Type.caption, text).copyWith(color: warn ? N360Colors.laterite : N360Colors.ink),
            ),
          ),
        ],
      ),
    );
  }
}

/// How the last photo ended, in words.
class _ResultCard extends StatelessWidget {
  const _ResultCard({required this.result});

  final SelfieOutcome result;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String name = result.workerName ?? '';
    final (IconData icon, String text) = switch (result.result) {
      SelfieResult.marked => (
        Icons.check_circle_outline,
        result.distance != null && result.radius != null
            ? l.selfieMarked(name, result.distance!, result.radius!)
            : l.selfieMarkedPlain(name),
      ),
      SelfieResult.queued => (Icons.cloud_upload_outlined, l.selfieQueued(name)),
      SelfieResult.refused => (Icons.block, l.selfieRefused(result.message ?? l.selfieFailed)),
    };
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Semantics(
            liveRegion: true,
            container: true,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Icon(icon, size: N360IconSize.lg, color: N360Colors.ink2),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: Text(text, key: const ValueKey<String>('selfie-result'), style: N360Type.fit(N360Type.body, text)),
                ),
              ],
            ),
          ),
          if (result.result == SelfieResult.queued) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(l.selfieQueuedNote, style: N360Type.fit(N360Type.caption, l.selfieQueuedNote)),
          ],
        ],
      ),
    );
  }
}
