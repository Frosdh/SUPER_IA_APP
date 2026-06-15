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

// Asesores del supervisor — necesarios para filtros de "Lista de Recuperaciones"
$asesores_lista = [];
try {
  if ($supervisor_table_id) {
    $st = $pdo->prepare('SELECT a.id, u.nombre FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE a.supervisor_id=? ORDER BY u.nombre');
    $st->execute([$supervisor_table_id]);
    $asesores_lista = $st->fetchAll();
  }
} catch (Throwable $_) {}

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
            <a href="recuperacion_creditos.php" class="btn-navy">
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

    </div><!-- /content-area -->
  </div><!-- /main-content -->

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