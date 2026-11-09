import 'package:flutter/widgets.dart';

/// Skeleton so the router compiles while the screen is being built.
class IssueDetailScreen extends StatelessWidget {
  const IssueDetailScreen({super.key, required this.clientUuid});

  final String clientUuid;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
