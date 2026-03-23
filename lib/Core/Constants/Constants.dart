class Constants {
  static const mapApiKey = "add your api key";
  static const anotherApiKey = "add your api key";
  static const destinationMarkerId = "DestinationMarker";
  static const pickupMarkerId = "PickupMarker";
  static const currentLocationMarkerId = "currentLocationMarker";
  static const currentRoutePolylineId = "currentRoutePolyline";
  static const driverMarkerId = "CurrentDriverMarker";
  static const driverOriginPolyId = "driverOriginPolyLine";
  static const apiBaseUrl = "http://corporativoqbank.com/fuber_api";

  // ─── PayPhone ────────────────────────────────────────────────────────────
  /// Reemplaza con tu token real de PayPhone (obtenido en app.payphone.app → Integración → Token)
  static const payPhoneToken = 'TU_TOKEN_PAYPHONE_AQUI';

  /// URL base de la API de PayPhone Button
  static const payPhoneApiUrl = 'https://pay.payphonetodoesposible.com/api';

  /// URLs de retorno que el WebView intercepta para saber el resultado del pago.
  /// Deben existir en tu servidor y responder HTTP 200.
  static const payPhoneResponseUrl = 'http://corporativoqbank.com/fuber_api/payment_response.php';
  static const payPhoneCancelUrl   = 'http://corporativoqbank.com/fuber_api/payment_cancel.php';
}
