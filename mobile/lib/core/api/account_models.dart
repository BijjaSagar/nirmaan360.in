/// What deleting an account does. The server decides it; the app only shows it
/// and sends it back, so the server can tell when it changed in between.
enum AccountDeletionMode {
  /// A member, or an owner while another active owner remains: the person's
  /// sign-in and personal details go at once, their work stays with the company.
  account,

  /// The only active owner: the whole company is erased after a grace period.
  company;

  static AccountDeletionMode parse(Object? raw) => raw == 'company' ? company : account;
}

/// A company deletion already asked for and not yet done.
class PendingDeletion {
  const PendingDeletion({required this.eraseAfter, required this.canCancel});

  final DateTime eraseAfter;

  /// Only an owner can call it off.
  final bool canCancel;
}

/// `GET /auth/account/deletion`: what would happen if this person deleted
/// their account now.
class AccountDeletionPreview {
  const AccountDeletionPreview({
    required this.mode,
    required this.companyName,
    required this.otherPeople,
    required this.requiresConfirmName,
    required this.graceDays,
    this.eraseAfter,
    this.pending,
  });

  factory AccountDeletionPreview.fromJson(Map<String, dynamic> json) {
    final Object? pending = json['pending'];
    final DateTime? pendingAt = pending is Map ? _instant(pending['erase_after']) : null;
    return AccountDeletionPreview(
      mode: AccountDeletionMode.parse(json['mode']),
      companyName: (json['company_name'] as String?) ?? '',
      otherPeople: (json['other_people'] as num?)?.toInt() ?? 0,
      requiresConfirmName: json['requires_confirm_name'] == true,
      graceDays: (json['grace_days'] as num?)?.toInt() ?? 30,
      eraseAfter: _instant(json['erase_after']),
      pending: pending is Map && pendingAt != null
          ? PendingDeletion(eraseAfter: pendingAt, canCancel: pending['can_cancel'] == true)
          : null,
    );
  }

  final AccountDeletionMode mode;
  final String companyName;

  /// Active people in the company other than this one, contractor logins included.
  final int otherPeople;

  /// The company name must be typed to confirm. True exactly when [mode] is company.
  final bool requiresConfirmName;
  final int graceDays;

  /// For [AccountDeletionMode.company]: when the company would be erased.
  final DateTime? eraseAfter;
  final PendingDeletion? pending;
}

/// `POST /auth/account/deletion` went through.
class AccountDeletionResult {
  const AccountDeletionResult({required this.scheduled, this.eraseAfter});

  factory AccountDeletionResult.fromJson(Map<String, dynamic> json) => AccountDeletionResult(
        scheduled: json['result'] == 'scheduled',
        eraseAfter: _instant(json['erase_after']),
      );

  /// True when the company is to be erased later; false when the account is
  /// already gone.
  final bool scheduled;
  final DateTime? eraseAfter;
}

DateTime? _instant(Object? raw) => raw is String ? DateTime.tryParse(raw) : null;
