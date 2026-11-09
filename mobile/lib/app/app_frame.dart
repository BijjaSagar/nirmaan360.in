import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/design/design.dart';
import '../l10n/generated/app_localizations.dart';

const SystemUiOverlayStyle _chrome = SystemUiOverlayStyle(
  statusBarColor: Colors.transparent,
  statusBarIconBrightness: Brightness.dark,
  statusBarBrightness: Brightness.light,
  // The bottom bar is white and sits on the system bar, so the two read as one.
  systemNavigationBarColor: N360Colors.sheet,
  systemNavigationBarIconBrightness: Brightness.dark,
);

/// The `MaterialApp` every screen lives in.
///
/// It fits the theme to the script of the app language (Marathi and Hindi get
/// the Devanagari text roles), clamps the system text size once for the whole
/// app, and lays the shared paper under every page so page turns slide over it.
/// `Nirmaan360App` builds it with the real router; a test can build it with a
/// router of its own.
class N360AppFrame extends StatelessWidget {
  const N360AppFrame({super.key, required this.router, this.locale});

  final RouterConfig<Object> router;

  /// The stored app language, or null to follow the phone.
  final Locale? locale;

  /// Whether the app will render in Devanagari for [locale]. With no stored
  /// choice the phone's language decides, as `MaterialApp` resolves it.
  static bool devanagariFor(Locale? locale) {
    final String code = locale?.languageCode ?? WidgetsBinding.instance.platformDispatcher.locale.languageCode;
    return code == 'mr' || code == 'hi';
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      debugShowCheckedModeBanner: false,
      onGenerateTitle: (context) => AppLocalizations.of(context).appName,
      theme: buildN360Theme(devanagari: devanagariFor(locale)),
      routerConfig: router,
      locale: locale,
      localizationsDelegates: AppLocalizations.localizationsDelegates,
      supportedLocales: AppLocalizations.supportedLocales,
      builder: (context, child) => AnnotatedRegion<SystemUiOverlayStyle>(
        value: _chrome,
        child: N360TextScaling(child: LedgerPaper.root(child: child!)),
      ),
    );
  }
}
