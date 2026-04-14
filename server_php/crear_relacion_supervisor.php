<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "corporat_radix_copia";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// 1. Verificar si la columna supervisor_id_fk ya existe
$result = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'supervisor_id_fk'");
if ($result->num_rows == 0) {
    echo "Agregando columna supervisor_id_fk...\n";
    $conn->query("ALTER TABLE usuarios ADD COLUMN supervisor_id_fk INT(11) NULL AFTER id_rol_fk");
    echo "✅ Columna agregada\n";
} else {
    echo "La columna supervisor_id_fk ya existe\n";
}

// 2. Asignar supervisores a asesores basado en el prefijo
echo "\n--- Asignando asesores a supervisores ---\n";

// supervisor_g1 (ID 4) → asesor_g1, asesor_g2
$conn->query("UPDATE usuarios SET supervisor_id_fk = 4 WHERE usuario IN ('asesor_g1', 'asesor_g2')");
echo "✅ asesor_g1, asesor_g2 asignados a supervisor_g1\n";

// supervisor_q1 (ID 3) → asesor_q1, asesor_q2
$conn->query("UPDATE usuarios SET supervisor_id_fk = 3 WHERE usuario IN ('asesor_q1', 'asesor_q2')");
echo "✅ asesor_q1, asesor_q2 asignados a supervisor_q1\n";

// 3. Verificar las asignaciones
echo "\n--- Verificando asignaciones ---\n";
$result = $conn->query("
    SELECT u.usuario, u.nombres, r.nombre as rol, s.usuario as supervisor
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    LEFT JOIN usuarios s ON u.supervisor_id_fk = s.id_usuario
    WHERE r.nombre IN ('Asesor', 'Supervisor')
    ORDER BY r.nombre, u.usuario
");

while ($row = $result->fetch_assoc()) {
    if ($row['rol'] === 'Asesor') {
        echo $row['usuario'] . " → Supervisor: " . ($row['supervisor'] ?? 'Sin asignar') . "\n";
    } else {
        echo $row['usuario'] . " (Supervisor)\n";
    }
}

$conn->close();
echo "\n✅ Relación supervisor-asesor configurada correctamente\n";
?>
