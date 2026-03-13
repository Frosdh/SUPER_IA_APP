import 'dart:convert';

import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Models/NearbyDriverMapModel.dart';
import 'package:http/http.dart' as http;

class ApiProvider {
  Future<Map<String, dynamic>> registerNewUser({
    String nombre,
    String telefono,
    String email,
    String tokenFcm,
  }) async {
    final url = '${Constants.apiBaseUrl}/register_user.php';
    final response = await http.post(
      url,
      body: {
        'nombre': nombre,
        'telefono': telefono,
        'email': email,
        'token_fcm': tokenFcm ?? '',
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
      url,
      body: {'email': email},
    );

    if (response.statusCode != 200) return 0;

    final responseData = json.decode(response.body) as Map<String, dynamic>;
    return responseData['status'] == 'success' ? 1 : 0;
  }

  Future<int> verifyEmailOtp(String email, String codigo) async {
    final url = '${Constants.apiBaseUrl}/verificar_codigo_email.php';
    final response = await http.post(
      url,
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
      url,
      body: {'email': email},
    );

    if (response.statusCode != 200) {
      return {'status': 'error', 'exists': false};
    }

    return json.decode(response.body) as Map<String, dynamic>;
  }

  Future<List<NearbyDriverMapModel>> getNearbyDrivers({
    double lat,
    double lng,
    int categoriaId,
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

    final response = await http.post(url, body: body);
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
}
