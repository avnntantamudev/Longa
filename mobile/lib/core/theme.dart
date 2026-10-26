import 'package:flutter/material.dart';

/// Cores e tipografia do kit LONGA (resources/css/app.css).
class LongaColors {
  const LongaColors._();

  static const navy50 = Color(0xFFEEF3FA);
  static const navy100 = Color(0xFFD6E0EF);
  static const navy200 = Color(0xFFADC1DE);
  static const navy500 = Color(0xFF2F558C);
  static const navy700 = Color(0xFF163156);
  static const navy900 = Color(0xFF0B1F3A);
  static const orange50 = Color(0xFFFFF4E8);
  static const orange400 = Color(0xFFFF9129);
  static const orange500 = Color(0xFFFF7A00);
  static const orange600 = Color(0xFFDB6500);
  static const surface = Color(0xFFFFFFFF);
  static const surfaceMuted = Color(0xFFF5F7FA);
  static const border = Color(0xFFE3E8EF);
  static const ink = Color(0xFF0B1F3A);
  static const inkMuted = Color(0xFF5B6B82);
  static const success = Color(0xFF16A34A);
  static const successSoft = Color(0xFFE8F7EE);
  static const warning = Color(0xFFD97706);
  static const danger = Color(0xFFDC2626);
  static const dangerSoft = Color(0xFFFDECEC);
}

ThemeData buildLongaTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: LongaColors.orange500,
    primary: LongaColors.orange500,
    onPrimary: Colors.white,
    secondary: LongaColors.navy900,
    surface: LongaColors.surface,
    error: LongaColors.danger,
  );

  final controlShape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(14));

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: LongaColors.surfaceMuted,
    appBarTheme: const AppBarTheme(backgroundColor: LongaColors.navy900, foregroundColor: Colors.white, centerTitle: false),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52), shape: controlShape, textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 16)),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), shape: controlShape, foregroundColor: LongaColors.navy900),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: LongaColors.border)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: LongaColors.border)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: LongaColors.orange500, width: 2)),
    ),
    cardTheme: CardThemeData(color: Colors.white, elevation: 0, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18), side: const BorderSide(color: LongaColors.border))),
    navigationBarTheme: const NavigationBarThemeData(indicatorColor: LongaColors.orange50),
  );
}
