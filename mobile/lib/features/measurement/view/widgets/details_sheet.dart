import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../core/design/design.dart';
import '../../../../core/sync/record_photos.dart' show RecordPhotos;
import '../../../../l10n/generated/app_localizations.dart';
import '../../../site_progress/view/widgets/progress_widgets.dart' show ProgressPhotoRow;
import 'book_kit.dart';

class EntryDetails {
  const EntryDetails({
    required this.location,
    required this.description,
    required this.measuredOn,
    this.photos = const <String>[],
  });

  final String location;
  final String description;
  final String measuredOn;

  /// Paths of the photos taken for this row.
  final List<String> photos;
}

/// Location, note and date live in a sheet, so a phone keyboard for words never
/// pushes the figure keypad off the screen.
Future<EntryDetails?> showDetailsSheet(BuildContext context, {required EntryDetails initial, required String today}) {
  return showLedgerSheet<EntryDetails>(
    context,
    isScrollControlled: true,
    builder: (_) => _DetailsSheet(initial: initial, today: today),
  );
}

class _DetailsSheet extends StatefulWidget {
  const _DetailsSheet({required this.initial, required this.today});

  final EntryDetails initial;
  final String today;

  @override
  State<_DetailsSheet> createState() => _DetailsSheetState();
}

class _DetailsSheetState extends State<_DetailsSheet> {
  late final TextEditingController _location = TextEditingController(text: widget.initial.location);
  late final TextEditingController _description = TextEditingController(text: widget.initial.description);
  late String _date = widget.initial.measuredOn;
  late List<String> _photos = widget.initial.photos;

  static const int _locationLimit = 120;
  static const int _descriptionLimit = 255;

  @override
  void dispose() {
    _location.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _pickOtherDate() async {
    final DateTime last = DateTime.parse(widget.today);
    final DateTime initial = DateTime.tryParse(_date) ?? last;
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: initial.isAfter(last) ? last : initial,
      firstDate: DateTime(last.year - 2),
      lastDate: last,
    );
    if (picked == null || !mounted) return;
    String two(int value) => value.toString().padLeft(2, '0');
    setState(() => _date = '${picked.year}-${two(picked.month)}-${two(picked.day)}');
  }

  void _done() {
    Navigator.of(context).pop(
      EntryDetails(location: _location.text, description: _description.text, measuredOn: _date, photos: _photos),
    );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MediaQueryData media = MediaQuery.of(context);
    final String yesterday = daysBefore(widget.today, 1);
    final bool other = _date != widget.today && _date != yesterday;

    return Padding(
      padding: EdgeInsets.only(bottom: media.viewInsets.bottom),
      child: SingleChildScrollView(
        padding: ledgerSheetPadding,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(l.measurementDetailsTitle, style: N360Type.title),
            const SizedBox(height: N360Space.md),
            SectionLabel(l.measurementDateLabel),
            const SizedBox(height: N360Space.xs),
            Row(
              children: <Widget>[
                Expanded(
                  child: ChoiceTile(
                    label: l.commonToday,
                    selected: _date == widget.today,
                    onTap: () => setState(() => _date = widget.today),
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: ChoiceTile(
                    label: l.measurementYesterday,
                    selected: _date == yesterday,
                    onTap: () => setState(() => _date = yesterday),
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: ChoiceTile(
                    label: other ? dayLabel(context, _date, widget.today) : l.measurementOtherDate,
                    selected: other,
                    onTap: _pickOtherDate,
                  ),
                ),
              ],
            ),
            const SizedBox(height: N360Space.md),
            GloveTextField(
              label: l.measurementLocationLabel,
              hint: l.measurementLocationHint,
              controller: _location,
              textInputAction: TextInputAction.next,
              inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(_locationLimit)],
            ),
            const SizedBox(height: N360Space.md),
            GloveTextField(
              label: l.measurementDescriptionLabel,
              hint: l.measurementDescriptionHint,
              controller: _description,
              maxLines: 2,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _done(),
              inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(_descriptionLimit)],
            ),
            const SizedBox(height: N360Space.md),
            SectionLabel(l.measurementPhotosTitle),
            const SizedBox(height: N360Space.xs),
            ProgressPhotoRow(
              key: const ValueKey<String>('measurement-form-photos'),
              photos: _photos,
              max: RecordPhotos.maxPerKind,
              onAdd: (String path) => setState(() => _photos = <String>[..._photos, path]),
              onRemove: (String path) => setState(() => _photos = <String>[for (final String p in _photos) if (p != path) p]),
            ),
            const SizedBox(height: N360Space.lg),
            GloveButton(label: l.commonDone, large: true, fullWidth: true, icon: Icons.check, onPressed: _done),
          ],
        ),
      ),
    );
  }
}
