import 'package:flutter/widgets.dart';

/// Skeleton so the router compiles while the screen is being built.
class MeasurementFormScreen extends StatelessWidget {
  const MeasurementFormScreen({super.key, this.clientUuid});

  final String? clientUuid;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
