import 'dart:io';

/// Modelo que acumula todos los datos del registro de conductor
/// a medida que el usuario avanza por los 6 pasos.
class DriverRegistrationData {
  // ── Paso 1: Ciudad ──────────────────────────────────────────
  String ciudad;

  // ── Paso 2: Datos personales ────────────────────────────────
  String nombre;
  String email;
  String telefono;
  String cedula;
  String password;

  // ── Paso 3: Documentos ──────────────────────────────────────
  File? licenciaFrente;
  File? licenciaReverso;
  File? fotoCedula;
  File? fotoSoat;
  File? fotoMatricula;
  File? vinculacionCoop;

  // ── Paso 4: Vehículo ────────────────────────────────────────
  String marca;
  String modelo;
  String placa;
  String color;
  int    anio;
  int    categoriaId;

  // ── Paso 5: Foto de perfil ──────────────────────────────────
  File? fotoPerfil;

  // ── Paso 6: Antecedentes ────────────────────────────────────
  bool aceptaAntecedentes;

  // ── Campos de Cooperativa ───────────────────────────────────
  String tipoConductor; // 'independiente' o 'cooperativa'
  int?   cooperativaId;

  DriverRegistrationData({
    this.ciudad             = 'Cuenca',
    this.nombre             = '',
    this.email              = '',
    this.telefono           = '',
    this.cedula             = '',
    this.password           = '',
    this.licenciaFrente,
    this.licenciaReverso,
    this.fotoCedula,
    this.fotoSoat,
    this.fotoMatricula,
    this.vinculacionCoop,
    this.marca              = '',
    this.modelo             = '',
    this.placa              = '',
    this.color              = '',
    this.anio               = 2020,
    this.categoriaId        = 1,
    this.fotoPerfil,
    this.aceptaAntecedentes = false,
    this.tipoConductor      = 'independiente',
    this.cooperativaId,
  });

  /// Verifica que todos los documentos requeridos estén adjuntos.
  bool get documentosCompletos =>
      licenciaFrente  != null &&
      licenciaReverso != null &&
      fotoCedula      != null &&
      fotoSoat        != null &&
      fotoMatricula   != null &&
      (tipoConductor == 'independiente' || vinculacionCoop != null);
}
