import 'package:flutter/material.dart';

class ConstantColors {
  // =============================================
  // WENDY UBER - PALETA VIOLETA + AZUL ELÉCTRICO
  // =============================================

  // Fondos
  static const Color backgroundDark = Color(0xFF0D0D1A);   // Azul noche profundo
  static const Color backgroundCard = Color(0xFF13132A);   // Tarjetas/contenedores
  static const Color backgroundLight = Color(0xFF1A1A35);  // Fondo secundario

  // Colores primarios
  static const Color primaryViolet = Color(0xFFA78BFA);    // Violeta principal
  static const Color primaryBlue = Color(0xFF60A5FA);      // Azul eléctrico
  static const Color accentWhatsApp = Color(0xFF128C7E);   // Verde WhatsApp

  // Gradientes
  static const Color gradientStart = Color(0xFFA78BFA);    // Violeta
  static const Color gradientEnd = Color(0xFF60A5FA);      // Azul eléctrico

  // Texto
  static const Color textWhite = Color(0xFFFFFFFF);
  static const Color textGrey = Color(0xFF9CA3AF);
  static const Color textSubtle = Color(0xFF6B7280);

  // Estado / acciones
  static const Color success = Color(0xFF34D399);
  static const Color error = Color(0xFFEF4444);
  static const Color warning = Color(0xFFFBBF24);

  // Separadores / bordes
  static const Color borderColor = Color(0xFF2D2D5E);
  static const Color dividerColor = Color(0xFF1F1F3D);

  // --- Compatibilidad con código antiguo ---
  static const Color PrimaryColor = primaryViolet;
  static const Color ActivePink = Color(0xfffb376a);
  static const Color DeepBlue = backgroundDark;

  // Gradiente listo para usar
  static const LinearGradient primaryGradient = LinearGradient(
    colors: [gradientStart, gradientEnd],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient buttonGradient = LinearGradient(
    colors: [Color(0xFFA78BFA), Color(0xFF60A5FA)],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );
}
