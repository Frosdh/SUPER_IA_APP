<?php
/**
 * funciones_validacion.php — Super_IA
 * Validaciones server-side para formularios de asesor.
 * Mismo conjunto de reglas que validaciones.js (doble barrera).
 */

// ─────────────────────────────────────────────────────────────
// CÉDULA ECUATORIANA — algoritmo oficial Registro Civil EC
// ─────────────────────────────────────────────────────────────
function validarCedulaEc(string $cedula): array {
    $cedula = preg_replace('/\s/', '', $cedula);

    // 1. Solo dígitos, exactamente 10
    if (!preg_match('/^\d{10}$/', $cedula)) {
        return ['ok' => false, 'msg' => 'La cédula debe tener exactamente 10 dígitos numéricos'];
    }

    // 2. Código de provincia (01–24 o 30)
    $provincia = (int) substr($cedula, 0, 2);
    if (($provincia < 1 || $provincia > 24) && $provincia !== 30) {
        return ['ok' => false, 'msg' => 'Código de provincia inválido (primeros 2 dígitos)'];
    }

    // 3. Tercer dígito: 0–5 persona natural; 6 entidad pública; 9 jurídica
    $tercero = (int) $cedula[2];
    if ($tercero >= 7 && $tercero !== 9) {
        return ['ok' => false, 'msg' => 'Tercer dígito inválido para persona natural'];
    }

    // 4. Persona natural (tercero 0–6) — módulo 10
    if ($tercero <= 6) {
        $coef = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $val = (int)$cedula[$i] * $coef[$i];
            if ($val >= 10) $val -= 9;
            $suma += $val;
        }
        $verificador = ($suma % 10 === 0) ? 0 : 10 - ($suma % 10);
        if ($verificador !== (int)$cedula[9]) {
            return ['ok' => false, 'msg' => 'Cédula inválida (dígito verificador incorrecto)'];
        }
        return ['ok' => true, 'msg' => 'Cédula válida'];
    }

    // 5. RUC jurídico (tercero = 9) — módulo 11
    $coefJ = [4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sumaJ = 0;
    for ($i = 0; $i < 9; $i++) $sumaJ += (int)$cedula[$i] * $coefJ[$i];
    $verJ = ($sumaJ % 11 === 0) ? 0 : 11 - ($sumaJ % 11);
    if ($verJ !== (int)$cedula[9]) {
        return ['ok' => false, 'msg' => 'RUC jurídico inválido (dígito verificador)'];
    }
    return ['ok' => true, 'msg' => 'RUC jurídico válido'];
}

// ─────────────────────────────────────────────────────────────
// EMAIL
// ─────────────────────────────────────────────────────────────
function validarEmail(string $email): array {
    $email = trim($email);
    if ($email === '') return ['ok' => false, 'msg' => 'El email es requerido'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Formato de email inválido (ej: correo@dominio.com)'];
    }
    // Dominios con typos comunes
    $domain = strtolower(explode('@', $email)[1]);
    $typos  = ['gmai.com'=>'gmail.com','gmial.com'=>'gmail.com','gnail.com'=>'gmail.com',
                'hotmial.com'=>'hotmail.com','hotmal.com'=>'hotmail.com','yaho.com'=>'yahoo.com'];
    if (isset($typos[$domain])) {
        return ['ok' => false, 'msg' => "¿Quisiste decir @{$typos[$domain]}?"];
    }
    return ['ok' => true, 'msg' => 'Email válido'];
}

// ─────────────────────────────────────────────────────────────
// TELÉFONO ECUADOR
// ─────────────────────────────────────────────────────────────
function validarTelefono(string $tel): array {
    $tel = preg_replace('/[\s\-().+]/', '', $tel);
    if ($tel === '') return ['ok' => false, 'msg' => 'El teléfono es requerido'];
    if (!ctype_digit($tel)) return ['ok' => false, 'msg' => 'Solo se permiten dígitos'];

    if (preg_match('/^09\d{8}$/', $tel)) return ['ok' => true, 'msg' => 'Número celular válido'];
    if (preg_match('/^0[2-7]\d{7}$/', $tel)) return ['ok' => true, 'msg' => 'Número fijo válido'];
    if (preg_match('/^593\d{9}$/', $tel)) return ['ok' => true, 'msg' => 'Número válido con código de país'];

    return ['ok' => false, 'msg' => 'Ingresa un número ecuatoriano válido (ej: 0987654321 o 022345678)'];
}

// ─────────────────────────────────────────────────────────────
// NOMBRES / APELLIDOS
// ─────────────────────────────────────────────────────────────
function validarNombre(string $valor, string $campo = 'Campo'): array {
    $valor = trim($valor);
    if (strlen($valor) < 2) return ['ok' => false, 'msg' => "$campo debe tener al menos 2 caracteres"];
    if (strlen($valor) > 80) return ['ok' => false, 'msg' => "$campo es demasiado largo"];
    if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\\s'\\-]+$/u", $valor)) {
        return ['ok' => false, 'msg' => "$campo solo puede contener letras"];
    }
    return ['ok' => true, 'msg' => "$campo válido"];
}

// ─────────────────────────────────────────────────────────────
// USUARIO
// ─────────────────────────────────────────────────────────────
function validarUsuario(string $valor): array {
    if (strlen($valor) < 4)  return ['ok' => false, 'msg' => 'El usuario debe tener mínimo 4 caracteres'];
    if (strlen($valor) > 30) return ['ok' => false, 'msg' => 'El usuario no puede superar 30 caracteres'];
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $valor)) {
        return ['ok' => false, 'msg' => 'Usuario solo puede contener letras, números, puntos, guiones o guión bajo'];
    }
    return ['ok' => true, 'msg' => 'Usuario válido'];
}

// ─────────────────────────────────────────────────────────────
// CONTRASEÑA
// ─────────────────────────────────────────────────────────────
function validarPassword(string $pass): array {
    if (strlen($pass) < 6) return ['ok' => false, 'msg' => 'La contraseña debe tener mínimo 6 caracteres'];
    return ['ok' => true, 'msg' => 'Contraseña aceptable'];
}

// ─────────────────────────────────────────────────────────────
// VALIDAR FORMULARIO COMPLETO — devuelve array de errores
// Llama a todas las funciones y acumula mensajes de error.
// ─────────────────────────────────────────────────────────────
function validarFormularioAsesor(array $post, bool $conUsuario = true): array {
    $errores = [];

    $checks = [
        ['nombres',   fn($v) => validarNombre($v, 'Nombres')],
        ['apellidos', fn($v) => validarNombre($v, 'Apellidos')],
        ['cedula',    fn($v) => validarCedulaEc($v)],
        ['email',     fn($v) => validarEmail($v)],
        ['telefono',  fn($v) => validarTelefono($v)],
        ['password',  fn($v) => validarPassword($v)],
    ];

    if ($conUsuario) {
        $checks[] = ['usuario', fn($v) => validarUsuario($v)];
    }

    foreach ($checks as [$campo, $fn]) {
        $val = trim($post[$campo] ?? '');
        $r   = $fn($val);
        if (!$r['ok']) $errores[] = $r['msg'];
    }

    // Confirmar contraseña
    if (isset($post['password_confirm'])) {
        if (($post['password'] ?? '') !== ($post['password_confirm'] ?? '')) {
            $errores[] = 'Las contraseñas no coinciden';
        }
    }

    return $errores;
}

// ─────────────────────────────────────────────────────────────
// VALIDAR FORMULARIO — devuelve errores indexados por campo
// ['campo' => 'mensaje de error']
// ─────────────────────────────────────────────────────────────
function validarFormularioCampos(array $post, bool $conUsuario = true): array {
    $errores = [];

    $checks = [
        'nombres'   => fn($v) => validarNombre($v, 'Nombres'),
        'apellidos' => fn($v) => validarNombre($v, 'Apellidos'),
        'cedula'    => fn($v) => validarCedulaEc($v),
        'email'     => fn($v) => validarEmail($v),
        'telefono'  => fn($v) => validarTelefono($v),
        'password'  => fn($v) => validarPassword($v),
    ];
    if ($conUsuario) {
        $checks['usuario'] = fn($v) => validarUsuario($v);
    }

    foreach ($checks as $campo => $fn) {
        $val = trim($post[$campo] ?? '');
        $r   = $fn($val);
        if (!$r['ok']) $errores[$campo] = $r['msg'];
    }

    if (isset($post['password_confirm'])) {
        if (($post['password'] ?? '') !== ($post['password_confirm'] ?? '')) {
            $errores['password_confirm'] = 'Las contraseñas no coinciden';
        }
    }

    return $errores;
}
