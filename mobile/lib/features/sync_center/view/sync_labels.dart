import 'package:flutter/material.dart';

import '../../../core/db/app_database.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../data/sync_repository.dart';

/// Words and pictures for outbox types and attention codes.
extension SyncLabels on AppLocalizations {
  String syncTypeLabel(OutboxType type) => switch (type) {
        OutboxType.dpr => syncTypeDpr,
        OutboxType.dprPhoto => syncTypeDprPhoto,
        OutboxType.dprSubmit => syncTypeDprSubmit,
        OutboxType.measurement => syncTypeMeasurement,
        OutboxType.measurementSubmit => syncTypeMeasurementSubmit,
        OutboxType.issue => syncTypeIssue,
        OutboxType.issueStatus => syncTypeIssueStatus,
        OutboxType.attendance => syncTypeAttendance,
        OutboxType.pettyCash => syncTypePettyCash,
        OutboxType.pettyBill => syncTypePettyBill,
      };

  String syncReasonFor(OutboxItem item) => switch (item.attentionCode) {
        AttentionCode.approved => syncReasonApproved,
        AttentionCode.inReview => syncReasonInReview,
        AttentionCode.rejected => syncReasonRejected,
        AttentionCode.validation => syncReasonValidation,
        AttentionCode.forbidden => syncReasonForbidden,
        AttentionCode.planFeature => syncReasonPlanFeature,
        AttentionCode.fileMissing => syncReasonFileMissing,
        AttentionCode.parentMissing => syncReasonParentMissing,
        AttentionCode.projectGone => syncReasonProjectGone,
        _ => syncReasonUnexpected,
      };
}

IconData syncTypeIcon(OutboxType type) => switch (type) {
      OutboxType.dpr => Icons.assignment,
      OutboxType.dprPhoto => Icons.photo_camera_outlined,
      OutboxType.dprSubmit => Icons.send,
      OutboxType.measurement => Icons.architecture,
      OutboxType.measurementSubmit => Icons.send,
      OutboxType.issue => Icons.report_problem_outlined,
      OutboxType.issueStatus => Icons.published_with_changes,
      OutboxType.attendance => Icons.groups,
      OutboxType.pettyCash => Icons.account_balance_wallet,
      OutboxType.pettyBill => Icons.receipt_long,
    };
