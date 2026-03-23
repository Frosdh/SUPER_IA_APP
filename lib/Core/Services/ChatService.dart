import 'dart:convert';
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Models/ChatMessage.dart';
import 'package:http/http.dart' as http;

class ChatService {
  static const _base = Constants.apiBaseUrl;

  /// Obtiene mensajes del viaje desde [ultimoId] en adelante.
  /// Si el endpoint no existe aún, retorna lista vacía sin crash.
  static Future<List<ChatMessage>> obtenerMensajes({
    required int viajeId,
    int ultimoId = 0,
  }) async {
    try {
      final uri = Uri.parse(
        '$_base/chat_mensajes.php?viaje_id=$viajeId&ultimo_id=$ultimoId',
      );
      final resp = await http.get(uri).timeout(const Duration(seconds: 8));
      if (resp.statusCode != 200) return [];
      final data = jsonDecode(resp.body);
      if (data['status'] != 'success') return [];
      final lista = (data['mensajes'] as List?) ?? [];
      return lista
          .map((e) => ChatMessage.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (_) {
      return [];
    }
  }

  /// Envía un mensaje al chat del viaje.
  /// [nombreRemitente] se usa para mostrar el nombre en la notificación push.
  /// Retorna true si el servidor confirmó, false en cualquier otro caso.
  static Future<bool> enviarMensaje({
    required int viajeId,
    required String remitente,       // 'pasajero' | 'conductor'
    required String mensaje,
    String nombreRemitente = '',     // nombre para la notificación push
  }) async {
    try {
      final uri = Uri.parse('$_base/chat_enviar.php');
      final resp = await http
          .post(
            uri,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({
              'viaje_id':          viajeId,
              'remitente':         remitente,
              'mensaje':           mensaje,
              'nombre_remitente':  nombreRemitente,
            }),
          )
          .timeout(const Duration(seconds: 8));
      if (resp.statusCode != 200) return false;
      final data = jsonDecode(resp.body);
      return data['status'] == 'success';
    } catch (_) {
      return false;
    }
  }
}
