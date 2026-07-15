// ============================================================
// ExcelSolicitudService.dart
// ------------------------------------------------------------
// Descarga el Excel de "Solicitud de Crédito" (réplica del
// formulario físico) ya llenado con los datos reales del cliente,
// y abre la hoja nativa de compartir/guardar del celular.
//
// Se usa en dos lugares:
//   1) NuevaEncuestaScreen: botón "Descargar Excel" que aparece
//      justo después de guardar con conexión (online).
//   2) SyncService (ver más abajo): cuando una encuesta guardada
//      SIN internet finalmente se sube sola en segundo plano, no
//      hay ningún diálogo de "guardado con éxito" en pantalla para
//      ofrecer la descarga (el asesor ya salió de esa pantalla).
//      Por eso este helper es independiente de cualquier State/
//      BuildContext de formulario: se puede llamar desde cualquier
//      parte en cuanto se conoce el cliente_id.
// ============================================================

import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:super_ia/Core/Constants/Constants.dart';

class ExcelSolicitudService {
  /// Descarga el Excel para [clienteId] y abre la hoja de compartir.
  /// Lanza una excepción con un mensaje entendible si algo falla, para
  /// que quien llame decida cómo mostrarlo (SnackBar, diálogo, etc.).
  static Future<void> descargarYCompartir(String clienteId) async {
    if (clienteId.isEmpty) {
      throw Exception('No se pudo identificar al cliente para generar el Excel.');
    }

    final url = Uri.parse(
        '${Constants.apiBaseUrl}/generar_solicitud_credito_excel.php?cliente_id=$clienteId');
    final resp = await http.get(
      url,
      headers: {'ngrok-skip-browser-warning': 'true'},
    ).timeout(const Duration(seconds: 30));

    if (resp.statusCode != 200 || resp.bodyBytes.isEmpty) {
      throw Exception('HTTP ${resp.statusCode}');
    }

    final contentType = (resp.headers['content-type'] ?? '').toLowerCase();
    if (contentType.contains('application/json')) {
      final data = json.decode(resp.body) as Map<String, dynamic>;
      throw Exception(data['message']?.toString() ?? 'Error del servidor');
    }

    final dir = await getTemporaryDirectory();
    final path = '${dir.path}/Solicitud_Credito_$clienteId.xlsx';
    final file = File(path);
    await file.writeAsBytes(resp.bodyBytes, flush: true);

    await Share.shareXFiles(
      [XFile(path, name: 'Solicitud_Credito.xlsx')],
      text: 'Solicitud de Crédito',
    );
  }
}
