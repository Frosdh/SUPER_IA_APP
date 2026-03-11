import 'dart:convert';

import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Constants/DemoData.dart';
import 'package:fu_uber/Core/Models/Drivers.dart';
import 'package:http/http.dart' as http;

class ApiProvider {
  static List<String> _candidateUrls(String endpoint) {
    return <String>[
      "${Constants.apiBaseUrl}/$endpoint",
      "http://10.0.2.2/fuber_api/$endpoint",
    ];
  }

  static Future<bool> registerNewUser(
    String nombre,
    String telefono,
    String email,
    String password,
  ) async {
    print(
      ">>> [APIPROVIDER] registerNewUser - ENTRADA - Datos: $nombre, $telefono, $email",
    );
    try {
      final urls = _candidateUrls("register_user.php");

      for (final url in urls) {
        try {
          print(">>> [APIPROVIDER] URL: $url");

          final response = await http
              .post(
            url,
            body: {
              "nombre": nombre,
              "telefono": telefono,
              "email": email,
              "password": password,
            },
          )
              .timeout(const Duration(seconds: 10));

          print("================================================");
          print(">>> CODIGO HTTP: ${response.statusCode}");
          print(">>> BODY COMPLETO DEL SERVIDOR: ${response.body}");
          print("================================================");

          if (response.statusCode != 200) {
            print(">>> [ERROR] Codigo HTTP inesperado: ${response.statusCode}");
            continue;
          }

          try {
            final data = json.decode(response.body) as Map<String, dynamic>;
            if (data["status"] == "success") {
              print(">>> [OK] Usuario registrado exitosamente en la base de datos.");
              return true;
            }
            print(">>> [ERROR] Backend respondio error: ${data["message"]}");
            continue;
          } catch (_) {
            final ok =
                response.body.contains("success") || response.body.contains("1");
            if (ok) {
              print(">>> [OK] Usuario registrado (fallback de parseo).");
              return true;
            }
            print(">>> [ERROR] Respuesta no JSON y sin indicador de exito.");
            continue;
          }
        } catch (e) {
          print(">>> [WARN] Error con URL $url: $e");
        }
      }
      return false;
    } catch (e) {
      print(">>> [EXCEPCION] Error de red o conexion: $e");
      return false;
    }
  }

  static Future<int> sendOtpToUser(String phone) async {
    print(">>> [APIPROVIDER] sendOtpToUser - ENTRADA - Telefono: $phone");
    // OTP de prueba local: la verificacion se hace en verifyOtp().
    // El registro real se realiza luego en RegisterScreen.
    return 1;
  }

  static Future<int> verifyOtp(String otp) async {
    // 1: OTP correcto, 0: OTP incorrecto.
    if (otp == "1234") {
      return 1;
    }
    return 0;
  }

  static Future<Map<String, dynamic>> checkUserByPhone(String phone) async {
    final urls = _candidateUrls("check_user.php");

    for (final url in urls) {
      try {
        print(">>> [APIPROVIDER] checkUserByPhone URL: $url");
        final response = await http
            .post(
          url,
          body: {
            "telefono": phone,
          },
        )
            .timeout(const Duration(seconds: 10));

        if (response.statusCode != 200) {
          continue;
        }

        final data = json.decode(response.body) as Map<String, dynamic>;
        if (data["status"] == "success") {
          return {
            "success": true,
            "exists": data["exists"] == true,
            "nombre": data["nombre"] ?? "",
            "email": data["email"] ?? "",
          };
        }
      } catch (e) {
        print(">>> [WARN] checkUserByPhone fallo con $url: $e");
      }
    }

    return {
      "success": false,
      "exists": false,
    };
  }

  static List<Driver> getNearbyDrivers() {
    return DemoData.nearbyDrivers;
  }
}
