import 'package:flutter/material.dart';

class ConstantColors {
  // =============================================
  // WENDY UBER - PALETA VIOLETA + AZUL ELÉCTRICO
  // =============================================

  // Fondos
  static const Color backgroundDark = Color(0xFF0D0D1A); // Azul noche profundo
  static const Color backgroundCard =
      Color(0xFF13132A); // Tarjetas/contenedores
  static const Color backgroundLight = Color(0xFF1A1A35); // Fondo secundario

  // Colores primarios
  static const Color primaryViolet = Color(0xFFA78BFA); // Violeta principal
  static const Color primaryBlue = Color(0xFF60A5FA); // Azul eléctrico
  static const Color accentWhatsApp = Color(0xFF128C7E); // Verde WhatsApp

  // Gradientes
  static const Color gradientStart = Color(0xFFA78BFA); // Violeta
  static const Color gradientEnd = Color(0xFF60A5FA); // Azul eléctrico

  // Texto
  static const Color textWhite = Color(0xFFFFFFFF);
  static const Color textGrey = Color(0xFF9CA3AF);
  static const Color textSubtle = Color(0xFF6B7280);
  static const Color textDark =
      Color(0xFF1F2937); // Texto oscuro para fondos claros
  static const Color textDarkGrey = Color(0xFF374151); // Texto gris oscuro

  // Estado / acciones
  static const Color success = Color(0xFF34D399);
  static const Color error = Color(0xFFEF4444);
  static const Color warning = Color(0xFFFBBF24);

  // Fondos alternativos (claros/AMARILLOS para mobile)
  static const Color backgroundYellow = Color(0xFFFBBF24); // Amarillo principal
  static const Color backgroundYellowLight =
      Color(0xFFFEF3C7); // Amarillo muy claro
  static const Color backgroundYellowPale =
      Color(0xFFfef3c7); // Amarillo pálido
  static const Color backgroundAmber = Color(0xFFF59E0B); // Ámbar
  // Amarillo-azul mixto (más suave)
  static const Color backgroundYellowBlue =
      Color(0xFFEFF6FF); // Azul muy claro con tinte amarillo
  static const Color backgroundAmberLight = Color(0xFFFEF3C7); // Amarillo suave

  // Separadores / bordes
  static const Color borderColor = Color(0xFF2D2D5E);
  static const Color dividerColor = Color(0xFF1F1F3D);
  static const Color borderLight = Color(0xFFD1D5DB); // Borde gris claro
  static const Color borderYellow = Color(0xFFFCD34D); // Borde amarillo

  // Grises alternativos para fondos claros
  static const Color grey100 = Color(0xFFF3F4F6);
  static const Color grey200 = Color(0xFFE5E7EB);
  static const Color grey300 = Color(0xFFD1D5DB);
  static const Color grey400 = Color(0xFF9CA3AF);

  // Azul marino para pasos
  static const Color primaryNavy = Color(0xFF1E3A5F);
  static const Color primaryNavyLight = Color(0xFF2E4A6F);

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
    colors: [warning, primaryBlue],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  static const LinearGradient yellowBlueGradient = LinearGradient(
    colors: [warning, primaryBlue],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  // Gradiente amarillo para fondos
  static const LinearGradient yellowGradient = LinearGradient(
    colors: [backgroundYellowLight, backgroundYellowPale],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
  );

  static const LinearGradient amberGradient = LinearGradient(
    colors: [backgroundAmber, warning],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
  );

  // Gradiente Amarillo + Azul + Gris
  static const LinearGradient yellowBlueGreyGradient = LinearGradient(
    colors: [warning, primaryBlue, grey300],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  // Gradiente Azul + Amarillo
  static const LinearGradient blueYellowGradient = LinearGradient(
    colors: [primaryBlue, warning],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  // Gradiente Gris claro para fondos
  static const LinearGradient greyGradient = LinearGradient(
    colors: [grey100, grey200],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
  );
}
