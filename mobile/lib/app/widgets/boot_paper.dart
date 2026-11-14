import 'package:flutter/widgets.dart';

import '../../core/design/design.dart';
import 'brand_mark.dart';

/// Shown until the local session has been read: the plain background and the
/// mark, so it is indistinguishable from the native launch screen it replaces.
class BootPaper extends StatelessWidget {
  const BootPaper({super.key});

  @override
  Widget build(BuildContext context) {
    return const Directionality(
      textDirection: TextDirection.ltr,
      child: ColoredBox(
        color: N360Colors.paper,
        child: Center(child: BrandMark(size: 92)),
      ),
    );
  }
}
