<?php
// ============================================================
// admin/db_admin_superIA.php — Panel Super_IA Logan
// Funciones y consultas para supervisores, asesores, clientes y KPIs
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Usar db_config.php central (connección MySQLi)
$configPath = __DIR__ . '/../db_config.php';
if (!file_exists($configPath)) {
    die("Error: no se encontró db_config.php");
}
require_once $configPath;

// ============================================================
// CLASE: Super_IA_Dashboard
// ============================================================

class Super_IA_Dashboard {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Obtener datos del supervisor autenticado
     */
    public function getSupervisorData($supervisor_id) {
        $query = "
            SELECT 
                u.id, u.nombre, u.email, u.rol,
                s.id as supervisor_id, s.meta_asesores,
                ja.agencia_id,
                a.nombre as agencia_nombre, a.ciudad as agencia_ciudad
            FROM usuario u
            LEFT JOIN supervisor s ON s.usuario_id = u.id
            LEFT JOIN jefe_agencia ja ON ja.id = s.jefe_agencia_id
            LEFT JOIN agencia a ON a.id = ja.agencia_id
            WHERE u.id = ? AND u.rol = 'supervisor' AND u.activo = 1
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return ['error' => $this->conn->error];
        }
        
        $stmt->bind_param("s", $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc() ?: [];
    }
    
    /**
     * Contar asesores activos del supervisor
     */
    public function countAsesores($supervisor_id) {
        $query = "
            SELECT COUNT(*) as total
            FROM asesor a
            JOIN usuario u ON u.id = a.usuario_id
            WHERE a.supervisor_id = ? AND u.activo = 1
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return 0;
        }
        
        // Get supervisor_id from usuario table if we have usuario_id instead
        $supervisor_row = $this->conn->query("
            SELECT id FROM supervisor WHERE usuario_id = '$supervisor_id' LIMIT 1
        ")->fetch_assoc();
        
        $sup_id = $supervisor_row['id'] ?? $supervisor_id;
        
        $stmt->bind_param("s", $sup_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['total'] ?? 0;
    }
    
    /**
     * Listar asesores del supervisor con nombre y email
     */
    public function listAsesores($supervisor_id, $limit = 50, $offset = 0) {
        $query = "
            SELECT 
                a.id, a.usuario_id, a.meta_tareas_diarias, a.meta_visitas_mes,
                u.nombre, u.email, u.ultimo_login,
                COUNT(DISTINCT cp.id) as num_clientes,
                COUNT(DISTINCT t.id) as num_tareas,
                COUNT(DISTINCT CASE WHEN t.estado = 'completada' THEN t.id END) as tareas_completadas
            FROM asesor a
            JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN cliente_prospecto cp ON cp.asesor_id = a.id
            LEFT JOIN tarea t ON t.asesor_id = a.id
            WHERE a.supervisor_id = ? AND u.activo = 1
            GROUP BY a.id, u.id
            ORDER BY u.nombre ASC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        // Convert to supervisor.id if needed
        $supervisor_row = $this->conn->query("
            SELECT id FROM supervisor WHERE usuario_id = '$supervisor_id' LIMIT 1
        ")->fetch_assoc();
        
        $sup_id = $supervisor_row['id'] ?? $supervisor_id;
        
        $stmt->bind_param("sii", $sup_id, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $asesores = [];
        while ($row = $result->fetch_assoc()) {
            $asesores[] = $row;
        }
        
        return $asesores;
    }
    
    /**
     * Contar clientes/prospectos del equipo del supervisor
     */
    public function countClientesBySupervisor($supervisor_id) {
        $query = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'cliente' THEN 1 ELSE 0 END) as clientes,
                SUM(CASE WHEN estado = 'prospecto' THEN 1 ELSE 0 END) as prospectos
            FROM cliente_prospecto cp
            JOIN asesor a ON a.id = cp.asesor_id
            WHERE a.supervisor_id = (
                SELECT id FROM supervisor WHERE usuario_id = ?
            )
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return ['total' => 0, 'clientes' => 0, 'prospectos' => 0];
        }
        
        $stmt->bind_param("s", $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result;
    }
    
    /**
     * Obtener tareas del día actual por supervisor
     */
    public function getTareasHoy($supervisor_id) {
        $query = "
            SELECT 
                t.id, t.tipo_tarea, t.estado, t.fecha_programada,
                u.nombre as asesor, cp.nombre as cliente,
                a.id as asesor_id
            FROM tarea t
            JOIN asesor a ON a.id = t.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE a.supervisor_id = (
                SELECT id FROM supervisor WHERE usuario_id = ?
            )
            AND CAST(t.fecha_programada AS DATE) = CURDATE()
            ORDER BY t.hora_programada ASC
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param("s", $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tareas = [];
        while ($row = $result->fetch_assoc()) {
            $tareas[] = $row;
        }
        
        return $tareas;
    }
    
    /**
     * Obtener KPIs del supervisor para gráficas
     */
    public function getKPISupervisor($supervisor_id, $mes = null, $anio = null) {
        if (!$mes) $mes = date('n');
        if (!$anio) $anio = date('Y');
        
        $query = "
            SELECT 
                SUM(k.num_prospectos) as total_prospectos,
                SUM(k.num_visitas_frio) as total_visitas,
                SUM(k.num_evaluaciones) as total_evaluaciones,
                SUM(k.num_operaciones_desembolsadas) as total_operaciones,
                AVG(k.pct_cumplimiento_prospectos) as pct_cumplimiento_avg,
                COUNT(DISTINCT k.asesor_id) as num_asesores
            FROM kpi_asesor k
            JOIN asesor a ON a.id = k.asesor_id
            WHERE a.supervisor_id = (
                SELECT id FROM supervisor WHERE usuario_id = ?
            )
            AND k.mes = ? AND k.anio = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param("sii", $supervisor_id, $mes, $anio);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result;
    }
    
    /**
     * Obtener desempeño de cada asesor (para tabla comparativa)
     */
    public function getDesempenoAsesores($supervisor_id, $mes = null, $anio = null) {
        if (!$mes) $mes = date('n');
        if (!$anio) $anio = date('Y');
        
        $query = "
            SELECT 
                u.nombre as asesor,
                k.num_prospectos,
                k.num_visitas_frio,
                k.num_evaluaciones,
                k.num_operaciones_desembolsadas,
                k.pct_cumplimiento_prospectos,
                k.pct_cumplimiento_visitas,
                k.tiempo_prospeccion_desembolso,
                k.comparacion_prospectos,
                k.comparacion_visitas
            FROM kpi_asesor k
            JOIN asesor a ON a.id = k.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            WHERE a.supervisor_id = (
                SELECT id FROM supervisor WHERE usuario_id = ?
            )
            AND k.mes = ? AND k.anio = ?
            ORDER BY k.pct_cumplimiento_prospectos DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param("sii", $supervisor_id, $mes, $anio);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Obtener alertas de modificaciones sin ver
     */
    public function getAlertasPendientes($supervisor_id) {
        $query = "
            SELECT 
                am.id, am.tarea_id, am.campo_modificado,
                am.valor_anterior, am.valor_nuevo, am.created_at,
                u.nombre as asesor
            FROM alerta_modificacion am
            JOIN asesor a ON a.id = am.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            WHERE am.supervisor_id = (
                SELECT id FROM supervisor WHERE usuario_id = ?
            )
            AND am.vista_supervisor = 0
            ORDER BY am.created_at DESC
            LIMIT 10
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param("s", $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $alertas = [];
        while ($row = $result->fetch_assoc()) {
            $alertas[] = $row;
        }
        
        return $alertas;
    }
    
    /**
     * Marcar alerta como vista
     */
    public function marcarAlertaVista($alerta_id) {
        $query = "
            UPDATE alerta_modificacion 
            SET vista_supervisor = 1, vista_at = NOW()
            WHERE id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("s", $alerta_id);
        return $stmt->execute();
    }
    
    /**
     * Obtener datos de penetración de mercado
     */
    public function getPenetracionMercado($supervisor_id, $mes = null, $anio = null) {
        if (!$mes) $mes = date('n');
        if (!$anio) $anio = date('Y');
        
        $query = "
            SELECT 
                rp.pct_cuenta_ahorro, rp.pct_cuenta_corriente, rp.pct_inversiones,
                rp.pct_interes_credito, rp.pct_ya_clientes, rp.total_prospectos
            FROM reporte_penetracion rp
            WHERE rp.supervisor_id = (
                SELECT id FROM supervisor WHERE usuario_id = ?
            )
            AND rp.mes = ? AND rp.anio = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param("sii", $supervisor_id, $mes, $anio);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result ?? [];
    }
}

// Instanciar para uso en las páginas
$dashboard = new Super_IA_Dashboard($conn);

?>
