import '../../../../core/db/app_database.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/contractor_models.dart';

StampKind paymentStampKind(String? status) => switch (status) {
  DocStatus.approved => StampKind.approved,
  DocStatus.inReview => StampKind.inReview,
  DocStatus.returned => StampKind.returned,
  DocStatus.rejected => StampKind.rejected,
  _ => StampKind.draft,
};

extension ContractorWords on AppLocalizations {
  String paymentStatusWord(String? status) => switch (status) {
    DocStatus.approved => statusApproved,
    DocStatus.inReview => statusInReview,
    DocStatus.returned => statusReturned,
    DocStatus.rejected => statusRejected,
    _ => statusDraft,
  };

  String paymentModeWord(String mode) => switch (mode) {
    'cash' => contractorModeCash,
    'bank' => contractorModeBank,
    'upi' => contractorModeUpi,
    _ => mode,
  };

  String partStateWord(String state) => switch (state) {
    'paid' => contractorPartPaid,
    'part_paid' => contractorPartPartPaid,
    'over' => contractorPartOver,
    _ => contractorPartNotPaid,
  };
}

/// How paid stands against work, as a word with its colour. Null when the
/// contract has no value to compare.
StatusPill? ratioPill(AppLocalizations l, ContractFigures figures) {
  if (figures.overLimit) return StatusPill(kind: StampKind.rejected, label: l.contractorOverLimit);
  if (figures.paidAhead) return StatusPill(kind: StampKind.returned, label: l.contractorPaidAhead);
  if (figures.ratioStatus == 'green') return StatusPill(kind: StampKind.approved, label: l.contractorInStep);
  return null;
}
