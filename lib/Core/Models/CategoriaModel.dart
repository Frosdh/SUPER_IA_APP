class CategoriaModel {
  final int id;
  final String nombre;
  final double tarifaBase;
  final double precioKm;
  final double precioMinuto;

  CategoriaModel({
    this.id,
    this.nombre,
    this.tarifaBase,
    this.precioKm,
    this.precioMinuto,
  });

  factory CategoriaModel.fromJson(Map<String, dynamic> json) {
    return CategoriaModel(
      id:            json['id'] as int,
      nombre:        json['nombre'] as String,
      tarifaBase:    (json['tarifa_base'] as num).toDouble(),
      precioKm:      (json['precio_km'] as num).toDouble(),
      precioMinuto:  (json['precio_minuto'] as num).toDouble(),
    );
  }

  /// Calcula el precio estimado del viaje
  double calcularPrecio(double distanciaKm, int duracionMin) {
    double precio = tarifaBase + (precioKm * distanciaKm) + (precioMinuto * duracionMin);
    return precio < 2.00 ? 2.00 : precio;
  }

  /// Icono por nombre de categoría
  String get icono {
    final n = nombre.toLowerCase();
    if (n.contains('moto'))     return '🏍️';
    if (n.contains('camion'))   return '🚐';
    if (n.contains('premium'))  return '🚘';
    return '🚗'; // default: auto
  }
}
