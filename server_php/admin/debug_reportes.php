<?php
// ARCHIVO TEMPORAL DE DIAGNÓSTICO - ELIMINAR DESPUÉS DE USAR
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>🔍 Diagnóstico reportes.php</h2><pre>";

// ── 1. Conexión BD ────────────────────────────────────────────────────
echo "\n✅ PASO 1: Cargando db_admin.php...\n";
try {
    require_once __DIR__ . '/db_admin.php';
    echo "✅ Conexión PDO OK\n";
} catch (Exception $e) {
    echo "❌ ERROR en db_admin.php: " . $e->getMessage() . "\n";
    exit;
}

// ── 2. Tabla configuracion ────────────────────────────────────────────
echo "\n✅ PASO 2: Consultando tabla configuracion...\n";
try {
    $r = $pdo->query("SELECT * FROM configuracion LIMIT 5")->fetchAll();
    echo "✅ Filas encontradas: " . count($r) . "\n";
    foreach ($r as $row) {
        echo "   → clave=" . $row['clave'] . "  valor=" . $row['valor'] . "\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 3. Tabla viajes - columnas ────────────────────────────────────────
echo "\n✅ PASO 3: Verificando columnas de tabla viajes...\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM viajes")->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Columnas viajes: " . implode(', ', $cols) . "\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 4. Tabla conductores - columnas ───────────────────────────────────
echo "\n✅ PASO 4: Verificando columnas de tabla conductores...\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM conductores")->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Columnas conductores: " . implode(', ', $cols) . "\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 5. Tabla usuarios - columnas ──────────────────────────────────────
echo "\n✅ PASO 5: Verificando columnas de tabla usuarios...\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Columnas usuarios: " . implode(', ', $cols) . "\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 6. Query totales ──────────────────────────────────────────────────
echo "\n✅ PASO 6: Query de totales...\n";
try {
    $desde = date('Y-m-01') . ' 00:00:00';
    $hasta = date('Y-m-d')  . ' 23:59:59';
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_viajes,
               SUM(CASE WHEN estado='terminado' THEN 1 ELSE 0 END) AS completados,
               COALESCE(SUM(CASE WHEN estado='terminado' THEN tarifa_total ELSE 0 END), 0) AS ingresos
        FROM viajes WHERE fecha_pedido BETWEEN ? AND ?
    ");
    $stmt->execute([$desde, $hasta]);
    $t = $stmt->fetch();
    echo "✅ total_viajes=" . $t['total_viajes'] . "  completados=" . $t['completados'] . "  ingresos=" . $t['ingresos'] . "\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 7. Query por día ──────────────────────────────────────────────────
echo "\n✅ PASO 7: Query viajes por día...\n";
try {
    $stmt = $pdo->prepare("
        SELECT DATE(fecha_pedido) AS dia, COUNT(*) AS viajes
        FROM viajes WHERE fecha_pedido BETWEEN ? AND ?
        GROUP BY dia ORDER BY dia
    ");
    $stmt->execute([$desde, $hasta]);
    $rows = $stmt->fetchAll();
    echo "✅ Filas por día: " . count($rows) . "\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 8. Query top conductores ──────────────────────────────────────────
echo "\n✅ PASO 8: Query top conductores...\n";
try {
    $stmt = $pdo->prepare("
        SELECT c.nombre, c.telefono, COUNT(*) AS viajes,
               COALESCE(SUM(v.tarifa_total),0) AS total
        FROM viajes v
        JOIN conductores c ON c.id = v.conductor_id
        WHERE v.estado='terminado' AND v.fecha_pedido BETWEEN ? AND ?
        GROUP BY v.conductor_id ORDER BY total DESC LIMIT 10
    ");
    $stmt->execute([$desde, $hasta]);
    $rows = $stmt->fetchAll();
    echo "✅ Conductores top: " . count($rows) . "\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 9. Query viajes lista ─────────────────────────────────────────────
echo "\n✅ PASO 9: Query lista de viajes...\n";
try {
    $stmt = $pdo->prepare("
        SELECT v.id, v.origen_texto, v.destino_texto,
               v.tarifa_total, v.estado, v.fecha_pedido,
               u.nombre AS pasajero, c.nombre AS conductor
        FROM viajes v
        LEFT JOIN usuarios u ON u.id = v.usuario_id
        LEFT JOIN conductores c ON c.id = v.conductor_id
        WHERE v.fecha_pedido BETWEEN ? AND ?
        ORDER BY v.fecha_pedido DESC LIMIT 5
    ");
    $stmt->execute([$desde, $hasta]);
    $rows = $stmt->fetchAll();
    echo "✅ Viajes encontrados: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo "   → id={$r['id']} estado={$r['estado']} tarifa={$r['tarifa_total']}\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ── 10. Query comisión ────────────────────────────────────────────────
echo "\n✅ PASO 10: Query comisión empresa...\n";
try {
    $cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave='comision_empresa'")->fetch();
    if ($cfg) {
        echo "✅ comision_empresa = " . $cfg['valor'] . "\n";
    } else {
        echo "⚠️ No existe clave 'comision_empresa' en configuracion (se usará 20% por defecto)\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n\n🎉 DIAGNÓSTICO COMPLETO\n";
echo "Si todos los pasos dicen ✅, el problema es otro.\n";
echo "Comparte este resultado para continuar.\n";
echo "</pre>";
echo "<br><br><b style='color:red'>⚠️ ELIMINA ESTE ARCHIVO DEL SERVIDOR DESPUÉS DE USARLO</b>";
