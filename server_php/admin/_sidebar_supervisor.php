<?php
/**
 * _sidebar_supervisor.php — Sidebar unificado para el panel del Supervisor
 *
 * Variables que DEBEN estar definidas antes de hacer require/include de este archivo:
 *   $currentPage  (string) — página activa: dashboard | mapa | clientes | operaciones |
 *                             alertas | reportes | asesores | agregar | solicitudes
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php'; // Asegurar conexión PDO disponible

$currentPage        = trim((string) ($currentPage ?? ''));
// Compatibilidad con nombres legacy usados en algunas páginas heredadas
switch ($currentPage) {
    case 'solicitudes_asesor':
    case 'solicitudes_supervisor':
    case 'solicitudes_admin':
        $currentPage = 'solicitudes';
        break;
    case 'administrar_asesores':
        $currentPage = 'asesores';
        break;
    case 'supervisor_dashboard':
        $currentPage = 'dashboard';
        break;
    case 'mapa_vivo':
        $currentPage = 'mapa';
        break;
}
$supervisor_nombre  = $supervisor_nombre ?? $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$supervisor_rol     = $supervisor_rol ?? $_SESSION['supervisor_rol'] ?? 'Supervisor';

// Cálculo automático de alertas si no viene definida
if (!isset($alertas_pendientes) || $alertas_pendientes === 0) {
    $alertas_pendientes = 0;
    $sess_sup_id = $_SESSION['supervisor_id'] ?? null;
    if ($sess_sup_id) {
        try {
            // Resolver table_id del supervisor (la sesión suele guardar el usuario_id)
            $stS = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
            $stS->execute([$sess_sup_id]);
            $table_id = $stS->fetchColumn();
            if ($table_id) {
                $stA = $pdo->prepare('SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id = ? AND vista_supervisor = 0');
                $stA->execute([$table_id]);
                $alertas_pendientes = (int)$stA->fetchColumn();
            }
        } catch (Throwable $e) {}
    }
}
?>
<link rel="stylesheet" href="supervisor_layout.css">

<!-- ══════════ SIDEBAR SUPERVISOR ══════════ -->
<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-star"></i><span>Super_IA</span></div>

    <!-- PRINCIPAL -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">PRINCIPAL</div>
        <a href="supervisor_index.php"
           class="sidebar-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
            <i class="fas fa-home"></i> <span>Dashboard</span>
        </a>
        <a href="mapa_vivo_superIA.php"
           class="sidebar-link <?= ($currentPage === 'mapa') ? 'active' : '' ?>">
            <i class="fas fa-map-marked-alt"></i> <span>Mapa en Vivo</span>
        </a>
    </div>

    <!-- GESTIÓN -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">GESTIÓN</div>
        <a href="clientes.php"
           class="sidebar-link <?= ($currentPage === 'clientes') ? 'active' : '' ?>">
            <i class="fas fa-address-book"></i> <span>Mis Clientes</span>
        </a>
        <a href="operaciones.php"
           class="sidebar-link <?= ($currentPage === 'operaciones') ? 'active' : '' ?>">
            <i class="fas fa-handshake"></i> <span>Operaciones</span>
        </a>
        <a href="alertas.php"
           class="sidebar-link <?= ($currentPage === 'alertas') ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> <span>Alertas</span>
            <?php if ($alertas_pendientes > 0): ?>
            <span class="badge-nav"><?= $alertas_pendientes > 99 ? '99+' : $alertas_pendientes ?></span>
            <?php endif; ?>
        </a>

        <a href="kpi_penetracion.php"
           class="sidebar-link <?= ($currentPage === 'reportes_penetracion') ? 'active' : '' ?>">
            <i class="fas fa-chart-bar"></i> <span>Reportes KPIs</span>
        </a>

        <a href="recuperacion.php"
           class="sidebar-link <?= ($currentPage === 'recuperacion') ? 'active' : '' ?>">
            <i class="fas fa-user-clock"></i> <span>Recuperación</span>
        </a>
        <a href="metas.php"
           class="sidebar-link <?= ($currentPage === 'metas') ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i> <span>Metas / Tareas</span>
        </a>
    </div>

    <!-- MI EQUIPO -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">MI EQUIPO</div>
        <a href="mis_asesores.php"
           class="sidebar-link <?= ($currentPage === 'asesores') ? 'active' : '' ?>">
            <i class="fas fa-users"></i> <span>Mis Asesores</span>
        </a>
        <a href="registro_asesor.php"
           class="sidebar-link <?= ($currentPage === 'agregar') ? 'active' : '' ?>">
            <i class="fas fa-user-plus"></i> <span>Crear Asesor</span>
        </a>
        <a href="administrar_solicitudes_asesor.php"
           class="sidebar-link <?= ($currentPage === 'solicitudes') ? 'active' : '' ?>">
            <i class="fas fa-file-circle-check"></i> <span>Solicitudes de Asesor</span>
        </a>
    </div>

    <!-- SESIÓN -->
    <div style="border-top:1px solid rgba(255,255,255,.1);padding-top:14px;margin:0 10px;">
        <div class="sidebar-section-title" style="padding:0 5px;">SESIÓN</div>
        <a href="logout.php" class="sidebar-link" style="color:rgba(252,165,165,.8)!important;">
            <i class="fas fa-sign-out-alt"></i> <span>Cerrar Sesión</span>
        </a>
    </div>
</div>

<!-- ══════════ MAIN WRAPPER ══════════ -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <div class="nav-title-group">
            <h2>
                <i class="<?= $navIcon ?? 'fas fa-shield-halved' ?> me-2" style="color:var(--brand-yellow);"></i>
                <?= $navTitle ?? 'Super_IA — Supervisor' ?>
            </h2>
            <?php if (!empty($navSubtitle)): ?>
                <small class="navbar-subtitle"><?= htmlspecialchars($navSubtitle) ?></small>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <div>
                <strong><?= htmlspecialchars($supervisor_nombre) ?></strong><br>
                <small><?= htmlspecialchars($supervisor_rol) ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <!-- CONTENT START -->
    <div class="content-area">
