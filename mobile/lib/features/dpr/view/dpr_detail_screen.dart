import 'package:flutter/widgets.dart';

/// Skeleton so the router compiles while the screen is being built.
class DprDetailScreen extends StatelessWidget {
  const DprDetailScreen({super.key, required this.clientUuid});

  final String clientUuid;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
