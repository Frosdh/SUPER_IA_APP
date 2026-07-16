<?php
/**
 * _sidebar_super_admin.php — Sidebar único para el rol SuperAdmin
 *
 * Un solo archivo para que TODAS las páginas del SuperAdmin muestren
 * exactamente el mismo menú (antes cada página tenía su propia copia y
 * se veían distintas entre sí al cambiar de pestaña).
 *
 * Requisitos antes de hacer require:
 *   - Debe existir $pdo (PDO) — se incluye db_admin.php si no está.
 *   - Definir $currentPage (string) con uno de estos valores:
 *       dashboard | mapa | mapa_calor | historial | usuarios |
 *       operaciones | metas | config_metas | alertas | tareas_descartadas |
 *       solicitudes | solicitudes_admin | crear_asesor |
 *       administrar_asesores
 *   - El <style> de cada página debe definir las clases .sidebar,
 *     .sidebar-brand, .sidebar-section, .sidebar-link (mismo navy en
 *     todas las páginas: --brand-navy / --brand-navy-deep / --brand-yellow).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($pdo)) require_once 'db_admin.php';

$currentPage = trim((string)($currentPage ?? ''));

$solicitudes_pendientes_sa = 0;
try {
    $solicitudes_pendientes_sa = (int)$pdo->query(
        "SELECT COUNT(*) FROM solicitud_registro WHERE estado = 'pendiente'"
    )->fetchColumn();
} catch (Throwable $e) {
    $solicitudes_pendientes_sa = 0;
}

$solicitudes_admin_pendientes_sa = 0;
try {
    $solicitudes_admin_pendientes_sa = (int)$pdo->query(
        "SELECT COUNT(*) FROM solicitudes_admin WHERE estado = 'pendiente'"
    )->fetchColumn();
} catch (Throwable $e) {
    $solicitudes_admin_pendientes_sa = 0;
}

function _sa_active(string $page, string $current): string {
    return $page === $current ? 'active' : '';
}
?>
<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-crown"></i> Super_IA</div>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="super_admin_index.php" class="sidebar-link <?= _sa_active('dashboard', $currentPage) ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link <?= _sa_active('mapa', $currentPage) ?>">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link <?= _sa_active('mapa_calor', $currentPage) ?>">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link <?= _sa_active('historial', $currentPage) ?>">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestion</div>
        <a href="usuarios.php" class="sidebar-link <?= _sa_active('usuarios', $currentPage) ?>">
            <i class="fas fa-users"></i> Usuarios
        </a>
        <a href="operaciones.php" class="sidebar-link <?= _sa_active('operaciones', $currentPage) ?>">
            <i class="fas fa-handshake"></i> Operaciones
        </a>
        <a href="metas.php" class="sidebar-link <?= _sa_active('metas', $currentPage) ?>">
            <i class="fas fa-bullseye"></i> Metas
        </a>
        <a href="alertas.php" class="sidebar-link <?= _sa_active('alertas', $currentPage) ?>">
            <i class="fas fa-bell"></i> Alertas
        </a>
        <a href="tareas_descartadas.php" class="sidebar-link <?= _sa_active('tareas_descartadas', $currentPage) ?>">
            <i class="fas fa-ban"></i> Tareas Descartadas
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Super Administracion</div>
        <a href="administrar_solicitudes_global.php" class="sidebar-link <?= _sa_active('solicitudes', $currentPage) ?>">
            <i class="fas fa-file-signature"></i> Solicitudes Pendientes
            <?php if ($solicitudes_pendientes_sa > 0): ?>
                <span class="badge bg-danger ms-auto" style="font-size:10px;padding:3px 7px;border-radius:10px;"><?= $solicitudes_pendientes_sa ?></span>
            <?php endif; ?>
        </a>
        <a href="administrar_solicitudes_admin.php" class="sidebar-link <?= _sa_active('solicitudes_admin', $currentPage) ?>">
            <i class="fas fa-file-alt"></i> Solicitudes de Gerente/Admin
            <?php if ($solicitudes_admin_pendientes_sa > 0): ?>
                <span class="badge bg-danger ms-auto" style="font-size:10px;padding:3px 7px;border-radius:10px;"><?= $solicitudes_admin_pendientes_sa ?></span>
            <?php endif; ?>
        </a>
        <a href="crear_asesor_admin.php" class="sidebar-link <?= _sa_active('crear_asesor', $currentPage) ?>">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_asesores.php" class="sidebar-link <?= _sa_active('administrar_asesores', $currentPage) ?>">
            <i class="fas fa-users-cog"></i> Administrar Asesores
        </a>
    </div>
</div>
