<?php
if (session_status() === PHP_SESSION_NONE)
  session_start();
require_once 'db_admin.php';
if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
  header('Location:login.php?role=supervisor');
  exit;
}
$supervisor_usuario_id = $_SESSION['supervisor_id'];
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? '';
$supervisor_rol = $_SESSION['supervisor_rol'] ?? 'Supervisor';
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
      position: fixed;
      height: 100vh;
      left: 0;
      top: 0;
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
      margin-left: 230px;
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
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      color: var(--brand-gray);
      letter-spacing: .3px;
      border-bottom: 2px solid var(--brand-border);
    }

    .table td {
      vertical-align: middle;
      font-size: 13.5px;
      border-color: #f0f3f7;
    }

    .mora-badge {
      display: inline-block;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 800;
    }

    .mora-ok {
      background: #dcfce7;
      color: #065f46;
    }

    .mora-mid {
      background: #fef9c3;
      color: #854d0e;
    }

    .mora-high {
      background: #fee2e2;
      color: #991b1b;
    }

    .btn-crear {
      background: var(--brand-yellow);
      color: var(--brand-navy-deep);
      border: none;
      padding: 6px 14px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .btn-crear:hover {
      background: var(--brand-yellow-deep);
    }

    .btn-bulk {
      background: linear-gradient(135deg, #dc2626, #ef4444);
      color: #fff;
      border: none;
      padding: 9px 20px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 13.5px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-bulk:hover {
      opacity: .9;
    }

    .empty-msg {
      padding: 40px 20px;
      text-align: center;
      color: #9ca3af;
    }

    .empty-msg i {
      font-size: 36px;
      display: block;
      margin-bottom: 12px;
      opacity: .5;
    }

    .mora-input {
      width: 72px;
      border: 1.5px solid var(--brand-border);
      border-radius: 7px;
      padding: 4px 8px;
      font-size: 13px;
      text-align: center;
    }

    .mora-input:focus {
      border-color: var(--brand-navy);
      outline: none;
    }
  </style>
</head>

<body>
  <?php require_once '_sidebar_supervisor.php'; ?>

  <div class="main-content">
    <div class="navbar-custom">
      <h2><i class="fas fa-user-clock"></i> Recuperación de Cartera</h2>
      <div style="display:flex;align-items:center;gap:14px;font-size:13px;">
        <div><strong><?= htmlspecialchars($supervisor_nombre) ?></strong><br><small
            style="opacity:.7;"><?= htmlspecialchars($supervisor_rol) ?></small></div>
        <a href="logout.php"
          style="background:rgba(255,221,0,.15);color:#fff;border:1px solid rgba(255,221,0,.28);padding:7px 14px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;">Salir</a>
      </div>
    </div>

    <div class="content-area">
      <div class="page-header">
        <h1><i class="fas fa-user-clock"></i> Recuperación de Cartera</h1>
        <button class="btn-bulk" id="btnBulkCrear" style="display:none;">
          <i class="fas fa-bolt"></i> Crear tareas (seleccionados)
        </button>
      </div>

      <!-- Sugerencias live (click para filtrar/seleccionar) -->
      <div id="suggestionsBox" style="display:none;position:relative;max-width:980px;margin-top:8px;">
        <div id="suggestionsInner" style="position:relative;background:#fff;border:1px solid var(--brand-border);border-radius:10px;box-shadow:var(--brand-shadow);max-height:280px;overflow:auto;padding:8px;">
          <!-- items populated by JS -->
        </div>
      </div>

      <!-- FILTROS — solo búsqueda por cliente/cédula -->
      <div class="filter-bar">
        <i class="fas fa-search" style="color:var(--brand-gray);"></i>
        <input type="text" id="inputBusqueda" value="<?= htmlspecialchars($q) ?>"
          placeholder="Buscar por nombre o cédula del cliente…"
          style="min-width:260px;flex:1;padding:9px 14px;border:1.5px solid var(--brand-border);border-radius:9px;font-size:14px;outline:none;"
          oninput="filtrarTabla()">
        <span id="cntResultados" style="font-size:13px;color:var(--brand-gray);margin-left:auto;"></span>
      </div>

      <!-- TABLA -->
      <div class="section-card">
        <div class="section-header">
          <h5><i class="fas fa-list" style="color:#dc2626;"></i> Créditos Aprobados / Desembolsados</h5>
          <span class="sec-badge"><?= count($creditos) ?> créditos</span>
        </div>
        <?php if (empty($creditos)): ?>
          <div class="empty-msg"><i class="fas fa-check-circle" style="color:#10b981;"></i>
            <p>No se encontraron créditos aprobados<?= $q ? ' con esos filtros' : '' ?>.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="table table-hover mb-0" id="tablaCreditos">
              <thead>
                <tr>
                  <th style="width:36px;"><input type="checkbox" id="chkAll" title="Seleccionar todos"></th>
                  <th>Cliente</th>
                  <th>Asesor</th>
                  <th>Monto</th>
                  <th>Desembolso</th>
                  <th>Meses desde desemb.</th>
                  <th>Meses en mora</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <!-- Mensaje vacío al filtrar (JS lo muestra/oculta) -->
                <tr id="emptyFiltered" style="display:none;">
                  <td colspan="8" style="text-align:center;padding:32px 0;color:#9ca3af;">
                    <i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;opacity:.4;"></i>
                    No hay créditos que coincidan con la búsqueda.
                  </td>
                </tr>
                <?php foreach ($creditos as $cr):
                  $fechaRaw = !empty($cr['fecha_desembolso']) ? $cr['fecha_desembolso'] : $cr['created_at'];
                  try { $dt0 = new DateTime($fechaRaw); } catch(Throwable $_) { $dt0 = new DateTime(); }
                  $dt1 = new DateTime();
                  $meses = max(0, (int) (($dt1->format('Y') - $dt0->format('Y')) * 12 + ($dt1->format('n') - $dt0->format('n'))));
                  $moraClass = $meses <= 3 ? 'mora-ok' : ($meses <= 6 ? 'mora-mid' : 'mora-high');
                  $nombreDisplay = htmlspecialchars(trim($cr['cliente_nombre']??'') ?: ($cr['cedula']??'—'));
                  ?>
                  <tr data-nombre="<?= strtolower(htmlspecialchars($cr['cliente_nombre']??'')) ?>" data-cedula="<?= strtolower(htmlspecialchars($cr['cedula']??'')) ?>" data-asesor="<?= strtolower(htmlspecialchars($cr['asesor_nombre']??'')) ?>" data-meses="<?= $meses ?>">
                    <td><input type="checkbox" class="chk-rec" data-credito-id="<?= htmlspecialchars($cr['id']) ?>"
                        data-asesor-id="<?= htmlspecialchars($cr['asesor_id'] ?? '') ?>"
                        data-fuente="<?= htmlspecialchars($cr['fuente'] ?? 'proceso') ?>"></td>
                    <td>
                      <div style="font-weight:700;"><?= $nombreDisplay ?>
                        <?php if (($cr['fuente']??'') === 'ficha' && ($cr['estado_revision']??'') === 'pendiente'): ?>
                          <span style="font-size:10px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:1px 5px;font-weight:600;vertical-align:middle;margin-left:4px;">Pendiente revisión</span>
                        <?php endif; ?>
                      </div>
                      <small class="text-muted"><?= htmlspecialchars($cr['cedula'] ?? '') ?><?= !empty($cr['telefono']) ? ' · ' . htmlspecialchars($cr['telefono']) : '' ?></small>
                    </td>
                    <td><?= htmlspecialchars($cr['asesor_nombre'] ?? '—') ?></td>
                    <td><strong><?= is_numeric($cr['monto_aprobado']??'') ? '$'.number_format((float)$cr['monto_aprobado'],2) : (htmlspecialchars($cr['monto_aprobado']??'') ?: '—') ?></strong></td>
                    <td><?= !empty($fechaRaw) ? date('d/m/Y', strtotime($fechaRaw)) : '—' ?></td>
                    <td><span class="mora-badge <?= $moraClass ?>"><?= $meses ?> mes<?= $meses != 1 ? 'es' : '' ?></span></td>
                    <td>
                      <input type="number" class="mora-input mora-val" min="0" max="999" value="<?= $meses ?>"
                        data-credito-id="<?= htmlspecialchars($cr['id']) ?>" title="Meses en mora">
                    </td>
                    <td style="white-space:nowrap;">
                      <?php $tel = trim($cr['telefono'] ?? ''); $tel_clean = preg_replace('/\D+/', '', $tel); ?>
                      <?php $email = trim($cr['email'] ?? ''); ?>
                      <?php
                        // Heurística: si es número local ecuatoriano (9 dígitos empezando en 9), agregar prefijo 593 para WhatsApp
                        $wa_tel = '';
                        $tel_uri = '';
                        if ($tel_clean) {
                          if (strlen($tel_clean) == 9 && strpos($tel_clean, '9') === 0) {
                            $wa_tel = '593' . $tel_clean;
                            $tel_uri = '+593' . $tel_clean;
                          } else {
                            $wa_tel = $tel_clean;
                            $tel_uri = $tel_clean;
                          }
                        }
                      ?>
                      <?php if ($tel_clean): ?>
                        <a href="tel:<?= htmlspecialchars($tel_uri) ?>" class="btn btn-sm btn-outline-primary me-1" title="Llamar"><i class="fas fa-phone"></i></a>
                        <a href="https://wa.me/<?= htmlspecialchars($wa_tel) ?>" target="_blank" class="btn btn-sm btn-outline-success me-1" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                      <?php endif; ?>
                      <?php if ($email): ?>
                        <a href="mailto:<?= htmlspecialchars($email) ?>" class="btn btn-sm btn-outline-secondary me-1" title="Email"><i class="fas fa-envelope"></i></a>
                      <?php endif; ?>
                      <button class="btn-crear btn-abrir-modal" data-credito-id="<?= htmlspecialchars($cr['id']) ?>"
                        data-asesor-id="<?= htmlspecialchars($cr['asesor_id'] ?? '') ?>"
                        data-fuente="<?= htmlspecialchars($cr['fuente'] ?? 'proceso') ?>"
                        data-cliente="<?= $nombreDisplay ?>"
                        data-meses="<?= $meses ?>">
                        <i class="fas fa-plus"></i> Crear tarea
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Barra de acción bulk -->
          <div id="bulkBar"
            style="display:none;padding:14px 20px;border-top:1px solid var(--brand-border);background:#fafbfc;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <span id="bulkCount" style="font-weight:700;font-size:13.5px;">0 seleccionados</span>
            <select id="bulkAsesorSel" class="form-select form-select-sm" style="width:240px;">
              <option value="">Enviar a: todos los asesores del equipo</option>
              <?php foreach ($asesores_lista as $as): ?>
                <option value="<?= htmlspecialchars($as['id']) ?>"><?= htmlspecialchars($as['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="date" id="bulkFecha" class="form-control form-control-sm" style="width:160px;"
              value="<?= date('Y-m-d') ?>">
            <button class="btn-bulk" id="bulkConfirmar"><i class="fas fa-bolt"></i> Confirmar y crear</button>
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
      // ── FILTRADO LIVE por nombre / cédula ──────────────────
      function filtrarTabla(){
        var q = (document.getElementById('inputBusqueda').value||'').toLowerCase().trim();
        var filas = document.querySelectorAll('#tablaCreditos tbody tr');
        var vis = 0;
        filas.forEach(function(tr){
          if (tr.id === 'emptyFiltered') return;
          var nombre = (tr.dataset.nombre || '').toLowerCase();
          var cedula = (tr.dataset.cedula || '').toLowerCase();
          var show = !q || nombre.includes(q) || cedula.includes(q);
          tr.style.display = show ? '' : 'none';
          if (show) vis++;
        });
        var cnt = document.getElementById('cntResultados');
        if(cnt) cnt.textContent = vis + ' resultado' + (vis!==1?'s':'');
        var badge = document.querySelector('.sec-badge');
        if(badge) badge.textContent = vis + ' crédito' + (vis!==1?'s':'');
        var vacioDiv = document.getElementById('emptyFiltered');
        if(vacioDiv) vacioDiv.style.display = (vis === 0) ? '' : 'none';
      }
      // Ejecutar al cargar
      document.addEventListener('DOMContentLoaded', function(){ filtrarTabla(); buildSuggestions(); });

      // Construir lista de sugerencias a partir de la tabla
      function buildSuggestions(){
        var inner = document.getElementById('suggestionsInner');
        if(!inner) return;
        inner.innerHTML = '';
        var filas = Array.from(document.querySelectorAll('#tablaCreditos tbody tr'));
        filas.forEach(function(tr){
          var nombre = (tr.dataset.nombre||'').trim();
          var cedula = (tr.dataset.cedula||'').trim();
          var asesor  = (tr.dataset.asesor||'').trim();
          var creditId = tr.querySelector('.chk-rec')?tr.querySelector('.chk-rec').dataset.creditoId:'';
          var label = nombre || cedula || ('Crédito ' + creditId);
          var item = document.createElement('div');
          item.className = 'suggestion-item';
          item.style.cssText = 'padding:8px 10px;border-radius:8px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;';
          item.innerHTML = '<div><strong style="font-weight:700;">'+escapeHtml(label)+'</strong><div style="font-size:12px;color:#6b7280;">'+escapeHtml(asesor)+' · '+escapeHtml(cedula)+'</div></div>';
          item.dataset.nombre = nombre;
          item.dataset.cedula = cedula;
          item.dataset.asesor = asesor;
          item.dataset.creditoId = creditId;
          item.addEventListener('click', function(){
            // al click: poner input, ocultar sugerencias y filtrar tabla dejando solo ese cliente visible
            var qbox = document.getElementById('inputBusqueda');
            qbox.value = this.dataset.nombre || this.dataset.cedula || '';
            // opcional: seleccionar asesor en el select
            var selA = document.getElementById('selectAsesorLive');
            if(selA && this.dataset.asesor) selA.value = this.dataset.asesor.toLowerCase();
            filtrarTabla();
            hideSuggestions();
            // intentar desplazar hasta la fila
            var fila = document.querySelector('#tablaCreditos tbody tr[data-nombre="'+(this.dataset.nombre||'')+'"]');
            if(fila) fila.scrollIntoView({behavior:'smooth',block:'center'});
          });
          inner.appendChild(item);
        });
      }

      function showSuggestions(){
        var box = document.getElementById('suggestionsBox'); if(!box) return; box.style.display='block';
      }
      function hideSuggestions(){
        var box = document.getElementById('suggestionsBox'); if(!box) return; box.style.display='none';
      }

      // Escape simple HTML
      function escapeHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

      // Mostrar sugerencias al enfocar el input, actualizar mientras escribe
      var inputB = document.getElementById('inputBusqueda');
      if(inputB){
        inputB.addEventListener('focus', function(){ buildSuggestions(); showSuggestions(); });
        inputB.addEventListener('input', function(){ buildSuggestions(); showSuggestions(); });
      }
      // Cerrar al hacer click fuera
      document.addEventListener('click', function(e){
        var box = document.getElementById('suggestionsBox');
        if(!box) return;
        if(e.target.closest('#suggestionsBox') || e.target.closest('#inputBusqueda')) return;
        hideSuggestions();
      });
      // Exponer filtrarTabla al scope global (input usa oninput)
      window.filtrarTabla = filtrarTabla;
    })();
  </script>
</body>
</html>