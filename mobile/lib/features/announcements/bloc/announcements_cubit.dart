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
  });

  final AnnouncementsLoad load;
  final List<Announcement> items;

  /// The last page read; 0 before the first.
  final int page;
  final bool hasMore;
  final bool loadingMore;

  /// Why the list could not be read.
  final ApiFailure? failure;

  AnnouncementsState copyWith({
    AnnouncementsLoad? load,
    List<Announcement>? items,
    int? page,
    bool? hasMore,
    bool? loadingMore,
    ApiFailure? failure,
    bool clearFailure = false,
  }) => AnnouncementsState(
    load: load ?? this.load,
    items: items ?? this.items,
    page: page ?? this.page,
    hasMore: hasMore ?? this.hasMore,
    loadingMore: loadingMore ?? this.loadingMore,
    failure: clearFailure ? null : (failure ?? this.failure),
  );

  @override
  List<Object?> get props => <Object?>[load, items, page, hasMore, loadingMore, failure];
}

/// The announcements list: the first page on open, more on request, all of it
/// again on pull to refresh. Text comes in [locale], the language the app shows.
class AnnouncementsCubit extends Cubit<AnnouncementsState> {
  AnnouncementsCubit(this._repository, {required this.locale}) : super(const AnnouncementsState());

  final AnnouncementsRepository _repository;
  final String locale;

  Future<void> load() async {
    if (state.items.isEmpty) emit(state.copyWith(load: AnnouncementsLoad.loading, clearFailure: true));
    try {
      final AnnouncementPage first = await _repository.page(1, locale: locale);
      if (isClosed) return;
      emit(AnnouncementsState(load: AnnouncementsLoad.ready, items: first.items, page: first.page, hasMore: first.hasMore));
    } on ApiFailure catch (failure) {
      if (isClosed) return;
      // A refresh that fails keeps what is already on screen.
      emit(state.copyWith(load: state.items.isEmpty ? AnnouncementsLoad.failed : AnnouncementsLoad.ready, failure: failure));
    }
  }

  Future<void> loadMore() async {
    if (!state.hasMore || state.loadingMore || state.load != AnnouncementsLoad.ready) return;
    emit(state.copyWith(loadingMore: true));
    try {
      final AnnouncementPage next = await _repository.page(state.page + 1, locale: locale);
      if (isClosed) return;
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
      if (isClosed) return;
      emit(state.copyWith(loadingMore: false, failure: failure));
    }
  }
}
