import 'package:flutter/material.dart';

import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../data/inspection_repository.dart';

/// Words and pictures for the states of an inspection and its items. A state is
/// always a word, with a mark beside it, and never a colour alone.
extension InspectionWords on AppLocalizations {
  String inspectionResultWord(ItemResult result) => switch (result) {
    ItemResult.pass => inspectionResultPass,
    ItemResult.fail => inspectionResultFail,
    ItemResult.na => inspectionResultNa,
    ItemResult.pending => inspectionResultPending,
  };

  /// What the person sees on a row: sending trouble first, then the server's word.
  String inspectionStatusWord(Inspection inspection) {
    if (inspection.needsAttention) return shellPillAttention;
    if (inspection.notSent) return progressNotSent;
    return switch (inspection.status) {
      InspectionStatus.passed => inspectionStatusPassed,
      InspectionStatus.failed => inspectionStatusFailed,
      _ => inspectionStatusOpen,
    };
  }

  /// Null for a category this app has no word for.
  String? checklistCategoryWord(String? category) => switch (category) {
    'pour_card' => inspectionCategoryPourCard,
    'shuttering' => inspectionCategoryShuttering,
    'reinforcement' => inspectionCategoryReinforcement,
    'ppe' => inspectionCategoryPpe,
    'safety' => inspectionCategorySafety,
    'general' => inspectionCategoryGeneral,
    _ => null,
  };
}

StampKind itemStampKind(ItemResult result) => switch (result) {
  ItemResult.pass => StampKind.approved,
  ItemResult.fail => StampKind.rejected,
  ItemResult.na => StampKind.draft,
  ItemResult.pending => StampKind.queued,
};

StampKind inspectionStampKind(Inspection inspection) {
  if (inspection.needsAttention) return StampKind.rejected;
  if (inspection.notSent) return StampKind.queued;
  return switch (inspection.status) {
    InspectionStatus.passed => StampKind.approved,
    InspectionStatus.failed => StampKind.rejected,
    _ => StampKind.inReview,
  };
}

IconData itemResultIcon(ItemResult result) => switch (result) {
  ItemResult.pass => Icons.check_circle_outline,
  ItemResult.fail => Icons.cancel_outlined,
  ItemResult.na => Icons.remove_circle_outline,
  ItemResult.pending => Icons.radio_button_unchecked,
};
