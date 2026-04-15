<?php
require_once 'db_admin.php';

echo "========== TEST CARGA DE COOPERATIVAS ==========\n\n";

// Intentar obtener cooperativas de la BD
echo "1. Intentando cargar desde BD...\n";
try {
    $stmt = $pdo->query("
        SELECT DISTINCT id_cooperativa, nombre 
        FROM cooperativas 
        ORDER BY nombre ASC 
        LIMIT 20
    ");
    $cooperativas = $stmt->fetchAll();
    echo "✓ Conexión a BD exitosa\n";
    echo "✓ Cooperativas encontradas en BD: " . count($cooperativas) . "\n";
    
    if (count($cooperativas) > 0) {
        echo "\nLista de Cooperativas:\n";
        foreach ($cooperativas as $i => $coop) {
            echo "  " . ($i+1) . ". [ID: " . $coop['id_cooperativa'] . "] " . $coop['nombre'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "✓ Usando valores por defecto...\n";
    $cooperativas = [
        ['id_cooperativa' => 1, 'nombre' => 'Super_IA - Quito'],
        ['id_cooperativa' => 2, 'nombre' => 'Super_IA - Guayaquil'],
        ['id_cooperativa' => 3, 'nombre' => 'Super_IA - Cuenca'],
        ['id_cooperativa' => 4, 'nombre' => 'Super_IA - Ambato']
    ];
    echo "Total de cooperativas por defecto: " . count($cooperativas) . "\n";
    foreach ($cooperativas as $i => $coop) {
        echo "  " . ($i+1) . ". [ID: " . $coop['id_cooperativa'] . "] " . $coop['nombre'] . "\n";
    }
}

echo "\n========== FIN DE TEST ==========\n";
?>
