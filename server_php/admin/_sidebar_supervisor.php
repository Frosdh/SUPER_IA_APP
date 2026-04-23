<?php
/**
 * _sidebar_supervisor.php — Sidebar unificado para el panel del Supervisor
 *
 * Variables que DEBEN estar definidas antes de hacer require/include de este archivo:
 *   $currentPage  (string) — página activa: dashboard | mapa | clientes | operaciones |
 *                             alertas | reportes | asesores | agregar | solicitudes
 *   $alertas_pendientes (int) — cantidad de alertas sin ver (puede ser 0)
 *   $supervisor_nombre  (string)
 *   $supervisor_rol     (string)
 */
$alertas_pendientes = $alertas_pendientes ?? 0;
?>
<!-- ══════════ SIDEBAR SUPERVISOR ══════════ -->
<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-star"></i><span>Super_IA</span></div>

    <!-- PRINCIPAL -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">PRINCIPAL</div>
        <a href="supervisor_index.php"
           class="sidebar-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_superIA.php"
           class="sidebar-link <?= ($currentPage === 'mapa') ? 'active' : '' ?>">
            <i class="fas fa-map-marked-alt"></i> Mapa en Vivo
        </a>
    </div>

    <!-- GESTIÓN -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">GESTIÓN</div>
        <a href="clientes.php"
           class="sidebar-link <?= ($currentPage === 'clientes') ? 'active' : '' ?>">
            <i class="fas fa-address-book"></i> Mis Clientes
        </a>
        <a href="operaciones.php"
           class="sidebar-link <?= ($currentPage === 'operaciones') ? 'active' : '' ?>">
            <i class="fas fa-handshake"></i> Operaciones
        </a>
        <a href="alertas.php"
           class="sidebar-link <?= ($currentPage === 'alertas') ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> Alertas
            <?php if ($alertas_pendientes > 0): ?>
            <span class="badge-nav"><?= $alertas_pendientes > 99 ? '99+' : $alertas_pendientes ?></span>
            <?php endif; ?>
        </a>
        <a href="reportes.php"
           class="sidebar-link <?= ($currentPage === 'reportes') ? 'active' : '' ?>">
            <i class="fas fa-chart-bar"></i> Reportes KPI
        </a>
          <a href="recuperacion.php"
              class="sidebar-link <?= ($currentPage === 'recuperacion') ? 'active' : '' ?>">
            <i class="fas fa-user-clock"></i> Recuperación
        </a>
        <a href="metas.php"
           class="sidebar-link <?= ($currentPage === 'metas') ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i> Metas / Tareas
        </a>
    </div>

    <!-- MI EQUIPO -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">MI EQUIPO</div>
        <a href="mis_asesores.php"
           class="sidebar-link <?= ($currentPage === 'asesores') ? 'active' : '' ?>">
            <i class="fas fa-users"></i> Mis Asesores
        </a>
        <a href="registro_asesor.php"
           class="sidebar-link <?= ($currentPage === 'agregar') ? 'active' : '' ?>">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_solicitudes_asesor.php"
           class="sidebar-link <?= ($currentPage === 'solicitudes') ? 'active' : '' ?>">
            <i class="fas fa-file-circle-check"></i> Solicitudes
        </a>
    </div>

    <!-- SESIÓN -->
    <div style="border-top:1px solid rgba(255,255,255,.1);padding-top:14px;margin:0 10px;">
        <div class="sidebar-section-title" style="padding:0 5px;">SESIÓN</div>
        <a href="logout.php" class="sidebar-link" style="color:rgba(252,165,165,.8)!important;">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>
    </div>
</div>
