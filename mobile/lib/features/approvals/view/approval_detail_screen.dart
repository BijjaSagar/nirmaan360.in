import 'package:flutter/widgets.dart';

/// Skeleton so the router compiles while the screen is being built.
class ApprovalDetailScreen extends StatelessWidget {
  const ApprovalDetailScreen({super.key, required this.approvalId});

  final int approvalId;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
