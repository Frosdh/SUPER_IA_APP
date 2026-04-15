<?php
// _sidebar.php — Sidebar Super_IA Logan
// Requiere que $currentPage y $totalPendientes estén definidos antes de incluir.
$currentPage      = $currentPage    ?? '';
$totalPendientes  = $totalPendientes ?? 0;
$isSupervisor     = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;
$isAsesor         = isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true;
$isAdmin          = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// ── Contar alertas pendientes para el supervisor actual ──────
$totalAlertasBadge = 0;
if ($isSupervisor) {
    $sup_usuario_id = $_SESSION['supervisor_id'] ?? null;
    if ($sup_usuario_id) {
        try {
            // Resolver supervisor.id real (la sesión guarda usuario.id)
            $stSid = $conn->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
            if ($stSid) {
                $stSid->bind_param('s', $sup_usuario_id);
                $stSid->execute();
                $rowSid = $stSid->get_result()->fetch_assoc();
                $sup_real_id = $rowSid ? $rowSid['id'] : null;
                $stSid->close();
                if ($sup_real_id) {
                    $stCnt = $conn->prepare(
                        'SELECT COUNT(*) AS cnt FROM alerta_modificacion
                         WHERE supervisor_id = ? AND vista_supervisor = 0'
                    );
                    if ($stCnt) {
                        $stCnt->bind_param('s', $sup_real_id);
                        $stCnt->execute();
                        $rowCnt = $stCnt->get_result()->fetch_assoc();
                        $totalAlertasBadge = (int)($rowCnt['cnt'] ?? 0);
                        $stCnt->close();
                    }
                }
            }
        } catch (\Throwable $e) { /* tabla puede no existir aún */ }
    }
}

// Navegación para Supervisores
$nav_supervisor = [
    'PRINCIPAL' => [
        ['href' => 'index_supervisor.php', 'icon' => 'fa-home', 'label' => 'Dashboard', 'page' => 'dashboard'],
        ['href' => 'mis_asesores.php', 'icon' => 'fa-users', 'label' => 'Mis Asesores', 'page' => 'asesores'],
        ['href' => 'operaciones.php', 'icon' => 'fa-briefcase', 'label' => 'Operaciones', 'page' => 'operaciones'],
        ['href' => 'pendientes.php', 'icon' => 'fa-hourglass-end', 'label' => 'Pendientes', 'page' => 'pendientes', 'badge' => $totalPendientes],
    ],
    'ANÁLISIS' => [
        ['href' => 'reportes.php', 'icon' => 'fa-chart-bar', 'label' => 'Reportes KPI', 'page' => 'reportes'],
        ['href' => 'mapa_vivo.php', 'icon' => 'fa-map-marked-alt', 'label' => 'Ubicaciones', 'page' => 'mapa'],
        ['href' => 'alertas.php', 'icon' => 'fa-bell', 'label' => 'Alertas', 'page' => 'alertas', 'badge' => $totalAlertasBadge],
    ],
];

// Navegación para Asesores
$nav_asesor = [
    'PRINCIPAL' => [
        ['href' => 'asesor_index.php', 'icon' => 'fa-home', 'label' => 'Mi Dashboard', 'page' => 'dashboard'],
        ['href' => 'mis_clientes.php', 'icon' => 'fa-contacts', 'label' => 'Mis Clientes', 'page' => 'clientes'],
        ['href' => 'mis_tareas.php', 'icon' => 'fa-tasks', 'label' => 'Mis Tareas', 'page' => 'tareas'],
    ],
    'GESTIÓN' => [
        ['href' => 'agregar_cliente.php', 'icon' => 'fa-user-plus', 'label' => 'Nuevo Cliente', 'page' => 'agregar'],
        ['href' => 'mi_perfil.php', 'icon' => 'fa-user-circle', 'label' => 'Mi Perfil', 'page' => 'perfil'],
    ],
];

$nav = $isSupervisor ? $nav_supervisor : ($isAsesor ? $nav_asesor : []);
$user_role = $isSupervisor ? 'Supervisor' : ($isAsesor ? 'Asesor' : 'Usuario');
?>
<div class="col-md-2 sidebar" style="position:sticky;top:0;height:100vh;overflow-y:auto;">
    <div class="brand">
        <i class="fas fa-star"></i>
        <span>Super_IA</span>
    </div>

    <?php foreach ($nav as $section => $items): ?>
        <div class="section-label"><?= $section ?></div>

        <?php foreach ($items as $item): ?>
            <a href="<?= $item['href'] ?>" class="<?= $currentPage === $item['page'] ? 'active' : '' ?>" style="display:flex;align-items:center;gap:11px;">
                <i class="fas <?= $item['icon'] ?>"></i>
                <?= $item['label'] ?>
                <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                    <span class="badge bg-danger ms-auto" style="font-size:10px;padding:3px 7px;border-radius:10px;"><?= $item['badge'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="logout-link">
        <div class="section-label">SESIÓN</div>
        <a href="logout.php" style="color:rgba(252,165,165,.8) !important;">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>
    </div>
</div>

<!-- Core JS for interactive features -->
<script src="admin.js"></script>
