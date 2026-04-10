class CooperativaModel {
  final String id;
  final String nombre;
  final String codigo;
  final String? ciudad;

  CooperativaModel({
    required this.id,
    required this.nombre,
    required this.codigo,
    this.ciudad,
  });

  factory CooperativaModel.fromJson(Map<String, dynamic> json) {
    return CooperativaModel(
      id: json['id'] as String,
      nombre: json['nombre'] as String,
      codigo: json['codigo'] as String,
      ciudad: json['ciudad'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'nombre': nombre,
    'codigo': codigo,
    'ciudad': ciudad,
  };

  @override
  String toString() => 'CooperativaModel(id: $id, nombre: $nombre, codigo: $codigo)';
}
