import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/sync/failures.dart';

/// Why a sign-in failed, in terms a person can be told.
enum SignInProblem { wrongCredentials, noNetwork, serverDown, tooManyTries, otherUsersWork }

class SignInState extends Equatable {
  const SignInState({
    this.submitting = false,
    this.problem,
    this.otherUserCount = 0,
    this.emailMissing = false,
    this.passwordMissing = false,
  });

  final bool submitting;
  final SignInProblem? problem;

  /// For [SignInProblem.otherUsersWork]: how many entries are waiting.
  final int otherUserCount;
  final bool emailMissing;
  final bool passwordMissing;

  @override
  List<Object?> get props => <Object?>[submitting, problem, otherUserCount, emailMissing, passwordMissing];
}

class SignInCubit extends Cubit<SignInState> {
  SignInCubit({required this._services, required this._gate}) : super(const SignInState());

  final AppServices _services;
  final AppGate _gate;

  Future<void> submit({required String email, required String password}) async {
    if (state.submitting) return;
    final String trimmed = email.trim();
    if (trimmed.isEmpty || password.isEmpty) {
      emit(SignInState(emailMissing: trimmed.isEmpty, passwordMissing: password.isEmpty));
      return;
    }

    emit(const SignInState(submitting: true));
    // Keeps this screen up, button spinning, through the first pull.
    _gate.beginSignIn();
    SignInState outcome = const SignInState();
    try {
      await _services.auth.signIn(email: trimmed, password: password);
    } on ValidationFailure {
      outcome = const SignInState(problem: SignInProblem.wrongCredentials);
    } on OfflineFailure {
      outcome = const SignInState(problem: SignInProblem.noNetwork);
    } on RateLimited {
      outcome = const SignInState(problem: SignInProblem.tooManyTries);
    } on DifferentUserFailure catch (failure) {
      outcome = SignInState(problem: SignInProblem.otherUsersWork, otherUserCount: failure.unsentCount);
    } on Exception {
      // ServerFailure and anything unforeseen: never show raw text.
      outcome = const SignInState(problem: SignInProblem.serverDown);
    }
    if (!isClosed) emit(outcome);
    _gate.endSignIn();
  }
}
