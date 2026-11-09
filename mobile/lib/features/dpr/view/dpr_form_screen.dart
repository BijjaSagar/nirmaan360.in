import 'package:flutter/widgets.dart';

/// Skeleton so the router compiles while the screen is being built.
class DprFormScreen extends StatelessWidget {
  const DprFormScreen({super.key, this.clientUuid, this.date});

  final String? clientUuid;
  final String? date;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
