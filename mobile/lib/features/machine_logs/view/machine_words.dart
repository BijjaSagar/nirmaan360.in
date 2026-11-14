import '../../../l10n/generated/app_localizations.dart';
import '../../measurement/bloc/measurement_book.dart' show formatQty;
import '../data/machine_repository.dart';

/// Words for machines and what they worked. No rate, hire term or cost appears
/// anywhere in this feature: the server sends none and the screens ask for none.
extension MachineWords on AppLocalizations {
  /// Null for a category this app has no word for.
  String? machineCategoryWord(String? category) => switch (category) {
    'excavator' => machineCategoryExcavator,
    'backhoe' => machineCategoryBackhoe,
    'crane' => machineCategoryCrane,
    'mixer' => machineCategoryMixer,
    'transit_mixer' => machineCategoryTransitMixer,
    'pump' => machineCategoryPump,
    'roller' => machineCategoryRoller,
    'tipper' => machineCategoryTipper,
    'dg_set' => machineCategoryDgSet,
    'compressor' => machineCategoryCompressor,
    'vehicle' => machineCategoryVehicle,
    'formwork' => machineCategoryFormwork,
    'scaffolding' => machineCategoryScaffolding,
    'tools' => machineCategoryTools,
    'other' => machineCategoryOther,
    _ => null,
  };

  /// `AST-0001 · JCB 3DX`, or a number for a machine this phone has not seen.
  String machineLogTitle(Machine? machine, int assetId) =>
      machine?.displayName.isNotEmpty ?? false ? machine!.displayName : machinesUnknownMachine(assetId);

  /// What a day's log says it did, in the app's words: hours, km, breakdown.
  /// A day with none of them was an idle one, so it says no hours.
  List<String> machineLogFigures(MachineLog log) {
    final List<String> parts = <String>[
      if (log.hasHours) machinesHoursValue(log.hours!.toWire()),
      if (log.hasKm) machinesKmValue(formatQty(log.km!)),
      if (log.hasBreakdown) machinesBreakdownValue(log.breakdownHours!.toWire()),
    ];
    if (parts.isEmpty && log.hours != null) parts.add(machinesHoursValue(log.hours!.toWire()));
    return parts;
  }
}
