import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Models/DiscountCodeModel.dart';
import 'package:fu_uber/Core/Preferences/DiscountPreferences.dart';

class DiscountCodeService {
  /// Validar un código de descuento en el servidor
  static Future<DiscountCode> validarCodigo(String codigo) async {
    if (codigo == null || codigo.isEmpty) {
      throw Exception('Código vacío');
    }

    final codigoLimpio = codigo.trim().toUpperCase();

    // ── Verificación local primero (evita llamada al servidor innecesaria) ──
    final yaUsado = await DiscountPreferences.fueUsado(codigoLimpio);
    if (yaUsado) {
      print('>>> [DISCOUNT] Cupón ya usado localmente: $codigoLimpio');
      throw Exception('Este código ya fue utilizado en un viaje anterior');
    }

    final urls = [
      '${Constants.apiBaseUrl}/validar_codigo.php',
      'http://10.0.2.2/fuber_api/validar_codigo.php',
    ];

    for (final url in urls) {
      try {
        print('>>> [DISCOUNT] Validando código: $codigoLimpio en $url');

        final response = await http.post(
          url,
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: {
            'codigo': codigoLimpio,
          },
        ).timeout(const Duration(seconds: 8));

        print('>>> [DISCOUNT] HTTP ${response.statusCode}');
        print('>>> [DISCOUNT] Response: ${response.body}');

        if (response.statusCode == 200) {
          final data = json.decode(response.body);

          if (data['status'] == 'success') {
            // Crear el objeto DiscountCode desde la respuesta
            final discountCode = DiscountCode.fromJson(data['codigo']);

            // Verificar si el código es válido
            if (!discountCode.esValido()) {
              throw Exception('Código expirado o no disponible');
            }

            print('>>> [DISCOUNT] Código válido: $codigoLimpio');
            return discountCode;
          } else {
            throw Exception(data['message'] ?? 'Código inválido');
          }
        }
      } catch (e) {
        print('>>> [DISCOUNT] Error en $url: $e');
        if (e.toString().contains('Código')) {
          rethrow;
        }
      }
    }

    throw Exception('No se pudo validar el código. Intenta más tarde.');
  }

  /// Guardar cupón como usado
  static Future<void> guardarCuponUsado(String codigo) async {
    try {
      await DiscountPreferences.guardarCuponUsado(codigo);
      print('>>> [DISCOUNT] Cupón guardado como usado: $codigo');
    } catch (e) {
      print('>>> [DISCOUNT] Error al guardar cupón usado: $e');
    }
  }

  /// Verificar si un cupón ya fue usado localmente
  static Future<bool> fueUsado(String codigo) async {
    try {
      final usado = await DiscountPreferences.fueUsado(codigo);
      print('>>> [DISCOUNT] ¿Cupón usado? $codigo = $usado');
      return usado;
    } catch (_) {
      return false;
    }
  }

  /// Obtener cupones usados
  static Future<List<String>> obtenerCuponesUsados() async {
    try {
      return await DiscountPreferences.obtenerCuponesUsados();
    } catch (_) {
      return [];
    }
  }

  /// Calcular precio con descuento
  static double aplicarDescuento(double precioOriginal, DiscountCode cupom) {
    if (cupom == null) return precioOriginal;
    return cupom.calcularPrecioFinal(precioOriginal);
  }

  /// Obtener monto del descuento
  static double obtenerMontoDescuento(double precioOriginal, DiscountCode cupom) {
    if (cupom == null) return 0.0;
    return cupom.calcularDescuento(precioOriginal);
  }

  /// Validar que el precio mínimo se cumple
  static bool cumpleMinimoViaje(DiscountCode cupom, double precioViaje) {
    if (cupom == null) return true;
    if (cupom.minimoViaje == null || cupom.minimoViaje <= 0) return true;
    return precioViaje >= cupom.minimoViaje;
  }

  /// Obtener mensaje de error si no cumple mínimo
  static String obtenerMensajeMinimo(DiscountCode cupom, double precioViaje) {
    if (cupom == null) return '';
    if (cupom.minimoViaje == null || cupom.minimoViaje <= 0) return '';
    if (precioViaje < cupom.minimoViaje) {
      return 'Viaje mínimo requerido: \$${cupom.minimoViaje.toStringAsFixed(2)}';
    }
    return '';
  }

  /// Registrar uso del cupón en el servidor (después de confirmar viaje)
  static Future<void> registrarUsoEnServidor(String codigo, int viajeId) async {
    final urls = [
      '${Constants.apiBaseUrl}/registrar_uso_codigo.php',
      'http://10.0.2.2/fuber_api/registrar_uso_codigo.php',
    ];

    for (final url in urls) {
      try {
        print('>>> [DISCOUNT] Registrando uso del código: $codigo (viaje: $viajeId)');

        final response = await http.post(
          url,
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: {
            'codigo': codigo,
            'viaje_id': viajeId.toString(),
          },
        ).timeout(const Duration(seconds: 8));

        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['status'] == 'success') {
            print('>>> [DISCOUNT] Uso registrado correctamente');
            return;
          }
        }
      } catch (e) {
        print('>>> [DISCOUNT] Error al registrar uso en $url: $e');
      }
    }

    print('>>> [DISCOUNT] Advertencia: No se pudo registrar uso en servidor, pero se guarda localmente');
  }
}
