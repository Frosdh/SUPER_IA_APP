<?php
// _sidebar.php — Sidebar compartido del Panel GeoMove Admin
// Requiere que $currentPage y $totalPendientes estén definidos antes de incluir.
// Ejemplo: $currentPage = 'dashboard'; $totalPendientes = ...;
$currentPage    = $currentPage    ?? '';
$totalPendientes = $totalPendientes ?? 0;

$nav = [
    'principal' => [
        ['href' => 'index.php',     'icon' => 'fa-home',          'label' => 'Dashboard',    'page' => 'dashboard'],
        ['href' => 'pendientes.php','icon' => 'fa-user-clock',     'label' => 'Pendientes',   'page' => 'pendientes', 'badge' => $totalPendientes],
        ['href' => 'activos.php',   'icon' => 'fa-id-badge',       'label' => 'Conductores',  'page' => 'conductores'],
        ['href' => 'viajes.php',    'icon' => 'fa-route',          'label' => 'Viajes',       'page' => 'viajes'],
        ['href' => 'mapa.php',      'icon' => 'fa-map-marked-alt', 'label' => 'Mapa en vivo', 'page' => 'mapa'],
    ],
    'Pasajeros' => [
        ['href' => 'usuarios.php',  'icon' => 'fa-users',          'label' => 'Usuarios',     'page' => 'usuarios'],
    ],
    'Configuración' => [
        ['href' => 'tarifas.php',   'icon' => 'fa-tags',           'label' => 'Tarifas',      'page' => 'tarifas'],
        ['href' => 'descuentos.php','icon' => 'fa-ticket-alt',     'label' => 'Descuentos',   'page' => 'descuentos'],
        ['href' => 'soporte.php',   'icon' => 'fa-headset',        'label' => 'Soporte',      'page' => 'soporte'],
        ['href' => 'reportes.php',  'icon' => 'fa-chart-bar',      'label' => 'Reportes',     'page' => 'reportes'],
    ],
];
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
