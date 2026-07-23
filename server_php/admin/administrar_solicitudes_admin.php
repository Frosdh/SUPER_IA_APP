<?php
// ============================================================
// admin/administrar_solicitudes_admin.php
// ------------------------------------------------------------
// Vista ÚNICA del Super Administrador para aprobar/rechazar TODAS
// las solicitudes de registro de gerente, supervisor y asesor,
// sin importar si se originaron desde la web (3 tablas "legacy",
// una por rol) o desde la app móvil (tabla `solicitud_registro`):
//   - solicitudes_admin      -> crea usuario.rol = 'gerente_general'
//   - solicitudes_supervisor -> crea usuario.rol = 'supervisor'
//   - solicitudes_asesor     -> crea usuario.rol = 'asesor'
//   - solicitud_registro     -> usuario y perfil de rol ya existen,
//                                 solo se activa/deniega la cuenta.
// Antes cada tabla tenía su propia pantalla aislada (y las de
// supervisor/asesor/app ni siquiera eran accesibles para el Super
// Administrador). Ahora se listan juntas, con badge de rol, origen,
// banco/cooperativa resuelto y filtro por banco/rol/estado.
// ============================================================
require_once 'db_admin.php';

// ── Helpers compartidos ──────────────────────────────────────
function asu_uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Resuelve el id de cooperativa guardado en una solicitud (UUID real de
// `unidad_bancaria`, o "seps_123" del catastro SEPS importado) a un
// `unidad_bancaria.id` real, creando una fila espejo si hace falta.
// Tiene efectos secundarios (INSERT) -> solo se usa al APROBAR.
function asu_resolverUnidadBancariaId(PDO $pdo, string $idCooperativa): ?string {
    $idCooperativa = trim($idCooperativa);
    if ($idCooperativa === '') return null;

    if (strpos($idCooperativa, 'seps_') === 0) {
        $sepsId = substr($idCooperativa, 5);
        $stSeps = $pdo->prepare('SELECT * FROM seps_cooperativas WHERE id = ? LIMIT 1');
        $stSeps->execute([$sepsId]);
        $seps = $stSeps->fetch();
        if (!$seps) return null;

        $codigo = 'SEPS-' . $seps['id'];
        $stExiste = $pdo->prepare('SELECT id FROM unidad_bancaria WHERE codigo = ? LIMIT 1');
        $stExiste->execute([$codigo]);
        $existente = $stExiste->fetchColumn();
        if ($existente) return (string)$existente;

        $nuevoId = asu_uuid();
        $pdo->prepare('INSERT INTO unidad_bancaria (id, nombre, codigo, descripcion, activo) VALUES (?, ?, ?, ?, 1)')
            ->execute([$nuevoId, $seps['razon_social'], $codigo, $seps['direccion'] ?? null]);
        return $nuevoId;
    }

    $stChk = $pdo->prepare('SELECT id FROM unidad_bancaria WHERE id = ? LIMIT 1');
    $stChk->execute([$idCooperativa]);
    $found = $stChk->fetchColumn();
    return $found ? (string)$found : null;
}

// Encuentra (o crea) una agencia "por defecto" para una cooperativa que
// todavía no tiene ninguna agencia registrada.
function asu_resolverAgenciaPrincipal(PDO $pdo, string $unidadBancariaId): string {
    $st = $pdo->prepare("SELECT id FROM agencia WHERE unidad_bancaria_id = ? ORDER BY id LIMIT 1");
    $st->execute([$unidadBancariaId]);
    $ag = $st->fetchColumn();
    if ($ag) return (string)$ag;

    $st = $pdo->prepare("SELECT nombre FROM unidad_bancaria WHERE id = ? LIMIT 1");
    $st->execute([$unidadBancariaId]);
    $nombreCoop = $st->fetchColumn() ?: 'Cooperativa';
    $nombreCoopCorto = mb_substr((string)$nombreCoop, 0, 30);
    $nombreZona = mb_substr('Zona - ' . $nombreCoopCorto, 0, 45);

    $zonaId = asu_uuid();
    try {
        $pdo->prepare("INSERT INTO zona (id, nombre, ciudad) VALUES (?, ?, ?)")
            ->execute([$zonaId, $nombreZona, 'N/D']);
    } catch (\Throwable $e) {
        $pdo->prepare("INSERT INTO zona (id, nombre) VALUES (?, ?)")
            ->execute([$zonaId, $nombreZona]);
    }

    $agenciaId = asu_uuid();
    try {
        $pdo->prepare("INSERT INTO agencia (id, zona_id, unidad_bancaria_id, nombre, ciudad, direccion, activo) VALUES (?, ?, ?, ?, ?, ?, 1)")
            ->execute([$agenciaId, $zonaId, $unidadBancariaId, 'Agencia Principal', 'N/D', 'N/D']);
    } catch (\Throwable $e) {
        $pdo->prepare("INSERT INTO agencia (id, zona_id, unidad_bancaria_id, nombre, activo) VALUES (?, ?, ?, ?, 1)")
            ->execute([$agenciaId, $zonaId, $unidadBancariaId, 'Agencia Principal']);
    }
    return $agenciaId;
}

// Resuelve supervisor.jefe_agencia_id (NOT NULL) a partir del "gerente
// responsable" elegido/asignado en la solicitud.
function asu_resolverJefeAgenciaId(PDO $pdo, ?string $idAdministrador, string $idCooperativaSolicitud): ?string {
    if (!empty($idAdministrador)) {
        $st = $pdo->prepare('SELECT id FROM jefe_agencia WHERE usuario_id = ? LIMIT 1');
        $st->execute([$idAdministrador]);
        $ja = $st->fetchColumn();
        if ($ja) return (string)$ja;

        $st = $pdo->prepare('SELECT unidad_bancaria_id FROM gerente_general WHERE usuario_id = ? LIMIT 1');
        $st->execute([$idAdministrador]);
        $ubId = $st->fetchColumn();
        if ($ubId) {
            $agenciaId = asu_resolverAgenciaPrincipal($pdo, (string)$ubId);
            $nuevoJaId = asu_uuid();
            $pdo->prepare('INSERT INTO jefe_agencia (id, usuario_id, agencia_id) VALUES (?, ?, ?)')
                ->execute([$nuevoJaId, $idAdministrador, $agenciaId]);
            return $nuevoJaId;
        }
    }
    return null;
}

// Resolución de SOLO LECTURA del nombre de banco/cooperativa para mostrar
// en la tabla (sin crear filas espejo). Usa un cache local por request.
function asu_nombreBanco(PDO $pdo, ?string $idCooperativa, array &$cache): ?array {
    $idCooperativa = trim((string)($idCooperativa ?? ''));
    if ($idCooperativa === '') return null;
    if (array_key_exists($idCooperativa, $cache)) return $cache[$idCooperativa];

    $resultado = null;
    if (strpos($idCooperativa, 'seps_') === 0) {
        $sepsId = substr($idCooperativa, 5);
        $st = $pdo->prepare('SELECT razon_social FROM seps_cooperativas WHERE id = ? LIMIT 1');
        $st->execute([$sepsId]);
        $nombre = $st->fetchColumn();
        if ($nombre) $resultado = ['id' => $idCooperativa, 'nombre' => $nombre];
    } else {
        $st = $pdo->prepare('SELECT id, nombre FROM unidad_bancaria WHERE id = ? LIMIT 1');
        $st->execute([$idCooperativa]);
        $row = $st->fetch();
        if ($row) $resultado = ['id' => $row['id'], 'nombre' => $row['nombre']];
    }
    $cache[$idCooperativa] = $resultado;
    return $resultado;
}

// Fallback para asesores sin id_cooperativa propio: se resuelve vía la
// cadena supervisor -> jefe_agencia -> agencia -> unidad_bancaria.
function asu_bancoViaSupervisor(PDO $pdo, ?string $usuarioIdSupervisor): ?array {
    $usuarioIdSupervisor = trim((string)($usuarioIdSupervisor ?? ''));
    if ($usuarioIdSupervisor === '') return null;
    $st = $pdo->prepare("
        SELECT ub.id, ub.nombre
        FROM supervisor sv
        JOIN jefe_agencia ja ON ja.id = sv.jefe_agencia_id
        JOIN agencia ag ON ag.id = ja.agencia_id
        JOIN unidad_bancaria ub ON ub.id = ag.unidad_bancaria_id
        WHERE sv.usuario_id = ?
        LIMIT 1
    ");
    $st->execute([$usuarioIdSupervisor]);
    $row = $st->fetch();
    return $row ?: null;
}

// Resuelve la credencial (PDF/imagen) a una ruta relativa verificada en
// disco, probando varias carpetas candidatas. Devuelve [url, existe, ext].
function asu_resolverCredencial(string $archivo, array $carpetasRelativas): array {
    $archivo = trim($archivo);
    if ($archivo === '') return [null, false, null];
    $norm = ltrim(str_replace('\\', '/', $archivo), '/');

    $candidatos = [];
    if (stripos($norm, 'uploads/') === 0) {
        $candidatos[] = '../../' . $norm;
    }
    foreach ($carpetasRelativas as $carpeta) {
        $candidatos[] = '../../' . rtrim($carpeta, '/') . '/' . basename($norm);
    }

    foreach ($candidatos as $rel) {
        $fisica = __DIR__ . '/' . $rel;
        if (is_file($fisica)) {
            return [$rel, true, strtolower(pathinfo($fisica, PATHINFO_EXTENSION))];
        }
    }
    return [$candidatos[0] ?? null, false, null];
}

// ── Sesión — SOLO SUPER ADMIN ────────────────────────────────
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;
if (!$is_super_admin) {
    header('Location: login.php?role=super_admin');
    exit;
}
$admin_id     = $_SESSION['super_admin_id'];
$admin_nombre = $_SESSION['super_admin_nombre'];
$admin_rol    = $_SESSION['super_admin_rol'] ?? 'Super Administrador';

$mensaje_exito = '';
$mensaje_error = '';
if (isset($_SESSION['flash_success'])) { $mensaje_exito = (string)$_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $mensaje_error = (string)$_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// ── Procesar aprobación/rechazo ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_solicitud   = $_POST['id_solicitud'] ?? null;
    $accion         = $_POST['accion'] ?? null;
    $tipo_solicitud = $_POST['tipo_solicitud'] ?? null; // gerente_general | supervisor | asesor | jefe_regional | jefe_agencia | administrador
    $origen         = $_POST['origen_solicitud'] ?? 'legacy'; // legacy (3 tablas web) | app (solicitud_registro)
    $observaciones  = trim($_POST['observaciones'] ?? '');

    $mensaje_exito = '';
    $mensaje_error = '';

    if ($id_solicitud && $accion && $origen === 'app') {
        // ============================================================
        // SOLICITUDES ORIGINADAS DESDE LA APP MÓVIL (solicitud_registro)
        // El usuario y su perfil de rol (asesor/supervisor/gerente_general)
        // ya existen; aquí solo se activa o se deniega la cuenta.
        // ============================================================
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT id, usuario_id, estado FROM solicitud_registro WHERE id = ? LIMIT 1 FOR UPDATE');
            $st->execute([$id_solicitud]);
            $sol = $st->fetch();

            if (!$sol) {
                throw new Exception('Solicitud no encontrada');
            }
            if ($sol['estado'] !== 'pendiente') {
                throw new Exception('Esta solicitud ya fue procesada anteriormente');
            }

            if ($accion === 'aprobar') {
                $pdo->prepare("UPDATE solicitud_registro SET estado='aprobado', revisado_por=?, revisado_at=NOW() WHERE id=?")
                    ->execute([$admin_id, $id_solicitud]);
                $pdo->prepare("UPDATE usuario SET activo=1, estado_aprobacion='aprobado', aprobado_por=?, fecha_aprobacion=NOW() WHERE id=?")
                    ->execute([$admin_id, $sol['usuario_id']]);
                $mensaje_exito = "✅ Solicitud aprobada. El usuario ya puede iniciar sesión.";
            } elseif ($accion === 'rechazar') {
                $pdo->prepare("UPDATE solicitud_registro SET estado='denegado', revisado_por=?, revisado_at=NOW(), motivo_denegacion=? WHERE id=?")
                    ->execute([$admin_id, ($observaciones !== '' ? $observaciones : null), $id_solicitud]);
                $pdo->prepare("UPDATE usuario SET activo=0, estado_aprobacion='denegado' WHERE id=?")
                    ->execute([$sol['usuario_id']]);
                $mensaje_exito = "❌ Solicitud rechazada.";
            } else {
                throw new Exception('Acción inválida');
            }

            $pdo->commit();
        } catch (\Throwable $eTx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensaje_error = "Error: " . $eTx->getMessage();
        }
    } elseif ($id_solicitud && $accion && in_array($tipo_solicitud, ['gerente_general', 'supervisor', 'asesor'], true)) {
        try {
            // ============================================================
            // GERENTE / ADMINISTRADOR (solicitudes_admin)
            // ============================================================
            if ($tipo_solicitud === 'gerente_general') {
                if ($accion === 'aprobar') {
                    $stmt = $pdo->prepare("SELECT * FROM solicitudes_admin WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$id_solicitud]);
                    $solicitud = $stmt->fetch();

                    if (!$solicitud) {
                        $mensaje_error = "❌ Solicitud no encontrada o ya procesada.";
                    } else {
                        $chkEmail = $pdo->prepare("SELECT id FROM usuario WHERE email = ? LIMIT 1");
                        $chkEmail->execute([$solicitud['email']]);
                        if ($chkEmail->fetch()) {
                            $mensaje_error = "❌ Ya existe un usuario con ese email.";
                        } else {
                            $pdo->beginTransaction();
                            try {
                                $nombre_completo = trim($solicitud['nombres'] . ' ' . $solicitud['apellidos']);
                                $nuevo_usuario_id = asu_uuid();
                                $pdo->prepare("
                                    INSERT INTO usuario
                                        (id, nombre, email, telefono, password_hash, rol, activo, estado_aprobacion, aprobado_por, fecha_aprobacion)
                                    VALUES (?, ?, ?, ?, ?, 'gerente_general', 1, 'aprobado', ?, NOW())
                                ")->execute([$nuevo_usuario_id, $nombre_completo, $solicitud['email'], $solicitud['telefono'], $solicitud['password_hash'], $admin_id]);

                                $unidadBancariaId = asu_resolverUnidadBancariaId($pdo, (string)$solicitud['id_cooperativa']);
                                if (!$unidadBancariaId) {
                                    throw new Exception('No se pudo vincular la cooperativa seleccionada en la solicitud (id_cooperativa="' . $solicitud['id_cooperativa'] . '").');
                                }

                                $nuevo_gg_id = asu_uuid();
                                $pdo->prepare("INSERT INTO gerente_general (id, usuario_id, unidad_bancaria_id) VALUES (?, ?, ?)")
                                    ->execute([$nuevo_gg_id, $nuevo_usuario_id, $unidadBancariaId]);

                                $pdo->prepare("UPDATE solicitudes_admin SET estado = 'aprobada', fecha_aprobacion = NOW() WHERE id_solicitud = ?")
                                    ->execute([$id_solicitud]);

                                $pdo->commit();
                                $mensaje_exito = "✅ Solicitud aprobada. El nuevo gerente puede iniciar sesión.";
                            } catch (\Throwable $eTx) {
                                $pdo->rollBack();
                                $mensaje_error = "Error al aprobar: " . $eTx->getMessage();
                            }
                        }
                    }
                } elseif ($accion === 'rechazar') {
                    $stmt = $pdo->prepare("UPDATE solicitudes_admin SET estado = 'rechazada', observaciones = ?, fecha_aprobacion = NOW() WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$observaciones, $id_solicitud]);
                    $mensaje_exito = $stmt->rowCount() > 0 ? "❌ Solicitud rechazada." : "";
                    if ($stmt->rowCount() === 0) $mensaje_error = "❌ Solicitud no encontrada o ya procesada.";
                }

            // ============================================================
            // SUPERVISOR (solicitudes_supervisor)
            // ============================================================
            } elseif ($tipo_solicitud === 'supervisor') {
                $gerente_asignar = trim($_POST['gerente_asignar'] ?? '');
                if ($gerente_asignar !== '') {
                    try {
                        $pdo->prepare("UPDATE solicitudes_supervisor SET id_administrador = ? WHERE id_solicitud = ? AND (id_administrador IS NULL OR id_administrador = '')")
                            ->execute([$gerente_asignar, $id_solicitud]);
                    } catch (\Throwable $e) { /* no bloquear */ }
                }

                if ($accion === 'aprobar') {
                    $stmt = $pdo->prepare("SELECT * FROM solicitudes_supervisor WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$id_solicitud]);
                    $solicitud = $stmt->fetch();

                    if (!$solicitud) {
                        $mensaje_error = "❌ Solicitud no encontrada o ya procesada.";
                    } else {
                        $chkEmail = $pdo->prepare("SELECT id FROM usuario WHERE email = ? LIMIT 1");
                        $chkEmail->execute([$solicitud['email']]);
                        if ($chkEmail->fetch()) {
                            $mensaje_error = "❌ Ya existe un usuario con ese email.";
                        } else {
                            $pdo->beginTransaction();
                            try {
                                $nombre_completo = trim($solicitud['nombres'] . ' ' . $solicitud['apellidos']);
                                $nuevo_usuario_id = asu_uuid();
                                $pdo->prepare("
                                    INSERT INTO usuario
                                        (id, nombre, email, telefono, password_hash, rol, activo, estado_aprobacion, aprobado_por, fecha_aprobacion)
                                    VALUES (?, ?, ?, ?, ?, 'supervisor', 1, 'aprobado', ?, NOW())
                                ")->execute([$nuevo_usuario_id, $nombre_completo, $solicitud['email'], $solicitud['telefono'], $solicitud['password_hash'], $admin_id]);

                                $jefeAgenciaId = asu_resolverJefeAgenciaId($pdo, $solicitud['id_administrador'] ?: null, (string)$solicitud['id_cooperativa']);
                                if (!$jefeAgenciaId) {
                                    throw new \Exception('No se pudo asignar una agencia/jefe de agencia para esta solicitud. Asigna un "Gerente Responsable" antes de aprobar.');
                                }

                                $nuevo_sup_id = asu_uuid();
                                $pdo->prepare("INSERT INTO supervisor (id, usuario_id, jefe_agencia_id, meta_asesores) VALUES (?, ?, ?, 5)")
                                    ->execute([$nuevo_sup_id, $nuevo_usuario_id, $jefeAgenciaId]);

                                $pdo->prepare("UPDATE solicitudes_supervisor SET estado = 'aprobada', fecha_aprobacion = NOW() WHERE id_solicitud = ?")
                                    ->execute([$id_solicitud]);

                                $pdo->commit();
                                $mensaje_exito = "✅ Solicitud aprobada. El nuevo supervisor puede iniciar sesión.";
                            } catch (\Throwable $eTx) {
                                $pdo->rollBack();
                                $mensaje_error = "Error al aprobar: " . $eTx->getMessage();
                            }
                        }
                    }
                } elseif ($accion === 'rechazar') {
                    $stmt = $pdo->prepare("UPDATE solicitudes_supervisor SET estado = 'rechazada', observaciones = ?, fecha_aprobacion = NOW() WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$observaciones, $id_solicitud]);
                    if ($stmt->rowCount() > 0) { $mensaje_exito = "❌ Solicitud rechazada."; }
                    else { $mensaje_error = "❌ Solicitud no encontrada o ya procesada."; }
                }

            // ============================================================
            // ASESOR (solicitudes_asesor)
            // ============================================================
            } elseif ($tipo_solicitud === 'asesor') {
                if ($accion === 'aprobar') {
                    $stmt = $pdo->prepare("SELECT * FROM solicitudes_asesor WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$id_solicitud]);
                    $solicitud = $stmt->fetch();

                    if (!$solicitud) {
                        $mensaje_error = "❌ Solicitud no encontrada o ya procesada.";
                    } else {
                        $chkEmail = $pdo->prepare("SELECT id FROM usuario WHERE email = ? LIMIT 1");
                        $chkEmail->execute([$solicitud['email']]);
                        if ($chkEmail->fetch()) {
                            $mensaje_error = "❌ Ya existe un usuario con ese email.";
                        } else {
                            $stmtSup = $pdo->prepare("SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1");
                            $stmtSup->execute([$solicitud['id_supervisor']]);
                            $supervisor_pk = $stmtSup->fetchColumn();

                            if (!$supervisor_pk) {
                                $mensaje_error = "❌ No se encontró el registro de supervisor asociado a esta solicitud.";
                            } else {
                                $pdo->beginTransaction();
                                try {
                                    $nombre_completo = trim($solicitud['nombres'] . ' ' . $solicitud['apellidos']);
                                    $nuevo_usuario_id = asu_uuid();
                                    $pdo->prepare("
                                        INSERT INTO usuario
                                            (id, nombre, email, telefono, password_hash, rol, activo, estado_aprobacion, aprobado_por, fecha_aprobacion)
                                        VALUES (?, ?, ?, ?, ?, 'asesor', 1, 'aprobado', ?, NOW())
                                    ")->execute([$nuevo_usuario_id, $nombre_completo, $solicitud['email'], $solicitud['telefono'], $solicitud['password_hash'], $admin_id]);

                                    $nuevo_asesor_id = asu_uuid();
                                    $pdo->prepare("INSERT INTO asesor (id, usuario_id, supervisor_id) VALUES (?, ?, ?)")
                                        ->execute([$nuevo_asesor_id, $nuevo_usuario_id, $supervisor_pk]);

                                    $pdo->prepare("UPDATE solicitudes_asesor SET estado = 'aprobada', fecha_aprobacion = NOW() WHERE id_solicitud = ?")
                                        ->execute([$id_solicitud]);

                                    $pdo->commit();
                                    $mensaje_exito = "✅ Solicitud aprobada. El nuevo asesor ya puede iniciar sesión.";
                                } catch (\Throwable $eTx) {
                                    $pdo->rollBack();
                                    $mensaje_error = "Error al aprobar: " . $eTx->getMessage();
                                }
                            }
                        }
                    }
                } elseif ($accion === 'rechazar') {
                    $stmt = $pdo->prepare("UPDATE solicitudes_asesor SET estado = 'rechazada', observaciones = ?, fecha_aprobacion = NOW() WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$observaciones, $id_solicitud]);
                    if ($stmt->rowCount() > 0) { $mensaje_exito = "❌ Solicitud rechazada."; }
                    else { $mensaje_error = "❌ Solicitud no encontrada o ya procesada."; }
                }
            }
        } catch (\Throwable $e) {
            $mensaje_error = "Error: " . $e->getMessage();
        }
    } else {
        $mensaje_error = "Datos de la solicitud inválidos.";
    }

    // Post-Redirect-Get
    $_SESSION['flash_success'] = $mensaje_exito;
    $_SESSION['flash_error']   = $mensaje_error;
    header('Location: administrar_solicitudes_admin.php');
    exit;
}

// ── Cargar las 3 tablas legacy ───────────────────────────────
$solAdmin = [];
try {
    $solAdmin = $pdo->query("SELECT * FROM solicitudes_admin ORDER BY fecha_solicitud DESC")->fetchAll();
} catch (\Throwable $e) { $solAdmin = []; }

$solSupervisor = [];
try {
    $solSupervisor = $pdo->query("
        SELECT ss.*, u_ger.nombre AS gerente_nombre
        FROM solicitudes_supervisor ss
        LEFT JOIN usuario u_ger ON u_ger.id = ss.id_administrador
        ORDER BY ss.fecha_solicitud DESC
    ")->fetchAll();
} catch (\Throwable $e) { $solSupervisor = []; }

$solAsesor = [];
try {
    $solAsesor = $pdo->query("
        SELECT sa.*, u_sup.nombre AS supervisor_nombre
        FROM solicitudes_asesor sa
        LEFT JOIN usuario u_sup ON u_sup.id = sa.id_supervisor
        ORDER BY sa.fecha_solicitud DESC
    ")->fetchAll();
} catch (\Throwable $e) { $solAsesor = []; }

// Solicitudes originadas desde la app móvil (usuario + perfil de rol ya
// creados; solo falta activar/denegar). Se listan junto a las 3 de arriba
// para tener una única bandeja del Super Administrador.
$solApp = [];
try {
    $solApp = $pdo->query("
        SELECT
            sr.id, sr.usuario_id, sr.rol_solicitado, sr.documento_url,
            sr.documento_nombre_original, sr.estado, sr.created_at,
            sr.revisado_at, sr.motivo_denegacion,
            u.nombre, u.email, u.telefono,
            COALESCE(ub_ger.id, ub_sup.id, ub_ase.id) AS banco_id,
            COALESCE(ub_ger.nombre, ub_sup.nombre, ub_ase.nombre) AS banco_nombre
        FROM solicitud_registro sr
        JOIN usuario u ON u.id = sr.usuario_id
        LEFT JOIN gerente_general gg   ON gg.usuario_id = u.id
        LEFT JOIN unidad_bancaria ub_ger ON ub_ger.id = gg.unidad_bancaria_id
        LEFT JOIN supervisor sv        ON sv.usuario_id = u.id
        LEFT JOIN jefe_agencia ja_sv   ON ja_sv.id = sv.jefe_agencia_id
        LEFT JOIN agencia ag_sv        ON ag_sv.id = ja_sv.agencia_id
        LEFT JOIN unidad_bancaria ub_sup ON ub_sup.id = ag_sv.unidad_bancaria_id
        LEFT JOIN asesor a2            ON a2.usuario_id = u.id
        LEFT JOIN supervisor sv2       ON sv2.id = a2.supervisor_id
        LEFT JOIN jefe_agencia ja_a    ON ja_a.id = sv2.jefe_agencia_id
        LEFT JOIN agencia ag_a         ON ag_a.id = ja_a.agencia_id
        LEFT JOIN unidad_bancaria ub_ase ON ub_ase.id = ag_a.unidad_bancaria_id
        WHERE sr.rol_solicitado IN ('gerente_general','jefe_regional','jefe_agencia','supervisor','asesor','administrador')
        ORDER BY sr.created_at DESC
    ")->fetchAll();
} catch (\Throwable $e) { $solApp = []; }

$rolLabelsApp = [
    'gerente_general' => 'Gerente General',
    'jefe_regional'   => 'Jefe Regional',
    'jefe_agencia'    => 'Jefe de Agencia',
    'supervisor'      => 'Supervisor',
    'asesor'          => 'Asesor',
    'administrador'   => 'Administrador',
];
$rolIconsApp = [
    'gerente_general' => 'fa-user-tie',
    'jefe_regional'   => 'fa-user-tie',
    'jefe_agencia'    => 'fa-user-tie',
    'supervisor'      => 'fa-user-shield',
    'asesor'          => 'fa-user',
    'administrador'   => 'fa-user-cog',
];
$estadoAppUi = ['pendiente' => 'pendiente', 'aprobado' => 'aprobada', 'denegado' => 'rechazada'];

// ── Normalizar en un solo arreglo, resolviendo banco/credencial ─────
$bancoCache = [];
$todas = [];

foreach ($solAdmin as $s) {
    $banco = asu_nombreBanco($pdo, $s['id_cooperativa'] ?? null, $bancoCache);
    $todas[] = [
        'tipo' => 'gerente_general', 'tipo_label' => 'Gerente', 'tipo_icon' => 'fa-user-tie', 'origen' => 'legacy',
        'id_solicitud' => $s['id_solicitud'],
        'usuario' => $s['usuario'], 'nombre_completo' => trim($s['nombres'] . ' ' . $s['apellidos']),
        'email' => $s['email'], 'telefono' => $s['telefono'],
        'banco_id' => $banco['id'] ?? null, 'banco_nombre' => $banco['nombre'] ?? null,
        'credencial_url' => !empty($s['archivo_credencial']) ? 'descargar_credencial.php?id=' . (int)$s['id_solicitud'] : null,
        'credencial_exists' => !empty($s['archivo_credencial']),
        'credencial_ext' => 'pdf',
        'estado' => $s['estado'], 'fecha_solicitud' => $s['fecha_solicitud'], 'fecha_aprobacion' => $s['fecha_aprobacion'] ?? null,
        'observaciones' => $s['observaciones'] ?? null,
        'extra' => null,
        'sin_gerente' => false, 'coop_id_raw' => $s['id_cooperativa'] ?? '',
    ];
}

foreach ($solSupervisor as $s) {
    $banco = asu_nombreBanco($pdo, $s['id_cooperativa'] ?? null, $bancoCache);
    [$credUrl, $credExists, $credExt] = asu_resolverCredencial($s['credencial_archivo'] ?? '', ['uploads/supervisor_credentials']);
    $todas[] = [
        'tipo' => 'supervisor', 'tipo_label' => 'Supervisor', 'tipo_icon' => 'fa-user-shield', 'origen' => 'legacy',
        'id_solicitud' => $s['id_solicitud'],
        'usuario' => $s['usuario'], 'nombre_completo' => trim($s['nombres'] . ' ' . $s['apellidos']),
        'email' => $s['email'], 'telefono' => $s['telefono'],
        'banco_id' => $banco['id'] ?? null, 'banco_nombre' => $banco['nombre'] ?? null,
        'credencial_url' => $credUrl, 'credencial_exists' => $credExists, 'credencial_ext' => $credExt,
        'estado' => $s['estado'], 'fecha_solicitud' => $s['fecha_solicitud'], 'fecha_aprobacion' => $s['fecha_aprobacion'] ?? null,
        'observaciones' => $s['observaciones'] ?? null,
        'extra' => !empty($s['gerente_nombre']) ? ('Gerente responsable: ' . $s['gerente_nombre']) : 'Sin gerente responsable asignado',
        'sin_gerente' => empty($s['id_administrador']), 'coop_id_raw' => $s['id_cooperativa'] ?? '',
    ];
}

foreach ($solAsesor as $s) {
    $banco = asu_nombreBanco($pdo, $s['id_cooperativa'] ?? null, $bancoCache);
    if (!$banco) $banco = asu_bancoViaSupervisor($pdo, $s['id_supervisor'] ?? null);
    [$credUrl, $credExists, $credExt] = asu_resolverCredencial($s['credencial_archivo'] ?? '', ['uploads/documentos_asesor', 'uploads/asesor_credentials']);
    $extraTxt = 'Supervisor: ' . ($s['supervisor_nombre'] ?? '—');
    if (!empty($s['banco'])) $extraTxt .= ' · Cuenta: ' . $s['banco'] . ' ' . ($s['numero_cuenta'] ?? '');
    $todas[] = [
        'tipo' => 'asesor', 'tipo_label' => 'Asesor', 'tipo_icon' => 'fa-user', 'origen' => 'legacy',
        'id_solicitud' => $s['id_solicitud'],
        'usuario' => $s['usuario'], 'nombre_completo' => trim($s['nombres'] . ' ' . $s['apellidos']),
        'email' => $s['email'], 'telefono' => $s['telefono'],
        'banco_id' => $banco['id'] ?? null, 'banco_nombre' => $banco['nombre'] ?? null,
        'credencial_url' => $credUrl, 'credencial_exists' => $credExists, 'credencial_ext' => $credExt,
        'estado' => $s['estado'], 'fecha_solicitud' => $s['fecha_solicitud'], 'fecha_aprobacion' => $s['fecha_aprobacion'] ?? null,
        'observaciones' => $s['observaciones'] ?? null,
        'extra' => $extraTxt,
        'sin_gerente' => false, 'coop_id_raw' => $s['id_cooperativa'] ?? '',
    ];
}

foreach ($solApp as $s) {
    $rol = $s['rol_solicitado'];
    $todas[] = [
        'tipo' => $rol,
        'tipo_label' => $rolLabelsApp[$rol] ?? ucfirst($rol),
        'tipo_icon' => $rolIconsApp[$rol] ?? 'fa-user',
        'origen' => 'app',
        'id_solicitud' => $s['id'],
        'usuario' => strstr((string)$s['email'], '@', true) ?: $s['email'],
        'nombre_completo' => $s['nombre'],
        'email' => $s['email'], 'telefono' => $s['telefono'],
        'banco_id' => $s['banco_id'] ?? null, 'banco_nombre' => $s['banco_nombre'] ?? null,
        'credencial_url' => $s['documento_url'] ?: null,
        'credencial_exists' => !empty($s['documento_url']),
        'credencial_ext' => !empty($s['documento_url']) ? strtolower(pathinfo($s['documento_url'], PATHINFO_EXTENSION)) : null,
        'estado' => $estadoAppUi[$s['estado']] ?? 'pendiente',
        'fecha_solicitud' => $s['created_at'], 'fecha_aprobacion' => $s['revisado_at'] ?? null,
        'observaciones' => $s['motivo_denegacion'] ?? null,
        'extra' => 'Origen: app móvil',
        'sin_gerente' => false, 'coop_id_raw' => '',
    ];
}

// Orden: pendientes primero, agrupadas por banco, luego más recientes
usort($todas, function ($a, $b) {
    $estOrder = ['pendiente' => 0, 'rechazada' => 1, 'aprobada' => 2];
    $ea = $estOrder[$a['estado']] ?? 3; $eb = $estOrder[$b['estado']] ?? 3;
    if ($ea !== $eb) return $ea <=> $eb;
    $ba = $a['banco_nombre'] ?? 'zzzz'; $bb = $b['banco_nombre'] ?? 'zzzz';
    $bc = strcasecmp($ba, $bb);
    if ($bc !== 0) return $bc;
    return strtotime((string)($b['fecha_solicitud'] ?? '')) <=> strtotime((string)($a['fecha_solicitud'] ?? ''));
});

// Bancos distintos presentes (para el <select> de filtro)
$bancosDisponibles = [];
foreach ($todas as $t) {
    if (!empty($t['banco_nombre'])) $bancosDisponibles[$t['banco_nombre']] = true;
}
ksort($bancosDisponibles, SORT_NATURAL | SORT_FLAG_CASE);

$totalPendientes = count(array_filter($todas, fn($t) => $t['estado'] === 'pendiente'));
$totalAprobadas  = count(array_filter($todas, fn($t) => $t['estado'] === 'aprobada'));
$totalRechazadas = count(array_filter($todas, fn($t) => $t['estado'] === 'rechazada'));

// Roles distintos presentes, en un orden preferido, para los botones de filtro
$ordenTipos = ['gerente_general', 'jefe_regional', 'jefe_agencia', 'administrador', 'supervisor', 'asesor'];
$tiposPresentes = [];
foreach ($todas as $t) {
    if (!isset($tiposPresentes[$t['tipo']])) {
        $tiposPresentes[$t['tipo']] = ['label' => $t['tipo_label'], 'icon' => $t['tipo_icon']];
    }
}
uksort($tiposPresentes, function ($a, $b) use ($ordenTipos) {
    $ia = array_search($a, $ordenTipos, true); $ia = $ia === false ? 99 : $ia;
    $ib = array_search($b, $ordenTipos, true); $ib = $ib === false ? 99 : $ib;
    return $ia <=> $ib;
});

$currentPage = 'solicitudes_admin';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Solicitudes de Gerente / Supervisor / Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; min-height: 100vh; }
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: sticky; height: 100vh; top: 0; flex-shrink: 0; }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 24px; }
        .page-header h1 { margin: 0; font-size: 26px; font-weight: 800; color: #1f2937; }
        .page-header p { color: #6b7280; font-size: 13.5px; margin-top: 4px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 22px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: 700; color: #1f2937; }
        .stat-card .label { color: #9ca3af; font-size: 13px; margin-top: 5px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; }

        .filter-bar { background: #fff; border: 1px solid #e2eaf4; border-radius: 14px; padding: 14px 18px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; box-shadow: 0 4px 14px rgba(10,39,72,.05); }
        .filter-bar input[type="text"], .filter-bar select { padding: 9px 14px; border: 1.5px solid #d7e0ea; border-radius: 9px; font-size: 13.5px; outline: none; }
        .btn-filter { background: #f8fafc; color: #64748b; border: 1.5px solid #e2eaf4; border-radius: 9px; padding: 8px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all .2s ease; display: inline-flex; align-items: center; gap: 6px; }
        .btn-filter:hover { background: #f1f5f9; color: #0a2748; border-color: #cbd5e1; }
        .btn-filter.active { background: #0a2748; color: #fff; border-color: #0a2748; }
        .btn-filter span.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: currentColor; }

        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 18px 20px; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; justify-content: space-between; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 15.5px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 10.5px; text-transform: uppercase; color: #6c757d; border: none; padding: 12px 14px; letter-spacing: .3px; }
        .table tbody td { padding: 12px 14px; vertical-align: middle; border-color: #f5f5f5; font-size: 13px; }
        .table tbody tr:hover { background: #fafbff; }
        .badge-tipo { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; background: #f3f4f6; color: #4b5563; }
        .badge-tipo.gerente_general { background: #ede9fe; color: #6d28d9; }
        .badge-tipo.jefe_regional { background: #ede9fe; color: #6d28d9; }
        .badge-tipo.jefe_agencia { background: #ede9fe; color: #6d28d9; }
        .badge-tipo.administrador { background: #fce7f3; color: #9d174d; }
        .badge-tipo.supervisor { background: #dbeafe; color: #1d4ed8; }
        .badge-tipo.asesor { background: #fef3c7; color: #92400e; }
        .badge-origen { font-size: 9.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .3px; }
        .badge-banco { background: #eef2ff; color: #4338ca; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; display: inline-block; }
        .badge-banco.sin { background: #f3f4f6; color: #9ca3af; font-style: italic; font-weight: 600; }
        .badge-pendiente { background: #fef08a; color: #713f12; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 11.5px; }
        .badge-aprobada { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 11.5px; }
        .badge-rechazada { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 11.5px; }
        .extra-txt { color: #6b7280; font-size: 11px; margin-top: 3px; }
        .modal-custom { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-custom.show { display: flex; }
        .modal-content-custom { background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%; max-height: 85vh; overflow-y: auto; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<?php $currentPage = 'solicitudes_admin'; require_once '_sidebar_super_admin.php'; ?>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-file-alt me-2"></i>Solicitudes de Cuentas</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($admin_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($admin_rol); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-file-alt me-2"></i>Gestión de Solicitudes — Gerente, Supervisor y Asesor</h1>
            <p>Aprueba o rechaza cuentas nuevas de gerente, supervisor o asesor — registradas desde la web o la app móvil — de cualquier banco/cooperativa. Filtra por banco, rol o estado para revisar una sola entidad a la vez.</p>
        </div>

        <?php if ($mensaje_exito): ?>
        <div class="alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($mensaje_exito); ?></div>
        <?php endif; ?>
        <?php if ($mensaje_error): ?>
        <div class="alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($mensaje_error); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="number" style="color:#f59e0b;"><?php echo $totalPendientes; ?></div><div class="label">Solicitudes Pendientes</div></div>
            <div class="stat-card"><div class="number" style="color:#10b981;"><?php echo $totalAprobadas; ?></div><div class="label">Aprobadas</div></div>
            <div class="stat-card"><div class="number" style="color:#ef4444;"><?php echo $totalRechazadas; ?></div><div class="label">Rechazadas</div></div>
        </div>

        <!-- FILTROS -->
        <div class="filter-bar">
            <div class="d-flex align-items-center gap-2" style="flex:1; min-width:220px;">
                <i class="fas fa-search" style="color:#64748b;"></i>
                <input type="text" id="busquedaSolicitud" placeholder="Buscar por nombre, usuario o email..." style="width:100%;">
            </div>

            <select id="filtroBanco" style="min-width:220px;">
                <option value="todos">Todos los bancos/cooperativas</option>
                <?php foreach (array_keys($bancosDisponibles) as $bn): ?>
                    <option value="<?= htmlspecialchars(strtolower($bn)) ?>"><?= htmlspecialchars($bn) ?></option>
                <?php endforeach; ?>
                <option value="__sin__">Sin banco asignado</option>
            </select>

            <div class="d-flex align-items-center gap-2 flex-wrap" id="tipoFilters">
                <button class="btn-filter active" data-tipo="todos">Todos los roles</button>
                <?php foreach ($tiposPresentes as $tipoKey => $tipoInfo): ?>
                    <button class="btn-filter" data-tipo="<?= htmlspecialchars($tipoKey) ?>"><i class="fas <?= htmlspecialchars($tipoInfo['icon']) ?>"></i> <?= htmlspecialchars($tipoInfo['label']) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap" id="statusFilters">
                <button class="btn-filter active" data-status="todos">Todos</button>
                <button class="btn-filter" data-status="pendiente">Pendientes</button>
                <button class="btn-filter" data-status="aprobada">Aprobadas</button>
                <button class="btn-filter" data-status="rechazada">Rechazadas</button>
            </div>

            <span id="cntResultados" style="font-size:12.5px;color:#64748b;margin-left:auto;font-weight:600;"><?php echo count($todas); ?> resultados</span>
        </div>

        <!-- TABLA -->
        <div class="table-card">
            <div class="card-header-custom"><h6>📋 Solicitudes de Creación de Cuenta</h6></div>

            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Rol</th>
                        <th>Banco / Cooperativa</th>
                        <th>Solicitante</th>
                        <th>Contacto</th>
                        <th>Credencial</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($todas)): ?>
                    <tr><td colspan="8" class="text-center py-4"><i class="fas fa-inbox me-2" style="color:#d1d5db;"></i>No hay solicitudes</td></tr>
                    <?php else: foreach ($todas as $t):
                        $bancoTexto = $t['banco_nombre'] ?? '';
                        $bancoData = $bancoTexto !== '' ? strtolower($bancoTexto) : '__sin__';
                    ?>
                    <tr data-search="<?= strtolower($t['usuario'] . ' ' . $t['nombre_completo'] . ' ' . $t['email']) ?>"
                        data-tipo="<?= $t['tipo'] ?>" data-status="<?= $t['estado'] ?>" data-banco="<?= htmlspecialchars($bancoData) ?>">
                        <td><span class="badge-tipo <?= $t['tipo'] ?>"><i class="fas <?= $t['tipo_icon'] ?>"></i> <?= htmlspecialchars($t['tipo_label']) ?></span></td>
                        <td>
                            <?php if ($bancoTexto !== ''): ?>
                                <span class="badge-banco"><i class="fas fa-university"></i> <?= htmlspecialchars($bancoTexto) ?></span>
                            <?php else: ?>
                                <span class="badge-banco sin">Sin asignar</span>
                            <?php endif; ?>
                            <?php if ($t['extra']): ?><div class="extra-txt"><?= htmlspecialchars($t['extra']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($t['nombre_completo'] ?: '—') ?></strong><br>
                            <small class="text-muted">@<?= htmlspecialchars($t['usuario']) ?></small>
                        </td>
                        <td><small><?= htmlspecialchars($t['email']) ?></small><br><small class="text-muted"><?= htmlspecialchars($t['telefono']) ?></small></td>
                        <td>
                            <?php if ($t['origen'] === 'legacy' && $t['tipo'] === 'gerente_general' && $t['credencial_exists']): ?>
                                <a href="<?= htmlspecialchars($t['credencial_url']) ?>" class="btn btn-sm btn-secondary" target="_blank" title="Descargar PDF"><i class="fas fa-file-pdf me-1"></i>Ver</a>
                            <?php elseif ($t['credencial_exists']): ?>
                                <a href="<?= htmlspecialchars($t['credencial_url']) ?>" class="btn btn-sm btn-secondary" target="_blank" title="Ver credencial"><i class="fas fa-file me-1"></i>Ver</a>
                            <?php elseif ($t['credencial_url']): ?>
                                <span class="text-muted" title="El archivo no se encontró en el servidor"><i class="fas fa-exclamation-triangle me-1"></i>No encontrada</span>
                            <?php else: ?>
                                <span class="text-muted"><i class="fas fa-times me-1"></i>Sin archivo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($t['fecha_solicitud']) ? date('d/m/Y H:i', strtotime($t['fecha_solicitud'])) : '—' ?></td>
                        <td>
                            <?php
                            $clase = match ($t['estado']) { 'pendiente' => 'badge-pendiente', 'aprobada' => 'badge-aprobada', 'rechazada' => 'badge-rechazada', default => 'badge-pendiente' };
                            $icono = match ($t['estado']) { 'pendiente' => '⏳', 'aprobada' => '✓', 'rechazada' => '✗', default => '⏳' };
                            ?>
                            <span class="badge <?= $clase ?>"><?= $icono . ' ' . ucfirst($t['estado']) ?></span>
                            <?php if ($t['estado'] === 'rechazada' && !empty($t['observaciones'])): ?>
                                <div class="extra-txt" title="<?= htmlspecialchars($t['observaciones']) ?>"><?= htmlspecialchars(mb_substr($t['observaciones'], 0, 30)) ?>…</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($t['estado'] === 'pendiente'): ?>
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="abrirModal('aprobar', '<?= $t['tipo'] ?>', '<?= $t['origen'] ?>', '<?= htmlspecialchars((string)$t['id_solicitud'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($t['nombre_completo']), ENT_QUOTES) ?>', <?= $t['sin_gerente'] ? 'true' : 'false' ?>, '<?= htmlspecialchars(addslashes((string)$t['coop_id_raw']), ENT_QUOTES) ?>')">
                                    <i class="fas fa-check me-1"></i>Aprobar
                                </button>
                                <button type="button" class="btn btn-sm btn-danger"
                                    onclick="abrirModal('rechazar', '<?= $t['tipo'] ?>', '<?= $t['origen'] ?>', '<?= htmlspecialchars((string)$t['id_solicitud'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($t['nombre_completo']), ENT_QUOTES) ?>', false, '')">
                                    <i class="fas fa-times me-1"></i>Rechazar
                                </button>
                            <?php elseif ($t['estado'] === 'aprobada'): ?>
                                <span class="text-success"><i class="fas fa-check-circle me-1"></i><?= !empty($t['fecha_aprobacion']) ? date('d/m/Y', strtotime($t['fecha_aprobacion'])) : '—' ?></span>
                            <?php else: ?>
                                <span class="text-danger"><i class="fas fa-times-circle me-1"></i><?= !empty($t['fecha_aprobacion']) ? date('d/m/Y', strtotime($t['fecha_aprobacion'])) : '—' ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL APROBAR/RECHAZAR -->
<div class="modal-custom" id="modalAccion">
    <div class="modal-content-custom">
        <h5 class="mb-3" id="modalTitulo"><i class="fas fa-check-circle text-success me-2"></i>Confirmar</h5>
        <p style="font-size:13.5px;color:#374151;margin-bottom:14px;">Solicitante: <strong id="modalNombre"></strong></p>

        <form method="POST" id="formAccion">
            <input type="hidden" name="id_solicitud" id="inputId">
            <input type="hidden" name="accion" id="inputAccion">
            <input type="hidden" name="tipo_solicitud" id="inputTipo">
            <input type="hidden" name="origen_solicitud" id="inputOrigen">

            <div id="gerenteAsignarWrap" style="display:none;margin-bottom:14px;">
                <label style="font-size:13px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">
                    <i class="fas fa-user-cog me-1"></i>Esta solicitud no tiene gerente asignado — elige uno (opcional):
                </label>
                <select name="gerente_asignar" id="gerenteAsignarSelect" class="form-select">
                    <option value="">-- Dejar sin asignar --</option>
                </select>
            </div>

            <div id="observacionesWrap" style="display:none;margin-bottom:14px;">
                <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Motivo (opcional):</label>
                <textarea name="observaciones" rows="3" class="form-control" placeholder="Ej. Documento ilegible, información incompleta…"></textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn" id="btnConfirmarModal" style="flex:1;">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModal(accion, tipo, origen, id, nombre, sinGerente, coopId) {
    document.getElementById('inputId').value = id;
    document.getElementById('inputAccion').value = accion;
    document.getElementById('inputTipo').value = tipo;
    document.getElementById('inputOrigen').value = origen;
    document.getElementById('modalNombre').textContent = nombre;

    const titulo = document.getElementById('modalTitulo');
    const btn = document.getElementById('btnConfirmarModal');
    const obsWrap = document.getElementById('observacionesWrap');
    const gerWrap = document.getElementById('gerenteAsignarWrap');
    const gerSelect = document.getElementById('gerenteAsignarSelect');

    if (accion === 'aprobar') {
        titulo.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Aprobar solicitud';
        btn.className = 'btn btn-success';
        btn.textContent = 'Aprobar';
        obsWrap.style.display = 'none';
    } else {
        titulo.innerHTML = '<i class="fas fa-times-circle text-danger me-2"></i>Rechazar solicitud';
        btn.className = 'btn btn-danger';
        btn.textContent = 'Rechazar';
        obsWrap.style.display = 'block';
    }

    if (accion === 'aprobar' && origen === 'legacy' && tipo === 'supervisor' && sinGerente && coopId) {
        gerWrap.style.display = 'block';
        gerSelect.innerHTML = '<option value="">Cargando gerentes…</option>';
        fetch(`api_gerentes_por_coop.php?cooperativa_id=${encodeURIComponent(coopId)}`)
            .then(res => res.json())
            .then(data => {
                gerSelect.innerHTML = '<option value="">-- Dejar sin asignar --</option>';
                (data.gerentes || []).forEach(ger => {
                    const opt = document.createElement('option');
                    opt.value = ger.id_usuario;
                    opt.textContent = ger.nombre + ' (' + ger.email + ')';
                    gerSelect.appendChild(opt);
                });
            })
            .catch(() => { gerSelect.innerHTML = '<option value="">-- Dejar sin asignar --</option>'; });
    } else {
        gerWrap.style.display = 'none';
        gerSelect.value = '';
    }

    document.getElementById('modalAccion').classList.add('show');
}
function cerrarModal() { document.getElementById('modalAccion').classList.remove('show'); }

// Filtros: búsqueda + banco + rol + estado (todo client-side)
document.addEventListener('DOMContentLoaded', function () {
    const inputBusqueda = document.getElementById('busquedaSolicitud');
    const selectBanco   = document.getElementById('filtroBanco');
    const cntResultados = document.getElementById('cntResultados');
    const tipoButtons    = document.querySelectorAll('#tipoFilters .btn-filter');
    const statusButtons  = document.querySelectorAll('#statusFilters .btn-filter');
    let currentTipo = 'todos';
    let currentStatus = 'todos';

    function aplicarFiltros() {
        const term = inputBusqueda ? inputBusqueda.value.toLowerCase().trim() : '';
        const banco = selectBanco ? selectBanco.value : 'todos';
        const filas = document.querySelectorAll('.table tbody tr[data-tipo]');
        let visibles = 0;

        filas.forEach(fila => {
            const texto = fila.dataset.search || '';
            const tipo = fila.dataset.tipo || '';
            const estado = fila.dataset.status || '';
            const filaBanco = fila.dataset.banco || '__sin__';

            const matchBusq = !term || texto.includes(term);
            const matchTipo = currentTipo === 'todos' || tipo === currentTipo;
            const matchStatus = currentStatus === 'todos' || estado === currentStatus;
            const matchBanco = banco === 'todos' || filaBanco === banco;

            const visible = matchBusq && matchTipo && matchStatus && matchBanco;
            fila.style.display = visible ? '' : 'none';
            if (visible) visibles++;
        });

        if (cntResultados) cntResultados.textContent = visibles + (visibles === 1 ? ' resultado' : ' resultados');
    }

    if (inputBusqueda) inputBusqueda.addEventListener('input', aplicarFiltros);
    if (selectBanco) selectBanco.addEventListener('change', aplicarFiltros);

    tipoButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            tipoButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentTipo = this.dataset.tipo || 'todos';
            aplicarFiltros();
        });
    });
    statusButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            statusButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStatus = this.dataset.status || 'todos';
            aplicarFiltros();
        });
    });
});
</script>

</body>
</html>
