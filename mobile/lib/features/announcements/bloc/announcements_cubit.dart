import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../data/announcement.dart';

enum AnnouncementsLoad { loading, ready, failed }

class AnnouncementsState extends Equatable {
  const AnnouncementsState({
    this.load = AnnouncementsLoad.loading,
    this.items = const <Announcement>[],
    this.page = 0,
    this.hasMore = false,
    this.loadingMore = false,
    this.failure,
    this.quietFailures = 0,
  });

  final AnnouncementsLoad load;
  final List<Announcement> items;

  /// The last page read; 0 before the first.
  final int page;
  final bool hasMore;
  final bool loadingMore;

  /// Why the list could not be read.
  final ApiFailure? failure;

  /// Goes up by one each time a refresh or an older page fails while the
  /// list is on screen, so the screen can say so once instead of going quiet.
  final int quietFailures;

  AnnouncementsState copyWith({
    AnnouncementsLoad? load,
    List<Announcement>? items,
    int? page,
    bool? hasMore,
    bool? loadingMore,
    ApiFailure? failure,
    bool clearFailure = false,
    int? quietFailures,
  }) => AnnouncementsState(
    load: load ?? this.load,
    items: items ?? this.items,
    page: page ?? this.page,
    hasMore: hasMore ?? this.hasMore,
    loadingMore: loadingMore ?? this.loadingMore,
    failure: clearFailure ? null : (failure ?? this.failure),
    quietFailures: quietFailures ?? this.quietFailures,
  );

  @override
  List<Object?> get props => <Object?>[load, items, page, hasMore, loadingMore, failure, quietFailures];
}

/// The announcements list: the first page on open, more on request, all of it
/// again on pull to refresh. Text comes in [locale], the language the app shows.
class AnnouncementsCubit extends Cubit<AnnouncementsState> {
  AnnouncementsCubit(this._repository, {required this.locale}) : super(const AnnouncementsState());

  final AnnouncementsRepository _repository;
  final String locale;

  /// Bumped by every [load], so an older page that arrives after a refresh is dropped.
  int _generation = 0;

  Future<void> load() async {
    final int generation = ++_generation;
    if (state.items.isEmpty) emit(state.copyWith(load: AnnouncementsLoad.loading, clearFailure: true));
    try {
      final AnnouncementPage first = await _repository.page(1, locale: locale);
      if (isClosed || generation != _generation) return;
      emit(
        AnnouncementsState(
          load: AnnouncementsLoad.ready,
          items: first.items,
          page: first.page,
          hasMore: first.hasMore,
          quietFailures: state.quietFailures,
        ),
      );
    } on ApiFailure catch (failure) {
      if (isClosed || generation != _generation) return;
      // A refresh that fails keeps what is already on screen, and says so.
      emit(
        state.items.isEmpty
            ? state.copyWith(load: AnnouncementsLoad.failed, failure: failure)
            : state.copyWith(load: AnnouncementsLoad.ready, loadingMore: false, failure: failure, quietFailures: state.quietFailures + 1),
      );
    }
  }

  Future<void> loadMore() async {
    if (!state.hasMore || state.loadingMore || state.load != AnnouncementsLoad.ready) return;
    final int generation = _generation;
    emit(state.copyWith(loadingMore: true));
    try {
      final AnnouncementPage next = await _repository.page(state.page + 1, locale: locale);
      if (isClosed || generation != _generation) return;
      final Set<int> seen = <int>{for (final Announcement item in state.items) item.id};
      emit(
        state.copyWith(
          items: <Announcement>[...state.items, ...next.items.where((item) => !seen.contains(item.id))],
          page: next.page,
          hasMore: next.hasMore,
          loadingMore: false,
          clearFailure: true,
        ),
      );
    } on ApiFailure catch (failure) {
      if (isClosed || generation != _generation) return;
      emit(state.copyWith(loadingMore: false, failure: failure, quietFailures: state.quietFailures + 1));
    }
  }
}
