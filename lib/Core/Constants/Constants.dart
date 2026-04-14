class Constants {
  static const mapApiKey = "add your api key";
  static const anotherApiKey = "add your api key";
  static const destinationMarkerId = "DestinationMarker";
  static const pickupMarkerId = "PickupMarker";
  static const currentLocationMarkerId = "currentLocationMarker";
  static const currentRoutePolylineId = "currentRoutePolyline";
  static const driverMarkerId = "CurrentDriverMarker";
  static const driverOriginPolyId = "driverOriginPolyLine";

  /// Base URL para los endpoints PHP.
  /// Apunta al servidor de producción: corporativoqbank.com
  /// Para desarrollo local sobreescribe con --dart-define:
  ///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2/SUPER_IA/server_php   (emulador)
  ///   flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8080/SUPER_IA/server_php  (USB)
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://corporativoqbank.com/SUPER_IA/server_php',
  );

  // ─── PayPhone ────────────────────────────────────────────────────────────
  /// Reemplaza con tu token real de PayPhone (obtenido en app.payphone.app → Integración → Token)
  static const payPhoneToken = 'TU_TOKEN_PAYPHONE_AQUI';

  /// URL base de la API de PayPhone Button
  static const payPhoneApiUrl = 'https://pay.payphonetodoesposible.com/api';
}