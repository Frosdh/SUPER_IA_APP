class SupervisorModel {
  final String id;
  final String nombre;
  final String email;

  SupervisorModel({
    required this.id,
    required this.nombre,
    required this.email,
  });

  factory SupervisorModel.fromJson(Map<String, dynamic> json) {
    return SupervisorModel(
      id: json['id'] as String,
      nombre: json['nombre'] as String,
      email: json['email'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'nombre': nombre,
    'email': email,
  };

  @override
  String toString() => 'SupervisorModel(id: $id, nombre: $nombre, email: $email)';
}
