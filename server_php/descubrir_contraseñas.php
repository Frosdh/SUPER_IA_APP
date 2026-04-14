<?php
// Hash para supervisor_g1 y supervisor_q1: 02423ab2e61297b8262449c93e19be42fb5bbb275860a7d93b1ebdc7b6535ed7

$contraseñas_comunes = [
    'supervisor',
    'supervisor123',
    'Supervisor123',
    'supervisor@2026',
    '123456',
    'password',
    'coac',
    'coac123',
    'finance',
    'finance123',
];

$hash_supervisor = '02423ab2e61297b8262449c93e19be42fb5bbb275860a7d93b1ebdc7b6535ed7';
$hash_admin = '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9';

echo "=== VERIFICANDO CONTRASEÑAS ===\n\n";

foreach ($contraseñas_comunes as $pwd) {
    $hash = hash('sha256', $pwd);
    if ($hash === $hash_supervisor) {
        echo "✅ ENCONTRADO: supervisor_g1 / supervisor_q1 → Contraseña: '$pwd'\n";
    }
    if ($hash === $hash_admin) {
        echo "✅ ENCONTRADO: admin → Contraseña: '$pwd'\n";
    }
}

echo "\nHashes generados:\n";
foreach ($contraseñas_comunes as $pwd) {
    echo "$pwd: " . hash('sha256', $pwd) . "\n";
}
?>
