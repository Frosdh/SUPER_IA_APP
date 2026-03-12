class NearbyDriverMapModel {
  final int id;
  final String nombre;
  final String telefono;
  final double latitud;
  final double longitud;
  final double calificacion;
  final int categoriaId;
  final String placa;
  final String marca;
  final String modelo;
  final String color;
  final double distanciaKm;

  NearbyDriverMapModel({
    this.id,
    this.nombre,
    this.telefono,
    this.latitud,
    this.longitud,
    this.calificacion,
    this.categoriaId,
    this.placa,
    this.marca,
    this.modelo,
    this.color,
    this.distanciaKm,
  });

  factory NearbyDriverMapModel.fromJson(Map<String, dynamic> json) {
    return NearbyDriverMapModel(
      id: (json['id'] as num)?.toInt(),
      nombre: json['nombre'] as String ?? '',
      telefono: json['telefono'] as String ?? '',
      latitud: (json['latitud'] as num)?.toDouble() ?? 0.0,
      longitud: (json['longitud'] as num)?.toDouble() ?? 0.0,
      calificacion: (json['calificacion'] as num)?.toDouble() ?? 5.0,
      categoriaId: (json['categoria_id'] as num)?.toInt() ?? 0,
      placa: json['placa'] as String ?? '',
      marca: json['marca'] as String ?? '',
      modelo: json['modelo'] as String ?? '',
      color: json['color'] as String ?? '',
      distanciaKm: (json['distancia_km'] as num)?.toDouble() ?? 0.0,
    );
  }

  String get autoDescripcion {
    final base = '$marca $modelo'.trim();
    if (base.isEmpty) return placa ?? '';
    if ((placa ?? '').isEmpty) return base;
    return '$base · $placa';
  }
}
