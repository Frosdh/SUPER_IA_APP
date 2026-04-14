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
  ///
  /// Opciones típicas:
  /// - Emulador Android (localhost del PC): `http://10.0.2.2/SUPER_IA/server_php`
  /// - Teléfono físico por Wi‑Fi/LAN: `http://<IP_DEL_PC>/SUPER_IA/server_php`
  /// - Teléfono físico por USB (si el Wi‑Fi está aislado):
  ///   1) `adb reverse tcp:8080 tcp:80`
  ///   2) `flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8080/SUPER_IA/server_php`
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://192.168.100.26/SUPER_IA/server_php',
  );

  // ─── PayPhone ────────────────────────────────────────────────────────────
  /// Reemplaza con tu token real de PayPhone (obtenido en app.payphone.app → Integración → Token)
  static const payPhoneToken = 'TU_TOKEN_PAYPHONE_AQUI';

  /// URL base de la API de PayPhone Button
  static const payPhoneApiUrl = 'https://pay.payphonetodoesposible.com/api';

  /// URLs de retorno que el WebView intercepta para saber el resultado del pago.
  /// Deben existir en tu servidor y responder HTTP 200.
  static const payPhoneResponseUrl =
      'http://corporativoqbank.com/fuber_api/payment_response.php';
  static const payPhoneCancelUrl =
      'http://corporativoqbank.com/fuber_api/payment_cancel.php';
}
