<?php
// TEST: Verificar que encuesta se guarda correctamente
// Acceso: /server_php/admin/test_guardar_encuesta.php

require_once 'db_admin.php';

echo "<h2>TEST: Flujo de Guardado de Encuesta</h2>";

// Verificar tabla encuesta_comercial
try {
    $st = $pdo->query("DESCRIBE encuesta_comercial");
    $cols = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>✅ Tabla encuesta_comercial existe</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th></tr>";
    foreach ($cols as $col) {
        $nulo = $col['Null'] === 'YES' ? 'SÍ' : 'NO';
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$nulo}</td></tr>";
    }
    echo "</table>";
} catch (PDOException $e) {
    echo "<h3>❌ Error verificando tabla: " . $e->getMessage() . "</h3>";
}

// Verificar últimas encuestas guardadas
echo "<h3>Últimas 5 encuestas guardadas:</h3>";
try {
    $st = $pdo->query("SELECT ec.id, ec.tarea_id, ec.cliente_id, t.estado, ec.created_at 
                       FROM encuesta_comercial ec 
                       LEFT JOIN tarea t ON ec.tarea_id = t.id 
                       ORDER BY ec.created_at DESC LIMIT 5");
    $encuestas = $st->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($encuestas)) {
        echo "<p>No hay encuestas guardadas aún.</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID Encuesta</th><th>ID Tarea</th><th>ID Cliente</th><th>Estado Tarea</th><th>Fecha Creación</th></tr>";
        foreach ($encuestas as $enc) {
            echo "<tr>";
            echo "<td>" . substr($enc['id'], 0, 8) . "...</td>";
            echo "<td>" . (substr($enc['tarea_id'], 0, 8) ?? 'NULL') . "...</td>";
            echo "<td>" . (substr($enc['cliente_id'], 0, 8) ?? 'NULL') . "...</td>";
            echo "<td>" . ($enc['estado'] ?? 'NULL') . "</td>";
            echo "<td>" . $enc['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}

// Verificar flujo de guardado
echo "<h3>Validación de Flujo:</h3>";
echo "<ul>";
echo "<li>" . (file_exists('guardar_encuesta.php') ? "✅" : "❌") . " guardar_encuesta.php existe</li>";
echo "<li>" . (file_exists('obtener_encuesta_para_editar.php') ? "✅" : "❌") . " obtener_encuesta_para_editar.php existe</li>";
echo "<li>" . (file_exists('nueva_encuesta.php') ? "✅" : "❌") . " nueva_encuesta.php existe</li>";
echo "</ul>";

echo "<hr>";
echo "<p><strong>Para crear una encuesta:</strong> Accede a <code>nueva_encuesta.php</code></p>";
echo "<p><strong>Para editar una encuesta:</strong> Accede a <code>nueva_encuesta.php?tarea_id=ID_TAREA</code></p>";
