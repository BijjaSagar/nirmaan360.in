import 'dart:async';
import 'dart:typed_data';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../data/petty_cash_repository.dart';

const String pettyCashWalletMetaKey = 'petty_cash.selected_wallet';

enum PettyCashLoad { loading, ready, noProject, noWallet }

class PettyCashState {
  const PettyCashState({
    this.load = PettyCashLoad.loading,
    this.project,
    this.wallets = const [],
    this.selectedWalletId,
    this.entries = const [],
  });

  final PettyCashLoad load;
  final ProjectRow? project;
  final List<WalletLine> wallets;
  final int? selectedWalletId;
  final List<SyncedRecord<PettyCashEntryRow>> entries;

  WalletLine? get selected {
    for (final wallet in wallets) {
      if (wallet.wallet.id == selectedWalletId) return wallet;
    }
    return null;
  }

  PettyCashState copyWith({
    PettyCashLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    List<WalletLine>? wallets,
    int? selectedWalletId,
    List<SyncedRecord<PettyCashEntryRow>>? entries,
  }) {
    return PettyCashState(
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      wallets: wallets ?? this.wallets,
      selectedWalletId: selectedWalletId ?? this.selectedWalletId,
      entries: entries ?? this.entries,
    );
  }
}

/// The wallet screen: balance preview, wallet switching and the entry list.
/// Everything comes from local tables, so it works offline.
class PettyCashCubit extends Cubit<PettyCashState> {
  PettyCashCubit(this._services) : super(const PettyCashState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<List<WalletLine>>? _walletsSub;
  StreamSubscription<List<SyncedRecord<PettyCashEntryRow>>>? _entriesSub;
  int? _entriesWalletId;

  /// Bytes of bills already fetched, so scrolling does not re-read them.
  final Map<String, Future<Uint8List>> _bills = {};

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _walletsSub?.cancel();
      _walletsSub = null;
      emit(state.copyWith(clearProject: true, load: PettyCashLoad.noProject));
      return;
    }
    if (state.project?.id == project.id && _walletsSub != null) return;
    emit(state.copyWith(project: project));
    _walletsSub?.cancel();
    _walletsSub = _services.pettyCash
        .watchWallets(project.id)
        .listen(_onWallets);
  }

  Future<void> _onWallets(List<WalletLine> wallets) async {
    if (wallets.isEmpty) {
      emit(state.copyWith(wallets: const [], load: PettyCashLoad.noWallet));
      return;
    }
    final int walletId = await _pickWallet(wallets);
    if (isClosed) return;
    emit(
      state.copyWith(
        load: PettyCashLoad.ready,
        wallets: wallets,
        selectedWalletId: walletId,
      ),
    );
    _watchEntries(walletId);
  }

  /// Keeps the current wallet if it still exists, else the remembered one,
  /// else the wallet held by this user, else the first.
  Future<int> _pickWallet(List<WalletLine> wallets) async {
    bool exists(int? id) => id != null && wallets.any((w) => w.wallet.id == id);

    if (exists(state.selectedWalletId)) return state.selectedWalletId!;
    final remembered = int.tryParse(
      await _services.db.readMeta(pettyCashWalletMetaKey) ?? '',
    );
    if (exists(remembered)) return remembered!;
    final int? me = _services.session.user?.id;
    for (final wallet in wallets) {
      if (wallet.wallet.userId == me) return wallet.wallet.id;
    }
    return wallets.first.wallet.id;
  }

  void _watchEntries(int walletId) {
    if (_entriesWalletId == walletId && _entriesSub != null) return;
    _entriesWalletId = walletId;
    _entriesSub?.cancel();
    _entriesSub = _services.pettyCash.watchEntries(walletId).listen((entries) {
      emit(state.copyWith(entries: entries));
    });
  }

  /// Switches wallet and remembers the choice for the expense form.
  Future<void> selectWallet(int walletId) async {
    if (walletId == state.selectedWalletId) return;
    emit(state.copyWith(selectedWalletId: walletId, entries: const []));
    _watchEntries(walletId);
    await _services.db.writeMeta(pettyCashWalletMetaKey, '$walletId');
  }

  /// The bytes of an entry's bill, read once for this screen.
  Future<Uint8List> billBytes(PettyCashEntryRow entry) {
    return _bills.putIfAbsent(entry.clientUuid, () {
      final Future<Uint8List> future = _services.pettyCash.billBytes(entry);
      future.catchError((Object _) {
        _bills.remove(entry.clientUuid);
        return Uint8List(0);
      });
      return future;
    });
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _walletsSub?.cancel();
    await _entriesSub?.cancel();
    return super.close();
  }
}
