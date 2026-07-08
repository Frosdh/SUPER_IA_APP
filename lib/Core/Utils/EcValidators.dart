/// EcValidators — Super_IA
/// Validadores reutilizables para formularios de registro (web y móvil).
/// Mismas reglas que server_php/admin/js/validaciones.js y
/// server_php/admin/funciones_validacion.php (doble barrera cliente/servidor).
library ec_validators;

class EcValidators {
  EcValidators._();

  // ─────────────────────────────────────────────────────────────
  // NOMBRES / APELLIDOS — solo letras y espacios
  // ─────────────────────────────────────────────────────────────
  static final RegExp _soloLetras =
      RegExp(r"^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s'-]+$");

  /// Valida que [valor] contenga solo letras (con tildes/ñ) y espacios.
  /// Devuelve el mensaje de error o `null` si es válido.
  static String? nombre(String? valor, [String campo = 'Este campo']) {
    final v = (valor ?? '').trim();
    if (v.isEmpty) return '$campo es requerido';
    if (v.length < 2) return '$campo debe tener al menos 2 caracteres';
    if (v.length > 80) return '$campo es demasiado largo';
    if (!_soloLetras.hasMatch(v)) return '$campo solo puede contener letras';
    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // TELÉFONO ECUADOR (celular o fijo)
  // ─────────────────────────────────────────────────────────────
  static final RegExp _soloDigitos = RegExp(r'^\d+$');
  static final RegExp _celularEc = RegExp(r'^09\d{8}$');
  static final RegExp _fijoEc = RegExp(r'^0[2-7]\d{7}$');
  static final RegExp _conCodigoPais = RegExp(r'^593\d{9}$');

  /// Valida un número de teléfono ecuatoriano (celular 09XXXXXXXX,
  /// fijo 0[2-7]XXXXXXX o con código de país 593XXXXXXXXX).
  static String? telefono(String? valor) {
    final tel = (valor ?? '').replaceAll(RegExp(r'[\s\-().+]'), '');
    if (tel.isEmpty) return 'El teléfono es requerido';
    if (!_soloDigitos.hasMatch(tel)) return 'El teléfono solo puede contener números';
    if (_celularEc.hasMatch(tel)) return null;
    if (_fijoEc.hasMatch(tel)) return null;
    if (_conCodigoPais.hasMatch(tel)) return null;
    return 'Ingresa un número ecuatoriano válido (ej: 0987654321)';
  }

  /// Variante estricta: exige que sea un celular ecuatoriano (09XXXXXXXX).
  static String? celular(String? valor) {
    final tel = (valor ?? '').replaceAll(RegExp(r'[\s\-().+]'), '');
    if (tel.isEmpty) return 'El teléfono es requerido';
    if (!_soloDigitos.hasMatch(tel)) return 'El teléfono solo puede contener números';
    if (tel.length != 10) return 'El celular debe tener 10 dígitos';
    if (!_celularEc.hasMatch(tel)) {
      return 'Ingresa un celular ecuatoriano válido (ej: 0987654321)';
    }
    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // CÉDULA ECUATORIANA — algoritmo oficial del Registro Civil
  // ─────────────────────────────────────────────────────────────
  /// Valida una cédula ecuatoriana (persona natural) o RUC jurídico
  /// usando el algoritmo módulo 10 / módulo 11 oficial.
  static String? cedula(String? valor) {
    final ced = (valor ?? '').replaceAll(RegExp(r'\s'), '');

    if (!RegExp(r'^\d{10}$').hasMatch(ced)) {
      return 'La cédula debe tener exactamente 10 dígitos numéricos';
    }

    final provincia = int.parse(ced.substring(0, 2));
    if ((provincia < 1 || provincia > 24) && provincia != 30) {
      return 'Código de provincia inválido (primeros 2 dígitos)';
    }

    final tercero = int.parse(ced[2]);
    if (tercero >= 7 && tercero != 9) {
      return 'Tercer dígito inválido para persona natural';
    }

    if (tercero <= 6) {
      const coef = [2, 1, 2, 1, 2, 1, 2, 1, 2];
      var suma = 0;
      for (var i = 0; i < 9; i++) {
        var val = int.parse(ced[i]) * coef[i];
        if (val >= 10) val -= 9;
        suma += val;
      }
      final verificador = suma % 10 == 0 ? 0 : 10 - (suma % 10);
      if (verificador != int.parse(ced[9])) {
        return 'Cédula inválida (dígito verificador incorrecto)';
      }
      return null;
    }

    // RUC jurídico (tercer dígito = 9) — módulo 11
    const coefJ = [4, 3, 2, 7, 6, 5, 4, 3, 2];
    var sumaJ = 0;
    for (var i = 0; i < 9; i++) sumaJ += int.parse(ced[i]) * coefJ[i];
    final verJ = sumaJ % 11 == 0 ? 0 : 11 - (sumaJ % 11);
    if (verJ != int.parse(ced[9])) {
      return 'RUC jurídico inválido (dígito verificador)';
    }
    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // EMAIL
  // ─────────────────────────────────────────────────────────────
  static final RegExp _emailRe = RegExp(
    r"^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$",
  );

  static String? email(String? valor) {
    final v = (valor ?? '').trim();
    if (v.isEmpty) return 'El email es requerido';
    if (!_emailRe.hasMatch(v)) return 'Formato de email inválido (ej: correo@dominio.com)';
    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // USUARIO
  // ─────────────────────────────────────────────────────────────
  static String? usuario(String? valor) {
    final v = valor ?? '';
    if (v.length < 4) return 'Mínimo 4 caracteres';
    if (v.length > 30) return 'Máximo 30 caracteres';
    if (!RegExp(r'^[a-zA-Z0-9._-]+$').hasMatch(v)) {
      return 'Solo letras, números, puntos, guiones o guión bajo';
    }
    return null;
  }
}
