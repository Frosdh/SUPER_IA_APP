<?php
// ============================================================
// admin/api_super_ia.php — API para operaciones Super_IA
// Endpoints AJAX para gestión de asesores, clientes, tareas
// ============================================================

header('Content-Type: application/json');

require_once 'db_admin_superIA.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticación
$supervisor_id = $_SESSION['supervisor_id'] ?? null;
if (!$supervisor_id || !isset($_SESSION['supervisor_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Obtener acción
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if (!$action) {
    http_response_code(400);
    echo json_encode(['error' => 'No action specified']);
    exit;
}

try {
    switch ($action) {
        
        // ============================================================
        // GET: Obtener datos
        // ============================================================
        
        case 'get_asesores':
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
            $offset = ($page - 1) * $limit;
            
            $asesores = $dashboard->listAsesores($supervisor_id, $limit, $offset);
            echo json_encode([
                'success' => true,
                'data' => $asesores,
                'page' => $page,
                'limit' => $limit
            ]);
            break;

        case 'get_tareas_hoy':
            $tareas = $dashboard->getTareasHoy($supervisor_id);
            $tareas_por_estado = [];
            foreach ($tareas as $tarea) {
                $estado = $tarea['estado'];
                if (!isset($tareas_por_estado[$estado])) {
                    $tareas_por_estado[$estado] = [];
                }
                $tareas_por_estado[$estado][] = $tarea;
            }
            echo json_encode([
                'success' => true,
                'data' => $tareas_por_estado,
                'total' => count($tareas)
            ]);
            break;

        case 'get_alertas':
            $alertas = $dashboard->getAlertasPendientes($supervisor_id);
            echo json_encode([
                'success' => true,
                'data' => $alertas,
                'total' => count($alertas)
            ]);
            break;

        case 'get_kpis':
            $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : date('n');
            $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');
            $kpis = $dashboard->getKPISupervisor($supervisor_id, $mes, $anio);
            echo json_encode([
                'success' => true,
                'data' => $kpis,
                'periodo' => "$mes/$anio"
            ]);
            break;

        case 'get_ubicaciones_asesores':
            // Obtener ubicaciones en tiempo real de todos los asesores del supervisor
            require_once '../db_config.php';
            $conn->query(
                "CREATE TABLE IF NOT EXISTS asesor_presencia (
                    asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
                    estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $conn->query(
                "ALTER TABLE asesor_presencia
                 MODIFY asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"
            );
            
            $query = "
                SELECT DISTINCT
                    ua.id, ua.asesor_id, ua.latitud, ua.longitud, ua.timestamp,
                    u.nombre as asesor_nombre,
                    COALESCE(ua.precision_m, 0) as precision_m
                FROM ubicacion_asesor ua
                INNER JOIN asesor a ON a.id = ua.asesor_id
                INNER JOIN supervisor s ON s.id = a.supervisor_id
                INNER JOIN usuario u ON u.id = a.usuario_id
                LEFT JOIN asesor_presencia ap ON ap.asesor_id = ua.asesor_id
                WHERE s.usuario_id = ?
                AND ua.timestamp >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                AND ua.latitud IS NOT NULL 
                AND ua.longitud IS NOT NULL
                AND COALESCE(ap.estado, 'conectado') = 'conectado'
                ORDER BY ua.asesor_id DESC, ua.timestamp DESC
            ";
            
            $stmt = $conn->prepare($query);
            $ubicaciones = [];

            if ($stmt) {
                // supervisor_id = usuario.id (char 36) — usar "s", no "i"
                $stmt->bind_param("s", $supervisor_id);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    
                    // Obtener solo la ubicación más reciente de cada asesor
                    $ubicaciones_map = [];
                    while ($row = $result->fetch_assoc()) {
                        $asesor_id = $row['asesor_id'];
                        if (!isset($ubicaciones_map[$asesor_id])) {
                            $ubicaciones_map[$asesor_id] = $row;
                        }
                    }
                    $ubicaciones = array_values($ubicaciones_map);
                }
                $stmt->close();
            }
            
            echo json_encode([
                'success' => true,
                'data' => $ubicaciones,
                'count' => count($ubicaciones)
            ]);
            break;

        // ============================================================
        // POST: Actualizar datos
        // ============================================================

        case 'marcar_alerta_vista':
            $alerta_id = $_POST['alerta_id'] ?? null;
            if (!$alerta_id) {
                throw new Exception('Alerta ID is required');
            }
            
            $success = $dashboard->marcarAlertaVista($alerta_id);
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Alerta marcada como vista' : 'Error actualizando alerta'
            ]);
            break;

        case 'eliminar_asesor':
            $asesor_id = $_POST['asesor_id'] ?? null;
            if (!$asesor_id) {
                throw new Exception('Asesor ID is required');
            }
            
            // Verificar que el asesor pertenece al supervisor
            $query = "
                SELECT a.id FROM asesor a
                WHERE a.id = ? 
                AND a.supervisor_id = (
                    SELECT id FROM supervisor WHERE usuario_id = ?
                )
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            
            $stmt->bind_param("ss", $asesor_id, $supervisor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('Asesor not found or unauthorized');
            }
            
            // Marcar como inactivo (no borrar)
            $update_query = "UPDATE usuario SET activo = 0 WHERE id = (SELECT usuario_id FROM asesor WHERE id = ?)";
            $stmt = $conn->prepare($update_query);
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            
            $stmt->bind_param("s", $asesor_id);
            $success = $stmt->execute();
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Asesor removido correctamente' : 'Error removiendo asesor'
            ]);
            break;

        case 'crear_cliente':
            $asesor_id = $_POST['asesor_id'] ?? null;
            $nombre = $_POST['nombre'] ?? null;
            $cedula = $_POST['cedula'] ?? null;
            $telefono = $_POST['telefono'] ?? null;
            $email = $_POST['email'] ?? null;
            $ciudad = $_POST['ciudad'] ?? null;
            
            if (!$asesor_id || !$nombre) {
                throw new Exception('Asesor ID y Nombre son requeridos');
            }
            
            $id = bin2hex(random_bytes(18)); // UUID like
            $query = "
                INSERT INTO cliente_prospecto 
                (id, nombre, cedula, telefono, email, ciudad, asesor_id, estado, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'prospecto', NOW())
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            
            $stmt->bind_param("sssssss", $id, $nombre, $cedula, $telefono, $email, $ciudad, $asesor_id);
            $success = $stmt->execute();
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Cliente creado exitosamente' : 'Error creando cliente',
                'cliente_id' => $success ? $id : null
            ]);
            break;

        case 'crear_tarea':
            $asesor_id = $_POST['asesor_id'] ?? null;
            $cliente_id = $_POST['cliente_id'] ?? null;
            $tipo_tarea = $_POST['tipo_tarea'] ?? 'prospecto_nuevo';
            $fecha = $_POST['fecha_programada'] ?? date('Y-m-d');
            $hora = $_POST['hora_programada'] ?? '08:00';
            
            if (!$asesor_id) {
                throw new Exception('Asesor ID es requerido');
            }
            
            $id = bin2hex(random_bytes(18));
            $query = "
                INSERT INTO tarea
                (id, asesor_id, cliente_prospecto_id, tipo_tarea, fecha_programada, hora_programada, estado, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'programada', NOW())
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            
            $stmt->bind_param("sssss", $id, $asesor_id, $cliente_id, $tipo_tarea, $fecha);
            $success = $stmt->execute();
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Tarea creada' : 'Error creando tarea',
                'tarea_id' => $success ? $id : null
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found: ' . $action]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
