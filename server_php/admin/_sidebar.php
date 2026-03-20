<?php
// _sidebar.php — Sidebar compartido del Panel GeoMove Admin
// Requiere que $currentPage y $totalPendientes estén definidos antes de incluir.
// Ejemplo: $currentPage = 'dashboard'; $totalPendientes = ...;
$currentPage    = $currentPage    ?? '';
$totalPendientes = $totalPendientes ?? 0;
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSecretary = isset($_SESSION['secretary_logged_in']) && $_SESSION['secretary_logged_in'] === true;

$nav = [
    'principal' => [
        ['href' => $isSecretary ? 'panel_cooperativa.php' : 'index.php', 'icon' => 'fa-home', 'label' => 'Dashboard', 'page' => 'dashboard'],
        ['href' => $isSecretary ? 'mapa_coop.php' : 'mapa.php', 'icon' => 'fa-map-marked-alt', 'label' => 'Mapa en vivo', 'page' => 'mapa'],
        ['href' => $isSecretary ? 'viajes_coop.php' : 'viajes.php', 'icon' => 'fa-route', 'label' => 'Historial de Viajes', 'page' => 'viajes'],
    ],
];

if (!$isSecretary) {
    // Calcular badges globales para el administrador
    if (isset($pdo)) {
        $stmtC = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0");
        $gBadgeCond = $stmtC->fetchColumn();
        
        $stmtS = $pdo->query("SELECT COUNT(*) FROM secretarias WHERE verificado = 0");
        $gBadgeSec = $stmtS->fetchColumn();
    } else {
        $gBadgeCond = 0; $gBadgeSec = 0;
    }

    $nav['principal'][] = ['href' => 'pendientes.php','icon' => 'fa-user-clock', 'label' => 'Cond. Pendientes', 'page' => 'pendientes', 'badge' => $gBadgeCond];
    $nav['principal'][] = ['href' => 'activos.php',   'icon' => 'fa-id-badge',  'label' => 'Cond. Activos', 'page' => 'conductores'];
    $nav['principal'][] = ['href' => 'pendientes_secretarias.php','icon' => 'fa-user-tie', 'label' => 'Sec. Pendientes', 'page' => 'secretarias', 'badge' => $gBadgeSec];
    $nav['principal'][] = ['href' => 'secretarias_activas.php', 'icon' => 'fa-briefcase', 'label' => 'Sec. Activas', 'page' => 'secretarias_activas'];
    $nav['principal'][] = ['href' => 'viajes.php',    'icon' => 'fa-route',     'label' => 'Viajes',      'page' => 'viajes'];
    
    $nav['Pasajeros'] = [
        ['href' => 'usuarios.php',  'icon' => 'fa-users',          'label' => 'Usuarios',     'page' => 'usuarios'],
    ];
    $nav['Configuración'] = [
        ['href' => 'tarifas.php',   'icon' => 'fa-tags',           'label' => 'Tarifas',      'page' => 'tarifas'],
        ['href' => 'descuentos.php','icon' => 'fa-ticket-alt',     'label' => 'Descuentos',   'page' => 'descuentos'],
        ['href' => 'soporte.php',   'icon' => 'fa-headset',        'label' => 'Soporte',      'page' => 'soporte'],
        ['href' => 'reportes.php',  'icon' => 'fa-chart-bar',      'label' => 'Reportes',     'page' => 'reportes'],
    ];
}
?>
<div class="col-md-2 sidebar" style="position:sticky;top:0;height:100vh;overflow-y:auto;">
    <div class="brand">
        <i class="fas fa-map-marked-alt"></i>
        <span>GeoMove Admin</span>
    </div>

    <?php foreach ($nav as $section => $items): ?>
        <?php if ($section !== 'principal'): ?>
            <div class="section-label"><?= $section ?></div>
        <?php else: ?>
            <div style="margin-top:10px;"></div>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <a href="<?= $item['href'] ?>" class="<?= $currentPage === $item['page'] ? 'active' : '' ?>">
                <i class="fas <?= $item['icon'] ?>"></i>
                <?= $item['label'] ?>
                <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                    <span class="badge bg-danger ms-auto" style="font-size:10px;padding:3px 7px;border-radius:10px;"><?= $item['badge'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="logout-link">
        <div class="section-label">Cuenta</div>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>
</div>
