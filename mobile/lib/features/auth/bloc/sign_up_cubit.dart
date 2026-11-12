import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/sync/failures.dart';

/// Why a sign-up failed as a whole, in terms a person can be told.
enum SignUpProblem { emailTaken, noNetwork, tooManyTries, otherUsersWork, serverDown }

/// The fields of the form, for what is wrong with each.
enum SignUpField { company, name, email, phone, password, confirm }

/// What is wrong with one field. The screen says it in the reader's language.
enum SignUpFieldError { missing, tooLong, invalid, tooShort, mismatch, taken }

/// What the person typed, as typed.
class SignUpInput {
  const SignUpInput({
    required this.company,
    required this.name,
    required this.email,
    required this.phone,
    required this.password,
    required this.confirm,
  });

  final String company;
  final String name;
  final String email;
  final String phone;
  final String password;
  final String confirm;
}

class SignUpState extends Equatable {
  const SignUpState({
    this.submitting = false,
    this.problem,
    this.otherUserCount = 0,
    this.fieldErrors = const <SignUpField, SignUpFieldError>{},
  });

  final bool submitting;
  final SignUpProblem? problem;

  /// For [SignUpProblem.otherUsersWork]: how much is waiting on this phone.
  final int otherUserCount;
  final Map<SignUpField, SignUpFieldError> fieldErrors;

  @override
  List<Object?> get props => <Object?>[submitting, problem, otherUserCount, fieldErrors];
}

class SignUpCubit extends Cubit<SignUpState> {
  SignUpCubit({required this._services, required this._gate}) : super(const SignUpState());

  final AppServices _services;
  final AppGate _gate;

  /// The same limits the server applies, so most mistakes are caught here.
  static const int maxName = 120;
  static const int maxEmail = 190;
  static const int maxPhone = 20;
  static const int minPassword = 8;
  static final RegExp _email = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  /// The email of the last attempt when its reply never arrived. The server may
  /// have made the account anyway, so if trying again says the email is taken,
  /// signing in with the same password finds out, and finishes the job.
  String? _lostEmail;

  Future<void> submit(SignUpInput input, {required String locale}) async {
    if (state.submitting) return;
    final String company = input.company.trim();
    final String name = input.name.trim();
    final String email = input.email.trim().toLowerCase();
    final String phone = input.phone.trim();

    final Map<SignUpField, SignUpFieldError> errors = <SignUpField, SignUpFieldError>{
      if (company.isEmpty) SignUpField.company: SignUpFieldError.missing,
      if (company.length > maxName) SignUpField.company: SignUpFieldError.tooLong,
      if (name.isEmpty) SignUpField.name: SignUpFieldError.missing,
      if (name.length > maxName) SignUpField.name: SignUpFieldError.tooLong,
      if (email.isEmpty) SignUpField.email: SignUpFieldError.missing,
      if (email.isNotEmpty && (!_email.hasMatch(email) || email.length > maxEmail))
        SignUpField.email: SignUpFieldError.invalid,
      if (phone.length > maxPhone) SignUpField.phone: SignUpFieldError.tooLong,
      if (input.password.length < minPassword) SignUpField.password: SignUpFieldError.tooShort,
      if (input.password.length >= minPassword && input.confirm != input.password)
        SignUpField.confirm: SignUpFieldError.mismatch,
    };
    if (errors.isNotEmpty) {
      emit(SignUpState(fieldErrors: errors));
      return;
    }

    emit(const SignUpState(submitting: true));
    // Keeps this screen up, button spinning, through the first pull.
    _gate.beginSignIn();
    final bool retrying = _lostEmail == email;
    _lostEmail = null;
    SignUpState outcome = const SignUpState();
    try {
      await _services.auth.signUp(
        company: company,
        name: name,
        email: email,
        phone: phone.isEmpty ? null : phone,
        password: input.password,
        passwordConfirmation: input.confirm,
        locale: locale,
      );
    } on ValidationFailure catch (failure) {
      outcome = retrying && failure.errors.containsKey('email')
          ? await _signInInstead(email, input.password)
          : _fromValidation(failure);
    } on Exception catch (failure) {
      if (_mayHaveLanded(failure)) _lostEmail = email;
      outcome = _fromFailure(failure);
    }
    if (!isClosed) emit(outcome);
    _gate.endSignIn();
  }

  /// The account was most likely made by the attempt whose reply was lost.
  Future<SignUpState> _signInInstead(String email, String password) async {
    try {
      await _services.auth.signIn(email: email, password: password);
      return const SignUpState();
    } on ValidationFailure {
      return const SignUpState(problem: SignUpProblem.emailTaken);
    } on Exception catch (failure) {
      return _fromFailure(failure);
    }
  }

  /// No reply, or a server error: the request may still have gone through.
  static bool _mayHaveLanded(Exception failure) =>
      failure is OfflineFailure ||
      (failure is ServerFailure && (failure.statusCode == null || failure.statusCode! >= 500));

  static SignUpState _fromValidation(ValidationFailure failure) {
    final Map<String, List<String>> server = failure.errors;
    if (server.containsKey('email')) return const SignUpState(problem: SignUpProblem.emailTaken);
    final Map<SignUpField, SignUpFieldError> errors = <SignUpField, SignUpFieldError>{
      if (server.containsKey('company')) SignUpField.company: SignUpFieldError.invalid,
      if (server.containsKey('name')) SignUpField.name: SignUpFieldError.invalid,
      if (server.containsKey('phone')) SignUpField.phone: SignUpFieldError.taken,
      if (server.containsKey('password')) SignUpField.password: SignUpFieldError.invalid,
    };
    // Anything else the app does not send, so it cannot be put right here.
    if (errors.isEmpty) return const SignUpState(problem: SignUpProblem.serverDown);
    return SignUpState(fieldErrors: errors);
  }

  static SignUpState _fromFailure(Exception failure) => switch (failure) {
        OfflineFailure() => const SignUpState(problem: SignUpProblem.noNetwork),
        RateLimited() => const SignUpState(problem: SignUpProblem.tooManyTries),
        DifferentUserFailure(:final int unsentCount) =>
          SignUpState(problem: SignUpProblem.otherUsersWork, otherUserCount: unsentCount),
        // ServerFailure and anything unforeseen: never show raw text.
        _ => const SignUpState(problem: SignUpProblem.serverDown),
      };
}
