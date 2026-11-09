import 'package:flutter/material.dart';

import 'signature/page_transitions.dart';
import 'tokens.dart';
import 'typography.dart';

/// The app's `ThemeData`, so even plain Material widgets land on the ledger:
/// warm page, soft rounded corners, no tint, ripple in faint ink.
///
/// Pass [devanagari] when the app locale is Marathi or Hindi: every text role
/// in the theme is then fitted for the script (see [N360Type.forDevanagari]),
/// so a plain `Text` with no explicit style reads as large as its English
/// counterpart. Screens that pass `N360Type.*` styles directly should wrap them
/// in [N360Type.fit] for the same effect.
ThemeData buildN360Theme({bool devanagari = false}) {
  TextStyle t(TextStyle style) => devanagari ? N360Type.forDevanagari(style) : style;

  const ColorScheme scheme = ColorScheme(
    brightness: Brightness.light,
    primary: N360Colors.primary,
    onPrimary: N360Colors.onPrimary,
    primaryContainer: N360Colors.paper2,
    onPrimaryContainer: N360Colors.ink,
    secondary: N360Colors.laterite,
    onSecondary: N360Colors.sheet,
    secondaryContainer: N360Colors.paper2,
    onSecondaryContainer: N360Colors.ink,
    tertiary: N360Colors.moss,
    onTertiary: N360Colors.sheet,
    error: N360Colors.stampRed,
    onError: N360Colors.sheet,
    surface: N360Colors.paper,
    onSurface: N360Colors.ink,
    surfaceTint: Color(0x00000000),
    surfaceContainerLowest: N360Colors.sheet,
    surfaceContainerLow: N360Colors.sheet,
    surfaceContainer: N360Colors.paper,
    surfaceContainerHigh: N360Colors.paper2,
    surfaceContainerHighest: N360Colors.paper2,
    onSurfaceVariant: N360Colors.inkLabel,
    outline: N360Colors.ruleStrong,
    outlineVariant: N360Colors.rule,
    shadow: Color(0x00000000),
    scrim: N360Colors.ink,
    inverseSurface: N360Colors.ink,
    onInverseSurface: N360Colors.paper,
    inversePrimary: N360Colors.primaryTint,
  );

  const OutlineInputBorder resting = OutlineInputBorder(
    borderRadius: N360Radius.md,
    borderSide: BorderSide(color: N360Colors.ruleStrong, width: 1),
  );

  final ButtonStyle pressed = ButtonStyle(
    overlayColor: WidgetStateProperty.all(N360Colors.ink.withValues(alpha: 0.07)),
    shape: WidgetStateProperty.all(const RoundedRectangleBorder(borderRadius: N360Radius.tileRadius)),
    minimumSize: WidgetStateProperty.all(const Size(64, N360Size.tap)),
    padding: WidgetStateProperty.all(const EdgeInsets.symmetric(horizontal: N360Space.lg, vertical: N360Space.sm)),
    textStyle: WidgetStateProperty.all(t(N360Type.button)),
    elevation: WidgetStateProperty.all(0),
    shadowColor: WidgetStateProperty.all(const Color(0x00000000)),
    tapTargetSize: MaterialTapTargetSize.padded,
  );

  return ThemeData(
    useMaterial3: true,
    brightness: Brightness.light,
    colorScheme: scheme,
    fontFamily: N360Type.mukta,
    scaffoldBackgroundColor: N360Colors.paper,
    dividerColor: N360Colors.rule,
    shadowColor: const Color(0x00000000),
    splashFactory: InkRipple.splashFactory,
    splashColor: N360Colors.ink.withValues(alpha: 0.06),
    highlightColor: N360Colors.ink.withValues(alpha: 0.04),
    hoverColor: N360Colors.ink.withValues(alpha: 0.03),
    focusColor: N360Colors.ink.withValues(alpha: 0.06),
    visualDensity: VisualDensity.standard,
    materialTapTargetSize: MaterialTapTargetSize.padded,
    canvasColor: N360Colors.paper,
    cardColor: N360Colors.sheet,
    disabledColor: N360Colors.inkLabel,
    hintColor: N360Colors.inkLabel,
    pageTransitionsTheme: const PageTransitionsTheme(
      builders: <TargetPlatform, PageTransitionsBuilder>{
        TargetPlatform.android: N360PageTransitionsBuilder(),
        TargetPlatform.iOS: N360PageTransitionsBuilder(),
        TargetPlatform.macOS: N360PageTransitionsBuilder(),
        TargetPlatform.windows: N360PageTransitionsBuilder(),
        TargetPlatform.linux: N360PageTransitionsBuilder(),
        TargetPlatform.fuchsia: N360PageTransitionsBuilder(),
      },
    ),
    textTheme: TextTheme(
      displayLarge: t(N360Type.displayLarge),
      displayMedium: t(N360Type.display),
      displaySmall: t(N360Type.title),
      headlineLarge: t(N360Type.display),
      headlineMedium: t(N360Type.title),
      headlineSmall: t(N360Type.heading),
      titleLarge: t(N360Type.title),
      titleMedium: t(N360Type.heading),
      titleSmall: t(N360Type.bodyStrong),
      bodyLarge: t(N360Type.bodyLarge),
      bodyMedium: t(N360Type.body),
      bodySmall: t(N360Type.caption),
      labelLarge: t(N360Type.button),
      labelMedium: t(N360Type.label),
      labelSmall: t(N360Type.caption),
    ),
    iconTheme: const IconThemeData(color: N360Colors.ink, size: N360IconSize.lg),
    appBarTheme: AppBarTheme(
      backgroundColor: const Color(0x00000000),
      foregroundColor: N360Colors.ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      surfaceTintColor: const Color(0x00000000),
      shadowColor: const Color(0x00000000),
      centerTitle: false,
      toolbarHeight: N360Size.tap,
      titleTextStyle: t(N360Type.title),
      iconTheme: const IconThemeData(color: N360Colors.ink, size: N360IconSize.xl),
    ),
    dividerTheme: const DividerThemeData(color: N360Colors.rule, thickness: 1, space: 1),
    cardTheme: const CardThemeData(
      color: N360Colors.sheet,
      elevation: 0,
      margin: EdgeInsets.zero,
      surfaceTintColor: Color(0x00000000),
      shape: RoundedRectangleBorder(
        borderRadius: N360Radius.cardRadius,
        side: BorderSide(color: N360Colors.cardEdge),
      ),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: N360Colors.sheet,
      elevation: 0,
      surfaceTintColor: const Color(0x00000000),
      shape: const RoundedRectangleBorder(
        borderRadius: N360Radius.cardRadius,
        side: BorderSide(color: N360Colors.cardEdge),
      ),
      titleTextStyle: t(N360Type.title),
      contentTextStyle: t(N360Type.body),
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: N360Colors.sheet,
      modalBackgroundColor: N360Colors.sheet,
      elevation: 0,
      modalElevation: 0,
      surfaceTintColor: Color(0x00000000),
      dragHandleColor: N360Colors.ruleStrong,
      dragHandleSize: Size(32, 3),
      showDragHandle: true,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(N360Radius.card)),
        side: BorderSide(color: N360Colors.cardEdge),
      ),
    ),
    snackBarTheme: SnackBarThemeData(
      backgroundColor: N360Colors.ink,
      contentTextStyle: t(N360Type.body).copyWith(color: N360Colors.paper),
      elevation: 0,
      behavior: SnackBarBehavior.floating,
      shape: const RoundedRectangleBorder(borderRadius: N360Radius.md),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: N360Colors.sheet,
      contentPadding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.card),
      hintStyle: t(N360Type.bodyLarge).copyWith(color: N360Colors.inkLabel),
      labelStyle: t(N360Type.label),
      floatingLabelBehavior: FloatingLabelBehavior.always,
      errorStyle: t(N360Type.caption).copyWith(color: N360Colors.stampRed),
      border: resting,
      enabledBorder: resting,
      focusedBorder: const OutlineInputBorder(
        borderRadius: N360Radius.md,
        borderSide: BorderSide(color: N360Colors.laterite, width: 2),
      ),
      errorBorder: const OutlineInputBorder(
        borderRadius: N360Radius.md,
        borderSide: BorderSide(color: N360Colors.stampRed, width: 1.5),
      ),
      focusedErrorBorder: const OutlineInputBorder(
        borderRadius: N360Radius.md,
        borderSide: BorderSide(color: N360Colors.stampRed, width: 2),
      ),
    ),
    textSelectionTheme: TextSelectionThemeData(
      cursorColor: N360Colors.laterite,
      selectionColor: N360Colors.primaryTint,
      selectionHandleColor: N360Colors.laterite,
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(
      color: N360Colors.orange,
      linearTrackColor: N360Colors.rule,
      circularTrackColor: N360Colors.rule,
      strokeWidth: 2.5,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: pressed.copyWith(
        backgroundColor: WidgetStateProperty.all(N360Colors.primary),
        foregroundColor: WidgetStateProperty.all(N360Colors.onPrimary),
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: pressed.copyWith(
        backgroundColor: WidgetStateProperty.all(N360Colors.primary),
        foregroundColor: WidgetStateProperty.all(N360Colors.onPrimary),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: pressed.copyWith(
        foregroundColor: WidgetStateProperty.all(N360Colors.ink),
        side: WidgetStateProperty.all(const BorderSide(color: N360Colors.ruleStrong, width: 1.25)),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: pressed.copyWith(foregroundColor: WidgetStateProperty.all(N360Colors.ink)),
    ),
    iconButtonTheme: IconButtonThemeData(
      style: ButtonStyle(
        overlayColor: WidgetStateProperty.all(N360Colors.ink.withValues(alpha: 0.08)),
        minimumSize: WidgetStateProperty.all(const Size.square(N360Size.tap)),
        iconSize: WidgetStateProperty.all(N360IconSize.xl),
      ),
    ),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(
      backgroundColor: N360Colors.primary,
      foregroundColor: N360Colors.onPrimary,
      elevation: 0,
      focusElevation: 0,
      hoverElevation: 0,
      highlightElevation: 0,
      shape: RoundedRectangleBorder(borderRadius: N360Radius.tileRadius),
    ),
    checkboxTheme: CheckboxThemeData(
      materialTapTargetSize: MaterialTapTargetSize.padded,
      fillColor: WidgetStateProperty.resolveWith((states) {
        return states.contains(WidgetState.selected) ? N360Colors.ink : N360Colors.sheet;
      }),
      checkColor: WidgetStateProperty.all(N360Colors.sheet),
      side: const BorderSide(color: N360Colors.ruleStrong, width: 1.5),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(6))),
    ),
    radioTheme: RadioThemeData(
      fillColor: WidgetStateProperty.resolveWith((states) {
        return states.contains(WidgetState.selected) ? N360Colors.laterite : N360Colors.ruleStrong;
      }),
    ),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith((states) {
        return states.contains(WidgetState.selected) ? N360Colors.sheet : N360Colors.ink3;
      }),
      trackColor: WidgetStateProperty.resolveWith((states) {
        return states.contains(WidgetState.selected) ? N360Colors.moss : N360Colors.rule;
      }),
    ),
    listTileTheme: ListTileThemeData(
      minVerticalPadding: N360Space.sm + 2,
      minTileHeight: N360Size.tap,
      iconColor: N360Colors.ink2,
      textColor: N360Colors.ink,
      titleTextStyle: t(N360Type.bodyStrong),
      subtitleTextStyle: t(N360Type.caption),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: N360Colors.sheet,
      selectedColor: N360Colors.ink,
      labelStyle: t(N360Type.label),
      side: const BorderSide(color: N360Colors.ruleStrong),
      shape: const RoundedRectangleBorder(borderRadius: N360Radius.sm),
      padding: const EdgeInsets.symmetric(horizontal: N360Space.tile, vertical: 6),
    ),
    tabBarTheme: TabBarThemeData(
      labelColor: N360Colors.ink,
      unselectedLabelColor: N360Colors.inkLabel,
      indicatorColor: N360Colors.primary,
      dividerColor: N360Colors.rule,
      labelStyle: t(N360Type.bodyStrong),
      unselectedLabelStyle: t(N360Type.body),
    ),
    popupMenuTheme: PopupMenuThemeData(
      color: N360Colors.sheet,
      elevation: 0,
      surfaceTintColor: const Color(0x00000000),
      shape: const RoundedRectangleBorder(
        borderRadius: N360Radius.md,
        side: BorderSide(color: N360Colors.cardEdge),
      ),
      textStyle: t(N360Type.body),
    ),
    tooltipTheme: TooltipThemeData(
      decoration: const BoxDecoration(color: N360Colors.ink, borderRadius: N360Radius.sm),
      textStyle: t(N360Type.caption).copyWith(color: N360Colors.paper),
    ),
    scrollbarTheme: ScrollbarThemeData(
      thumbColor: WidgetStateProperty.all(N360Colors.ink3),
      radius: const Radius.circular(N360Radius.small),
    ),
  );
}
