import 'dart:convert';

import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Models/NearbyDriverMapModel.dart';
import 'package:http/http.dart' as http;

class ApiProvider {
  Future<Map<String, dynamic>> loginDriver({
    required String identificador,
    required String password,
  }) async {
    final url = '${Constants.apiBaseUrl}/login_conductor.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'identificador': identificador,
        'password': password,
      },
    );

    print('>>> [DRIVER_LOGIN] HTTP ${response.statusCode} desde $url');
    if (response.statusCode != 200) {
      print('>>> [DRIVER_LOGIN] Body (non-200): ${response.body}');
    }

    if (response.statusCode != 200) {
      return <String, dynamic>{
        'status': 'error',
        'message': 'No se pudo conectar con el servidor',
      };
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> updateDriverStatus({
    required int conductorId,
    required String estado,
  }) async {
    final url = '${Constants.apiBaseUrl}/actualizar_estado_conductor.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'conductor_id': conductorId.toString(),
        'estado': estado,
      },
    );

    print('>>> [DRIVER_STATUS] HTTP ${response.statusCode} desde $url');
    if (response.statusCode != 200) {
      print('>>> [DRIVER_STATUS] Body (non-200): ${response.body}');
    }

    if (response.statusCode != 200) {
      return <String, dynamic>{
        'status': 'error',
        'message': 'No se pudo actualizar el estado',
      };
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> updateDriverLocation({
    required int conductorId,
    required double latitud,
    required double longitud,
  }) async {
    final url = '${Constants.apiBaseUrl}/actualizar_ubicacion_conductor.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'conductor_id': conductorId.toString(),
        'latitud': latitud.toString(),
        'longitud': longitud.toString(),
      },
    );

    print('>>> [DRIVER_LOC] HTTP ${response.statusCode} desde $url');
    if (response.statusCode != 200) {
      print('>>> [DRIVER_LOC] Body (non-200): ${response.body}');
    }

    if (response.statusCode != 200) {
      return <String, dynamic>{
        'status': 'error',
        'message': 'No se pudo actualizar la ubicacion',
      };
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> getDriverPendingRequest({
    required int conductorId,
  }) async {
    final url = '${Constants.apiBaseUrl}/obtener_solicitud_conductor.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'conductor_id': conductorId.toString(),
      },
    );

    print(
      '>>> [DRIVER_POLL] HTTP ${response.statusCode} desde $url (conductor_id=$conductorId)',
    );
    // Si el hosting no tiene el PHP, normalmente aqui veras 404 con HTML.
    if (response.statusCode != 200) {
      print('>>> [DRIVER_POLL] Body (non-200): ${response.body}');
    }

    if (response.statusCode != 200) {
      return <String, dynamic>{
        'status': 'error',
        'found': false,
        'message': 'No se pudo consultar solicitudes',
      };
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> respondDriverRequest({
    required int conductorId,
    required int viajeId,
    required String accion,
  }) async {
    final url = '${Constants.apiBaseUrl}/responder_solicitud_conductor.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'conductor_id': conductorId.toString(),
        'viaje_id': viajeId.toString(),
        'accion': accion,
      },
    );

    print(
      '>>> [DRIVER_RESP] HTTP ${response.statusCode} desde $url (viaje_id=$viajeId, accion=$accion)',
    );
    if (response.statusCode != 200) {
      print('>>> [DRIVER_RESP] Body (non-200): ${response.body}');
    }

    if (response.statusCode != 200) {
      return <String, dynamic>{
        'status': 'error',
        'message': 'No se pudo responder la solicitud',
      };
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> updateRideStatus({
    required int conductorId,
    required int viajeId,
    required String estado,
  }) async {
    final url = '${Constants.apiBaseUrl}/actualizar_estado_viaje.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'conductor_id': conductorId.toString(),
        'viaje_id': viajeId.toString(),
        'estado': estado,
      },
    );

    print(
      '>>> [RIDE_STATUS] HTTP ${response.statusCode} desde $url (viaje_id=$viajeId, estado=$estado)',
    );
    if (response.statusCode != 200) {
      print('>>> [RIDE_STATUS] Body (non-200): ${response.body}');
      return <String, dynamic>{
        'status': 'error',
        'message': 'No se pudo actualizar el estado del viaje',
      };
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  // Cancela un viaje activo (puede llamarlo el conductor o el pasajero).
  Future<Map<String, dynamic>> cancelRide({required int viajeId}) async {
    final url = '${Constants.apiBaseUrl}/cancelar_viaje.php';
    try {
      final response = await http.post(
        Uri.parse(url),
        body: {'viaje_id': viajeId.toString()},
      ).timeout(const Duration(seconds: 8));
      if (response.statusCode == 200) {
        return json.decode(response.body) as Map<String, dynamic>;
      }
    } catch (e) {
      print('>>> [CANCELAR] Error: $e');
    }
    return <String, dynamic>{'status': 'error', 'message': 'Error de conexión'};
  }

  // Consulta el estado actual de un viaje (usado por el conductor para detectar cancelación).
  Future<Map<String, dynamic>> getRideStatus({required int viajeId}) async {
    final url = '${Constants.apiBaseUrl}/estado_viaje.php';
    try {
      final response = await http.post(
        Uri.parse(url),
        body: {'viaje_id': viajeId.toString()},
      ).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        return json.decode(response.body) as Map<String, dynamic>;
      }
    } catch (_) {}
    return <String, dynamic>{'status': 'error', 'estado': ''};
  }

  Future<Map<String, dynamic>> registerNewUser({
    required String nombre,
    required String telefono,
    required String email,
    required String tokenFcm,
  }) async {
    final url = '${Constants.apiBaseUrl}/register_user.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'nombre': nombre,
        'telefono': telefono,
        'email': email,
        'token_fcm': tokenFcm,
      },
    );

    print('>>> [REGISTER] HTTP ${response.statusCode} desde $url');
    print('>>> [REGISTER] Body: ${response.body}');

    if (response.statusCode != 200) {
      return <String, dynamic>{
        'status': 'error',
        'message': 'No se pudo conectar con el servidor',
      };
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<int> sendEmailOtp(String email) async {
    final url = '${Constants.apiBaseUrl}/enviar_codigo_email.php';
    final response = await http.post(
      Uri.parse(url),
      body: {'email': email},
    );

    if (response.statusCode != 200) return 0;

    final responseData = json.decode(response.body) as Map<String, dynamic>;
    return responseData['status'] == 'success' ? 1 : 0;
  }

  Future<int> verifyEmailOtp(String email, String codigo) async {
    final url = '${Constants.apiBaseUrl}/verificar_codigo_email.php';
    final response = await http.post(
      Uri.parse(url),
      body: {
        'email': email,
        'codigo': codigo,
      },
    );

    if (response.statusCode != 200) return 0;

    final responseData = json.decode(response.body) as Map<String, dynamic>;
    return responseData['status'] == 'success' &&
            responseData['valid'] == true
        ? 1
        : 0;
  }

  Future<Map<String, dynamic>> checkUserByEmail(String email) async {
    final url = '${Constants.apiBaseUrl}/check_user_by_email.php';
    final response = await http.post(
      Uri.parse(url),
      body: {'email': email},
    );

    if (response.statusCode != 200) {
      return {'status': 'error', 'exists': false};
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> getDriverTripHistory({
    required int conductorId,
    int page = 1,
  }) async {
    final url = '${Constants.apiBaseUrl}/historial_conductor.php';
    try {
      print('>>> [HISTORIAL] Llamando $url con conductor_id=$conductorId');
      final response = await http.post(
        Uri.parse(url),
        body: {
          'conductor_id': conductorId.toString(),
          'page': page.toString(),
        },
      ).timeout(const Duration(seconds: 8));
      print('>>> [HISTORIAL] HTTP ${response.statusCode}');
      print('>>> [HISTORIAL] Body: ${response.body}');
      if (response.statusCode == 200) {
        final decoded = json.decode(response.body) as Map<String, dynamic>;
        return decoded;
      }
      return <String, dynamic>{
        'status': 'error',
        'viajes': [],
        'debug': 'HTTP ${response.statusCode}: ${response.body}',
      };
    } catch (e) {
      print('>>> [HISTORIAL] Excepcion: $e');
      return <String, dynamic>{
        'status': 'error',
        'viajes': [],
        'debug': 'Excepcion: $e',
      };
    }
  }

  Future<List<NearbyDriverMapModel>> getNearbyDrivers({
    required double lat,
    required double lng,
    int? categoriaId,
    double radioKm = 5,
  }) async {
    final url = '${Constants.apiBaseUrl}/obtener_conductores_cercanos.php';
    final body = <String, String>{
      'lat': lat.toString(),
      'lng': lng.toString(),
      'radio_km': radioKm.toString(),
    };
    if (categoriaId != null) {
      body['categoria_id'] = categoriaId.toString();
    }

    final response = await http.post(Uri.parse(url), body: body);
    if (response.statusCode != 200) {
      return <NearbyDriverMapModel>[];
    }

    final responseData = json.decode(response.body) as Map<String, dynamic>;
    if (responseData['status'] != 'success') {
      return <NearbyDriverMapModel>[];
    }

    final conductores = responseData['conductores'] as List<dynamic>;
    return conductores
        .map((dynamic item) => NearbyDriverMapModel.fromJson(
              (item as Map).cast<String, dynamic>(),
            ))
        .toList();
  }
  Future<Map<String, dynamic>> registerDriver({
    required String nombre,
    required String telefono,
    required String cedula,
    required String password,
    required String marca,
    required String modelo,
    required String placa,
    required String color,
    required int anio,
    required int categoriaId,
    String email  = '',
    String ciudad = 'Cuenca',
    String tipoConductor = 'independiente',
    int? cooperativaId,
  }) async {
    final url = '${Constants.apiBaseUrl}/register_driver.php';
    try {
      final body = {
        'nombre':       nombre,
        'email':        email,
        'telefono':     telefono,
        'cedula':       cedula,
        'password':     password,
        'ciudad':       ciudad,
        'marca':        marca,
        'modelo':       modelo,
        'placa':        placa,
        'color':        color,
        'anio':         anio.toString(),
        'categoria_id': categoriaId.toString(),
        'tipo_conductor': tipoConductor,
      };
      if (cooperativaId != null) {
        body['cooperativa_id'] = cooperativaId.toString();
      }

      final response = await http.post(
        Uri.parse(url),
        body: body,
      ).timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        return json.decode(response.body) as Map<String, dynamic>;
      }
      return {'status': 'error', 'message': 'Error de conexión (HTTP ${response.statusCode})'};
    } catch (e) {
      return {'status': 'error', 'message': 'Error de red o timeout'};
    }
  }

  // ── Sube un documento o foto de perfil del conductor ─────────
  Future<Map<String, dynamic>> uploadDocumentoConductor({
    required int    conductorId,
    required String tipo,
    required String imagenBase64,
  }) async {
    final url = '${Constants.apiBaseUrl}/upload_documento_conductor.php';
    try {
      final response = await http.post(
        Uri.parse(url),
        body: {
          'conductor_id':  conductorId.toString(),
          'tipo':          tipo,
          'imagen_base64': imagenBase64,
        },
      ).timeout(const Duration(seconds: 30)); // imágenes pueden tardar más

      if (response.statusCode == 200) {
        return json.decode(response.body) as Map<String, dynamic>;
      }
      return {'status': 'error', 'message': 'Error HTTP ${response.statusCode}'};
    } catch (e) {
      return {'status': 'error', 'message': 'Error al subir documento: $e'};
    }
  }

  // ── Obtiene el estado de los documentos de un conductor ───────
  Future<Map<String, dynamic>> obtenerDocumentosConductor(int conductorId) async {
    final url = '${Constants.apiBaseUrl}/obtener_documentos_conductor.php?conductor_id=$conductorId';
    try {
      final response = await http.get(Uri.parse(url))
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        return json.decode(response.body) as Map<String, dynamic>;
      }
      return {'status': 'error', 'message': 'Error HTTP ${response.statusCode}'};
    } catch (e) {
      return {'status': 'error', 'message': 'Error de red: $e'};
    }
  }

  // ── Obtiene el perfil completo del conductor (datos, vehículo, docs, stats)
  Future<Map<String, dynamic>> obtenerPerfilConductor(int conductorId) async {
    final url = '${Constants.apiBaseUrl}/obtener_perfil_conductor.php';
    try {
      final response = await http.post(
        Uri.parse(url),
        body: {'conductor_id': conductorId.toString()},
      ).timeout(const Duration(seconds: 12));

      if (response.statusCode == 200) {
        return json.decode(response.body) as Map<String, dynamic>;
      }
      return {'status': 'error', 'message': 'Error HTTP ${response.statusCode}'};
    } catch (e) {
      return {'status': 'error', 'message': 'Error de red: $e'};
    }
  }

  // ── Obtiene la lista de cooperativas para el registro ────────
  Future<Map<String, dynamic>> obtenerCooperativas() async {
    final url = '${Constants.apiBaseUrl}/obtener_cooperativas.php';
    try {
      final response = await http.get(Uri.parse(url))
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        return json.decode(response.body) as Map<String, dynamic>;
      }
      return {'status': 'error', 'message': 'Error HTTP ${response.statusCode}'};
    } catch (e) {
      return {'status': 'error', 'message': 'Error de red: $e'};
    }
  }
}
