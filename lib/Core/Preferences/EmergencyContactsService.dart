import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

class ContactoEmergencia {
  final String nombre;
  final String telefono;

  ContactoEmergencia({
    required this.nombre,
    required this.telefono,
  });

  Map<String, dynamic> toJson() => {
    'nombre': nombre,
    'telefono': telefono,
  };

  factory ContactoEmergencia.fromJson(Map<String, dynamic> json) {
    return ContactoEmergencia(
      nombre: json['nombre'] as String,
      telefono: json['telefono'] as String,
    );
  }
}

class EmergencyContactsService {
  static const String _key = 'emergency_contacts';

  /// Obtiene la lista de contactos de emergencia
  static Future<List<ContactoEmergencia>> obtenerContactos() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null || raw.isEmpty) return <ContactoEmergencia>[];
    try {
      final lista = jsonDecode(raw) as List;
      return lista.map((e) => ContactoEmergencia.fromJson(e as Map<String, dynamic>)).toList();
    } catch (_) {
      return <ContactoEmergencia>[];
    }
  }

  /// Agrega un nuevo contacto de emergencia
  static Future<void> agregarContacto(ContactoEmergencia contacto) async {
    final prefs = await SharedPreferences.getInstance();
    final contactos = await obtenerContactos();
    // Evitar duplicados por teléfono
    if (!contactos.any((c) => c.telefono == contacto.telefono)) {
      contactos.add(contacto);
      await prefs.setString(_key, jsonEncode(contactos.map((c) => c.toJson()).toList()));
    }
  }

  /// Elimina un contacto por teléfono
  static Future<void> eliminarContacto(String telefono) async {
    final prefs = await SharedPreferences.getInstance();
    final contactos = await obtenerContactos();
    contactos.removeWhere((c) => c.telefono == telefono);
    await prefs.setString(_key, jsonEncode(contactos.map((c) => c.toJson()).toList()));
  }

  /// Actualiza un contacto existente
  static Future<void> actualizarContacto(String telefonoAnterior, ContactoEmergencia nuevoContacto) async {
    final prefs = await SharedPreferences.getInstance();
    final contactos = await obtenerContactos();
    final index = contactos.indexWhere((c) => c.telefono == telefonoAnterior);
    if (index >= 0) {
      contactos[index] = nuevoContacto;
      await prefs.setString(_key, jsonEncode(contactos.map((c) => c.toJson()).toList()));
    }
  }

  /// Limpia todos los contactos de emergencia
  static Future<void> limpiar() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key);
  }
}
