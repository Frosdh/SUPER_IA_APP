import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

class ShareTripService {
  /// Genera un mensaje con los detalles del viaje para compartir
  static String generarMensajeViaje({
    required String nombrePasajero,
    required String destinoNombre,
    required LatLng ubicacionActual,
    required LatLng destino,
    double distanciaKm = 0.0,
    int duracionMin = 0,
    double precioEstimado = 0.0,
    String nombreConductor = '',
    String placaConductor = '',
    String autoConductor = '',
    int etaMin = 0,
  }) {
    // Enlace de Google Maps con coordenadas
    final mapsUrl = 'https://maps.google.com/?q=${ubicacionActual.latitude},${ubicacionActual.longitude}';

    // Construir mensaje
    String mensaje = '📍 *Estoy en un viaje GeoMove*\n\n';

    if (nombrePasajero.isNotEmpty) {
      mensaje += '👤 Pasajero: $nombrePasajero\n';
    }

    if (nombreConductor.isNotEmpty) {
      mensaje += '🚗 Conductor: $nombreConductor\n';
    }

    if (placaConductor.isNotEmpty) {
      mensaje += '📋 Placa: $placaConductor\n';
    }

    if (autoConductor.isNotEmpty) {
      mensaje += '🚙 Vehículo: $autoConductor\n';
    }

    mensaje += '\n📍 *Ruta*\n';
    mensaje += '🔴 Destino: $destinoNombre\n';

    if (distanciaKm > 0) {
      mensaje += '📏 Distancia: ${distanciaKm.toStringAsFixed(2)} km\n';
    }

    if (duracionMin > 0) {
      mensaje += '⏱️ Tiempo estimado: $duracionMin minutos\n';
    }

    if (etaMin > 0) {
      mensaje += '⏳ ETA del conductor: $etaMin minutos\n';
    }

    if (precioEstimado > 0) {
      mensaje += '💵 Precio: \$${precioEstimado.toStringAsFixed(2)}\n';
    }

    mensaje += '\n🗺️ Mi ubicación: $mapsUrl\n\n';
    mensaje += '_Compartido desde GeoMove_ ✓';

    return mensaje;
  }

  /// Valida que el número de teléfono tenga un formato adecuado
  static bool validarTelefono(String? telefono) {
    if (telefono == null || telefono.isEmpty) return false;
    final cleaned = telefono.replaceAll(RegExp(r'[^\d+]'), '');
    return cleaned.length >= 10 && cleaned.length <= 15;
  }

  /// Formatea el teléfono con código de país para WhatsApp (Ecuador: +593)
  static String formatearTelefonoWhatsapp(String? telefono) {
    if (telefono == null || telefono.isEmpty) return '';

    // Limpiar caracteres no numéricos excepto +
    var cleaned = telefono.replaceAll(RegExp(r'[^\d+]'), '');

    // Si ya tiene +593, devolverlo como está
    if (cleaned.startsWith('+593')) return cleaned;

    // Si tiene +, pero no es +593, quitar el + y procesar
    if (cleaned.startsWith('+')) {
      cleaned = cleaned.substring(1);
    }

    // Si comienza con 593, ya tiene el código
    if (cleaned.startsWith('593')) return '+$cleaned';

    // Si comienza con 0 (formato local Ecuador), remover y agregar +593
    if (cleaned.startsWith('0')) {
      return '+593${cleaned.substring(1)}';
    }

    // Si solo tiene los 10 dígitos, agregar +593
    if (cleaned.length == 10) {
      return '+593$cleaned';
    }

    // Fallback: agregar +593 al principio
    return '+593$cleaned';
  }

  /// Envía el mensaje del viaje por WhatsApp
  static Future<void> compartirPorWhatsapp({
    required String telefono,
    required String mensaje,
  }) async {
    if (telefono.isEmpty) {
      throw Exception('Número de teléfono no proporcionado');
    }

    if (mensaje.isEmpty) {
      throw Exception('Mensaje vacío');
    }

    // Validar teléfono
    if (!validarTelefono(telefono)) {
      throw Exception('Número de teléfono inválido: $telefono');
    }

    // Formatear teléfono
    final telefonoFormateado = formatearTelefonoWhatsapp(telefono);

    // URL para WhatsApp
    final waUrl = Uri.parse('https://wa.me/$telefonoFormateado?text=${Uri.encodeComponent(mensaje)}');
    final waUrlScheme = Uri.parse('whatsapp://send?phone=$telefonoFormateado&text=${Uri.encodeComponent(mensaje)}');

    try {
      // Intentar primero con el esquema nativo de WhatsApp
      if (await canLaunchUrl(waUrlScheme)) {
        await launchUrl(waUrlScheme);
      } else if (await canLaunchUrl(waUrl)) {
        // Si falla, intentar con https
        await launchUrl(waUrl);
      } else {
        throw Exception('No se pudo abrir WhatsApp');
      }
    } catch (e) {
      print('>>> [SHARE] Error al abrir WhatsApp: $e');
      rethrow;
    }
  }

  /// Envía el mensaje por SMS
  static Future<void> compartirPorSMS({
    required String telefono,
    required String mensaje,
  }) async {
    if (telefono.isEmpty) {
      throw Exception('Número de teléfono no proporcionado');
    }

    if (mensaje.isEmpty) {
      throw Exception('Mensaje vacío');
    }

    // Validar teléfono
    if (!validarTelefono(telefono)) {
      throw Exception('Número de teléfono inválido: $telefono');
    }

    // Limpiar teléfono para SMS (puede incluir caracteres especiales en algunos casos)
    final telefonoLimpio = telefono.replaceAll(RegExp(r'[^\d+]'), '');

    // URL para SMS
    final smsUrl = Uri.parse('sms:$telefonoLimpio?body=${Uri.encodeComponent(mensaje)}');

    try {
      if (await canLaunchUrl(smsUrl)) {
        await launchUrl(smsUrl);
      } else {
        throw Exception('No se pudo abrir la aplicación de SMS');
      }
    } catch (e) {
      print('>>> [SHARE] Error al enviar SMS: $e');
      rethrow;
    }
  }

  /// Genera un enlace de Google Maps con la ubicación actual
  static String generarEnlaceMaps(LatLng? ubicacion) {
    if (ubicacion == null) return '';
    return 'https://maps.google.com/?q=${ubicacion.latitude},${ubicacion.longitude}';
  }

  /// Genera un código QR datos (como string) para la ubicación
  /// Este puede ser usado con paquetes como qr_flutter
  static String generarDatosQR(LatLng? ubicacion) {
    if (ubicacion == null) return '';
    return 'https://maps.google.com/?q=${ubicacion.latitude},${ubicacion.longitude}';
  }
}
