import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../data/petty_cash_repository.dart' show LocalSyncState;

/// Nothing once sent; a stamp saying where the entry is otherwise.
class PettySyncStamp extends StatelessWidget {
  const PettySyncStamp({super.key, required this.state});

  final LocalSyncState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return switch (state) {
      LocalSyncState.synced => const SizedBox.shrink(),
      LocalSyncState.queued => RubberStamp(
        kind: StampKind.queued,
        label: l.statusQueued,
        size: StampSize.small,
      ),
      LocalSyncState.sending => RubberStamp(
        kind: StampKind.inReview,
        label: l.pettyCashSyncSending,
        size: StampSize.small,
      ),
      LocalSyncState.attention => RubberStamp(
        kind: StampKind.rejected,
        label: l.pettyCashSyncAttention,
        size: StampSize.small,
      ),
    };
  }
}

/// A small bill preview: the picture when it can be shown, otherwise a labelled
/// placeholder. Decoding is capped with `cacheWidth` so a list of receipts stays
/// cheap on a low-end phone.
class BillThumbnail extends StatelessWidget {
  const BillThumbnail({
    super.key,
    required this.load,
    required this.size,
  });

  /// Bytes of the image, or null when there is nothing to show.
  final Future<Uint8List>? load;
  final double size;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final int cacheWidth =
        (size * MediaQuery.devicePixelRatioOf(context)).round().clamp(40, 400);

    final Widget placeholder = ColoredBox(
      color: N360Colors.paper2,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          const Icon(Icons.receipt_long, size: 20, color: N360Colors.ink2),
          Text(
            l.pettyCashBillLabel,
            style: N360Type.caption.copyWith(fontSize: 11, height: 1.1, color: N360Colors.ink2),
          ),
        ],
      ),
    );

    return SizedBox.square(
      dimension: size,
      child: ClipRRect(
        borderRadius: N360Radius.sm,
        child: load == null
            ? placeholder
            : FutureBuilder<Uint8List>(
                future: load,
                builder: (context, snapshot) {
                  final Uint8List? bytes = snapshot.data;
                  if (bytes == null || bytes.length < 16) return placeholder;
                  return Image.memory(
                    bytes,
                    fit: BoxFit.cover,
                    cacheWidth: cacheWidth,
                    gaplessPlayback: true,
                    errorBuilder: (_, _, _) => placeholder,
                  );
                },
              ),
      ),
    );
  }
}
