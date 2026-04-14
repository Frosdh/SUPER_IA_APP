class ChatMessage {
  final int id;
  final String remitente; // 'pasajero' | 'conductor'
  final String mensaje;
  final String fecha;

  ChatMessage({
    required this.id,
    required this.remitente,
    required this.mensaje,
    required this.fecha,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    return ChatMessage(
      id:        int.tryParse(json['id']?.toString() ?? '0') ?? 0,
      remitente: json['remitente']?.toString() ?? '',
      mensaje:   json['mensaje']?.toString() ?? '',
      fecha:     json['fecha']?.toString() ?? '',
    );
  }

  /// Hora formateada HH:mm
  String get horaFormateada {
    try {
      final dt = DateTime.parse(fecha).toLocal();
      return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    } catch (_) {
      return '';
    }
  }
}
