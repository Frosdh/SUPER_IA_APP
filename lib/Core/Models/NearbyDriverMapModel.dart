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
    required this.id,
    required this.nombre,
    required this.telefono,
    required this.latitud,
    required this.longitud,
    required this.calificacion,
    required this.categoriaId,
    required this.placa,
    required this.marca,
    required this.modelo,
    required this.color,
    required this.distanciaKm,
  });

  factory NearbyDriverMapModel.fromJson(Map<String, dynamic> json) {
    return NearbyDriverMapModel(
      id: ((json['id'] as num?) ?? 0).toInt(),
      nombre: (json['nombre'] as String?) ?? '',
      telefono: (json['telefono'] as String?) ?? '',
      latitud: ((json['latitud'] as num?) ?? 0.0).toDouble(),
      longitud: ((json['longitud'] as num?) ?? 0.0).toDouble(),
      calificacion: ((json['calificacion'] as num?) ?? 5.0).toDouble(),
      categoriaId: ((json['categoria_id'] as num?) ?? 0).toInt(),
      placa: (json['placa'] as String?) ?? '',
      marca: (json['marca'] as String?) ?? '',
      modelo: (json['modelo'] as String?) ?? '',
      color: (json['color'] as String?) ?? '',
      distanciaKm: ((json['distancia_km'] as num?) ?? 0.0).toDouble(),
    );
  }

  String get autoDescripcion {
    final base = '$marca $modelo'.trim();
    if (base.isEmpty) return placa;
    if (placa.isEmpty) return base;
    return '$base · $placa';
  }
}
