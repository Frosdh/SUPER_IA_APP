class AsesorRegistrationModel {
  final String nombres;
  final String apellidos;
  final String email;
  final String telefono;
  final String contrasena;
  final String supervisor_id;
  final String unidad_bancaria_id;

  AsesorRegistrationModel({
    required this.nombres,
    required this.apellidos,
    required this.email,
    required this.telefono,
    required this.contrasena,
    required this.supervisor_id,
    required this.unidad_bancaria_id,
  });

  factory AsesorRegistrationModel.fromJson(Map<String, dynamic> json) {
    return AsesorRegistrationModel(
      nombres: json['nombres'] as String,
      apellidos: json['apellidos'] as String,
      email: json['email'] as String,
      telefono: json['telefono'] as String,
      contrasena: json['contrasena'] as String,
      supervisor_id: json['supervisor_id'] as String,
      unidad_bancaria_id: json['unidad_bancaria_id'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'nombres': nombres,
    'apellidos': apellidos,
    'email': email,
    'telefono': telefono,
    'contrasena': contrasena,
    'supervisor_id': supervisor_id,
    'unidad_bancaria_id': unidad_bancaria_id,
  };

  @override
  String toString() => 'AsesorRegistration(email: $email, nombre: $nombres $apellidos)';
}
