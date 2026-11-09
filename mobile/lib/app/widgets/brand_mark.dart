import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// The Nirmaan360 symbol from the supplied logo artwork.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = N360Size.tile});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Image.asset(
      'assets/brand/logo_mark.png',
      width: size,
      height: size,
      fit: BoxFit.contain,
      excludeFromSemantics: true,
    );
  }
}

/// The symbol and the name together, for places with room for the full logo.
class BrandLockup extends StatelessWidget {
  const BrandLockup({super.key, this.height = 44});

  final double height;

  @override
  Widget build(BuildContext context) {
    return Image.asset(
      'assets/brand/logo_full.png',
      height: height,
      fit: BoxFit.contain,
      alignment: Alignment.centerLeft,
      semanticLabel: 'Nirmaan360',
    );
  }
}
