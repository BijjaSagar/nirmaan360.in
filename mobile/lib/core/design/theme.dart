import 'package:flutter/material.dart';

import 'signature/page_transitions.dart';
import 'tokens.dart';
import 'typography.dart';

/// The app's `ThemeData`, so even plain Material widgets land on the ledger:
/// warm page, soft rounded corners, no tint, ripple in faint ink.
ThemeData buildN360Theme() {
  const ColorScheme scheme = ColorScheme(
    brightness: Brightness.light,
    primary: N360Colors.haldi,
    onPrimary: N360Colors.ink,
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
    inversePrimary: N360Colors.haldi,
  );

  const OutlineInputBorder resting = OutlineInputBorder(
    borderRadius: N360Radius.md,
    borderSide: BorderSide(color: N360Colors.ruleStrong, width: 1),
  );

  final ButtonStyle pressed = ButtonStyle(
    overlayColor: WidgetStateProperty.all(N360Colors.ink.withValues(alpha: 0.07)),
    shape: WidgetStateProperty.all(const RoundedRectangleBorder(borderRadius: N360Radius.tileRadius)),
    minimumSize: WidgetStateProperty.all(const Size(64, N360Size.tap)),
    textStyle: WidgetStateProperty.all(N360Type.button),
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
    canvasColor: N360Colors.paper,
    cardColor: N360Colors.sheet,
    dividerColor: N360Colors.rule,
    disabledColor: N360Colors.inkLabel,
    hintColor: N360Colors.inkLabel,
    shadowColor: const Color(0x00000000),
    splashFactory: InkRipple.splashFactory,
    splashColor: N360Colors.ink.withValues(alpha: 0.08),
    highlightColor: N360Colors.ink.withValues(alpha: 0.05),
    hoverColor: N360Colors.ink.withValues(alpha: 0.04),
    focusColor: N360Colors.ink.withValues(alpha: 0.08),
    visualDensity: VisualDensity.standard,
    materialTapTargetSize: MaterialTapTargetSize.padded,
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
    textTheme: const TextTheme(
      displayLarge: N360Type.displayLarge,
      displayMedium: N360Type.display,
      displaySmall: N360Type.title,
      headlineLarge: N360Type.display,
      headlineMedium: N360Type.title,
      headlineSmall: N360Type.heading,
      titleLarge: N360Type.title,
      titleMedium: N360Type.heading,
      titleSmall: N360Type.bodyStrong,
      bodyLarge: N360Type.bodyLarge,
      bodyMedium: N360Type.body,
      bodySmall: N360Type.caption,
      labelLarge: N360Type.button,
      labelMedium: N360Type.label,
      labelSmall: N360Type.caption,
    ),
    iconTheme: const IconThemeData(color: N360Colors.ink, size: 26),
    appBarTheme: const AppBarTheme(
      backgroundColor: Color(0x00000000),
      foregroundColor: N360Colors.ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      surfaceTintColor: Color(0x00000000),
      shadowColor: Color(0x00000000),
      centerTitle: false,
      toolbarHeight: N360Size.tapLarge,
      titleTextStyle: N360Type.title,
      iconTheme: IconThemeData(color: N360Colors.ink, size: 28),
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
    dialogTheme: const DialogThemeData(
      backgroundColor: N360Colors.sheet,
      elevation: 0,
      surfaceTintColor: Color(0x00000000),
      shape: RoundedRectangleBorder(
        borderRadius: N360Radius.cardRadius,
        side: BorderSide(color: N360Colors.cardEdge),
      ),
      titleTextStyle: N360Type.title,
      contentTextStyle: N360Type.body,
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: N360Colors.sheet,
      modalBackgroundColor: N360Colors.sheet,
      elevation: 0,
      modalElevation: 0,
      surfaceTintColor: Color(0x00000000),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(N360Radius.card)),
        side: BorderSide(color: N360Colors.cardEdge),
      ),
    ),
    snackBarTheme: const SnackBarThemeData(
      backgroundColor: N360Colors.ink,
      contentTextStyle: TextStyle(fontFamily: N360Type.mukta, fontSize: 16, color: N360Colors.paper),
      elevation: 0,
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: N360Radius.md),
    ),
    inputDecorationTheme: const InputDecorationTheme(
      filled: true,
      fillColor: N360Colors.sheet,
      contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 18),
      hintStyle: N360Type.bodyLarge,
      labelStyle: N360Type.label,
      floatingLabelBehavior: FloatingLabelBehavior.always,
      errorStyle: TextStyle(fontFamily: N360Type.mukta, fontSize: 14, color: N360Colors.stampRed),
      border: resting,
      enabledBorder: resting,
      focusedBorder: OutlineInputBorder(
        borderRadius: N360Radius.md,
        borderSide: BorderSide(color: N360Colors.laterite, width: 2.25),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: N360Radius.md,
        borderSide: BorderSide(color: N360Colors.stampRed, width: 2),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: N360Radius.md,
        borderSide: BorderSide(color: N360Colors.stampRed, width: 2.25),
      ),
    ),
    textSelectionTheme: TextSelectionThemeData(
      cursorColor: N360Colors.laterite,
      selectionColor: N360Colors.haldi.withValues(alpha: 0.4),
      selectionHandleColor: N360Colors.laterite,
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(
      color: N360Colors.haldi,
      linearTrackColor: N360Colors.rule,
      circularTrackColor: N360Colors.rule,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: pressed.copyWith(
        backgroundColor: WidgetStateProperty.all(N360Colors.haldi),
        foregroundColor: WidgetStateProperty.all(N360Colors.ink),
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: pressed.copyWith(
        backgroundColor: WidgetStateProperty.all(N360Colors.haldi),
        foregroundColor: WidgetStateProperty.all(N360Colors.ink),
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
      ),
    ),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(
      backgroundColor: N360Colors.haldi,
      foregroundColor: N360Colors.ink,
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
      shape: const RoundedRectangleBorder(borderRadius: N360Radius.sm),
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
    listTileTheme: const ListTileThemeData(
      minVerticalPadding: N360Space.md,
      minTileHeight: N360Size.tap,
      iconColor: N360Colors.ink2,
      textColor: N360Colors.ink,
      titleTextStyle: N360Type.bodyLarge,
      subtitleTextStyle: N360Type.caption,
    ),
    chipTheme: const ChipThemeData(
      backgroundColor: N360Colors.sheet,
      selectedColor: N360Colors.haldi,
      labelStyle: N360Type.label,
      side: BorderSide(color: N360Colors.ruleStrong),
      shape: RoundedRectangleBorder(borderRadius: N360Radius.sm),
      padding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
    ),
    tabBarTheme: const TabBarThemeData(
      labelColor: N360Colors.ink,
      unselectedLabelColor: N360Colors.inkLabel,
      indicatorColor: N360Colors.haldi,
      dividerColor: N360Colors.rule,
      labelStyle: N360Type.bodyStrong,
      unselectedLabelStyle: N360Type.body,
    ),
    popupMenuTheme: const PopupMenuThemeData(
      color: N360Colors.sheet,
      elevation: 0,
      surfaceTintColor: Color(0x00000000),
      shape: RoundedRectangleBorder(
        borderRadius: N360Radius.md,
        side: BorderSide(color: N360Colors.cardEdge),
      ),
      textStyle: N360Type.bodyLarge,
    ),
    tooltipTheme: TooltipThemeData(
      decoration: const BoxDecoration(color: N360Colors.ink, borderRadius: N360Radius.sm),
      textStyle: N360Type.caption.copyWith(color: N360Colors.paper),
    ),
    scrollbarTheme: ScrollbarThemeData(
      thumbColor: WidgetStateProperty.all(N360Colors.ink3),
      radius: const Radius.circular(N360Radius.small),
    ),
  );
}
