<?php
// ============================================================
// administrador_index.php — Dashboard Administrador Total SUPER_IA
// Acceso completo: todo lo de supervisor + todo lo de gerente
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

// ── Auth: acepta admin_logged_in, super_admin_logged_in y administrador_logged_in ──
$is_admin      = isset($_SESSION['admin_logged_in'])          && $_SESSION['admin_logged_in']          === true;
$is_super      = isset($_SESSION['super_admin_logged_in'])    && $_SESSION['super_admin_logged_in']    === true;
$is_adm        = isset($_SESSION['administrador_logged_in'])  && $_SESSION['administrador_logged_in']  === true;

if (!$is_admin && !$is_super && !$is_adm) {
    header('Location: login.php?role=administrador');
    exit;
}

$admin_nombre = $_SESSION['administrador_nombre']
    ?? $_SESSION['super_admin_nombre']
    ?? $_SESSION['admin_nombre']
    ?? 'Administrador';
$admin_email  = $_SESSION['administrador_email']
    ?? $_SESSION['super_admin_email']
    ?? $_SESSION['admin_email']
    ?? '';

// ── Bancos/cooperativas para el combobox de búsqueda ─────────
$bancos_dash = [];
try {
    $bancos_dash = $pdo->query("SELECT id, nombre FROM unidad_bancaria ORDER BY nombre ASC")->fetchAll();
} catch (Throwable $e) {
    $bancos_dash = [];
}
$bancos_dash_ids = array_map(fn($b) => (string)$b['id'], $bancos_dash);

$banco_filtro = trim($_GET['banco_filtro'] ?? '');
if ($banco_filtro !== '' && !in_array($banco_filtro, $bancos_dash_ids, true)) {
    $banco_filtro = '';
}
$nombre_banco_filtro = '';
foreach ($bancos_dash as $b) { if ($b['id'] === $banco_filtro) { $nombre_banco_filtro = $b['nombre']; break; } }

// ── Resolver todos los asesor.id que aplican: todo el sistema, o
//    solo los del banco/cooperativa elegido en el combobox. Todas las
//    consultas de más abajo filtran por "asesor_id IN (...)" cuando
//    hay un banco seleccionado.
$asesor_ids_dashboard = [];
try {
    $sqlAseDashAdm = "
        SELECT a.id
        FROM asesor a
        JOIN usuario au ON au.id = a.usuario_id
        LEFT JOIN supervisor sv_da ON sv_da.id = a.supervisor_id
        LEFT JOIN jefe_agencia ja_da ON ja_da.id = sv_da.jefe_agencia_id
        LEFT JOIN agencia ag_da ON ag_da.id = ja_da.agencia_id
        WHERE au.activo = 1
    ";
    $paramsAseDashAdm = [];
    if ($banco_filtro !== '') {
        $sqlAseDashAdm .= " AND ag_da.unidad_bancaria_id = ?";
        $paramsAseDashAdm[] = $banco_filtro;
    }
    $stAseDashAdm = $pdo->prepare($sqlAseDashAdm);
    $stAseDashAdm->execute($paramsAseDashAdm);
    $asesor_ids_dashboard = $stAseDashAdm->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $asesor_ids_dashboard = [];
}
$hay_filtro_banco = ($banco_filtro !== '');

// ══════════════════════════════════════════════════════════════
// MÉTRICAS PRINCIPALES
// ══════════════════════════════════════════════════════════════
$mesI = date('Y-m-01');
$mesF = date('Y-m-t');

// Contadores rápidos
$total_usuarios       = 0;
$total_asesores       = 0;
$total_supervisores   = 0;
$total_clientes       = 0;
$clientes_activos     = 0;
$tareas_hoy           = 0;
$tareas_completadas   = 0;
$tareas_postergadas   = 0;
$alertas_pendientes   = 0;
$solicitudes_pend     = 0;
$creditos_mes         = 0;
$monto_aprobado_mes   = 0.0;
$fichas_pendientes    = 0;
$encuestas_mes        = 0;

$phAdm = !empty($asesor_ids_dashboard) ? implode(',', array_fill(0, count($asesor_ids_dashboard), '?')) : '';

try { $total_usuarios     = (int)$pdo->query("SELECT COUNT(*) FROM usuario WHERE activo=1")->fetchColumn(); } catch(Exception $e){}
try {
    if ($hay_filtro_banco) {
        $total_asesores = count($asesor_ids_dashboard);
    } else {
        $total_asesores = (int)$pdo->query("SELECT COUNT(*) FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE u.activo=1")->fetchColumn();
    }
} catch(Exception $e){}
try {
    if ($hay_filtro_banco) {
        $stTS = $pdo->prepare("
            SELECT COUNT(DISTINCT sv.id) FROM supervisor sv
            JOIN usuario u ON u.id=sv.usuario_id
            JOIN jefe_agencia ja ON ja.id=sv.jefe_agencia_id
            JOIN agencia ag ON ag.id=ja.agencia_id
            WHERE u.activo=1 AND ag.unidad_bancaria_id = ?
        ");
        $stTS->execute([$banco_filtro]);
        $total_supervisores = (int)$stTS->fetchColumn();
    } else {
        $total_supervisores = (int)$pdo->query("SELECT COUNT(*) FROM supervisor s JOIN usuario u ON u.id=s.usuario_id WHERE u.activo=1")->fetchColumn();
    }
} catch(Exception $e){}
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $r = $pdo->prepare("SELECT COUNT(*) as tot, SUM(estado!='descartado') as act FROM cliente_prospecto WHERE asesor_id IN ($phAdm)");
        $r->execute($asesor_ids_dashboard);
        $r = $r->fetch();
    } elseif ($hay_filtro_banco) {
        $r = ['tot' => 0, 'act' => 0];
    } else {
        $r = $pdo->query("SELECT COUNT(*) as tot, SUM(estado!='descartado') as act FROM cliente_prospecto")->fetch();
    }
    $total_clientes   = (int)($r['tot'] ?? 0);
    $clientes_activos = (int)($r['act'] ?? 0);
} catch(Exception $e){}
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $r = $pdo->prepare("SELECT COUNT(*) as tot, SUM(estado='completada') as comp, SUM(estado='postergada') as post FROM tarea WHERE asesor_id IN ($phAdm) AND fecha_programada=CURDATE()");
        $r->execute($asesor_ids_dashboard);
        $r = $r->fetch();
    } elseif ($hay_filtro_banco) {
        $r = ['tot' => 0, 'comp' => 0, 'post' => 0];
    } else {
        $r = $pdo->query("SELECT COUNT(*) as tot, SUM(estado='completada') as comp, SUM(estado='postergada') as post FROM tarea WHERE fecha_programada=CURDATE()")->fetch();
    }
    $tareas_hoy          = (int)($r['tot']  ?? 0);
    $tareas_completadas  = (int)($r['comp'] ?? 0);
    $tareas_postergadas  = (int)($r['post'] ?? 0);
} catch(Exception $e){}
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE asesor_id IN ($phAdm) AND vista_supervisor=0");
        $st->execute($asesor_ids_dashboard);
        $alertas_pendientes = (int)$st->fetchColumn();
    } elseif ($hay_filtro_banco) {
        $alertas_pendientes = 0;
    } else {
        $alertas_pendientes = (int)$pdo->query("SELECT COUNT(*) FROM alerta_modificacion WHERE vista_supervisor=0")->fetchColumn();
    }
} catch(Exception $e){}
try { $solicitudes_pend   = (int)$pdo->query("SELECT COUNT(*) FROM solicitud_registro WHERE estado='pendiente'")->fetchColumn(); } catch(Exception $e){}
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $r = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(monto_aprobado),0) as monto FROM credito_proceso WHERE asesor_id IN ($phAdm) AND estado_credito IN ('aprobado','desembolsado') AND DATE(updated_at) BETWEEN ? AND ?");
        $r->execute(array_merge($asesor_ids_dashboard, [$mesI, $mesF]));
        $row = $r->fetch();
    } elseif ($hay_filtro_banco) {
        $row = ['cnt' => 0, 'monto' => 0];
    } else {
        $r = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(monto_aprobado),0) as monto FROM credito_proceso WHERE estado_credito IN ('aprobado','desembolsado') AND DATE(updated_at) BETWEEN ? AND ?");
        $r->execute([$mesI, $mesF]);
        $row = $r->fetch();
    }
    $creditos_mes       = (int)($row['cnt']   ?? 0);
    $monto_aprobado_mes = (float)($row['monto'] ?? 0);
} catch(Exception $e){}
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ficha_producto WHERE asesor_id IN ($phAdm) AND estado_revision='pendiente'");
        $st->execute($asesor_ids_dashboard);
        $fichas_pendientes = (int)$st->fetchColumn();
    } elseif ($hay_filtro_banco) {
        $fichas_pendientes = 0;
    } else {
        $fichas_pendientes  = (int)$pdo->query("SELECT COUNT(*) FROM ficha_producto WHERE estado_revision='pendiente'")->fetchColumn();
    }
} catch(Exception $e){}
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $r = $pdo->prepare("SELECT COUNT(*) as cnt FROM (
            SELECT t.id FROM encuesta_comercial ec JOIN tarea t ON t.id=ec.tarea_id WHERE t.asesor_id IN ($phAdm) AND DATE(t.fecha_realizada) BETWEEN ? AND ?
            UNION ALL
            SELECT t2.id FROM encuesta_crediticia ecr JOIN tarea t2 ON t2.id=ecr.tarea_id WHERE t2.asesor_id IN ($phAdm) AND DATE(t2.fecha_realizada) BETWEEN ? AND ?
        ) x");
        $r->execute(array_merge($asesor_ids_dashboard, [$mesI,$mesF], $asesor_ids_dashboard, [$mesI,$mesF]));
        $encuestas_mes = (int)($r->fetch()['cnt'] ?? 0);
    } elseif ($hay_filtro_banco) {
        $encuestas_mes = 0;
    } else {
        $r = $pdo->prepare("SELECT COUNT(*) as cnt FROM (
            SELECT t.id FROM encuesta_comercial ec JOIN tarea t ON t.id=ec.tarea_id WHERE DATE(t.fecha_realizada) BETWEEN ? AND ?
            UNION ALL
            SELECT t2.id FROM encuesta_crediticia ecr JOIN tarea t2 ON t2.id=ecr.tarea_id WHERE DATE(t2.fecha_realizada) BETWEEN ? AND ?
        ) x");
        $r->execute([$mesI,$mesF,$mesI,$mesF]);
        $encuestas_mes = (int)($r->fetch()['cnt'] ?? 0);
    }
} catch(Exception $e){}

$pct_tareas = $tareas_hoy > 0 ? round(100 * $tareas_completadas / $tareas_hoy) : 0;

// ── Top 5 asesores del mes ─────────────────────────────────────
$top_asesores = [];
try {
    $sqlTop = "
        SELECT u.nombre, COUNT(t.id) as total,
               SUM(t.estado='completada') as comp,
               ROUND(100*SUM(t.estado='completada')/NULLIF(COUNT(t.id),0),1) as pct
        FROM asesor a
        JOIN usuario u ON u.id=a.usuario_id
        LEFT JOIN tarea t ON t.asesor_id=a.id AND t.fecha_programada BETWEEN ? AND ?
    ";
    $paramsTop = [$mesI, $mesF];
    if ($hay_filtro_banco && $phAdm !== '') {
        $sqlTop .= " WHERE a.id IN ($phAdm)";
        $paramsTop = array_merge($paramsTop, $asesor_ids_dashboard);
    } elseif ($hay_filtro_banco) {
        $sqlTop .= " WHERE 1=0";
    }
    $sqlTop .= " GROUP BY a.id, u.nombre ORDER BY comp DESC LIMIT 5";
    $top_asesores = $pdo->prepare($sqlTop);
    $top_asesores->execute($paramsTop);
    $top_asesores = $top_asesores->fetchAll();
} catch(Exception $e){}

// ── Pipeline crédito por estado ───────────────────────────────
$pipeline = [];
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $st = $pdo->prepare("
            SELECT estado_credito, COUNT(*) as total,
                   COALESCE(SUM(monto_aprobado),0) as monto
            FROM credito_proceso
            WHERE asesor_id IN ($phAdm)
            GROUP BY estado_credito
            ORDER BY FIELD(estado_credito,'prospectado','entrevista_venta','levantamiento','solicitud','analisis','aprobado','desembolsado','rechazado','recuperacion')
        ");
        $st->execute($asesor_ids_dashboard);
        $pipeline = $st->fetchAll();
    } elseif (!$hay_filtro_banco) {
        $pipeline = $pdo->query("
            SELECT estado_credito, COUNT(*) as total,
                   COALESCE(SUM(monto_aprobado),0) as monto
            FROM credito_proceso
            GROUP BY estado_credito
            ORDER BY FIELD(estado_credito,'prospectado','entrevista_venta','levantamiento','solicitud','analisis','aprobado','desembolsado','rechazado','recuperacion')
        ")->fetchAll();
    }
} catch(Exception $e){}

// ── Alertas recientes ─────────────────────────────────────────
$alertas_recientes = [];
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $st = $pdo->prepare("
            SELECT am.id, am.created_at, u.nombre as asesor, am.campo_modificado, am.valor_nuevo, am.vista_supervisor
            FROM alerta_modificacion am
            JOIN asesor a ON a.id=am.asesor_id
            JOIN usuario u ON u.id=a.usuario_id
            WHERE am.asesor_id IN ($phAdm)
            ORDER BY am.created_at DESC LIMIT 8
        ");
        $st->execute($asesor_ids_dashboard);
        $alertas_recientes = $st->fetchAll();
    } elseif (!$hay_filtro_banco) {
        $alertas_recientes = $pdo->query("
            SELECT am.id, am.created_at, u.nombre as asesor, am.campo_modificado, am.valor_nuevo, am.vista_supervisor
            FROM alerta_modificacion am
            JOIN asesor a ON a.id=am.asesor_id
            JOIN usuario u ON u.id=a.usuario_id
            ORDER BY am.created_at DESC LIMIT 8
        ")->fetchAll();
    }
} catch(Exception $e){}

// ── Solicitudes pendientes recientes ─────────────────────────
$solicitudes_recientes = [];
try {
    $solicitudes_recientes = $pdo->query("
        SELECT sr.id, sr.rol_solicitado, sr.created_at, u.nombre, u.email
        FROM solicitud_registro sr JOIN usuario u ON u.id=sr.usuario_id
        WHERE sr.estado='pendiente'
        ORDER BY sr.created_at DESC LIMIT 6
    ")->fetchAll();
} catch(Exception $e){}

// ── Tareas por tipo hoy ──────────────────────────────────────
$tareas_por_tipo = [];
try {
    if ($hay_filtro_banco && $phAdm !== '') {
        $st = $pdo->prepare("
            SELECT tipo_tarea, COUNT(*) as total, SUM(estado='completada') as comp
            FROM tarea WHERE asesor_id IN ($phAdm) AND fecha_programada=CURDATE()
            GROUP BY tipo_tarea ORDER BY total DESC LIMIT 7
        ");
        $st->execute($asesor_ids_dashboard);
        $tareas_por_tipo = $st->fetchAll();
    } elseif (!$hay_filtro_banco) {
        $tareas_por_tipo = $pdo->query("
            SELECT tipo_tarea, COUNT(*) as total, SUM(estado='completada') as comp
            FROM tarea WHERE fecha_programada=CURDATE()
            GROUP BY tipo_tarea ORDER BY total DESC LIMIT 7
        ")->fetchAll();
    }
} catch(Exception $e){}

// ══════════════════════════════════════════════════════════════
// KPIs de tacómetro (mismo cálculo que el dashboard del Supervisor),
// aplicados sobre $asesor_ids_dashboard (todo el sistema, o solo el
// banco/cooperativa elegido en el combobox de arriba).
// ══════════════════════════════════════════════════════════════
$periodo_inicio = $mesI;
$periodo_fin    = $mesF;
$ops_aprobadas  = $creditos_mes; // créditos aprobados/desembolsados del mes (ya calculado arriba)
$kpi_dash = [
    'actividad_pct' => $pct_tareas,
    'actividad_realizadas' => $tareas_completadas,
    'actividad_total' => $tareas_hoy,
    'penetracion_pct' => 0,
    'penetracion_clientes' => 0,
    'penetracion_visitas' => 0,
    'interes_pct' => 0,
    'interes_si' => 0,
    'interes_total' => 0,
    'prospeccion_pct' => 0,
    'prospeccion_avance' => 0,
    'prospeccion_meta' => 0,
    'levantamiento_pct' => 0,
    'levantamientos' => 0,
    'interesados' => 0,
    'frio_pct' => 0,
    'frio_visitas' => 0,
    'recuperacion_pct' => 0,
    'recuperaciones' => 0,
    'eficiencia_pct' => 0,
    'postventa_pct' => 0,
    'operaciones_total' => $creditos_mes,
    'operaciones_monto' => $monto_aprobado_mes,
];

if (!empty($asesor_ids_dashboard)) {
    try {
        $phDashAdm = implode(',', array_fill(0, count($asesor_ids_dashboard), '?'));
        $paramsPeriodoAdm = array_merge($asesor_ids_dashboard, [$periodo_inicio, $periodo_fin]);

        $st = $pdo->prepare("SELECT COUNT(t.id) as visitas,
                   SUM(CASE WHEN (ec.p2_es_cliente = 1 OR cp.estado = 'cliente') THEN 1 ELSE 0 END) as clientes
            FROM tarea t
            LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE t.asesor_id IN ($phDashAdm) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodoAdm);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi_dash['penetracion_visitas'] = (int)($r['visitas'] ?? 0);
        $kpi_dash['penetracion_clientes'] = (int)($r['clientes'] ?? 0);
        $kpi_dash['penetracion_pct'] = $kpi_dash['penetracion_visitas'] > 0 ? round($kpi_dash['penetracion_clientes'] * 100 / $kpi_dash['penetracion_visitas'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(ec.id) as total,
                   SUM(CASE WHEN ec.interes_conocer_productos = 1 THEN 1 ELSE 0 END) as si
            FROM encuesta_comercial ec
            JOIN tarea t ON t.id = ec.tarea_id
            WHERE t.asesor_id IN ($phDashAdm) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodoAdm);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi_dash['interes_total'] = (int)($r['total'] ?? 0);
        $kpi_dash['interes_si'] = (int)($r['si'] ?? 0);
        $kpi_dash['interes_pct'] = $kpi_dash['interes_total'] > 0 ? round($kpi_dash['interes_si'] * 100 / $kpi_dash['interes_total'], 1) : 0;

        $st = $pdo->prepare("SELECT COALESCE(SUM(meta_visitas_mes), 0) FROM asesor WHERE id IN ($phDashAdm)");
        $st->execute($asesor_ids_dashboard);
        $kpi_dash['prospeccion_meta'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id IN ($phDashAdm) AND estado = 'completada' AND DATE(COALESCE(fecha_realizada,fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodoAdm);
        $kpi_dash['prospeccion_avance'] = (int)$st->fetchColumn();
        $kpi_dash['prospeccion_pct'] = $kpi_dash['prospeccion_meta'] > 0 ? round($kpi_dash['prospeccion_avance'] * 100 / $kpi_dash['prospeccion_meta'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id IN ($phDashAdm) AND t.estado = 'completada' AND t.tipo_tarea = 'levantamiento' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodoAdm);
        $kpi_dash['levantamientos'] = (int)$st->fetchColumn();
        $kpi_dash['interesados'] = $kpi_dash['interes_si'];
        $kpi_dash['levantamiento_pct'] = $kpi_dash['interesados'] > 0 ? round($kpi_dash['levantamientos'] * 100 / $kpi_dash['interesados'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id)
            FROM tarea t
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE t.asesor_id IN ($phDashAdm) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?
              AND (t.tipo_tarea = 'visita_frio' OR cp.origen_prospecto = 'frio')");
        $st->execute($paramsPeriodoAdm);
        $kpi_dash['frio_visitas'] = (int)$st->fetchColumn();
        $kpi_dash['frio_pct'] = $kpi_dash['prospeccion_avance'] > 0 ? round($kpi_dash['frio_visitas'] * 100 / $kpi_dash['prospeccion_avance'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id)
            FROM tarea t
            WHERE t.asesor_id IN ($phDashAdm) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?
              AND (t.tipo_tarea = 'recuperacion' OR t.tipo_tarea LIKE '%recupera%')");
        $st->execute($paramsPeriodoAdm);
        $kpi_dash['recuperaciones'] = (int)$st->fetchColumn();
        $kpi_dash['recuperacion_pct'] = $kpi_dash['prospeccion_avance'] > 0 ? round($kpi_dash['recuperaciones'] * 100 / $kpi_dash['prospeccion_avance'], 1) : 0;
        $kpi_dash['eficiencia_pct'] = $kpi_dash['penetracion_visitas'] > 0 ? round($kpi_dash['interes_si'] * 100 / $kpi_dash['penetracion_visitas'], 1) : 0;
        $kpi_dash['postventa_pct'] = $clientes_activos > 0 ? round(min($ops_aprobadas, $clientes_activos) * 100 / $clientes_activos, 1) : 0;
    } catch (Throwable $e) {
        error_log('Dashboard Administrador KPI resumen: ' . $e->getMessage());
    }
}

$currentPage = 'admin_dashboard';
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SUPER_IA — Panel Administrador</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="js/cooperativa_buscador.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0b1120;--surface:#111827;--surface2:#1a2540;--surface3:#1e2d4a;
  --border:#1f3050;--text:#f0f4ff;--muted:#8a9ab8;--faint:#4a5f7a;
  --accent:#6366f1;--accent2:#818cf8;
  --purple:#8b5cf6;--cyan:#06b6d4;--green:#10b981;--amber:#f59e0b;
  --red:#ef4444;--pink:#ec4899;
  --sidebar-w:230px;
}
html,body{height:100%;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ─── Layout ──────────────────────── */
.layout{display:flex;height:100vh;overflow:hidden}

/* ─── Sidebar ─────────────────────── */
.sidebar{
  width:var(--sidebar-w);min-width:var(--sidebar-w);
  background:linear-gradient(180deg,#080e1a 0%,#0d1630 100%);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  overflow-y:auto;z-index:100;
  scrollbar-width:thin;scrollbar-color:var(--border) transparent;
}
.sidebar-brand{
  padding:22px 20px 18px;
  display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--border);
}
.brand-icon{
  width:38px;height:38px;border-radius:10px;
  background:linear-gradient(135deg,var(--accent),var(--purple));
  display:grid;place-items:center;font-size:18px;flex-shrink:0;
}
.brand-name{font-weight:800;font-size:16px;letter-spacing:.5px}
.brand-tag{font-size:10px;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
.sidebar-section{padding:16px 12px 4px;font-size:10px;font-weight:700;letter-spacing:1.5px;color:var(--faint);text-transform:uppercase}
.nav-link-adm{
  display:flex;align-items:center;gap:10px;
  padding:9px 14px;margin:1px 8px;border-radius:9px;
  color:var(--muted);font-size:13px;font-weight:500;
  text-decoration:none;transition:.18s ease;
  position:relative;
}
.nav-link-adm:hover{background:rgba(99,102,241,.08);color:var(--text)}
.nav-link-adm.active{background:linear-gradient(90deg,rgba(99,102,241,.18),rgba(139,92,246,.08));color:var(--accent2);border-left:2px solid var(--accent)}
.nav-link-adm .ico{width:18px;text-align:center;font-size:14px;flex-shrink:0}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700}
.sidebar-user{
  margin-top:auto;padding:16px;border-top:1px solid var(--border);
  display:flex;align-items:center;gap:10px;
}
.avatar{
  width:36px;height:36px;border-radius:10px;
  background:linear-gradient(135deg,var(--accent),var(--purple));
  display:grid;place-items:center;font-size:14px;font-weight:700;flex-shrink:0;
}
.user-name{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:10px;color:var(--accent2)}

/* ─── Main ────────────────────────── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{
  padding:0 28px;height:58px;min-height:58px;
  display:flex;align-items:center;justify-content:space-between;
  background:rgba(8,14,26,.8);backdrop-filter:blur(10px);
  border-bottom:1px solid var(--border);
}
.topbar-title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.topbar-actions{display:flex;align-items:center;gap:10px}
.btn-adm{
  padding:7px 14px;border-radius:8px;border:1px solid var(--border);
  background:transparent;color:var(--muted);font-size:12px;font-weight:600;
  cursor:pointer;transition:.15s;text-decoration:none;display:flex;align-items:center;gap:6px;
}
.btn-adm:hover{border-color:var(--accent);color:var(--accent)}
.btn-adm.danger:hover{border-color:var(--red);color:var(--red)}
.content{flex:1;overflow-y:auto;padding:24px 28px 32px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}

/* ─── Cards ───────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.kpi-card{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:18px 20px;position:relative;overflow:hidden;
}
.kpi-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--c,var(--accent));
}
.kpi-label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.kpi-val{font-size:28px;font-weight:800;line-height:1;margin-bottom:4px}
.kpi-sub{font-size:12px;color:var(--muted)}
.kpi-icon{position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:28px;opacity:.1}

.panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.panel-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-title{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px}
.panel-body{padding:20px}
.panel-link{font-size:12px;color:var(--accent);text-decoration:none;font-weight:600}
.panel-link:hover{color:var(--accent2)}

/* ─── Progress bar ────────────────── */
.prog-wrap{background:rgba(255,255,255,.06);border-radius:20px;height:6px;overflow:hidden}
.prog-fill{height:100%;border-radius:20px;background:var(--c,var(--accent));transition:.4s ease}

/* ─── Table ───────────────────────── */
.adm-table{width:100%;border-collapse:collapse;font-size:13px}
.adm-table th{padding:8px 12px;color:var(--faint);font-size:10px;letter-spacing:.8px;text-transform:uppercase;font-weight:700;border-bottom:1px solid var(--border)}
.adm-table td{padding:10px 12px;border-bottom:1px solid rgba(31,48,80,.5);vertical-align:middle}
.adm-table tr:last-child td{border-bottom:none}
.adm-table tr:hover td{background:rgba(99,102,241,.04)}

/* ─── Badge / pill ────────────────── */
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700}
.pill-green {background:rgba(16,185,129,.15);color:#34d399}
.pill-amber {background:rgba(245,158,11,.15);color:#fbbf24}
.pill-red   {background:rgba(239,68,68,.15);color:#f87171}
.pill-blue  {background:rgba(99,102,241,.15);color:#818cf8}
.pill-cyan  {background:rgba(6,182,212,.15);color:#22d3ee}
.pill-muted {background:rgba(148,163,184,.1);color:#94a3b8}

/* ─── Alert dot ───────────────────── */
.alert-dot{width:8px;height:8px;border-radius:50%;background:var(--amber);display:inline-block;margin-right:6px;animation:pulse 1.8s ease infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* ─── Grid layout main ────────────── */
.row-grid{display:grid;gap:16px}
.g-2{grid-template-columns:1fr 1fr}
.g-3{grid-template-columns:2fr 1fr}

/* ─── Pipeline funnel ─────────────── */
.funnel-item{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.funnel-label{font-size:12px;color:var(--muted);width:140px;flex-shrink:0;text-transform:capitalize}
.funnel-bar-wrap{flex:1;background:rgba(255,255,255,.05);border-radius:4px;height:8px;overflow:hidden}
.funnel-bar{height:100%;border-radius:4px}
.funnel-count{font-size:12px;font-weight:700;color:var(--text);min-width:28px;text-align:right}

/* ─── Filtro banco/cooperativa ────── */
.dash-banco-bar{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:14px 18px;margin-bottom:20px;
  display:flex;align-items:center;gap:14px;flex-wrap:wrap;
}
.dash-banco-bar label{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);white-space:nowrap}
.coop-buscador-wrap{position:relative;flex:1;min-width:260px;max-width:420px}
.coop-buscador-wrap input.form-control{
  background:var(--surface2);border:1px solid var(--border);color:var(--text);
  border-radius:9px;padding:9px 30px 9px 12px;font-size:13.5px;
}
.coop-buscador-wrap input.form-control:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.coop-buscador-clear{
  position:absolute;right:9px;top:50%;transform:translateY(-50%);
  border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:13px;padding:4px;display:none;
}
.coop-buscador-clear:hover{color:var(--red)}
.coop-buscador-clear.show{display:block}
.coop-buscador-list{
  display:none;position:absolute;top:100%;left:0;right:0;z-index:50;
  max-height:260px;overflow-y:auto;background:var(--surface2);border:1.5px solid var(--border);
  border-radius:10px;margin-top:6px;box-shadow:0 12px 28px rgba(0,0,0,.4);
}
.coop-buscador-item{padding:9px 14px;font-size:13.5px;color:var(--text);cursor:pointer;border-bottom:1px solid var(--border)}
.coop-buscador-item:last-child{border-bottom:none}
.coop-buscador-item:hover{background:rgba(99,102,241,.14)}
.coop-buscador-empty{padding:10px 14px;font-size:12.5px;color:var(--muted);font-style:italic}
.dash-banco-tag{
  display:inline-flex;align-items:center;gap:6px;background:rgba(245,158,11,.1);color:#fbbf24;
  border:1px solid rgba(245,158,11,.3);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;
}
.dash-banco-tag.all{background:rgba(99,102,241,.1);color:var(--accent2);border-color:rgba(99,102,241,.3)}

/* ─── KPI Gauges (estilo Supervisor) ─── */
.kpi-section{
  background:linear-gradient(155deg,#060f1d 0%,#0b1f3a 45%,#0f2d55 100%);
  border-radius:22px;border:1px solid rgba(99,102,241,.2);
  padding:22px 20px 24px;margin-bottom:24px;position:relative;overflow:hidden;
}
.kpi-section::before{
  content:'';position:absolute;inset:0;
  background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(255,255,255,.016) 3px,rgba(255,255,255,.016) 4px);
  pointer-events:none;animation:scanMove 10s linear infinite;
}
@keyframes scanMove{from{background-position:0 0}to{background-position:0 100px}}
.kpi-section::after{
  content:'';position:absolute;right:-60px;top:-60px;width:280px;height:280px;
  background:radial-gradient(circle,rgba(99,102,241,.14) 0%,transparent 65%);
  pointer-events:none;animation:orbPulse 4s ease-in-out infinite;
}
@keyframes orbPulse{0%,100%{opacity:.55;transform:scale(1)}50%{opacity:1;transform:scale(1.18)}}
.kpi-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;position:relative;z-index:1;flex-wrap:wrap;gap:10px}
.kpi-title{font-size:17px;font-weight:900;color:#fff;display:flex;align-items:center;gap:10px;letter-spacing:-.2px}
.kpi-title i{color:var(--accent2);font-size:19px;filter:drop-shadow(0 0 9px rgba(99,102,241,.65));animation:iconBounce 2.8s ease-in-out infinite}
@keyframes iconBounce{0%,100%{transform:translateY(0) rotate(0deg)}40%{transform:translateY(-4px) rotate(-4deg)}70%{transform:translateY(-2px) rotate(3deg)}}
.kpi-title-sub{font-size:11.5px;color:rgba(255,255,255,.42);font-weight:600;margin-top:3px;letter-spacing:.1px}
.kpi-live{display:flex;align-items:center;gap:7px;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.32);padding:5px 14px;border-radius:99px;font-size:11.5px;font-weight:800;color:#4ade80;letter-spacing:.5px}
.kpi-live-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;box-shadow:0 0 8px #4ade8088;animation:livePulse 1.8s infinite}
@keyframes livePulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.gauge-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;position:relative;z-index:1}
@keyframes kpiEnter{from{opacity:0;transform:translateY(32px) scale(.91)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes critRing{0%,100%{box-shadow:0 4px 22px rgba(0,0,0,.28),0 0 0 0 rgba(239,68,68,0)}50%{box-shadow:0 4px 22px rgba(0,0,0,.28),0 0 0 7px rgba(239,68,68,.2)}}
@keyframes fillShimmer{0%{transform:translateX(-100%)}100%{transform:translateX(220%)}}
.g-card{
  background:rgba(255,255,255,.97);border:1.5px solid rgba(255,255,255,.15);border-radius:20px;
  padding:17px 13px 14px;display:flex;flex-direction:column;align-items:center;
  position:relative;overflow:hidden;text-decoration:none;color:inherit;
  transition:transform .28s cubic-bezier(.34,1.56,.64,1),box-shadow .28s,border-color .28s;
  box-shadow:0 5px 22px rgba(0,0,0,.28);
  animation:kpiEnter .52s cubic-bezier(.34,1.56,.64,1) both;
}
.g-card::before{
  content:'';position:absolute;top:0;left:-120%;width:55%;height:100%;
  background:linear-gradient(105deg,transparent 35%,rgba(255,255,255,.5) 55%,transparent 75%);
  transition:left .6s ease;pointer-events:none;z-index:3;
}
.g-card::after{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--gc,var(--accent));border-radius:3px 3px 0 0;
  transition:height .28s,box-shadow .28s;
}
.g-glow{position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(var(--gc-rgb,99,102,241),.07) 0%,transparent 55%);pointer-events:none;z-index:0}
.g-card.g-crit{animation:kpiEnter .52s cubic-bezier(.34,1.56,.64,1) both,critRing 2.3s ease-in-out 1s infinite}
.g-title{font-size:10.5px;font-weight:900;text-transform:uppercase;letter-spacing:.6px;color:#64748b;display:flex;align-items:center;gap:5px;margin-bottom:3px;position:relative;z-index:1}
.g-title i{font-size:12px;color:var(--gc,var(--accent));filter:drop-shadow(0 0 5px rgba(var(--gc-rgb,99,102,241),.55))}
.g-chart{width:100%;max-width:175px;height:155px;min-height:155px}
.g-pct-row{display:flex;align-items:center;gap:8px;margin-top:-9px;position:relative;z-index:1}
.g-pct{font-size:25px;font-weight:900;color:#1a2744;line-height:1}
.g-badge{font-size:9px;font-weight:800;padding:2px 7px;border-radius:5px}
.gb-ok{background:rgba(74,222,128,.13);color:#22c55e}
.gb-wa{background:rgba(251,191,36,.13);color:#f59e0b}
.gb-er{background:rgba(239,68,68,.13);color:#ef4444}
.g-meta{font-size:11px;color:#64748b;margin-top:5px;text-align:center;font-weight:600;position:relative;z-index:1}
.g-track{width:78%;height:5px;background:rgba(0,0,0,.09);border-radius:99px;overflow:hidden;margin-top:9px;position:relative;z-index:1}
.g-fill{height:100%;border-radius:99px;background:var(--gc,var(--accent));transition:width 1.6s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden}
.g-fill::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.6),transparent);animation:fillShimmer 2.8s ease-in-out infinite}
.g-ops{background:linear-gradient(135deg,#050f1c 0%,#0a2748 55%,#123a6d 100%);border-color:rgba(99,102,241,.3);border-width:1.5px}
.ops-big{font-size:42px;font-weight:900;color:var(--accent2);line-height:1;margin:14px 0 5px;filter:drop-shadow(0 0 14px rgba(99,102,241,.45));animation:opsGlow 2.5s ease-in-out infinite}
@keyframes opsGlow{0%,100%{filter:drop-shadow(0 0 10px rgba(99,102,241,.4))}50%{filter:drop-shadow(0 0 20px rgba(99,102,241,.75))}}
.ops-sub{font-size:15px;font-weight:700;color:rgba(255,255,255,.82)}
.ops-tag{font-size:9px;text-transform:uppercase;color:rgba(255,255,255,.45);font-weight:700;letter-spacing:.6px;margin-top:4px}

/* ─── Embudo de conversión ─────────── */
.funnel-wrap2{
  background:var(--surface);border:1px solid var(--border);border-radius:18px;
  padding:20px 24px;margin-bottom:24px;position:relative;overflow:hidden;
}
.funnel-steps2{display:flex;align-items:center;justify-content:space-between;margin-top:18px;flex-wrap:wrap;gap:10px}
.f-step2{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;max-width:90px}
.f-ico2{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:19px;position:relative}
.f-pct-badge2{position:absolute;bottom:-6px;right:-6px;font-size:9px;font-weight:800;padding:2px 6px;border-radius:20px}
.f-lbl2{font-size:11px;color:var(--muted);font-weight:600;text-align:center}
.f-val2{font-size:14px;font-weight:800}
.f-arrow2{color:var(--faint);font-size:13px}

/* ─── Scrollbar ───────────────────── */
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}

/* ─── Responsive ──────────────────── */
@media(max-width:1200px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.sidebar{display:none}.kpi-grid{grid-template-columns:1fr}.g-2,.g-3{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">

  <!-- ─── SIDEBAR ──────────────────────────────── -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">🛡️</div>
      <div>
        <div class="brand-name">SUPER<span style="color:var(--accent2)">_IA</span></div>
        <div class="brand-tag">Administrador</div>
      </div>
    </div>

    <div class="sidebar-section">Panel Global</div>
    <a class="nav-link-adm active" href="administrador_index.php">
      <span class="ico"><i class="fa-solid fa-house"></i></span> Dashboard
    </a>
    <a class="nav-link-adm" href="usuarios.php">
      <span class="ico"><i class="fa-solid fa-users"></i></span> Usuarios
      <?php if($solicitudes_pend > 0):?><span class="nav-badge"><?=$solicitudes_pend?></span><?php endif?>
    </a>
    <a class="nav-link-adm" href="mis_supervisores.php">
      <span class="ico"><i class="fa-solid fa-user-tie"></i></span> Supervisores
    </a>
    <a class="nav-link-adm" href="mis_asesores_superIA.php">
      <span class="ico"><i class="fa-solid fa-users-gear"></i></span> Asesores
    </a>

    <div class="sidebar-section">Operaciones</div>
    <a class="nav-link-adm" href="clientes.php">
      <span class="ico"><i class="fa-solid fa-address-card"></i></span> Clientes
    </a>
    <a class="nav-link-adm" href="operaciones.php">
      <span class="ico"><i class="fa-solid fa-briefcase"></i></span> Operaciones
    </a>
    <a class="nav-link-adm" href="recuperacion_creditos.php">
      <span class="ico"><i class="fa-solid fa-rotate-left"></i></span> Recuperación
    </a>
    <a class="nav-link-adm" href="pendientes.php">
      <span class="ico"><i class="fa-solid fa-hourglass-end"></i></span> Pendientes
      <?php if($fichas_pendientes > 0):?><span class="nav-badge"><?=$fichas_pendientes?></span><?php endif?>
    </a>

    <div class="sidebar-section">Monitoreo</div>
    <a class="nav-link-adm" href="mapa_vivo_superIA.php">
      <span class="ico"><i class="fa-solid fa-map-location-dot"></i></span> Mapa Vivo
    </a>
    <a class="nav-link-adm" href="alertas.php">
      <span class="ico"><i class="fa-solid fa-bell"></i></span> Alertas
      <?php if($alertas_pendientes > 0):?><span class="nav-badge"><?=$alertas_pendientes?></span><?php endif?>
    </a>
    <a class="nav-link-adm" href="historial_rutas.php">
      <span class="ico"><i class="fa-solid fa-route"></i></span> Rutas GPS
    </a>

    <div class="sidebar-section">Análisis</div>
    <a class="nav-link-adm" href="reportes.php">
      <span class="ico"><i class="fa-solid fa-chart-bar"></i></span> Reportes KPI
    </a>
    <a class="nav-link-adm" href="mapa_calor.php">
      <span class="ico"><i class="fa-solid fa-fire"></i></span> Mapa Calor
    </a>
    <a class="nav-link-adm" href="kpi_penetracion.php">
      <span class="ico"><i class="fa-solid fa-chart-pie"></i></span> Penetración
    </a>
    <a class="nav-link-adm" href="exportar.php">
      <span class="ico"><i class="fa-solid fa-file-export"></i></span> Exportar
    </a>

    <div class="sidebar-section">Sistema</div>
    <a class="nav-link-adm" href="administrar_solicitudes_admin.php">
      <span class="ico"><i class="fa-solid fa-clipboard-check"></i></span> Solicitudes
      <?php if($solicitudes_pend > 0):?><span class="nav-badge"><?=$solicitudes_pend?></span><?php endif?>
    </a>
    <a class="nav-link-adm" href="super_admin_index.php">
      <span class="ico"><i class="fa-solid fa-crown"></i></span> Super Admin
    </a>

    <div class="sidebar-user">
      <div class="avatar"><?=strtoupper(substr($admin_nombre,0,1))?></div>
      <div style="min-width:0">
        <div class="user-name"><?=htmlspecialchars($admin_nombre)?></div>
        <div class="user-role">Administrador Total</div>
      </div>
      <a href="logout.php" title="Cerrar sesión" style="margin-left:auto;color:var(--muted);font-size:14px;flex-shrink:0"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </aside>

  <!-- ─── MAIN ─────────────────────────────────── -->
  <div class="main">
    <div class="topbar">
      <div class="topbar-title">
        <span style="color:var(--accent)">🛡️</span>
        Panel Administrador
        <span style="font-size:12px;color:var(--muted);font-weight:400">— Acceso Total</span>
      </div>
      <div class="topbar-actions">
        <?php if($alertas_pendientes>0):?>
        <a class="btn-adm" href="alertas.php">
          <span class="alert-dot"></span><?=$alertas_pendientes?> alertas
        </a>
        <?php endif?>
        <?php if($solicitudes_pend>0):?>
        <a class="btn-adm" href="administrar_solicitudes_admin.php">
          <i class="fa-solid fa-user-clock"></i> <?=$solicitudes_pend?> solicitud<?=$solicitudes_pend>1?'es':''?>
        </a>
        <?php endif?>
        <a class="btn-adm" href="mapa_vivo_superIA.php"><i class="fa-solid fa-satellite-dish"></i> Mapa Vivo</a>
        <a class="btn-adm danger" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
      </div>
    </div>

    <div class="content">

      <!-- ── Saludo ─────────────────────────── -->
      <div style="margin-bottom:22px">
        <h5 style="font-weight:800;font-size:20px">Hola, <?=htmlspecialchars(explode(' ',$admin_nombre)[0])?>
          <span style="background:linear-gradient(90deg,var(--accent),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent"> 👋</span>
        </h5>
        <p style="color:var(--muted);font-size:13px;margin-top:2px">
          <?=date('l d \d\e F Y')?> · Visión completa del sistema SUPER_IA
        </p>
      </div>

      <!-- ── Filtro por banco/cooperativa (búsqueda por escritura) ── -->
      <div class="dash-banco-bar">
        <label><i class="fa-solid fa-university me-1"></i> Banco/Cooperativa:</label>
        <form method="get" id="formBancoDashAdm" style="flex:1;min-width:260px;max-width:420px;">
          <div class="coop-buscador-wrap">
            <input type="text" id="banco-dash-adm-buscar" class="form-control" placeholder="Escribe para buscar…" autocomplete="off" value="<?=htmlspecialchars($nombre_banco_filtro)?>">
            <input type="hidden" name="banco_filtro" id="banco-dash-adm-hidden" value="<?=htmlspecialchars($banco_filtro)?>">
            <button type="button" class="coop-buscador-clear <?=$banco_filtro!==''?'show':''?>" id="banco-dash-adm-clear" title="Quitar filtro">
              <i class="fa-solid fa-circle-xmark"></i>
            </button>
            <div id="banco-dash-adm-lista" class="coop-buscador-list"></div>
          </div>
        </form>
        <?php if ($banco_filtro !== ''): ?>
          <span class="dash-banco-tag"><i class="fa-solid fa-filter"></i> Viendo solo: <?=htmlspecialchars($nombre_banco_filtro)?></span>
        <?php else: ?>
          <span class="dash-banco-tag all"><i class="fa-solid fa-globe"></i> Viendo todos los bancos/cooperativas</span>
        <?php endif; ?>
      </div>

      <!-- ── KPI Cards ──────────────────────── -->
      <div class="kpi-grid">
        <div class="kpi-card" style="--c:var(--accent)">
          <div class="kpi-label">Usuarios activos</div>
          <div class="kpi-val"><?=$total_usuarios?></div>
          <div class="kpi-sub"><?=$total_supervisores?> superv. · <?=$total_asesores?> asesores</div>
          <i class="fa-solid fa-users kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--green)">
          <div class="kpi-label">Clientes registrados</div>
          <div class="kpi-val"><?=$total_clientes?></div>
          <div class="kpi-sub"><?=$clientes_activos?> activos</div>
          <i class="fa-solid fa-address-card kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--cyan)">
          <div class="kpi-label">Tareas hoy</div>
          <div class="kpi-val"><?=$tareas_hoy?></div>
          <div class="kpi-sub"><?=$tareas_completadas?> completadas · <?=$pct_tareas?>%</div>
          <i class="fa-solid fa-list-check kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--amber)">
          <div class="kpi-label">Créditos aprobados (mes)</div>
          <div class="kpi-val"><?=$creditos_mes?></div>
          <div class="kpi-sub">$<?=number_format($monto_aprobado_mes,0)?> aprobados</div>
          <i class="fa-solid fa-building-columns kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--purple)">
          <div class="kpi-label">Encuestas mes</div>
          <div class="kpi-val"><?=$encuestas_mes?></div>
          <div class="kpi-sub">Comerciales + crediticias</div>
          <i class="fa-solid fa-clipboard-list kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--red)">
          <div class="kpi-label">Alertas sin revisar</div>
          <div class="kpi-val"><?=$alertas_pendientes?></div>
          <div class="kpi-sub">Requieren atención</div>
          <i class="fa-solid fa-bell kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:#f472b6">
          <div class="kpi-label">Fichas pendientes</div>
          <div class="kpi-val"><?=$fichas_pendientes?></div>
          <div class="kpi-sub">Por aprobar / revisar</div>
          <i class="fa-solid fa-file-circle-question kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--amber)">
          <div class="kpi-label">Solicitudes pendientes</div>
          <div class="kpi-val"><?=$solicitudes_pend?></div>
          <div class="kpi-sub">Nuevos registros</div>
          <i class="fa-solid fa-user-clock kpi-icon"></i>
        </div>
      </div>

      <!-- ── Barra progreso tareas hoy ─────── -->
      <div class="panel mb-4">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-list-check" style="color:var(--cyan)"></i> Progreso de tareas hoy</div>
          <span style="font-size:12px;color:var(--muted)"><?=date('d/m/Y')?></span>
        </div>
        <div class="panel-body">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px">
            <span><?=$tareas_completadas?> completadas de <?=$tareas_hoy?> programadas</span>
            <strong style="color:var(--cyan)"><?=$pct_tareas?>%</strong>
          </div>
          <div class="prog-wrap">
            <div class="prog-fill" style="width:<?=$pct_tareas?>%;--c:var(--cyan)"></div>
          </div>
          <div style="display:flex;gap:16px;margin-top:12px;font-size:12px;color:var(--muted)">
            <span><span style="color:var(--green)">●</span> Completadas: <?=$tareas_completadas?></span>
            <span><span style="color:var(--amber)">●</span> Postergadas: <?=$tareas_postergadas?></span>
            <span><span style="color:var(--faint)">●</span> Pendientes: <?=max(0,$tareas_hoy-$tareas_completadas-$tareas_postergadas)?></span>
          </div>
        </div>
      </div>

      <!-- ── KPIs Tacómetro (estilo Supervisor) ─────────────────── -->
      <div class="kpi-section">
        <div class="kpi-hd">
          <div>
            <div class="kpi-title"><i class="fa-solid fa-gauge-high"></i> KPIs Globales — Tacómetros en Vivo</div>
            <div class="kpi-title-sub">Rendimiento de <?=$banco_filtro!==''?htmlspecialchars($nombre_banco_filtro):'todo el sistema'?> &middot; <?=strtoupper(date('M Y'))?></div>
          </div>
          <div class="kpi-live"><div class="kpi-live-dot"></div>EN VIVO</div>
        </div>
        <div class="gauge-kpi-grid">
        <?php
        $gkpisAdm = [
          ['k'=>'actividad',    'lbl'=>'Actividad',      'ico'=>'fa-bolt',            'c'=>'#60a5fa','cr'=>'96,165,250',  'v'=>$kpi_dash['actividad_pct'],    'meta'=>$kpi_dash['actividad_realizadas'].'/'.$kpi_dash['actividad_total'].' hoy',        'view'=>'actividad'],
          ['k'=>'penetracion',  'lbl'=>'Penetración',    'ico'=>'fa-chart-pie',       'c'=>'#4ade80','cr'=>'74,222,128',  'v'=>$kpi_dash['penetracion_pct'],   'meta'=>$kpi_dash['penetracion_clientes'].'/'.$kpi_dash['penetracion_visitas'].' visitas', 'view'=>'mercado'],
          ['k'=>'interes',      'lbl'=>'Interés',        'ico'=>'fa-heart-pulse',     'c'=>'#fbbf24','cr'=>'251,191,36',  'v'=>$kpi_dash['interes_pct'],       'meta'=>$kpi_dash['interes_si'].'/'.$kpi_dash['interes_total'].' encuestas',              'view'=>'interes'],
          ['k'=>'prospeccion',  'lbl'=>'Prospección',    'ico'=>'fa-route',           'c'=>'#a78bfa','cr'=>'167,139,250', 'v'=>$kpi_dash['prospeccion_pct'],   'meta'=>$kpi_dash['prospeccion_avance'].'/'.$kpi_dash['prospeccion_meta'].' meta',        'view'=>'prospeccion'],
          ['k'=>'levantam',     'lbl'=>'Levantamientos', 'ico'=>'fa-clipboard-check', 'c'=>'#38bdf8','cr'=>'56,189,248',  'v'=>$kpi_dash['levantamiento_pct'], 'meta'=>$kpi_dash['levantamientos'].'/'.$kpi_dash['interesados'].' interesados',         'view'=>'evaluacion'],
          ['k'=>'frio',         'lbl'=>'Visitas Frío',   'ico'=>'fa-snowflake',       'c'=>'#fb923c','cr'=>'251,146,60',  'v'=>$kpi_dash['frio_pct'],          'meta'=>$kpi_dash['frio_visitas'].' visitas frías',                                      'view'=>'frio'],
          ['k'=>'eficiencia',   'lbl'=>'Eficiencia',     'ico'=>'fa-bolt-lightning',  'c'=>'#f472b6','cr'=>'244,114,182', 'v'=>$kpi_dash['eficiencia_pct'],    'meta'=>$kpi_dash['interes_si'].' con interés',                                          'view'=>'eficiencia'],
          ['k'=>'postventa',    'lbl'=>'Post-Venta',     'ico'=>'fa-rotate',          'c'=>'#2dd4bf','cr'=>'45,212,191',  'v'=>$kpi_dash['postventa_pct'],     'meta'=>$ops_aprobadas.' aprobados',                                                     'view'=>'postventa'],
          ['k'=>'recuperacion', 'lbl'=>'Recuperación',   'ico'=>'fa-shield-halved',   'c'=>'#ef4444','cr'=>'239,68,68',   'v'=>$kpi_dash['recuperacion_pct'],  'meta'=>$kpi_dash['recuperaciones'].' gestiones',                                        'view'=>'recuperacion'],
        ];
        // kpi_penetracion.php solo acepta sesiones admin_logged_in o supervisor_logged_in;
        // si la sesión activa es super_admin/administrador, las tarjetas no son clicables.
        $gauges_clicables = $is_admin;
        foreach ($gkpisAdm as $g):
          $v = round((float)$g['v'], 1);
          $bc = $v>=70?'gb-ok':($v>=35?'gb-wa':'gb-er');
          $bt = $v>=70?'OK':($v>=35?'Bajo':'Crítico');
          $tag = $gauges_clicables ? 'a' : 'div';
        ?>
        <<?=$tag?> <?=$gauges_clicables?('href="kpi_penetracion.php?view='.htmlspecialchars($g['view']).'"'):''?> class="g-card<?=$bc==='gb-er'?' g-crit':''?>" style="--gc:<?=$g['c']?>;--gc-rgb:<?=$g['cr']?>;">
          <div class="g-glow"></div>
          <div class="g-title"><i class="fa-solid <?=$g['ico']?>"></i><?=htmlspecialchars($g['lbl'])?></div>
          <div class="g-chart" id="gc-adm-<?=htmlspecialchars($g['k'])?>"></div>
          <div class="g-pct-row">
            <span class="g-pct"><?=$v?>%</span>
            <span class="g-badge <?=$bc?>"><?=$bt?></span>
          </div>
          <div class="g-meta"><?=htmlspecialchars($g['meta'])?></div>
          <div class="g-track"><div class="g-fill" style="width:<?=min(100,$v)?>%;background:<?=$g['c']?>;"></div></div>
        </<?=$tag?>>
        <?php endforeach; ?>

        <!-- CRÉDITOS ESPECIAL -->
        <div class="g-card g-ops" style="--gc:#6366f1;--gc-rgb:99,102,241;justify-content:center;">
          <div class="g-glow"></div>
          <div class="g-title" style="color:rgba(255,255,255,.6);"><i class="fa-solid fa-hand-holding-dollar" style="color:var(--accent2);"></i>Créditos <?=date('M Y')?></div>
          <div class="ops-big" id="cnt-ops-big-adm">0</div>
          <div class="ops-sub">$<?=number_format($kpi_dash['operaciones_monto'],0,'.',',')?></div>
          <div class="ops-tag">Monto prestado este mes</div>
          <div class="g-track" style="background:rgba(255,255,255,.1);width:80%;margin-top:12px;">
            <div class="g-fill" style="width:<?=min(100,$kpi_dash['operaciones_total']*10)?>%;background:var(--accent2);"></div>
          </div>
        </div>
        </div><!-- /gauge-kpi-grid -->
      </div><!-- /kpi-section -->

      <!-- ── Embudo de conversión ────────────────────────────────── -->
      <div class="funnel-wrap2 panel">
        <div class="panel-head" style="border-bottom:none;">
          <div class="panel-title"><i class="fa-solid fa-diagram-project" style="color:var(--accent2)"></i> Flujo de Conversión</div>
          <span style="font-size:12px;color:var(--muted)"><?=strtoupper(date('M Y'))?></span>
        </div>
        <div class="funnel-steps2">
          <?php
          $fstepsAdm = [
            ['lbl'=>'Prospección','ico'=>'fa-solid fa-route',           'c'=>'#a78bfa','v'=>$kpi_dash['prospeccion_pct'],   'n'=>$kpi_dash['prospeccion_avance']],
            ['lbl'=>'Visitas',    'ico'=>'fa-solid fa-map-marker-alt',  'c'=>'#60a5fa','v'=>$kpi_dash['penetracion_pct'],   'n'=>$kpi_dash['penetracion_visitas']],
            ['lbl'=>'Interés',    'ico'=>'fa-solid fa-heart-pulse',     'c'=>'#fbbf24','v'=>$kpi_dash['interes_pct'],       'n'=>$kpi_dash['interes_si']],
            ['lbl'=>'Levantam.',  'ico'=>'fa-solid fa-clipboard-check', 'c'=>'#38bdf8','v'=>$kpi_dash['levantamiento_pct'], 'n'=>$kpi_dash['levantamientos']],
            ['lbl'=>'Eficiencia', 'ico'=>'fa-solid fa-bolt',            'c'=>'#f472b6','v'=>$kpi_dash['eficiencia_pct'],    'n'=>$kpi_dash['interes_si']],
            ['lbl'=>'Créditos',   'ico'=>'fa-solid fa-handshake',       'c'=>'#6366f1','v'=>min(100,$kpi_dash['operaciones_total']*10),'n'=>$kpi_dash['operaciones_total']],
          ];
          foreach ($fstepsAdm as $i=>$fs): ?>
          <div class="f-step2">
            <div class="f-ico2" style="color:<?=$fs['c']?>;background:<?=$fs['c']?>18;border:1px solid <?=$fs['c']?>44;">
              <i class="<?=$fs['ico']?>"></i>
              <span class="f-pct-badge2" style="background:<?=$fs['c']?>;color:#0a0f1a;"><?=(int)$fs['v']?>%</span>
            </div>
            <div class="f-lbl2"><?=htmlspecialchars($fs['lbl'])?></div>
            <div class="f-val2" style="color:<?=$fs['c']?>"><?=$fs['n']?></div>
          </div>
          <?php if ($i < count($fstepsAdm)-1): ?>
          <div class="f-arrow2"><i class="fa-solid fa-chevron-right"></i></div>
          <?php endif; endforeach; ?>
        </div>
      </div>

      <!-- ── Fila: Top asesores + Pipeline ─── -->
      <div class="row-grid g-2 mb-4">

        <!-- Top asesores -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="fa-solid fa-ranking-star" style="color:var(--amber)"></i> Top asesores del mes</div>
            <a class="panel-link" href="mis_asesores_superIA.php">Ver todos →</a>
          </div>
          <div class="panel-body" style="padding:12px 20px">
            <?php if(empty($top_asesores)):?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px">Sin datos aún</p>
            <?php else: foreach($top_asesores as $i=>$a): $c=['var(--amber)','var(--muted)','#cd7f32','var(--faint)','var(--faint)'][$i]??'var(--faint)' ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
              <span style="color:<?=$c?>;font-weight:800;font-size:14px;width:16px;text-align:center"><?=$i+1?></span>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($a['nombre'])?></div>
                <div class="prog-wrap" style="margin-top:4px">
                  <div class="prog-fill" style="width:<?=$a['pct']??0?>%;--c:<?=$c?>"></div>
                </div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-size:13px;font-weight:700;color:var(--text)"><?=$a['comp']?>/<?=$a['total']?></div>
                <div style="font-size:10px;color:var(--muted)"><?=$a['pct']??0?>%</div>
              </div>
            </div>
            <?php endforeach; endif ?>
          </div>
        </div>

        <!-- Pipeline crédito -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="fa-solid fa-filter" style="color:var(--purple)"></i> Pipeline de crédito</div>
            <a class="panel-link" href="operaciones.php">Ver todo →</a>
          </div>
          <div class="panel-body" style="padding:12px 20px">
            <?php if(empty($pipeline)):?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px">Sin datos aún</p>
            <?php else:
              $maxP = max(array_column($pipeline,'total'));
              $colors=['prospectado'=>'#6366f1','entrevista_venta'=>'#8b5cf6','levantamiento'=>'#06b6d4','solicitud'=>'#f59e0b','analisis'=>'#ec4899','aprobado'=>'#10b981','desembolsado'=>'#22d3ee','rechazado'=>'#ef4444','recuperacion'=>'#f97316'];
              foreach($pipeline as $p):
                $c=$colors[$p['estado_credito']]??'#64748b';
                $pct=round(100*$p['total']/max($maxP,1));
              ?>
              <div class="funnel-item">
                <span class="funnel-label"><?=str_replace('_',' ',$p['estado_credito'])?></span>
                <div class="funnel-bar-wrap">
                  <div class="funnel-bar" style="width:<?=$pct?>%;background:<?=$c?>"></div>
                </div>
                <span class="funnel-count"><?=$p['total']?></span>
              </div>
              <?php endforeach; endif ?>
          </div>
        </div>
      </div>

      <!-- ── Fila: Alertas + Solicitudes ───── -->
      <div class="row-grid g-2 mb-4">

        <!-- Alertas recientes -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><span class="alert-dot"></span> Alertas recientes</div>
            <a class="panel-link" href="alertas.php">Ver todas →</a>
          </div>
          <?php if(empty($alertas_recientes)):?>
          <div style="padding:28px;text-align:center;color:var(--muted);font-size:13px">
            <i class="fa-solid fa-check-circle" style="color:var(--green);font-size:24px;display:block;margin-bottom:8px"></i>
            Sin alertas pendientes
          </div>
          <?php else:?>
          <table class="adm-table">
            <thead><tr>
              <th>Asesor</th><th>Campo</th><th>Nuevo valor</th><th>Hora</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach($alertas_recientes as $al):?>
            <tr>
              <td style="font-weight:600"><?=htmlspecialchars($al['asesor'])?></td>
              <td style="color:var(--muted)"><?=htmlspecialchars($al['campo_modificado']??'—')?></td>
              <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($al['valor_nuevo']??'—')?></td>
              <td style="color:var(--muted);white-space:nowrap;font-size:11px"><?=date('H:i',strtotime($al['created_at']))?></td>
              <td><?=$al['vista_supervisor']?'<span class="pill pill-green">Vista</span>':'<span class="pill pill-amber">Nueva</span>'?></td>
            </tr>
            <?php endforeach?>
            </tbody>
          </table>
          <?php endif?>
        </div>

        <!-- Solicitudes pendientes -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="fa-solid fa-user-clock" style="color:var(--accent)"></i> Solicitudes de registro</div>
            <a class="panel-link" href="administrar_solicitudes_admin.php">Gestionar →</a>
          </div>
          <?php if(empty($solicitudes_recientes)):?>
          <div style="padding:28px;text-align:center;color:var(--muted);font-size:13px">
            <i class="fa-solid fa-inbox" style="font-size:24px;display:block;margin-bottom:8px"></i>
            Sin solicitudes pendientes
          </div>
          <?php else:?>
          <table class="adm-table">
            <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach($solicitudes_recientes as $sol):?>
            <tr>
              <td style="font-weight:600"><?=htmlspecialchars($sol['nombre'])?></td>
              <td style="color:var(--muted);font-size:11px"><?=htmlspecialchars($sol['email'])?></td>
              <td><span class="pill pill-blue"><?=htmlspecialchars($sol['rol_solicitado'])?></span></td>
              <td style="color:var(--muted);font-size:11px"><?=date('d/m H:i',strtotime($sol['created_at']))?></td>
            </tr>
            <?php endforeach?>
            </tbody>
          </table>
          <?php endif?>
        </div>
      </div>

      <!-- ── Tareas por tipo hoy ─────────────── -->
      <?php if(!empty($tareas_por_tipo)):?>
      <div class="panel mb-4">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-chart-column" style="color:var(--green)"></i> Tareas por tipo — hoy</div>
        </div>
        <div class="panel-body" style="padding:16px 20px">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">
            <?php foreach($tareas_por_tipo as $tt):
              $pct2 = $tt['total']>0?round(100*$tt['comp']/$tt['total']):0;
              $label = str_replace(['_','prospecto nuevo','visita frio'],['  ','prospecto','en frío'],strtolower($tt['tipo_tarea']));
            ?>
            <div style="background:var(--surface2);border-radius:10px;padding:12px 14px;border:1px solid var(--border)">
              <div style="font-size:11px;color:var(--muted);margin-bottom:4px;text-transform:capitalize"><?=htmlspecialchars($label)?></div>
              <div style="font-size:20px;font-weight:800;margin-bottom:6px"><?=$tt['comp']?><span style="font-size:13px;color:var(--muted);font-weight:400">/<?=$tt['total']?></span></div>
              <div class="prog-wrap">
                <div class="prog-fill" style="width:<?=$pct2?>%;--c:var(--green)"></div>
              </div>
            </div>
            <?php endforeach?>
          </div>
        </div>
      </div>
      <?php endif?>

      <!-- ── Accesos rápidos ─────────────────── -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-bolt" style="color:var(--amber)"></i> Accesos rápidos</div>
        </div>
        <div class="panel-body" style="padding:16px 20px">
          <div style="display:flex;flex-wrap:wrap;gap:10px">
            <?php
            $links=[
              ['mapa_vivo_superIA.php','fa-satellite-dish','Mapa vivo','var(--cyan)'],
              ['alertas.php','fa-bell','Alertas','var(--red)'],
              ['operaciones.php','fa-briefcase','Operaciones','var(--purple)'],
              ['reportes.php','fa-chart-bar','Reportes','var(--accent)'],
              ['mis_asesores_superIA.php','fa-users-gear','Asesores','var(--green)'],
              ['mis_supervisores.php','fa-user-tie','Supervisores','var(--amber)'],
              ['clientes.php','fa-address-card','Clientes','var(--cyan)'],
              ['exportar.php','fa-file-export','Exportar','var(--muted)'],
              ['historial_rutas.php','fa-route','Rutas GPS','var(--pink)'],
              ['mapa_calor.php','fa-fire','Mapa calor','var(--red)'],
              ['kpi_penetracion.php','fa-chart-pie','Penetración','var(--purple)'],
              ['administrar_solicitudes_admin.php','fa-clipboard-check','Solicitudes','var(--amber)'],
            ];
            foreach($links as [$href,$icon,$label,$color]):?>
            <a href="<?=$href?>" style="
              display:flex;align-items:center;gap:8px;
              background:var(--surface2);border:1px solid var(--border);
              border-radius:9px;padding:10px 14px;
              color:var(--text);text-decoration:none;font-size:13px;font-weight:500;
              transition:.15s;
            " onmouseover="this.style.borderColor='<?=$color?>'" onmouseout="this.style.borderColor='var(--border)'">
              <i class="fa-solid <?=$icon?>" style="color:<?=$color?>"></i> <?=$label?>
            </a>
            <?php endforeach?>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /layout -->
<script>
// ── Combobox de búsqueda por escritura para el filtro de banco ──
const BANCOS_DASH_ADM = <?= json_encode(array_map(fn($b) => ['id' => (string)$b['id'], 'nombre' => $b['nombre']], $bancos_dash), JSON_UNESCAPED_UNICODE) ?>;

const bancoDashAdmBuscarInput = document.getElementById('banco-dash-adm-buscar');
const bancoDashAdmHidden      = document.getElementById('banco-dash-adm-hidden');
const bancoDashAdmClearBtn    = document.getElementById('banco-dash-adm-clear');

if (bancoDashAdmBuscarInput && typeof initCooperativaBuscador === 'function') {
    initCooperativaBuscador({
        inputId:  'banco-dash-adm-buscar',
        hiddenId: 'banco-dash-adm-hidden',
        listId:   'banco-dash-adm-lista',
        data: BANCOS_DASH_ADM,
        onSelect: function () {
            bancoDashAdmClearBtn.classList.add('show');
            document.getElementById('formBancoDashAdm').submit();
        }
    });
    bancoDashAdmClearBtn.addEventListener('click', function () {
        window.location.href = 'administrador_index.php';
    });
    bancoDashAdmBuscarInput.addEventListener('input', function () {
        bancoDashAdmClearBtn.classList.toggle('show', !!bancoDashAdmHidden.value);
    });
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.g-fill').forEach(function (el) {
    var w = el.style.width; el.style.width = '0';
    setTimeout(function () { el.style.width = w; }, 350);
  });

  function counterAdm(el, target, dur) {
    if (!el) return;
    var s = 0, step = target / (dur / 16);
    var t = setInterval(function () {
      s += step;
      if (s >= target) { s = target; clearInterval(t); }
      el.textContent = Math.round(s).toLocaleString();
    }, 16);
  }
  setTimeout(function () {
    counterAdm(document.getElementById('cnt-ops-big-adm'), <?=$kpi_dash['operaciones_total']?>, 1000);
  }, 400);

  function makeGaugeAdm(id, val, color) {
    var el = document.getElementById(id); if (!el) return;
    var fill = val >= 70 ? color : (val >= 35 ? '#fbbf24' : '#f87171');
    var trackColor = val >= 70 ? 'rgba(74,222,128,.12)' : (val >= 35 ? 'rgba(251,191,36,.12)' : 'rgba(239,68,68,.12)');
    new ApexCharts(el, {
      series: [Math.min(100, Math.max(0, val))],
      chart: { type: 'radialBar', height: 155, width: '100%', toolbar: { show: false },
        background: 'transparent',
        animations: { enabled: true, easing: 'easeout', speed: 1400,
          animateGradually: { enabled: true, delay: 120 },
          dynamicAnimation: { enabled: true, speed: 700 } } },
      plotOptions: { radialBar: {
        startAngle: -135, endAngle: 135,
        track: { background: trackColor, strokeWidth: '72%', margin: 4, dropShadow: { enabled: false } },
        dataLabels: { show: true, name: { show: false }, value: {
          offsetY: 8, fontSize: '18px', fontWeight: '900', fontFamily: 'Inter,sans-serif', color: '#1a2744',
          formatter: function (v) { return Math.round(v) + '%'; } } },
        hollow: { margin: 5, size: '50%', background: 'transparent',
          dropShadow: { enabled: true, top: 2, left: 0, blur: 6, opacity: .08 } } } },
      fill: { type: 'gradient', gradient: {
        shade: 'dark', type: 'diagonal1', shadeIntensity: .22,
        gradientToColors: [fill], inverseColors: false, opacityFrom: 1, opacityTo: .85, stops: [0, 100] } },
      colors: [color],
      stroke: { lineCap: 'round', width: 3 },
      tooltip: { enabled: false },
      grid: { padding: { top: -12, bottom: -12, left: -10, right: -10 } },
      states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } }
    }).render();
  }

  var gdAdm = <?php $gaAdm = []; foreach ($gkpisAdm as $g) $gaAdm[] = ['k' => $g['k'], 'v' => (float)$g['v'], 'c' => $g['c']]; echo json_encode($gaAdm); ?>;

  document.querySelectorAll('.gauge-kpi-grid .g-card').forEach(function (c, i) {
    c.style.animationDelay = (i * 0.065) + 's';
  });

  gdAdm.forEach(function (g, i) {
    setTimeout(function () { makeGaugeAdm('gc-adm-' + g.k, g.v, g.c); }, i * 70);
  });
});

// Auto-refresh alertas badge cada 60s
setTimeout(()=>location.reload(), 60000);
</script>
</body>
</html>
