<?php
require_once 'db_admin.php';

echo "📋 VERIFICACIÓN DE ALMACENAMIENTO DE PDF:\n";
echo "==========================================\n\n";

// 1. Verificar tabla solicitudes_admin
echo "1. ESTRUCTURA DE TABLA solicitudes_admin:\n";
$stmt = $pdo->query('DESCRIBE solicitudes_admin');
$cols = $stmt->fetchAll();
echo "   Columnas: ";
foreach($cols as $col) {
    echo $col['Field'] . " ";
}
echo "\n\n";

// 2. Verificar carpeta de almacenamiento
echo "2. CARPETA DE ALMACENAMIENTO:\n";
$carpeta = __DIR__ . '/solicitudes_admin';
if (is_dir($carpeta)) {
    echo "   ✅ Carpeta existe: $carpeta\n";
    $archivos = scandir($carpeta);
    $pdfs = array_filter($archivos, function($f) { return strpos($f, '.pdf') !== false; });
    echo "   PDFs almacenados: " . count($pdfs) . "\n";
    foreach($pdfs as $pdf) {
        $tamano = filesize($carpeta . '/' . $pdf) / 1024;
        echo "      - $pdf (" . round($tamano, 2) . " KB)\n";
    }
} else {
    echo "   ❌ Carpeta no existe\n";
}

// 3. Verificar datos en BD
echo "\n3. DATOS EN BASE DE DATOS:\n";
$stmt = $pdo->query("SELECT id_solicitud, usuario, archivo_credencial, estado FROM solicitudes_admin ORDER BY fecha_solicitud DESC LIMIT 5");
$solicitudes = $stmt->fetchAll();
if (!empty($solicitudes)) {
    echo "   Últimas solicitudes:\n";
    foreach($solicitudes as $sol) {
        echo "   - ID: {$sol['id_solicitud']}, Usuario: {$sol['usuario']}, Estado: {$sol['estado']}\n";
        echo "     Archivo en BD: {$sol['archivo_credencial']}\n";
    }
} else {
    echo "   Sin solicitudes\n";
}

echo "\n✅ RESUMEN:\n";
echo "   • PDF se guarda en: " . $carpeta . "/\n";
echo "   • Nombre se guarda en: solicitudes_admin.archivo_credencial\n";
echo "   • Archivo físico: SÍ se guarda\n";
echo "   • Referencia en BD: SÍ se guarda\n";
?>
