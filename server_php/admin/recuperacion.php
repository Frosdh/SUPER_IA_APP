<?php
if (session_status() === PHP_SESSION_NONE)
  session_start();
require_once 'db_admin.php';
$is_admin_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!isset($_SESSION['supervisor_logged_in']) && !$is_admin_gerente) {
  header('Location:login.php?role=supervisor');
  exit;
}
$supervisor_usuario_id = $is_admin_gerente ? null : ($_SESSION['supervisor_id'] ?? null);
$supervisor_nombre     = $is_admin_gerente ? ($_SESSION['admin_nombre'] ?? 'Gerente') : ($_SESSION['supervisor_nombre'] ?? '');
$supervisor_rol        = $is_admin_gerente ? ($_SESSION['admin_rol'] ?? 'Gerente') : ($_SESSION['supervisor_rol'] ?? 'Supervisor');
// Resolver supervisor.id de forma robusta: la sesión puede contener usuario_id o supervisor.id
$supervisor_table_id = null;
try {
  $sess_sup = $_SESSION['supervisor_id'] ?? null;
  if ($sess_sup) {
    // Intentar primero como usuario_id
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id=? LIMIT 1');
    $st->execute([$sess_sup]);
    $supervisor_table_id = $st->fetchColumn() ?: null;
    // Si no lo encontramos, intentar como supervisor.id directamente
    if (!$supervisor_table_id) {
      $st = $pdo->prepare('SELECT id FROM supervisor WHERE id=? LIMIT 1');
      $st->execute([$sess_sup]);
      $supervisor_table_id = $st->fetchColumn() ?: null;
    }
  }
} catch (Throwable $_) {
}

// Alertas badge
$alertas_pendientes = 0;
try {
  if ($supervisor_table_id) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id=? AND vista_supervisor=0');
    $st->execute([$supervisor_table_id]);
    $alertas_pendientes = (int) $st->fetchColumn();
  }
} catch (Throwable $_) {
}

// al momdento de ver los cleintes no me esaparece no eme stasconsumiendo de base

$q = trim($_GET['q'] ?? '');

// Debug global — visitar ?dump=1
if (isset($_GET['dump']) && $_GET['dump'] == '1') {
  echo "<pre style=\"white-space:pre-wrap;word-break:break-word;font-size:12px;\">";
  echo "=== DEBUG RECUPERACION ===\n\n";
  echo "SESSION supervisor_id: " . htmlspecialchars($_SESSION['supervisor_id'] ?? '(vacío)') . "\n";
  echo "supervisor_table_id resuelto: " . htmlspecialchars($supervisor_table_id ?? '(NULL — no encontrado)') . "\n\n";

  // Asesores de este supervisor
  try {
    $asCount = $pdo->prepare('SELECT COUNT(*) FROM asesor WHERE supervisor_id = ?');
    $asCount->execute([$supervisor_table_id]);
    $asList = $pdo->prepare('SELECT id, usuario_id FROM asesor WHERE supervisor_id = ? LIMIT 10');
    $asList->execute([$supervisor_table_id]);
    echo "Asesores de este supervisor: " . $asCount->fetchColumn() . "\n";
    echo htmlspecialchars(var_export($asList->fetchAll(PDO::FETCH_ASSOC), true)) . "\n\n";
  } catch (Throwable $e) { echo "Error asesores: " . htmlspecialchars($e->getMessage()) . "\n\n"; }

  // ficha_producto
  try {
    $fpAll   = $pdo->query("SELECT COUNT(*) FROM ficha_producto")->fetchColumn();
    $fpCred  = $pdo->query("SELECT COUNT(*) FROM ficha_producto WHERE producto_tipo='credito'")->fetchColumn();
    $fpNoRec = $pdo->query("SELECT COUNT(*) FROM ficha_producto WHERE producto_tipo='credito' AND COALESCE(estado_revision,'pendiente')!='rechazada'")->fetchColumn();

    $fpByAsesorId = $pdo->prepare("SELECT COUNT(*) FROM ficha_producto WHERE producto_tipo='credito' AND asesor_id IN (SELECT id FROM asesor WHERE supervisor_id=?)");
    $fpByAsesorId->execute([$supervisor_table_id]);

    $fpByUserId = $pdo->prepare("SELECT COUNT(*) FROM ficha_producto WHERE producto_tipo='credito' AND usuario_id IN (SELECT usuario_id FROM asesor WHERE supervisor_id=?)");
    $fpByUserId->execute([$supervisor_table_id]);

    echo "ficha_producto — total: $fpAll | tipo=credito: $fpCred | no rechazada: $fpNoRec\n";
    echo "  via asesor_id: " . $fpByAsesorId->fetchColumn() . " | via usuario_id: " . $fpByUserId->fetchColumn() . "\n\n";

    // Mostrar asesores del supervisor vs fichas existentes
    $asRows = $pdo->prepare("SELECT a.id AS asesor_id, a.usuario_id, u.nombre FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE a.supervisor_id=?");
    $asRows->execute([$supervisor_table_id]);
    $asData = $asRows->fetchAll(PDO::FETCH_ASSOC);
    echo "Asesores del supervisor:\n" . htmlspecialchars(var_export($asData, true)) . "\n\n";

    $fpSample = $pdo->query("SELECT id, usuario_id, asesor_id, producto_tipo, estado_revision, cliente_cedula, created_at FROM ficha_producto WHERE producto_tipo='credito' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "Últimas 5 fichas crédito (usuario_id y asesor_id):\n" . htmlspecialchars(var_export($fpSample, true)) . "\n\n";
  } catch (Throwable $e) { echo "Error ficha_producto: " . htmlspecialchars($e->getMessage()) . "\n\n"; }

  // credito_proceso
  try {
    $cpAll = $pdo->query("SELECT COUNT(*) FROM credito_proceso")->fetchColumn();
    $cpApro = $pdo->query("SELECT COUNT(*) FROM credito_proceso WHERE LOWER(COALESCE(estado_credito,'')) IN ('aprobado','desembolsado')")->fetchColumn();
    echo "credito_proceso — total: $cpAll | aprobado/desembolsado: $cpApro\n";
    $cpSample = $pdo->query("SELECT id, asesor_id, estado_credito, cedula_deudor, monto_aprobado, created_at FROM credito_proceso ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "Últimas 5:\n" . htmlspecialchars(var_export($cpSample, true)) . "\n";
  } catch (Throwable $e) { echo "Error credito_proceso: " . htmlspecialchars($e->getMessage()) . "\n\n"; }

  echo "</pre>";
  exit;
}

// Asesores del supervisor — necesarios para modal de asignación
$asesores_lista = [];
try {
  if ($supervisor_table_id) {
    $st = $pdo->prepare('SELECT a.id, u.nombre FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE a.supervisor_id=? ORDER BY u.nombre');
    $st->execute([$supervisor_table_id]);
    $asesores_lista = $st->fetchAll();
  }
} catch (Throwable $_) {}

// ══════════════════════════════════════════════════════════════
// CRÉDITOS APROBADOS — mismo patrón que operaciones.php
// Usa COLLATE utf8mb4_unicode_ci para resolver mismatch de colaciones
// entre ficha_producto y asesor.
// ══════════════════════════════════════════════════════════════
$creditos = [];

// Paso 1: obtener todos los IDs de asesores de este supervisor
$all_ids = [];
if ($supervisor_table_id) {
  try {
    $sess_uid = $_SESSION['supervisor_id'] ?? '';
    $stA = $pdo->prepare("SELECT id, usuario_id FROM asesor WHERE supervisor_id IN (?, ?)");
    $stA->execute([$supervisor_table_id, $sess_uid]);
    $asesores_rows = $stA->fetchAll(PDO::FETCH_ASSOC);
    $asesor_ids  = array_column($asesores_rows, 'id');
    $usuario_ids = array_column($asesores_rows, 'usuario_id');
    $all_ids = array_values(array_unique(array_filter(array_merge($asesor_ids, $usuario_ids))));
  } catch (Throwable $_) {}
}

// FUENTE 1: ficha_producto + ficha_credito (app móvil)
// Solo fichas APROBADAS (estado_revision = 'aprobada')
if (!empty($all_ids)) {
  try {
    $ph = implode(',', array_fill(0, count($all_ids), '?'));
    $f1sql = "
      SELECT
        fp.id,
        fp.cliente_cedula                                             AS cedula,
        COALESCE(cp.nombre, fp.cliente_nombre, '')                    AS cliente_nombre,
        COALESCE(cp.telefono, '')                                     AS telefono,
        COALESCE(cp.email, '')                                        AS email,
        a.id                                                          AS asesor_id,
        u.nombre                                                      AS asesor_nombre,
        COALESCE(fc.monto_credito, '')                                AS monto_aprobado,
        fp.created_at                                                 AS fecha_desembolso,
        fp.created_at,
        fp.estado_revision,
        'ficha'                                                       AS fuente
      FROM ficha_producto fp
      LEFT JOIN ficha_credito     fc ON fc.ficha_id = fp.id
      LEFT JOIN asesor             a  ON (
            a.id         = fp.asesor_id  COLLATE utf8mb4_unicode_ci
         OR a.usuario_id = fp.usuario_id COLLATE utf8mb4_unicode_ci
         OR a.id         = fp.usuario_id COLLATE utf8mb4_unicode_ci
      )
      LEFT JOIN usuario            u  ON u.id = a.usuario_id
      LEFT JOIN cliente_prospecto  cp ON cp.cedula = fp.cliente_cedula COLLATE utf8mb4_unicode_ci
      WHERE fp.producto_tipo = 'credito'
        AND fp.estado_revision = 'aprobada'
        AND (
          fp.asesor_id  COLLATE utf8mb4_unicode_ci IN ($ph)
          OR fp.usuario_id COLLATE utf8mb4_unicode_ci IN ($ph)
        )
      ORDER BY fp.created_at DESC
      LIMIT 500
    ";
    $st1 = $pdo->prepare($f1sql);
    $st1->execute(array_merge($all_ids, $all_ids));
    $creditos = $st1->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $eF1) {
    error_log('[recuperacion F1] ' . $eF1->getMessage());
  }
}

// FUENTE 2: credito_proceso (aprobado/desembolsado)
if (!empty($all_ids)) {
  try {
    $cpEstadoCol = null;
    if ($pdo->query("SHOW COLUMNS FROM credito_proceso LIKE 'estado_credito'")->fetchColumn())
      $cpEstadoCol = 'estado_credito';
    elseif ($pdo->query("SHOW COLUMNS FROM credito_proceso LIKE 'estado'")->fetchColumn())
      $cpEstadoCol = 'estado';

    $estadoCond = $cpEstadoCol
      ? "LOWER(COALESCE(cp2.$cpEstadoCol,'')) IN ('aprobado','desembolsado')"
      : '1=1';

    $ph2 = implode(',', array_fill(0, count($all_ids), '?'));
    $f2sql = "
      SELECT
        cp2.id,
        COALESCE(cl2.cedula, cp2.cedula_deudor, '')   AS cedula,
        COALESCE(cl2.nombre, '')                       AS cliente_nombre,
        COALESCE(cl2.telefono, '')                     AS telefono,
        COALESCE(cl2.email, '')                        AS email,
        cp2.asesor_id                                  AS asesor_id,
        u2.nombre                                      AS asesor_nombre,
        cp2.monto_aprobado,
        cp2.fecha_desembolso,
        cp2.created_at,
        'aprobada'                                     AS estado_revision,
        'proceso'                                      AS fuente
      FROM credito_proceso cp2
      LEFT JOIN cliente_prospecto cl2 ON cl2.id  = cp2.cliente_prospecto_id
      LEFT JOIN asesor             a2  ON a2.id   = cp2.asesor_id
      LEFT JOIN usuario            u2  ON u2.id   = a2.usuario_id
      WHERE $estadoCond
        AND cp2.asesor_id COLLATE utf8mb4_unicode_ci IN ($ph2)
      ORDER BY cp2.created_at DESC
      LIMIT 500
    ";
    $st2 = $pdo->prepare($f2sql);
    $st2->execute($all_ids);
    $creditos2 = $st2->fetchAll(PDO::FETCH_ASSOC);

    // Deduplicar por cédula
    $cedulasVistas = array_column($creditos, 'cedula');
    foreach ($creditos2 as $row) {
      if ($row['cedula'] !== '' && !in_array($row['cedula'], $cedulasVistas, true)) {
        $creditos[]      = $row;
        $cedulasVistas[] = $row['cedula'];
      }
    }
  } catch (Throwable $eF2) {
    error_log('[recuperacion F2] ' . $eF2->getMessage());
  }
}

// Filtro GET por nombre/cédula
if ($q !== '') {
  $qLow = strtolower($q);
  $creditos = array_values(array_filter($creditos, function($r) use ($qLow) {
    return str_contains(strtolower($r['cliente_nombre'] ?? ''), $qLow)
        || str_contains($r['cedula'] ?? '', $qLow);
  }));
}

// Ordenar: más recientes primero
usort($creditos, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

$currentPage = 'recuperacion';
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Recuperación — Supervisor</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --brand-yellow: #ffdd00;
      --brand-yellow-deep: #f4c400;
      --brand-navy: #123a6d;
      --brand-navy-deep: #0a2748;
      --brand-gray: #6b7280;
      --brand-border: #d7e0ea;
      --brand-bg: #f4f6f9;
      --brand-shadow: 0 16px 34px rgba(18, 58, 109, .08);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      background: var(--brand-bg);
      display: flex;
      min-height: 100vh;
      color: var(--brand-navy-deep);
    }

    .sidebar {
      width: 230px;
      background: linear-gradient(180deg, var(--brand-navy-deep), var(--brand-navy));
      color: #fff;
      padding: 20px 0;
      overflow-y: auto;
      position: sticky;
      height: 100vh;
      top: 0;
      flex-shrink: 0;
      z-index: 100;
    }

    .sidebar-brand {
      padding: 0 20px 24px;
      font-size: 18px;
      font-weight: 800;
      border-bottom: 1px solid rgba(255, 221, 0, .18);
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sidebar-brand i {
      color: var(--brand-yellow);
    }

    .sidebar-section {
      padding: 0 15px;
      margin-bottom: 22px;
    }

    .sidebar-section-title {
      font-size: 11px;
      text-transform: uppercase;
      color: rgba(255, 255, 255, .5);
      letter-spacing: .6px;
      padding: 0 10px;
      margin-bottom: 10px;
      font-weight: 700;
    }

    .sidebar-link {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 11px 15px;
      margin-bottom: 4px;
      border-radius: 10px;
      color: rgba(255, 255, 255, .82);
      text-decoration: none;
      font-size: 14px;
      border: 1px solid transparent;
      transition: .22s;
    }

    .sidebar-link:hover {
      background: rgba(255, 221, 0, .12);
      color: #fff;
      padding-left: 20px;
    }

    .sidebar-link.active {
      background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep));
      color: var(--brand-navy-deep);
      font-weight: 700;
    }

    .badge-nav {
      background: #dc2626;
      color: #fff;
      font-size: 10px;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 10px;
      margin-left: auto;
    }

    .main-content {
      flex: 1;
      margin-left: 0 !important;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .navbar-custom {
      background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy));
      color: #fff;
      padding: 15px 30px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 12px 28px rgba(18, 58, 109, .18);
    }

    .navbar-custom h2 {
      margin: 0;
      font-size: 20px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .navbar-custom h2 i {
      color: var(--brand-yellow);
    }

    .content-area {
      flex: 1;
      padding: 28px 30px 40px;
    }

    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 22px;
      flex-wrap: wrap;
      gap: 12px;
    }

    .page-header h1 {
      font-size: 22px;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 0;
    }

    .page-header h1 i {
      color: #dc2626;
    }

    .filter-bar {
      background: #fff;
      border: 1px solid var(--brand-border);
      border-radius: 14px;
      padding: 14px 18px;
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 20px;
      box-shadow: var(--brand-shadow);
    }

    .filter-bar input,
    .filter-bar select {
      padding: 8px 12px;
      border: 1.5px solid var(--brand-border);
      border-radius: 9px;
      font-size: 13.5px;
      background: #fff;
      outline: none;
    }

    .filter-bar input:focus,
    .filter-bar select:focus {
      border-color: var(--brand-navy);
    }

    .btn-search {
      background: var(--brand-yellow);
      color: var(--brand-navy-deep);
      font-weight: 800;
      padding: 9px 16px;
      border: none;
      border-radius: 9px;
      cursor: pointer;
      font-size: 13.5px;
    }

    .btn-search:hover {
      background: var(--brand-yellow-deep);
    }

    .section-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: var(--brand-shadow);
      border: 1px solid var(--brand-border);
      overflow: hidden;
      margin-bottom: 22px;
    }

    .section-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--brand-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #fafbfc;
    }

    .section-header h5 {
      font-size: 15px;
      font-weight: 800;
      margin: 0;
      color: var(--brand-navy-deep);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sec-badge {
      font-size: 11px;
      background: var(--brand-navy);
      color: #fff;
      padding: 3px 9px;
      border-radius: 10px;
      font-weight: 700;
    }

    .table th {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      color: #64748b;
      letter-spacing: .6px;
      border-bottom: 1px solid #edf2f7;
      background-color: #fafbfc;
      padding: 16px 12px;
    }

    .table td {
      vertical-align: middle;
      font-size: 13.5px;
      border-bottom: 1px solid #edf2f7;
      padding: 16px 12px;
    }

    .rec-row {
      transition: background-color 0.2s ease, transform 0.2s ease;
    }

    .rec-row:hover {
      background-color: #f8fafc !important;
    }

    .mora-badge {
      display: inline-block;
      padding: 5px 12px;
      border-radius: 6px;
      font-size: 11.5px;
      font-weight: 800;
    }

    .mora-ok {
      background: #e2fbe8;
      color: #107c41;
      border: 1px solid #c3f2cc;
    }

    .mora-mid {
      background: #fff8e1;
      color: #b25e00;
      border: 1px solid #ffe0b2;
    }

    .mora-high {
      background: #fdebee;
      color: #c51162;
      border: 1px solid #ffcdd2;
    }

    .btn-crear {
      background: #ffdd00;
      color: #0a2748;
      border: none;
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      box-shadow: 0 2px 4px rgba(255, 221, 0, 0.15);
      transition: all 0.2s ease;
    }

    .btn-crear:hover {
      background: #f4c400;
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(255, 196, 0, 0.25);
    }

    .btn-bulk {
      background: linear-gradient(135deg, #0a2748, #1e4d8c);
      color: #ffdd00;
      border: none;
      padding: 9px 20px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 13.5px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }

    .btn-bulk:hover {
      opacity: .95;
      transform: translateY(-1px);
    }

    .empty-msg {
      padding: 40px 20px;
      text-align: center;
      color: #94a3b8;
    }

    .empty-msg i {
      font-size: 36px;
      display: block;
      margin-bottom: 12px;
      opacity: .5;
    }

    /* Input meses en mora exacto al diseño */
    .mora-input-row {
      width: 110px;
      height: 38px;
      border-radius: 10px;
      border: 1.5px solid #e2e8f0;
      background-color: #f8fafc;
      font-weight: 700;
      color: #1e293b;
      font-size: 14px;
      text-align: center;
      transition: all 0.2s;
      outline: none;
      display: block;
      margin: 0 auto;
    }

    .mora-input-row:focus {
      border-color: #3b82f6 !important;
      background-color: #fff !important;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12) !important;
    }

    /* Ocultar flechas del input number */
    .mora-input-row::-webkit-outer-spin-button,
    .mora-input-row::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    .mora-input-row {
      -moz-appearance: textfield;
    }

    /* ══════════ PAGE HEADER (estilo "Mis Asesores") ══════════ */
    .ma-page-header {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 22px;
      padding-bottom: 18px;
      border-bottom: 2px solid #e8eef6;
      flex-wrap: wrap;
    }
    .ma-page-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      background: linear-gradient(135deg, #0a2748, #1e4d8c);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 14px rgba(10, 39, 72, .22);
      flex-shrink: 0;
    }
    .ma-page-icon i { color: #ffdd00; font-size: 22px; }
    .ma-page-title { font-size: 22px; font-weight: 900; color: #0a2748; margin: 0; }
    .ma-page-sub { font-size: 13px; color: #94a3b8; margin: 2px 0 0; font-weight: 500; }

    .btn-navy {
      background: #0a2748;
      color: #fff;
      border: 2px solid #0a2748;
      border-radius: 10px;
      padding: 8px 16px;
      font-size: 13.5px;
      font-weight: 700;
      transition: all 0.2s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
    }
    .btn-navy:hover {
      background: #1e4d8c;
      border-color: #1e4d8c;
      color: #fff;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(10, 39, 72, .15);
    }
    .btn-outline-navy {
      background: transparent;
      color: #0a2748;
      border: 2px solid #0a2748;
      border-radius: 10px;
      padding: 8px 16px;
      font-size: 13.5px;
      font-weight: 700;
      transition: all 0.2s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
    }
    .btn-outline-navy:hover {
      background: rgba(10, 39, 72, .05);
      color: #0a2748;
      transform: translateY(-1px);
    }

    /* ══════════ CRÉDITOS — GRID DE TARJETAS ══════════ */
    .creditos-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 18px;
      padding: 20px;
    }
    .cred-card {
      background: #fff;
      border-radius: 16px;
      border: 2px solid #e2eaf4;
      box-shadow: 0 3px 12px rgba(10, 39, 72, .07);
      overflow: hidden;
      transition: all .2s;
      display: flex;
      flex-direction: column;
    }
    .cred-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 26px rgba(10, 39, 72, .13);
      border-color: #93c5fd;
    }
    .cred-stripe { height: 5px; background: linear-gradient(90deg, #0a2748, #1e4d8c, #ffdd00); }
    .cred-body { padding: 16px 16px 14px; flex: 1; display: flex; flex-direction: column; gap: 10px; }
    .cred-top { display: flex; align-items: flex-start; gap: 12px; }
    .cred-avatar {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      background: linear-gradient(135deg, #0a2748, #1e4d8c);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      font-weight: 900;
      color: #ffdd00;
      flex-shrink: 0;
      box-shadow: 0 3px 10px rgba(10, 39, 72, .2);
    }
    .cred-name { font-size: 14.5px; font-weight: 800; color: #0a2748; margin: 0 0 2px; line-height: 1.25; }
    .cred-sub { font-size: 11.5px; color: #94a3b8; margin: 0; font-weight: 600; }
    .cred-chk { margin-left: auto; width: 18px; height: 18px; flex-shrink: 0; margin-top: 4px; }
    .cred-info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      padding-top: 10px;
      border-top: 1px solid #f0f4f8;
    }
    .cred-info-label { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #94a3b8; font-weight: 800; margin: 0 0 2px; }
    .cred-info-val { font-size: 13px; font-weight: 700; color: #1e293b; margin: 0; }
    .cred-mora-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding-top: 10px;
      border-top: 1px solid #f0f4f8;
    }
    .cred-footer { padding: 12px 16px; background: #f8fafc; border-top: 1px solid #edf2f9; }
    .cred-footer .btn-crear { width: 100%; justify-content: center; }

    /* ══════════ ESTADO / REVISIÓN — BADGES ══════════ */
    .estado-badge {
      display: inline-block;
      padding: 4px 11px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 800;
      white-space: nowrap;
    }
    .estado-programada { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .estado-en_proceso { background: #fff8e1; color: #b25e00; border: 1px solid #ffe0b2; }
    .estado-postergada { background: #f3e8ff; color: #7c3aed; border: 1px solid #e9d5ff; }
    .estado-completada { background: #e2fbe8; color: #107c41; border: 1px solid #c3f2cc; }
    .estado-cancelada { background: #fdebee; color: #c51162; border: 1px solid #ffcdd2; }
    .revision-pendiente { background: #fff8e1; color: #b25e00; border: 1px solid #ffe0b2; }
    .revision-aprobada { background: #e2fbe8; color: #107c41; border: 1px solid #c3f2cc; }
    .revision-rechazada { background: #fdebee; color: #c51162; border: 1px solid #ffcdd2; }
    .revision-na { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
  </style>
</head>

<body>
  <?php
  $navTitle = ''; $navIcon = ''; $navSubtitle = '';
  if ($is_admin_gerente) {
      $currentPage = 'recuperacion';
      require_once '_sidebar_gerente.php';
  } else {
      require_once '_sidebar_supervisor.php';
  }
  ?>

      <!-- HEADER -->
      <div class="ma-page-header">
        <div class="ma-page-icon"><i class="fas fa-user-clock"></i></div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3" style="flex:1;">
          <div>
            <h1 class="ma-page-title">Recuperación de Cartera</h1>
            <p class="ma-page-sub">Revisa, crea y da seguimiento a las recuperaciones de tu equipo</p>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="#cardCreditos" class="btn-navy">
              <i class="fas fa-plus"></i> Nueva Recuperación
            </a>
          </div>
        </div>
      </div>

      <!-- RECUPERACIONES PENDIENTES DE REVISIÓN -->
      <div class="section-card" id="cardRevision">
        <div class="section-header">
          <h5><i class="fa-solid fa-clipboard-check" style="color:#f59e0b;"></i> Recuperaciones por revisar</h5>
          <span class="sec-badge" id="badgeRevisionCount" style="background:#f59e0b;">0</span>
        </div>
        <div id="revisionEmpty" class="empty-msg">
          <i class="fas fa-check-circle" style="color:#10b981;"></i>
          <p>No hay recuperaciones pendientes de revisión.</p>
        </div>
        <div class="table-responsive" id="revisionTableWrap" style="display:none;">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3 py-3">CLIENTE</th>
                <th class="py-3">ASESOR</th>
                <th class="py-3">OBSERVACIONES DEL ASESOR</th>
                <th class="py-3">FINALIZADA</th>
                <th class="text-end pe-3 py-3">REVISIÓN</th>
              </tr>
            </thead>
            <tbody id="revisionTbody"></tbody>
          </table>
        </div>
      </div>

      <!-- LISTA DE RECUPERACIONES (todas las tareas, cualquier estado) -->
      <div class="section-card" id="cardListaRecuperaciones">
        <div class="section-header">
          <h5><i class="fa-solid fa-list-check" style="color:#0a2748;"></i> Lista de Recuperaciones</h5>
          <span class="sec-badge" id="badgeListaCount">0</span>
        </div>
        <div style="padding:14px 20px;border-bottom:1px solid var(--brand-border);display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#fafbfc;">
          <select id="listaEstadoFiltro" class="form-select form-select-sm" style="width:auto;">
            <option value="">Todos los estados</option>
            <option value="programada">Programada</option>
            <option value="en_proceso">En proceso</option>
            <option value="postergada">Postergada</option>
            <option value="completada">Completada</option>
            <option value="cancelada">Cancelada</option>
          </select>
          <?php if (!empty($asesores_lista)): ?>
          <select id="listaAsesorFiltro" class="form-select form-select-sm" style="width:auto;">
            <option value="">Todos los asesores</option>
            <?php foreach ($asesores_lista as $as): ?>
              <option value="<?= htmlspecialchars($as['id']) ?>"><?= htmlspecialchars($as['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <input type="text" id="listaBuscar" class="form-control form-control-sm" style="width:220px;" placeholder="Buscar cliente o cédula…">
          <button class="btn btn-sm btn-outline-secondary" id="listaRefrescar" type="button"><i class="fas fa-rotate"></i> Actualizar</button>
          <div class="ms-auto d-flex gap-2 flex-wrap" id="listaResumen" style="font-size:11.5px;"></div>
        </div>
        <div id="listaEmpty" class="empty-msg">
          <i class="fas fa-inbox" style="color:#94a3b8;"></i>
          <p>No hay recuperaciones para mostrar.</p>
        </div>
        <div class="table-responsive" id="listaTableWrap" style="display:none;">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3 py-3">CLIENTE</th>
                <th class="py-3">ASESOR</th>
                <th class="py-3 text-center">ESTADO</th>
                <th class="py-3">PROGRAMADA / REALIZADA</th>
                <th class="py-3 text-center">REVISIÓN</th>
                <th class="pe-3 py-3">OBSERVACIONES</th>
              </tr>
            </thead>
            <tbody id="listaTbody"></tbody>
          </table>
        </div>
      </div>

      <!-- FILTROS — búsqueda + A-Z -->
      <style>
      .filter-bar {
        display: flex;
        flex-direction: column;
        gap: 0;
        background: linear-gradient(135deg, #f8fafd 0%, #f0f5fb 100%);
        border-radius: 12px;
        border: 1px solid #e2eaf4;
        box-shadow: 0 2px 8px rgba(0,0,0,.04);
        overflow: hidden;
        margin-bottom: 20px;
      }
      .filter-row-1 {
        display: grid;
        grid-template-columns: 1fr 1fr auto;
        align-items: center;
        gap: 14px;
        padding: 16px 20px;
        border-bottom: 1px solid #edf2f9;
      }
      .filter-row-2 {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding: 12px 20px 14px;
        background: rgba(248, 250, 253, 0.6);
      }
      .filter-row-3 {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        padding: 10px 20px;
        background: rgba(248, 250, 253, 0.7);
        font-size: 12px;
      }
      .fi-group {
        display: flex;
        align-items: center;
        border: 1.5px solid #dde5f0;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
        overflow: hidden;
        transition: border-color .18s, box-shadow .18s;
      }
      .fi-group:focus-within {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1), 0 1px 3px rgba(0,0,0,.04);
      }
      .fi-ico {
        flex-shrink: 0;
        width: 40px;
        text-align: center;
        color: #b0bec5;
        font-size: 13px;
        pointer-events: none;
      }
      .fi-input {
        flex: 1;
        border: none;
        outline: none;
        padding: 11px 12px;
        font-size: 13px;
        font-weight: 600;
        color: #1a2744;
        background: transparent;
        min-width: 0;
      }
      .fi-input::placeholder {
        color: #b0bec5;
        font-weight: 500;
      }
      .fi-clear-btn {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 11px 18px;
        border-radius: 10px;
        border: 1.5px solid #dde5f0;
        background: #fff;
        color: #94a3b8;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: .18s;
        white-space: nowrap;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
      }
      .fi-clear-btn:hover {
        border-color: #ef4444;
        color: #ef4444;
        background: #fff5f5;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.1);
      }
      .az-all-btn {
        height: 30px;
        padding: 0 12px;
        border-radius: 8px;
        border: 1.5px solid #dde5f0;
        background: #fff;
        color: #475569;
        font-size: 11px;
        font-weight: 800;
        cursor: pointer;
        transition: .15s;
        box-shadow: 0 1px 2px rgba(0,0,0,.04);
      }
      .az-all-btn.active {
        background: linear-gradient(135deg, #0a2748 0%, #1e4d8c 100%);
        border-color: #0a2748;
        color: #ffdd00;
        box-shadow: 0 3px 10px rgba(10, 39, 72, 0.25);
      }
      .az-btn {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        border: 1.5px solid #e8eef6;
        background: #fff;
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
        cursor: pointer;
        transition: .15s;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 1px 2px rgba(0,0,0,.03);
      }
      .az-btn:hover {
        background: #eff6ff;
        border-color: #93c5fd;
        color: #1d4ed8;
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(59, 130, 246, 0.15);
      }
      .az-btn.active {
        background: linear-gradient(135deg, #ffdd00 0%, #f4c400 100%);
        border-color: #e6b800;
        color: #0a2748;
        box-shadow: 0 3px 10px rgba(255, 221, 0, 0.35);
        transform: translateY(-1px);
      }
      .fi-label {
        font-size: 10.5px;
        font-weight: 800;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        white-space: nowrap;
        margin-right: 8px;
      }
      .fi-count {
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 600;
        color: #94a3b8;
        margin-left: auto;
      }
      .fi-count-num {
        font-size: 14px;
        font-weight: 900;
        color: #0a2748;
      }
      </style>
      <div class="filter-bar">
        <!-- FILA 1: búsqueda -->
        <div class="filter-row-1">
          <div class="fi-group">
            <i class="fas fa-search fi-ico"></i>
            <input type="text" id="fiNombre" class="fi-input" placeholder="Buscar por nombre…">
          </div>
          <div class="fi-group">
            <i class="fas fa-id-card fi-ico"></i>
            <input type="text" id="fiCedula" class="fi-input" placeholder="Buscar por cédula…">
          </div>
          <button class="fi-clear-btn" id="fiClear">
            <i class="fas fa-rotate-left" style="font-size: 11px;"></i> Limpiar
          </button>
        </div>
        <!-- FILA 2: A-Z -->
        <div class="filter-row-2">
          <span class="fi-label"><i class="fas fa-sort-alpha-down"></i> Alfabético</span>
          <button class="az-all-btn active" id="azAllBtn">TODOS</button>
          <button class="az-btn" data-letter="A">A</button>
          <button class="az-btn" data-letter="B">B</button>
          <button class="az-btn" data-letter="C">C</button>
          <button class="az-btn" data-letter="D">D</button>
          <button class="az-btn" data-letter="E">E</button>
          <button class="az-btn" data-letter="F">F</button>
          <button class="az-btn" data-letter="G">G</button>
          <button class="az-btn" data-letter="H">H</button>
          <button class="az-btn" data-letter="I">I</button>
          <button class="az-btn" data-letter="J">J</button>
          <button class="az-btn" data-letter="K">K</button>
          <button class="az-btn" data-letter="L">L</button>
          <button class="az-btn" data-letter="M">M</button>
          <button class="az-btn" data-letter="N">N</button>
          <button class="az-btn" data-letter="O">O</button>
          <button class="az-btn" data-letter="P">P</button>
          <button class="az-btn" data-letter="Q">Q</button>
          <button class="az-btn" data-letter="R">R</button>
          <button class="az-btn" data-letter="S">S</button>
          <button class="az-btn" data-letter="T">T</button>
          <button class="az-btn" data-letter="U">U</button>
          <button class="az-btn" data-letter="V">V</button>
          <button class="az-btn" data-letter="W">W</button>
          <button class="az-btn" data-letter="X">X</button>
          <button class="az-btn" data-letter="Y">Y</button>
          <button class="az-btn" data-letter="Z">Z</button>
        </div>
        <!-- FILA 3: contador -->
        <div class="filter-row-3">
          <div class="fi-count">
            Mostrando <span class="fi-count-num" id="cntMostrados">0</span> de <?= count($creditos) ?>
          </div>
        </div>
      </div>

      <!-- CRÉDITOS — GRID DE TARJETAS -->
      <div class="section-card" id="cardCreditos">
        <div class="section-header" style="background: #fff; border-bottom: 1px solid #edf2f7; padding: 18px 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
          <h5 style="font-size: 16px; font-weight: 800; margin: 0; color: #0a2748; display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-list-ul" style="color: #dc2626;"></i> Créditos Aprobados / Desembolsados
          </h5>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <?php if (!empty($creditos)): ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#64748b;cursor:pointer;margin:0;">
              <input type="checkbox" id="chkAll" title="Seleccionar todos"> Seleccionar todos
            </label>
            <?php endif; ?>
            <span class="sec-badge" style="background-color: #0a2748; color: #fff; border-radius: 20px; font-weight: 800; padding: 6px 14px; font-size: 12px;"><?= count($creditos) ?> créditos</span>
          </div>
        </div>
        <?php if (empty($creditos)): ?>
          <div class="empty-msg"><i class="fas fa-check-circle" style="color:#10b981;"></i>
            <p>No se encontraron créditos aprobados<?= $q ? ' con esos filtros' : '' ?>.</p>
          </div>
        <?php else: ?>
          <div class="creditos-grid" id="creditosGrid">
            <?php foreach ($creditos as $cr):
              $fechaRaw = !empty($cr['fecha_desembolso']) ? $cr['fecha_desembolso'] : $cr['created_at'];
              try { $dt0 = new DateTime($fechaRaw); } catch(Throwable $_) { $dt0 = new DateTime(); }
              $dt1 = new DateTime();
              $meses = max(0, (int) (($dt1->format('Y') - $dt0->format('Y')) * 12 + ($dt1->format('n') - $dt0->format('n'))));
              $nombreDisplay = htmlspecialchars(trim($cr['cliente_nombre']??'') ?: ($cr['cedula']??'—'));
              $nombreBase = trim($cr['cliente_nombre'] ?? '') ?: ($cr['cedula'] ?? '?');
              $inicial = strtoupper(mb_substr($nombreBase, 0, 1));
              $badgeClass = $meses <= 3 ? 'mora-ok' : ($meses <= 6 ? 'mora-mid' : 'mora-high');
              ?>
              <div class="cred-card" data-nombre="<?= strtolower(htmlspecialchars($cr['cliente_nombre']??'')) ?>" data-cedula="<?= strtolower(htmlspecialchars($cr['cedula']??'')) ?>" data-asesor="<?= strtolower(htmlspecialchars($cr['asesor_nombre']??'')) ?>" data-meses="<?= $meses ?>">
                <div class="cred-stripe"></div>
                <div class="cred-body">
                  <div class="cred-top">
                    <div class="cred-avatar"><?= htmlspecialchars($inicial) ?></div>
                    <div style="flex:1;min-width:0;">
                      <h3 class="cred-name"><?= htmlspecialchars(strtolower($cr['cliente_nombre'] ?? '—')) ?></h3>
                      <p class="cred-sub"><?= htmlspecialchars($cr['cedula'] ?? '—') ?> &middot; <?= htmlspecialchars($cr['telefono'] ?? '—') ?></p>
                    </div>
                    <input type="checkbox" class="chk-rec cred-chk" data-credito-id="<?= htmlspecialchars($cr['id']) ?>"
                        data-asesor-id="<?= htmlspecialchars($cr['asesor_id'] ?? '') ?>"
                        data-fuente="<?= htmlspecialchars($cr['fuente'] ?? 'proceso') ?>" title="Seleccionar">
                  </div>
                  <div class="cred-info-grid">
                    <div>
                      <p class="cred-info-label">Asesor</p>
                      <p class="cred-info-val"><?= htmlspecialchars($cr['asesor_nombre'] ?? '—') ?></p>
                    </div>
                    <div>
                      <p class="cred-info-label">Monto</p>
                      <p class="cred-info-val"><?= is_numeric($cr['monto_aprobado']??'') ? '$'.number_format((float)$cr['monto_aprobado'],2) : (htmlspecialchars($cr['monto_aprobado']??'') ?: '—') ?></p>
                    </div>
                    <div>
                      <p class="cred-info-label">Desembolso</p>
                      <p class="cred-info-val"><?= !empty($fechaRaw) ? date('d/m/Y', strtotime($fechaRaw)) : '—' ?></p>
                    </div>
                    <div>
                      <p class="cred-info-label">Desde desemb.</p>
                      <p class="cred-info-val"><span class="mora-badge <?= $badgeClass ?>"><?= $meses ?> mes<?= $meses != 1 ? 'es' : '' ?></span></p>
                    </div>
                  </div>
                  <div class="cred-mora-row">
                    <span class="cred-info-label" style="margin:0;">Meses en mora</span>
                    <input type="number" class="mora-val mora-input-row text-center" style="width:80px;height:36px;"
                           data-credito-id="<?= htmlspecialchars($cr['id']) ?>"
                           value="<?= $meses ?>"
                           min="0">
                  </div>
                </div>
                <div class="cred-footer">
                  <button class="btn btn-crear btn-abrir-modal"
                          data-credito-id="<?= htmlspecialchars($cr['id']) ?>"
                          data-asesor-id="<?= htmlspecialchars($cr['asesor_id'] ?? '') ?>"
                          data-fuente="<?= htmlspecialchars($cr['fuente'] ?? 'proceso') ?>"
                          data-cliente="<?= $nombreDisplay ?>"
                          data-meses="<?= $meses ?>">
                    <i class="fa-solid fa-plus" style="font-size: 11px;"></i> Crear tarea
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Mensaje vacío al filtrar (JS lo muestra/oculta) -->
          <div id="emptyFiltered" class="empty-msg" style="display:none;">
            <i class="fas fa-search" style="color:#94a3b8;"></i>
            <p class="fw-bold text-muted mb-1">No hay créditos que coincidan con la búsqueda</p>
            <p class="small text-muted mb-0">Intenta ajustando los filtros.</p>
          </div>

          <!-- Barra de acción bulk -->
          <div id="bulkBar"
            style="display:none;padding:14px 20px;border-top:1px solid var(--brand-border);background:#fafbfc;align-items:center;gap:14px;flex-wrap:wrap;">
            <span id="bulkCount" style="font-weight:700;font-size:13.5px;">0 seleccionados</span>
            <select id="bulkAsesorSel" class="form-select form-select-sm" style="width:240px;">
              <option value="">Enviar a: todos los asesores del equipo</option>
              <?php foreach ($asesores_lista as $as): ?>
                <option value="<?= htmlspecialchars($as['id']) ?>"><?= htmlspecialchars($as['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="date" id="bulkFecha" class="form-control form-control-sm" style="width:160px;"
              value="<?= date('Y-m-d') ?>">
            <button class="btn-bulk" id="bulkConfirmar"><i class="fas fa-bolt"></i> Crear tareas</button>
          </div>
        <?php endif; ?>
      </div><!-- /section-card -->

      <!-- INFO -->
      <div
        style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 18px;font-size:13px;color:#1e40af;display:flex;gap:10px;align-items:flex-start;">
        <i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0;"></i>
        <div>
          <strong>¿Cómo funciona?</strong><br>
          Selecciona uno o varios créditos, ajusta los meses en mora si es necesario y crea las tareas de recuperación.
          Si eliges <em>"todos los asesores del equipo"</em>, la tarea aparecerá en la agenda de cada asesor bajo tu
          mando para que cualquiera pueda gestionarla.
          Los asesores pueden ver, posponer e iniciar la encuesta desde su agenda de tareas.
        </div>
      </div>

    </div><!-- /content-area -->
  </div><!-- /main-content -->

  <!-- ===== MODAL INDIVIDUAL ===== -->
  <div class="modal fade" id="modalCrear" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <div class="modal-header"
          style="background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border:none;">
          <h5 class="modal-title"><i class="fas fa-user-clock me-2"></i>Crear Tarea de Recuperación</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:24px;">
          <div class="mb-3">
            <label class="form-label fw-bold">Cliente</label>
            <div id="modalCliente" class="form-control" style="background:#f9fafb;pointer-events:none;"></div>
            <input type="hidden" id="modalCreditoId">
            <input type="hidden" id="modalAsesorId">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Meses en mora</label>
            <input type="number" id="modalMeses" class="form-control" min="0" max="999">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Fecha programada</label>
            <input type="date" id="modalFecha" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Asignar a</label>
            <select id="modalDistribuir" class="form-select">
              <option value="todos">Todos los asesores del equipo</option>
              <option value="original">Solo el asesor original del crédito</option>
              <?php foreach ($asesores_lista as $as): ?>
                <option value="asesor_<?= htmlspecialchars($as['id']) ?>"><?= htmlspecialchars($as['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-bold">Observaciones</label>
            <textarea id="modalMensaje" class="form-control" rows="2"
              placeholder="Notas para el asesor…">Recuperación: contactar cliente por cuotas pendientes</textarea>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #e5e7eb;">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-warning fw-bold" id="btnConfirmarModal"><i class="fas fa-check me-1"></i>Crear
            tarea</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function () {
      var modal = new bootstrap.Modal(document.getElementById('modalCrear'));

      // Abrir modal individual
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-abrir-modal');
        if (!btn) return;
        document.getElementById('modalCreditoId').value = btn.dataset.creditoId;
        document.getElementById('modalAsesorId').value = btn.dataset.asesorId || '';
        document.getElementById('modalCreditoId').dataset.fuente = btn.dataset.fuente || 'proceso';
        document.getElementById('modalCliente').textContent = btn.dataset.cliente;
        // meses desde la fila
        var moraInput = document.querySelector('.mora-val[data-credito-id="' + btn.dataset.creditoId + '"]');
        document.getElementById('modalMeses').value = moraInput ? moraInput.value : (btn.dataset.meses || 0);
        modal.show();
      });

      // Confirmar modal individual
      document.getElementById('btnConfirmarModal').addEventListener('click', function () {
        var btn = this; btn.disabled = true;
        var cid = document.getElementById('modalCreditoId').value;
        var asesorId = document.getElementById('modalAsesorId').value;
        var meses = parseInt(document.getElementById('modalMeses').value) || 0;
        var fecha = document.getElementById('modalFecha').value;
        var dist = document.getElementById('modalDistribuir').value;
        var msg = document.getElementById('modalMensaje').value;

        var fuente = document.getElementById('modalCreditoId').dataset.fuente || 'proceso';
        var payload = { credito_id: cid, fuente: fuente, meses_mora: meses, fecha_programada: fecha, mensaje: msg };
        if (dist === 'todos') { payload.distribuir_equipo = true; }
        else if (dist === 'original') { payload.asesor_id = asesorId; }
        else { payload.asesor_id = dist.replace('asesor_', ''); }

        fetch('crear_tarea_recuperacion.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
          .then(r => r.json()).then(j => {
            btn.disabled = false;
            if (j.status === 'success') {
              modal.hide();
              showToast('✅ ' + j.total + ' tarea(s) creada(s) correctamente', 'success');
            } else {
              showToast('❌ ' + (j.message || 'Error al crear'), 'danger');
            }
          }).catch(() => { btn.disabled = false; showToast('❌ Error de red', 'danger'); });
      });

      // Checkbox lógica
      var chkAll = document.getElementById('chkAll');
      var bulkBar = document.getElementById('bulkBar');
      if (chkAll) {
        chkAll.addEventListener('change', function () {
          document.querySelectorAll('.chk-rec').forEach(c => c.checked = this.checked);
          actualizarBulk();
        });
      }
      document.addEventListener('change', function (e) { if (e.target.matches('.chk-rec')) actualizarBulk(); });
      function actualizarBulk() {
        var sel = document.querySelectorAll('.chk-rec:checked').length;
        if (bulkBar) bulkBar.style.display = sel > 0 ? 'flex' : 'none';
        var cnt = document.getElementById('bulkCount');
        if (cnt) cnt.textContent = sel + ' seleccionado' + (sel !== 1 ? 's' : '');
      }

      // Bulk confirmar
      var bulkConfirmar = document.getElementById('bulkConfirmar');
      if (bulkConfirmar) {
        bulkConfirmar.addEventListener('click', function () {
          var btn = this;
          var checkedEls = Array.from(document.querySelectorAll('.chk-rec:checked'));
          var checks = checkedEls.map(c => c.dataset.creditoId);
          var fuenteMap = {};
          checkedEls.forEach(c => { fuenteMap[c.dataset.creditoId] = c.dataset.fuente || 'proceso'; });
          if (!checks.length) { showToast('Seleccione al menos un crédito', 'warning'); return; }
          var asesorSel = document.getElementById('bulkAsesorSel').value;
          var fecha = document.getElementById('bulkFecha').value;
          var payload = { credito_ids: checks, fuente_map: fuenteMap, fecha_programada: fecha, mensaje: 'Tarea de recuperación asignada por supervisor' };
          if (!asesorSel) { payload.distribuir_equipo = true; }
          else { payload.asesor_id = asesorSel; }
          // meses promedio: cada crédito usa su propio mora-val
          btn.disabled = true;
          fetch('crear_tarea_recuperacion.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(r => r.json()).then(j => {
              btn.disabled = false;
              if (j.status === 'success') {
                showToast('✅ ' + j.total + ' tarea(s) creada(s)', 'success');
                document.querySelectorAll('.chk-rec').forEach(c => c.checked = false);
                if (chkAll) chkAll.checked = false;
                actualizarBulk();
              } else { showToast('❌ ' + (j.message || 'Error'), 'danger'); }
            }).catch(() => { btn.disabled = false; showToast('❌ Error de red', 'danger'); });
        });
      }

      // Toast helper
      function showToast(msg, type) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:' + (type === 'success' ? '#065f46' : type === 'danger' ? '#991b1b' : '#854d0e') + ';color:#fff;padding:14px 20px;border-radius:12px;font-weight:700;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:340px;';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 4000);
      }
      // ── FILTRADO LIVE con A-Z, nombre y cédula ──────────────────────────
      function aplicarFiltros() {
        const fiNombre = document.getElementById('fiNombre').value.trim().toLowerCase();
        const fiCedula = document.getElementById('fiCedula').value.trim().toLowerCase();
        const fLetra = window.activeLetter || '';
        const tarjetas = document.querySelectorAll('#creditosGrid .cred-card');
        let visibles = 0;

        tarjetas.forEach(function(card) {
          const nombre = (card.dataset.nombre || '').toLowerCase();
          const cedula = (card.dataset.cedula || '').toLowerCase();

          const okNombre = !fiNombre || nombre.includes(fiNombre);
          const okCedula = !fiCedula || cedula.includes(fiCedula);
          const okLetra = !fLetra || nombre.startsWith(fLetra.toLowerCase());

          if (okNombre && okCedula && okLetra) {
            card.style.display = '';
            visibles++;
          } else {
            card.style.display = 'none';
          }
        });

        // Actualizar contador
        const cnt = document.getElementById('cntMostrados');
        if (cnt) cnt.textContent = visibles;

        // Mostrar/ocultar empty
        const emptyDiv = document.getElementById('emptyFiltered');
        if (emptyDiv) {
          emptyDiv.style.display = (visibles === 0 && tarjetas.length > 0) ? '' : 'none';
        }

        // Actualizar badge
        const badge = document.querySelector('#cardCreditos .sec-badge');
        if (badge) badge.textContent = visibles + ' crédito' + (visibles !== 1 ? 's' : '');
      }

      // Inicializar
      window.activeLetter = '';
      const fiNombre = document.getElementById('fiNombre');
      const fiCedula = document.getElementById('fiCedula');
      const fiClear = document.getElementById('fiClear');

      if (fiNombre) fiNombre.addEventListener('input', aplicarFiltros);
      if (fiCedula) fiCedula.addEventListener('input', aplicarFiltros);

      // Botones A-Z
      document.querySelectorAll('.az-btn').forEach(btn => {
        btn.addEventListener('click', function() {
          const letra = this.dataset.letter;
          window.activeLetter = (window.activeLetter === letra) ? '' : letra;
          document.querySelectorAll('.az-btn').forEach(b => b.classList.toggle('active', b.dataset.letter === window.activeLetter));
          document.getElementById('azAllBtn').classList.toggle('active', window.activeLetter === '');
          aplicarFiltros();
        });
      });

      // Botón TODOS
      if (document.getElementById('azAllBtn')) {
        document.getElementById('azAllBtn').addEventListener('click', function() {
          window.activeLetter = '';
          document.querySelectorAll('.az-btn').forEach(b => b.classList.remove('active'));
          this.classList.add('active');
          aplicarFiltros();
        });
      }

      // Botón Limpiar
      if (fiClear) {
        fiClear.addEventListener('click', function() {
          fiNombre.value = '';
          fiCedula.value = '';
          window.activeLetter = '';
          document.querySelectorAll('.az-btn').forEach(b => b.classList.remove('active'));
          document.getElementById('azAllBtn').classList.add('active');
          aplicarFiltros();
        });
      }

      // Inicializar contador
      aplicarFiltros();
      function filtrarTabla(){
        // Mantener compatibilidad con código anterior si existe
        aplicarFiltros();
      }
      // Ejecutar al cargar
      document.addEventListener('DOMContentLoaded', function(){ filtrarTabla(); });

      // Exponer filtrarTabla al scope global (input usa oninput)
      window.filtrarTabla = filtrarTabla;
    })();
  </script>

  <!-- ===== LISTA DE RECUPERACIONES (todas las tareas, cualquier estado) ===== -->
  <script>
    (function () {
      function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      }

      var ESTADO_LABEL = {
        programada: 'Programada',
        en_proceso: 'En proceso',
        postergada: 'Postergada',
        completada: 'Completada',
        cancelada: 'Cancelada'
      };

      function cargarLista() {
        var params = new URLSearchParams();
        var estadoSel = document.getElementById('listaEstadoFiltro');
        var asesorSel = document.getElementById('listaAsesorFiltro');
        var buscarInp = document.getElementById('listaBuscar');
        if (estadoSel && estadoSel.value) params.set('estado', estadoSel.value);
        if (asesorSel && asesorSel.value) params.set('asesor_id', asesorSel.value);
        if (buscarInp && buscarInp.value.trim()) params.set('q', buscarInp.value.trim());

        fetch('obtener_lista_recuperaciones.php?' + params.toString())
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j.status !== 'success') return;
            var tbody = document.getElementById('listaTbody');
            var wrap  = document.getElementById('listaTableWrap');
            var empty = document.getElementById('listaEmpty');
            var badge = document.getElementById('badgeListaCount');
            var resumenEl = document.getElementById('listaResumen');
            var items = j.tareas || [];

            if (badge) badge.textContent = j.total;

            if (resumenEl && j.resumen) {
              var r = j.resumen;
              resumenEl.innerHTML =
                '<span class="estado-badge estado-programada">Program.: ' + (r.programada || 0) + '</span>' +
                '<span class="estado-badge estado-en_proceso">En proceso: ' + (r.en_proceso || 0) + '</span>' +
                '<span class="estado-badge estado-postergada">Posterg.: ' + (r.postergada || 0) + '</span>' +
                '<span class="estado-badge estado-completada">Completadas: ' + (r.completada || 0) + '</span>';
            }

            if (!items.length) {
              if (wrap) wrap.style.display = 'none';
              if (empty) empty.style.display = '';
              return;
            }

            if (empty) empty.style.display = 'none';
            if (wrap) wrap.style.display = '';
            if (!tbody) return;
            tbody.innerHTML = '';

            items.forEach(function (it) {
              var tr = document.createElement('tr');
              tr.className = 'rec-row';

              var estadoKey = it.estado || '';
              var estadoLbl = ESTADO_LABEL[estadoKey] || estadoKey || '—';
              var estadoCls = 'estado-' + (estadoKey || 'programada');

              var revisionKey = it.revision_recuperacion;
              var revisionLbl, revisionCls;
              if (revisionKey === 'pendiente') { revisionLbl = 'Pendiente'; revisionCls = 'revision-pendiente'; }
              else if (revisionKey === 'aprobada') { revisionLbl = 'Aprobada'; revisionCls = 'revision-aprobada'; }
              else if (revisionKey === 'rechazada') { revisionLbl = 'Rechazada'; revisionCls = 'revision-rechazada'; }
              else { revisionLbl = '—'; revisionCls = 'revision-na'; }

              var fecha;
              if (estadoKey === 'completada' && it.fecha_realizada) {
                fecha = it.fecha_realizada + (it.hora_realizada ? ' ' + it.hora_realizada : '');
              } else {
                fecha = it.fecha_programada
                  ? it.fecha_programada + (it.hora_programada ? ' ' + it.hora_programada : '')
                  : '—';
              }

              tr.innerHTML =
                '<td class="ps-3 py-3">' +
                  '<div class="fw-bold" style="font-size:14px;color:#1e293b;">' + escapeHtml(it.cliente_nombre || '—') + '</div>' +
                  '<div class="text-muted small mt-1" style="font-size:11.5px;">' + escapeHtml(it.cliente_cedula || '—') + ' &middot; ' + escapeHtml(it.cliente_telefono || '—') + '</div>' +
                '</td>' +
                '<td class="py-3"><span class="text-secondary" style="font-size:13px;font-weight:500;">' + escapeHtml(it.asesor_nombre || '—') + '</span></td>' +
                '<td class="py-3 text-center"><span class="estado-badge ' + estadoCls + '">' + escapeHtml(estadoLbl) + '</span></td>' +
                '<td class="py-3 text-secondary" style="font-size:12.5px;">' + escapeHtml(fecha) + '</td>' +
                '<td class="py-3 text-center"><span class="estado-badge ' + revisionCls + '">' + escapeHtml(revisionLbl) + '</span></td>' +
                '<td class="py-3" style="font-size:12.5px;max-width:280px;">' + escapeHtml(it.observaciones || '—') + '</td>';
              tbody.appendChild(tr);
            });
          })
          .catch(function () {});
      }

      var estadoSel   = document.getElementById('listaEstadoFiltro');
      var asesorSel   = document.getElementById('listaAsesorFiltro');
      var buscarInp   = document.getElementById('listaBuscar');
      var refrescarBtn = document.getElementById('listaRefrescar');

      if (estadoSel) estadoSel.addEventListener('change', cargarLista);
      if (asesorSel) asesorSel.addEventListener('change', cargarLista);
      if (buscarInp) {
        var debTimer = null;
        buscarInp.addEventListener('input', function () {
          clearTimeout(debTimer);
          debTimer = setTimeout(cargarLista, 350);
        });
      }
      if (refrescarBtn) refrescarBtn.addEventListener('click', cargarLista);

      cargarLista();

      // Expuesto para que la sección de revisión pueda refrescar esta lista al aprobar/rechazar
      window.recargarListaRecuperaciones = cargarLista;
    })();
  </script>

  <!-- ===== REVISIÓN DE RECUPERACIONES (pendientes de aprobación) ===== -->
  <script>
    (function () {
      function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      }

      function showToastRev(msg, type) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:' + (type === 'success' ? '#065f46' : type === 'danger' ? '#991b1b' : '#854d0e') + ';color:#fff;padding:14px 20px;border-radius:12px;font-weight:700;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:340px;';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 4000);
      }

      function cargarRevision() {
        fetch('obtener_recuperaciones_revision.php')
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j.status !== 'success') return;
            var tbody = document.getElementById('revisionTbody');
            var wrap  = document.getElementById('revisionTableWrap');
            var empty = document.getElementById('revisionEmpty');
            var badge = document.getElementById('badgeRevisionCount');
            var items = j.pendientes || [];

            if (badge) badge.textContent = items.length;

            if (!items.length) {
              if (wrap) wrap.style.display = 'none';
              if (empty) empty.style.display = '';
              return;
            }

            if (empty) empty.style.display = 'none';
            if (wrap) wrap.style.display = '';
            if (!tbody) return;
            tbody.innerHTML = '';

            items.forEach(function (it) {
              var tr = document.createElement('tr');
              tr.className = 'rec-row';
              var fecha = it.fecha_realizada
                ? (it.fecha_realizada + (it.hora_realizada ? ' ' + it.hora_realizada : ''))
                : '—';
              tr.innerHTML =
                '<td class="ps-3 py-3">' +
                  '<div class="fw-bold" style="font-size:14.5px;color:#1e293b;">' + escapeHtml(it.cliente_nombre || '—') + '</div>' +
                  '<div class="text-muted small mt-1" style="font-size:11.5px;">' + escapeHtml(it.cliente_cedula || '—') + ' &middot; ' + escapeHtml(it.cliente_telefono || '—') + '</div>' +
                '</td>' +
                '<td class="py-3"><span class="text-secondary" style="font-size:13.5px;font-weight:500;">' + escapeHtml(it.asesor_nombre || '—') + '</span></td>' +
                '<td class="py-3" style="font-size:13px;max-width:320px;">' + escapeHtml(it.observaciones || '—') + '</td>' +
                '<td class="py-3 text-secondary" style="font-size:13px;">' + escapeHtml(fecha) + '</td>' +
                '<td class="text-end pe-3 py-3" style="white-space:nowrap;">' +
                  '<button class="btn btn-sm btn-success btn-revisar" data-id="' + escapeHtml(it.id) + '" data-accion="aprobar" style="font-weight:700;margin-right:6px;"><i class="fas fa-check"></i> Aprobar</button>' +
                  '<button class="btn btn-sm btn-outline-danger btn-revisar" data-id="' + escapeHtml(it.id) + '" data-accion="rechazar" style="font-weight:700;"><i class="fas fa-times"></i> Rechazar</button>' +
                '</td>';
              tbody.appendChild(tr);
            });
          })
          .catch(function () {});
      }

      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-revisar');
        if (!btn) return;

        var id = btn.dataset.id;
        var accion = btn.dataset.accion;
        var observacion = '';

        if (accion === 'rechazar') {
          observacion = prompt('Motivo del rechazo (opcional, se notifica internamente):', '');
          if (observacion === null) return; // canceló el prompt
        } else {
          if (!confirm('¿Confirmas que el cliente pagó (o vino a pagar a ventanilla) y apruebas esta recuperación como finalizada?')) return;
        }

        btn.disabled = true;
        fetch('revisar_recuperacion.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ tarea_id: id, accion: accion, observacion: observacion })
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j.status === 'success') {
              showToastRev('✅ ' + j.message, 'success');
              cargarRevision();
              if (window.recargarListaRecuperaciones) window.recargarListaRecuperaciones();
            } else {
              btn.disabled = false;
              showToastRev('❌ ' + (j.message || 'Error'), 'danger');
            }
          })
          .catch(function () {
            btn.disabled = false;
            showToastRev('❌ Error de red', 'danger');
          });
      });

      cargarRevision();
    })();
  </script>
</body>
</html>