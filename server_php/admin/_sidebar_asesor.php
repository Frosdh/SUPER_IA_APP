<?php
/**
 * _sidebar_asesor.php — Sidebar unificado para el panel del Asesor (web)
 *
 * Variables que DEBEN estar definidas antes del require de este archivo:
 *   $currentPage  (string) — dashboard | tareas | encuesta | clientes | productos |
 *                            operaciones | metas | mapa | alertas
 *   $tareas_pendientes (int)  — cantidad de tareas del día sin completar (puede ser 0)
 *   $alertas_pendientes (int) — alertas sin leer (puede ser 0)
 *   $asesor_nombre (string)
 */
$tareas_pendientes  = $tareas_pendientes  ?? 0;
$alertas_pendientes = $alertas_pendientes ?? 0;
?>
<!-- ══════════ SIDEBAR ASESOR ══════════ -->
<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-user-tie"></i><span>Mi Panel</span></div>

    <!-- PRINCIPAL -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">PRINCIPAL</div>
        <a href="asesor_index.php"
           class="sidebar-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="tareas_pendientes.php"
           class="sidebar-link <?= ($currentPage === 'tareas') ? 'active' : '' ?>">
            <i class="fas fa-list-check"></i> Tareas del día
            <?php if ($tareas_pendientes > 0): ?>
                <span class="badge-nav"><?= $tareas_pendientes > 99 ? '99+' : $tareas_pendientes ?></span>
            <?php endif; ?>
        </a>
        <a href="mapa_vivo_asesor.php"
           class="sidebar-link <?= ($currentPage === 'mapa') ? 'active' : '' ?>">
            <i class="fas fa-map-location-dot"></i> Mi Ubicación
        </a>
    </div>

    <!-- CAMPO -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">TRABAJO EN CAMPO</div>
        <a href="nueva_encuesta.php"
           class="sidebar-link <?= ($currentPage === 'encuesta') ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list"></i> Nueva Encuesta
        </a>
        <a href="clientes.php"
           class="sidebar-link <?= ($currentPage === 'clientes') ? 'active' : '' ?>">
            <i class="fas fa-address-book"></i> Mis Clientes
        </a>
        <a href="operaciones.php"
           class="sidebar-link <?= ($currentPage === 'operaciones') ? 'active' : '' ?>">
            <i class="fas fa-handshake"></i> Mis Operaciones
        </a>
    </div>

    <!-- METAS -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">METAS</div>
        <a href="metas_asesor.php"
           class="sidebar-link <?= ($currentPage === 'metas') ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i> Mis Metas
        </a>
        <a href="alertas.php"
           class="sidebar-link <?= ($currentPage === 'alertas') ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> Alertas
            <?php if ($alertas_pendientes > 0): ?>
                <span class="badge-nav"><?= $alertas_pendientes > 99 ? '99+' : $alertas_pendientes ?></span>
            <?php endif; ?>
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
