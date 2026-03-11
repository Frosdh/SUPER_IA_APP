// Modelo de Código de Descuento
class DiscountCode {
  int id;
  String codigo;           // Ej: FUBER20
  String tipo;             // 'porcentaje' o 'monto_fijo'
  double valor;            // 20 (20%) o 5.00 (5 dólares)
  double minimoViaje;      // Monto mínimo del viaje para usar el cupón
  int maximoUsos;          // Máximo de veces que se puede usar
  int usosActuales;        // Veces ya usado
  DateTime fechaInicio;
  DateTime fechaFin;
  bool activo;

  DiscountCode({
    this.id,
    this.codigo,
    this.tipo,
    this.valor,
    this.minimoViaje,
    this.maximoUsos,
    this.usosActuales,
    this.fechaInicio,
    this.fechaFin,
    this.activo,
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
    if (fechaFin != null && ahora.isAfter(fechaFin)) return false;

    // ✓ Debe haber alcanzado la fecha de inicio
    if (fechaInicio != null && ahora.isBefore(fechaInicio)) return false;

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
DateTime _parseDateTime(dynamic value) {
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
