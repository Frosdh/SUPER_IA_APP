// Modelo de Código de Descuento
class DiscountCode {
  late int id;
  late String codigo;           // Ej: FUBER20
  late String tipo;             // 'porcentaje' o 'monto_fijo'
  late double valor;            // 20 (20%) o 5.00 (5 dólares)
  late double minimoViaje;      // Monto mínimo del viaje para usar el cupón
  late int maximoUsos;          // Máximo de veces que se puede usar
  late int usosActuales;        // Veces ya usado
  DateTime? fechaInicio;
  DateTime? fechaFin;
  late bool activo;

  DiscountCode({
    required this.id,
    required this.codigo,
    required this.tipo,
    required this.valor,
    required this.minimoViaje,
    required this.maximoUsos,
    required this.usosActuales,
    this.fechaInicio,
    this.fechaFin,
    required this.activo,
  });

  // Crear desde JSON del servidor
  factory DiscountCode.fromJson(Map<String, dynamic> json) {
    return DiscountCode(
      id: json['id'] as int,
      codigo: json['codigo'] as String,
      tipo: json['tipo'] as String,
      valor: _parseDouble(json['valor']),
      minimoViaje: _parseDouble(json['minimo_viaje'] ?? json['minimo_viajes'] ?? 0.0),
      maximoUsos: json['maximo_usos'] as int,
      usosActuales: json['usos_actuales'] as int,
      fechaInicio: _parseDateTime(json['fecha_inicio']),
      fechaFin: _parseDateTime(json['fecha_fin']),
      activo: json['activo'] == 1 || json['activo'] == true,
    );
  }

  // Convertir a JSON
  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'codigo': codigo,
      'tipo': tipo,
      'valor': valor,
      'minimo_viaje': minimoViaje,
      'maximo_usos': maximoUsos,
      'usos_actuales': usosActuales,
      'fecha_inicio': fechaInicio?.toIso8601String(),
      'fecha_fin': fechaFin?.toIso8601String(),
      'activo': activo,
    };
  }

  // Verificar si el código está disponible
  bool esValido() {
    final ahora = DateTime.now();

    // ✓ Debe estar activo
    if (!activo) return false;

    // ✓ No debe estar expirado
    final fin = fechaFin;
    if (fin != null && ahora.isAfter(fin)) return false;

    // ✓ Debe haber alcanzado la fecha de inicio
    final inicio = fechaInicio;
    if (inicio != null && ahora.isBefore(inicio)) return false;

    // ✓ No debe haber alcanzado el máximo de usos
    if (maximoUsos != null && usosActuales >= maximoUsos) return false;

    return true;
  }

  // Calcular el descuento
  double calcularDescuento(double precioOriginal) {
    if (tipo == 'porcentaje') {
      return precioOriginal * (valor / 100);
    } else if (tipo == 'monto_fijo') {
      // No descontar más del precio original
      return valor > precioOriginal ? precioOriginal : valor;
    }
    return 0.0;
  }

  // Calcular precio final con descuento
  double calcularPrecioFinal(double precioOriginal) {
    final descuento = calcularDescuento(precioOriginal);
    final precioFinal = precioOriginal - descuento;
    return precioFinal < 0 ? 0 : precioFinal;
  }

  // Obtener string del descuento para mostrar
  String obtenerDescuentoTexto() {
    if (tipo == 'porcentaje') {
      return '-${valor.toStringAsFixed(0)}%';
    } else if (tipo == 'monto_fijo') {
      return '-\$${valor.toStringAsFixed(2)}';
    }
    return '';
  }

  @override
  String toString() => 'DiscountCode(codigo: $codigo, valor: $valor, tipo: $tipo)';
}

// Función auxiliar para parsear double
double _parseDouble(dynamic value) {
  if (value == null) return 0.0;
  if (value is double) return value;
  if (value is int) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0.0;
  return 0.0;
}

// Función auxiliar para parsear DateTime
DateTime? _parseDateTime(dynamic value) {
  if (value == null) return null;
  if (value is DateTime) return value;
  if (value is String) {
    try {
      return DateTime.parse(value);
    } catch (_) {
      return null;
    }
  }
  return null;
}
