import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

class SOSService {
  /// Envía mensaje SOS por WhatsApp a un teléfono con ubicación GPS
  static Future<void> enviarSOS({
    required String telefono,
    required LatLng ubicacion,
    required String nombrePasajero,
  }) async {
    try {
      // Crear URL de Google Maps con la ubicación
      final mapsUrl = 'https://maps.google.com/?q=${ubicacion.latitude},${ubicacion.longitude}';

      // Mensaje SOS con ubicación
      final mensaje =
          '¡EMERGENCIA! Soy $nombrePasajero en la app GeoMove.\n\n'
          '📍 Mi ubicación: $mapsUrl\n\n'
          'Por favor, ayuda.';

      // URL de WhatsApp
      final whatsappUrl = 'https://wa.me/$telefono?text=${Uri.encodeComponent(mensaje)}';

      if (await canLaunchUrl(Uri.parse(whatsappUrl))) {
        await launchUrl(Uri.parse(whatsappUrl));
      } else {
        // Fallback: intenta abrir WhatsApp directamente
        final waUrl = 'whatsapp://send?phone=$telefono&text=${Uri.encodeComponent(mensaje)}';
        if (await canLaunchUrl(Uri.parse(waUrl))) {
          await launchUrl(Uri.parse(waUrl));
        } else {
          throw 'No se pudo abrir WhatsApp';
        }
      }
    } catch (e) {
      print('Error al enviar SOS: $e');
      rethrow;
    }
  }

  /// Valida que el teléfono tenga formato válido
  static bool validarTelefono(String? telefono) {
    if (telefono == null || telefono.isEmpty) return false;
    // Acepta formatos: +593912345678, 0912345678, 912345678
    final regex = RegExp(r'^\+?[0-9]{10,15}$');
    return regex.hasMatch(telefono.replaceAll(RegExp(r'\s'), ''));
  }

  /// Formatea el teléfono para WhatsApp (agregar +593 si es Ecuador)
  static String formatearTelefonoWhatsapp(String? telefono) {
    if (telefono == null || telefono.isEmpty) return '';
    String cleaned = telefono.replaceAll(RegExp(r'\D'), ''); // Solo números

    // Si empieza con 0, reemplaza con código país
    if (cleaned.startsWith('0')) {
      cleaned = '593' + cleaned.substring(1);
    }

    // Si no tiene código país, agrega 593 (Ecuador)
    if (!cleaned.startsWith('593')) {
      cleaned = '593' + cleaned;
    }

    return cleaned;
  }
}
