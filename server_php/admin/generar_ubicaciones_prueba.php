<?php
// ============================================================
// generar_ubicaciones_prueba.php - Generar datos GPS de prueba
// Crea ubicaciones ficticias para asesores en Ecuador (Quito/Guayaquil area)
// ============================================================

require_once '../db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que es admin/supervisor
$is_allowed = (isset($_SESSION['super_admin_logged_in']) || isset($_SESSION['admin_logged_in']) || isset($_SESSION['supervisor_logged_in']));

if (!$is_allowed) {
    die("Acceso denegado");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar'])) {
    
    try {
        // Obtener supervisor
        $supervisor_id = $_POST['supervisor_id'] ?? null;
        
        if (!$supervisor_id) {
            $error = "Seleccionar un supervisor";
        } else {
            // Obtener asesores del supervisor
            $query = "
                SELECT a.id, u.nombre
                FROM asesor a
                INNER JOIN usuario u ON u.id = a.usuario_id
                WHERE a.supervisor_id = ?
                LIMIT 20
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $supervisor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $asesores = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            if (!$asesores) {
                $error = "No hay asesores para este supervisor";
            } else {
                // Ubicaciones base en Ecuador (Quito y Guayaquil)
                $ubicaciones_base = [
                    // Quito
                    ['latitud' => -0.2193, 'longitud' => -78.5097],
                    ['latitud' => -0.1807, 'longitud' => -78.4678],
                    ['latitud' => -0.2241, 'longitud' => -78.5249],
                    ['latitud' => -0.1929, 'longitud' => -78.5030],
                    // Guayaquil
                    ['latitud' => -2.1894, 'longitud' => -79.8696],
                    ['latitud' => -2.1729, 'longitud' => -79.8558],
                    ['latitud' => -2.2050, 'longitud' => -79.8735],
                ];
                
                $insert_count = 0;
                $delete_count = 0;
                
                // Primero, eliminar ubicaciones antiguas (>24 horas)
                $delete_sql = "DELETE FROM ubicacion_asesor WHERE timestamp < DATE_SUB(NOW(), INTERVAL 25 HOUR)";
                $conn->query($delete_sql);
                $delete_count = $conn->affected_rows;
                
                // Insertar nuevas ubicaciones para cada asesor
                foreach ($asesores as $idx => $asesor) {
                    $asesor_id = $asesor['id'];
                    
                    // Seleccionar ubicación base (rotar entre ellas)
                    $base = $ubicaciones_base[$idx % count($ubicaciones_base)];
                    
                    // Agregar variación aleatoria (±0.01 grados ≈ ±1km)
                    $lat = $base['latitud'] + (rand(-100, 100) / 10000);
                    $lng = $base['longitud'] + (rand(-100, 100) / 10000);
                    $precision = rand(5, 50); // metros
                    $timestamp = date('Y-m-d H:i:s', strtotime("-" . rand(0, 600) . " seconds"));
                    
                    $sql = "
                        INSERT INTO ubicacion_asesor (asesor_id, latitud, longitud, precision_m, timestamp)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            latitud = VALUES(latitud),
                            longitud = VALUES(longitud),
                            precision_m = VALUES(precision_m),
                            timestamp = VALUES(timestamp)
                    ";
                    
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $error .= "Error: " . $conn->error . "<br>";
                        continue;
                    }
                    
                    $stmt->bind_param("iddds", $asesor_id, $lat, $lng, $precision, $timestamp);
                    if ($stmt->execute()) {
                        $insert_count++;
                    }
                    $stmt->close();
                }
                
                $success = "✅ Generadas $insert_count ubicaciones de prueba<br>";
                if ($delete_count > 0) {
                    $success .= "🗑️ Eliminadas $delete_count ubicaciones antiguas";
                }
            }
        }
        
    } catch (Exception $e) {
        $error = "Excepción: " . $e->getMessage();
    }
}

// Obtener lista de supervisores
$supervisores = [];
$query = "
    SELECT s.id, u.nombre
    FROM supervisor s
    INNER JOIN usuario u ON u.id = s.usuario_id
    WHERE u.activo = 1
    ORDER BY u.nombre
";
$result = $conn->query($query);
if ($result) {
    $supervisores = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Ubicaciones de Prueba</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f7fa; padding: 20px; }
        .container { max-width: 600px; margin: 50px auto; }
        .card { box-shadow: 0 4px 16px rgba(0,0,0,.1); border: none; }
        .card-header { background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%); color: white; }
        .btn-primary { background-color: #FBBF24; border-color: #FBBF24; color: #1a0f3d; }
        .btn-primary:hover { background-color: #F59E0B; border-color: #F59E0B; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-header">
            <h5 style="margin: 0;">
                <i class="fas fa-map-pin"></i> Generar Ubicaciones de Prueba GPS
            </h5>
        </div>
        <div class="card-body" style="padding: 30px;">
            
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label for="supervisor_id" class="form-label">
                        <strong>Seleccionar Supervisor:</strong>
                    </label>
                    <select class="form-select" id="supervisor_id" name="supervisor_id" required>
                        <option value="">-- Elegir supervisor --</option>
                        <?php foreach ($supervisores as $sup): ?>
                        <option value="<?= $sup['id'] ?>">
                            <?= htmlspecialchars($sup['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Se generarán ubicaciones para todos sus asesores</small>
                </div>
                
                <div class="mb-3" style="background: #f9f9f9; padding: 15px; border-radius: 8px; border-left: 3px solid #FBBF24;">
                    <h6 style="margin: 0 0 10px;">ℹ️ Información:</h6>
                    <small style="color: #666;">
                        • Se generarán ubicaciones GPS ficticias en Quito y Guayaquil<br>
                        • Cada asesor tendrá una ubicación diferente<br>
                        • Las ubicaciones tienen timestamp reciente<br>
                        • Los datos antiguos (>24h) serán eliminados<br>
                        • Esto es solo para prueba - usar en producción con datos reales
                    </small>
                </div>
                
                <button type="submit" name="generar" class="btn btn-primary btn-lg w-100">
                    <i class="fas fa-bolt"></i> Generar Ubicaciones de Prueba
                </button>
            </form>
            
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center;">
                <a href="mapa_vivo_superIA.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-map"></i> Ver Mapa en Vivo
                </a>
                <a href="supervisor_index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-home"></i> Volver al Dashboard
                </a>
            </div>
            
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
