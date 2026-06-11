<?php
/**
 * mis_supervisores.php — Lista de supervisores a cargo del Gerente (jefe_agencia)
 */
require_once 'db_admin.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login_selector.php');
    exit;
}

$gerente_usuario_id = $_SESSION['admin_id']     ?? null;
$gerente_nombre     = $_SESSION['admin_nombre'] ?? 'Gerente';
$gerente_rol        = $_SESSION['admin_rol']    ?? 'jefe_agencia';

// ── Resolver jefe_agencia IDs según rol ──────────────────────
// jefe_agencia   → supervisor.jefe_agencia_id (1 jefe_agencia)
// gerente_general → gerente_general.unidad_bancaria_id → agencias → jefes_agencia (varios)
$ja_ids          = [];
$ja_id           = null; // primer ja_id (para compatibilidad)
$pre_sup_ids     = [];   // supervisor ids resueltos antes del filtro de búsqueda

require_once __DIR__ . '/helper_ja_ids.php';
try {
    // Combina jefe_agencia propio + cadena gerente_general (sin depender del rol)
    $ja_ids = resolver_ja_ids($pdo, $gerente_usuario_id);

    $ja_id = $ja_ids[0] ?? null;

    if (!empty($ja_ids)) {
        $phJa = implode(',', array_fill(0, count($ja_ids), '?'));
        $st = $pdo->prepare("SELECT id FROM supervisor WHERE jefe_agencia_id IN ($phJa)");
        $st->execute($ja_ids);
        $pre_sup_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (PDOException $e) {
    error_log('mis_supervisores resolver: ' . $e->getMessage());
}

// ── Parámetros de búsqueda / filtro ─────────────────────────
$q          = trim($_GET['q']      ?? '');
$filtro_est = trim($_GET['estado'] ?? 'todos');

// ── Query principal: supervisores filtrados por supervisor_ids ─
$supervisores = [];
$total_sups   = 0;

if (!empty($pre_sup_ids)) {
    try {
        $phPre = implode(',', array_fill(0, count($pre_sup_ids), '?'));
        $whereExtra = '';
        $params = $pre_sup_ids;

        if ($q !== '') {
            $whereExtra .= " AND (u.nombre LIKE ? OR u.email LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($filtro_est === 'activo') {
            $whereExtra .= " AND u.activo = 1";
        } elseif ($filtro_est === 'inactivo') {
            $whereExtra .= " AND u.activo = 0";
        } elseif ($filtro_est === 'pendiente') {
            $whereExtra .= " AND u.estado_aprobacion = 'pendiente'";
        }

        $st = $pdo->prepare("
            SELECT s.id AS supervisor_table_id,
                   u.id AS usuario_id,
                   u.nombre,
                   u.email,
                   u.activo,
                   u.estado_aprobacion,
                   s.meta_asesores,
                   (SELECT COUNT(*)
                    FROM asesor a
                    JOIN usuario ua ON ua.id = a.usuario_id
                    WHERE a.supervisor_id = s.id AND ua.activo = 1
                   ) AS total_asesores,
                   (SELECT COUNT(*)
                    FROM asesor a2
                    JOIN cliente_prospecto cp ON cp.asesor_id = a2.id
                    WHERE a2.supervisor_id = s.id
                   ) AS total_clientes,
                   (SELECT COUNT(*)
                    FROM tarea t
                    JOIN asesor a3 ON a3.id = t.asesor_id
                    WHERE a3.supervisor_id = s.id
                      AND t.fecha_programada = CURDATE()
                      AND t.estado = 'completada'
                   ) AS tareas_hoy,
                   (SELECT COUNT(*)
                    FROM alerta_modificacion am
                    WHERE am.supervisor_id = s.id AND am.vista_supervisor = 0
                   ) AS alertas_sin_ver
            FROM supervisor s
            JOIN usuario u ON u.id = s.usuario_id
            WHERE s.id IN ($phPre)
            $whereExtra
            ORDER BY u.nombre ASC
        ");
        $st->execute($params);
        $supervisores = $st->fetchAll(PDO::FETCH_ASSOC);
        $total_sups   = count($supervisores);

    } catch (PDOException $e) {
        error_log('mis_supervisores error: ' . $e->getMessage());
    }
}

// ── Alertas globales para badge del sidebar ──────────────────
$alertas_pendientes_sidebar = 0;
if (!empty($supervisores)) {
    try {
        $supIds = array_column($supervisores, 'supervisor_table_id');
        $ph = implode(',', array_fill(0, count($supIds), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id IN ($ph) AND vista_supervisor=0");
        $st->execute($supIds);
        $alertas_pendientes_sidebar = (int)$st->fetchColumn();
    } catch (PDOException $e) {}
}

// ── Cargar asesores de cada supervisor ───────────────────────
$asesores_por_sup = [];   // [ supervisor_table_id => [ ['nombre'=>..,'email'=>..,'telefono'=>..,'clientes'=>..], ... ] ]
if (!empty($supervisores)) {
    try {
        $supIds = array_column($supervisores, 'supervisor_table_id');
        $ph = implode(',', array_fill(0, count($supIds), '?'));
        $stA = $pdo->prepare("
            SELECT a.id AS asesor_id, a.supervisor_id,
                   u.nombre, u.email, u.telefono,
                   (SELECT COUNT(*) FROM cliente_prospecto cp WHERE cp.asesor_id = a.id) AS total_clientes
            FROM asesor a
            JOIN usuario u ON u.id = a.usuario_id
            WHERE a.supervisor_id IN ($ph) AND u.activo = 1
            ORDER BY u.nombre ASC
        ");
        $stA->execute($supIds);
        foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $asesores_por_sup[$row['supervisor_id']][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── Cargar clientes de cada asesor (para panel "Clientes de…") ──
$clientes_por_asesor = [];   // [ asesor_id => [ clientes... ] ]
if (!empty($asesores_por_sup)) {
    try {
        $aseIds = [];
        foreach ($asesores_por_sup as $lst) {
            foreach ($lst as $a) { if (!empty($a['asesor_id'])) $aseIds[] = $a['asesor_id']; }
        }
        if (!empty($aseIds)) {
            $phA = implode(',', array_fill(0, count($aseIds), '?'));
            $stC = $pdo->prepare("
                SELECT cp.asesor_id,
                       cp.nombre,
                       COALESCE(cp.cedula,'') AS cedula,
                       cp.email, cp.telefono, cp.telefono2,
                       CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END AS activo
                FROM cliente_prospecto cp
                WHERE cp.asesor_id IN ($phA)
                ORDER BY cp.nombre ASC
            ");
            $stC->execute($aseIds);
            foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $clientes_por_asesor[$row['asesor_id']][] = $row;
            }
        }
    } catch (PDOException $e) {}
}

// ── Solicitudes de supervisores pendientes ────────────────────
$solicitudes_supervisores = 0;
try {
    $st = $pdo->query("SELECT COUNT(*) FROM solicitudes_supervisor WHERE estado='pendiente'");
    $solicitudes_supervisores = (int)$st->fetchColumn();
} catch (PDOException $e) {}

$currentPage = 'supervisores';

// Funciones helpers ─────────────────────────────────────────
function badge_estado(array $row): string {
    if ($row['estado_aprobacion'] === 'pendiente') {
        return '<span class="badge badge-warning">Pendiente</span>';
    }
    if (!$row['activo']) {
        return '<span class="badge badge-danger">Inactivo</span>';
    }
    return '<span class="badge badge-success">Activo</span>';
}
function iniciales(string $nombre): string {
    $partes = explode(' ', trim($nombre));
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini ?: '?';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mis Supervisores — Super_IA</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
<style>
/* ── Página específica — gama navaja + amarillo como mis_asesores ── */
.ma-page-header{
    display:flex;align-items:center;gap:14px;
    margin-bottom:28px;
    padding-bottom:18px;
    border-bottom:2px solid #e8eef6;
}
.ma-page-icon{
    width:52px;height:52px;border-radius:14px;
    background:linear-gradient(135deg,#0a2748,#1e4d8c);
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 4px 14px rgba(10,39,72,.22);
    flex-shrink:0;
}
.ma-page-icon i{color:#ffdd00;font-size:22px;}
.ma-page-title{font-size:22px;font-weight:900;color:#0a2748;margin:0;}
.ma-page-sub{font-size:13px;color:#94a3b8;margin:2px 0 0;font-weight:500;}

/* Badge de notificación */
.sol-badge {
    position:absolute;top:-8px;right:-8px;
    background:#ef4444;color:#fff;
    font-size:11px;font-weight:800;
    min-width:20px;height:20px;border-radius:10px;
    padding:0 5px;
    display:flex;align-items:center;justify-content:center;
    border:2px solid #fff;
    animation:pulse-badge 2s infinite;
    box-shadow:0 0 0 0 rgba(239,68,68,.6);
}
@keyframes pulse-badge {
    0%   { box-shadow: 0 0 0 0 rgba(239,68,68,.6); }
    70%  { box-shadow: 0 0 0 7px rgba(239,68,68,0); }
    100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); }
}

.btn-navy {
    background:#0a2748;color:#fff;
    border:2px solid #0a2748;border-radius:10px;
    padding:8px 16px;font-size:13.5px;font-weight:700;
    transition:all .2s ease;text-decoration:none;
    display:inline-flex;align-items:center;gap:6px;
}
.btn-navy:hover{background:#1e4d8c;border-color:#1e4d8c;color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(10,39,72,.15);}
.btn-outline-navy{
    background:transparent;color:#0a2748;
    border:2px solid #0a2748;border-radius:10px;
    padding:8px 16px;font-size:13.5px;font-weight:700;
    transition:all .2s ease;text-decoration:none;
    display:inline-flex;align-items:center;gap:6px;position:relative;
}
.btn-outline-navy:hover{background:rgba(10,39,72,.05);color:#0a2748;transform:translateY(-1px);}

/* Stats row — como supervisor_index */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px;}
.stat-mini{background:#fff;border:1.5px solid #d7e0ea;border-radius:14px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 8px rgba(10,39,72,.06);}
.stat-mini-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.smi-blue{background:rgba(10,39,72,.08);color:#0a2748;}
.smi-green{background:rgba(34,197,94,.1);color:#16a34a;}
.smi-purple{background:rgba(139,92,246,.08);color:#7c3aed;}
.smi-red{background:rgba(239,68,68,.08);color:#dc2626;}
.stat-mini-val{font-size:1.4rem;font-weight:900;color:#0a2748;line-height:1;}
.stat-mini-lbl{font-size:.75rem;color:#64748b;margin-top:3px;}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #d7e0ea;border-radius:10px;padding:8px 14px;flex:1;min-width:200px;max-width:340px;box-shadow:0 2px 8px rgba(10,39,72,.04);}
.search-box input{background:none;border:none;outline:none;color:#0a2748;font-size:.88rem;width:100%;}
.search-box input::placeholder{color:#94a3b8;}
.filter-btns{display:flex;gap:6px;flex-wrap:wrap;}
.fbtn{padding:7px 15px;border-radius:9px;font-size:.8rem;font-weight:700;border:1.5px solid #d7e0ea;background:#fff;color:#64748b;cursor:pointer;transition:.2s;text-decoration:none;}
.fbtn.active,.fbtn:hover{background:rgba(10,39,72,.06);color:#0a2748;border-color:#0a2748;}
.fbtn-add{background:#0a2748;color:#ffdd00;border-color:#0a2748;display:flex;align-items:center;gap:6px;}
.fbtn-add:hover{background:#1e4d8c;color:#ffdd00;border-color:#1e4d8c;}

/* Cards grid — como mis_asesores */
.sups-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;margin-bottom:32px;}
.sup-card{
    background:#fff;border-radius:18px;
    border:2px solid #e2eaf4;
    box-shadow:0 3px 12px rgba(10,39,72,.07);
    transition:all .2s;overflow:hidden;
}
.sup-card:hover{transform:translateY(-4px);box-shadow:0 10px 28px rgba(10,39,72,.15);border-color:#93c5fd;}
.sup-card.active{border-color:#0a2748;box-shadow:0 6px 22px rgba(10,39,72,.22);}
.sup-stripe{height:5px;background:linear-gradient(90deg,#0a2748,#1e4d8c,#ffdd00);}
.sup-card-body{padding:18px 18px 0;}
.sup-card-header{display:flex;align-items:center;gap:14px;margin-bottom:14px;}
.sup-avatar{
    width:48px;height:48px;border-radius:14px;
    background:linear-gradient(135deg,#0a2748,#1e4d8c);
    display:flex;align-items:center;justify-content:center;
    font-size:1.1rem;font-weight:900;color:#ffdd00;
    flex-shrink:0;letter-spacing:.5px;
    box-shadow:0 3px 10px rgba(10,39,72,.2);
}
.sup-name{font-size:.98rem;font-weight:800;color:#0a2748;line-height:1.2;}
.sup-email{font-size:.75rem;color:#64748b;margin-top:2px;}

/* Badges */
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;}
.badge-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}
.badge-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.badge-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a;}
.alert-chip{display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;font-size:.7rem;font-weight:700;padding:2px 8px;}

/* Stats dentro de la card — 3 columnas */
.sup-card-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px;}
.sc-stat{background:#f8fafc;border-radius:10px;padding:10px 8px;text-align:center;border:1px solid #edf2f9;}
.sc-stat-val{font-size:1.1rem;font-weight:900;color:#0a2748;}
.sc-stat-lbl{font-size:.68rem;color:#64748b;margin-top:2px;font-weight:600;}

/* Meta bar */
.meta-bar{margin-bottom:14px;}
.meta-bar-row{display:flex;justify-content:space-between;margin-bottom:4px;}
.meta-bar-label{font-size:.73rem;color:#64748b;font-weight:600;}
.meta-bar-value{font-size:.73rem;font-weight:700;color:#0a2748;}
.meta-track{height:5px;background:#e8eef6;border-radius:3px;overflow:hidden;}
.meta-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#0a2748,#1e4d8c);}

/* Footer de la card */
.sup-card-footer{
    display:flex;align-items:center;justify-content:space-between;
    padding:12px 18px;
    background:#f8fafc;
    border-top:1px solid #edf2f9;
}
.sup-actions{display:flex;gap:6px;}
.btn-sm{padding:5px 12px;border-radius:8px;font-size:.75rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;transition:.18s;border:none;cursor:pointer;}
.btn-outline{background:#eef2ff;color:#4f46e5;border:1px solid #c7d2fe;}
.btn-outline:hover{background:#dde4ff;}
.btn-danger-sm{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}
.btn-danger-sm:hover{background:#fee2e2;}

/* Flechita expandir asesores */
.sup-arrow{
    width:32px;height:32px;border-radius:50%;
    background:#eef2ff;display:flex;align-items:center;justify-content:center;
    color:#4f46e5;font-size:14px;cursor:pointer;
    transition:transform .25s,background .18s;
    border:none;flex-shrink:0;
}
.sup-arrow:hover{background:#dde4ff;}
.sup-arrow.rotated{background:#0a2748;color:#ffdd00;transform:rotate(90deg);}

/* Panel expandible de asesores */
.asesores-panel{
    display:none;
    background:#f0f5fb;
    border-radius:0 0 18px 18px;
    border-top:2px solid #0a2748;
    padding:20px;
    animation:apIn .22s ease-out;
}
.asesores-panel.show{display:block;}
@keyframes apIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

.ap-header{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:14px;padding-bottom:10px;
    border-bottom:2px solid #dde5f0;flex-wrap:wrap;gap:8px;
}
.ap-title{
    font-size:14px;font-weight:800;color:#0a2748;
    display:flex;align-items:center;gap:8px;
}
.ap-title i{color:#ffdd00;background:#0a2748;width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;}
.ap-close{
    border:none;background:rgba(10,39,72,.08);color:#0a2748;
    width:28px;height:28px;border-radius:50%;cursor:pointer;
    font-size:13px;display:flex;align-items:center;justify-content:center;
    transition:.18s;
}
.ap-close:hover{background:#0a2748;color:#fff;}

/* Grid de asesores dentro del panel */
.asesores-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
    gap:12px;
}
.asesor-mini{
    background:#fff;border-radius:12px;
    border:1.5px solid #d7e0ea;padding:12px 14px;
    display:flex;align-items:center;gap:10px;
    box-shadow:0 2px 6px rgba(10,39,72,.05);
    transition:.18s;
}
.asesor-mini:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(10,39,72,.12);border-color:#93c5fd;}
.asesor-mini-av{
    width:32px;height:32px;border-radius:8px;
    background:linear-gradient(135deg,#0a2748,#1e4d8c);
    display:flex;align-items:center;justify-content:center;
    font-size:12px;font-weight:900;color:#ffdd00;flex-shrink:0;
}
.asesor-mini-info{min-width:0;flex:1;}
.asesor-mini-name{font-size:12px;font-weight:800;color:#0a2748;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.asesor-mini-meta{font-size:10px;color:#64748b;margin-top:1px;}
.asesor-mini-clientes{font-size:10px;font-weight:700;color:#0a2748;background:#eef2ff;padding:2px 8px;border-radius:20px;white-space:nowrap;}

/* Empty dentro del panel */
.ap-empty{text-align:center;padding:20px;color:#94a3b8;font-size:13px;}
.ap-empty i{display:block;font-size:24px;margin-bottom:6px;opacity:.4;}

/* ── Panel de clientes por asesor (igual que mis_asesores) ── */
.asesor-mini{cursor:pointer;}
.asesor-mini.active{border-color:#0a2748;background:#eef4fc;}
.cp-panel{
    display:none;
    background:#f0f5fb;
    border-radius:18px;
    border:2px solid #0a2748;
    padding:20px;
    margin-top:14px;
    grid-column:1/-1;
    animation:cpIn .22s ease-out;
}
.cp-panel.show{display:block;}
@keyframes cpIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.cp-panel-header{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:16px;padding-bottom:12px;
    border-bottom:2px solid #dde5f0;
    flex-wrap:wrap;gap:8px;
}
.cp-panel-title{font-size:15px;font-weight:800;color:#0a2748;display:flex;align-items:center;gap:8px;}
.cp-panel-title i{color:#ffdd00;background:#0a2748;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;}
.cp-close{
    border:none;background:rgba(10,39,72,.08);color:#0a2748;
    width:32px;height:32px;border-radius:50%;cursor:pointer;
    font-size:14px;display:flex;align-items:center;justify-content:center;transition:.18s;
}
.cp-close:hover{background:#0a2748;color:#fff;}
.cp-search{
    display:flex;align-items:center;gap:6px;background:#fff;
    border:1.5px solid #d7e0ea;border-radius:8px;padding:6px 10px;width:240px;
}
.cp-search i{color:#94a3b8;font-size:12px;}
.cp-search input{border:none;outline:none;flex:1;font-size:13px;min-width:0;}
.clientes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;}
.cc{
    background:#fff;border-radius:14px;border:1.5px solid #d7e0ea;
    box-shadow:0 2px 8px rgba(10,39,72,.06);padding:14px 15px;transition:all .18s;
}
.cc:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(10,39,72,.13);border-color:#93c5fd;}
.cc-top{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;}
.cc-icon{
    width:36px;height:36px;border-radius:10px;flex-shrink:0;
    background:linear-gradient(135deg,#eff6ff,#dbeafe);
    display:flex;align-items:center;justify-content:center;color:#1e40af;font-size:14px;
}
.cc-name{font-size:13px;font-weight:800;color:#0a2748;margin:0 0 2px;line-height:1.3;}
.cc-ci{font-size:11px;color:#94a3b8;font-weight:600;margin:0;}
.cc-contact{
    font-size:11px;color:#64748b;display:flex;flex-direction:column;gap:3px;
    padding-top:8px;border-top:1px solid #f0f4f8;
}
.cc-contact span{display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cc-contact i{color:#94a3b8;width:12px;flex-shrink:0;}
.cc-status{margin-top:9px;padding-top:8px;border-top:1px solid #f0f4f8;}
.pill-activo{
    display:inline-flex;align-items:center;gap:4px;
    background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;
    border-radius:20px;padding:2px 10px;font-size:10.5px;font-weight:700;
}
.pill-inactivo{
    display:inline-flex;align-items:center;gap:4px;
    background:#fef2f2;color:#991b1b;border:1px solid #fecaca;
    border-radius:20px;padding:2px 10px;font-size:10.5px;font-weight:700;
}
.cc-empty{
    grid-column:1/-1;text-align:center;padding:30px 20px;
    color:#94a3b8;font-size:14px;background:#fff;
    border-radius:14px;border:1.5px dashed #d7e0ea;
}
.cc-empty i{display:block;font-size:24px;margin-bottom:8px;opacity:.5;}
.d-none{display:none!important;}

/* Estado vacío global */
.empty-state{text-align:center;padding:60px 20px;}
.empty-icon{font-size:3rem;color:#94a3b8;margin-bottom:16px;}
.empty-state h3{font-size:1.1rem;font-weight:700;color:#475569;margin:0 0 8px;}
.empty-state p{font-size:.85rem;color:#64748b;}

/* Responsive */
@media(max-width:900px){
    .stats-row{grid-template-columns:1fr 1fr;}
}
@media(max-width:600px){
    .stats-row{grid-template-columns:1fr;}
    .sups-grid{grid-template-columns:1fr;}
    .toolbar{flex-direction:column;align-items:stretch;}
    .search-box{max-width:100%;}
}
</style>
</head>
<body>
<?php
$alertas_pendientes = $alertas_pendientes_sidebar;
require_once '_sidebar_gerente.php';
?>

<!-- ══════════ CONTENIDO PRINCIPAL ══════════ -->

<!-- HEADER -->
<div class="ma-page-header">
    <div class="ma-page-icon"><i class="fas fa-users-gear"></i></div>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3" style="flex:1;">
        <div>
            <h1 class="ma-page-title">Mis Supervisores</h1>
            <p class="ma-page-sub">Supervisores bajo tu gestión · <?= date('d/m/Y') ?></p>
        </div>
    </div>
</div>

<?php
$total_asesores_suma = array_sum(array_column($supervisores, 'total_asesores'));
$total_clientes_suma = array_sum(array_column($supervisores, 'total_clientes'));
$total_alertas_suma  = array_sum(array_column($supervisores, 'alertas_sin_ver'));
?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-mini">
        <div class="stat-mini-icon smi-blue"><i class="fas fa-users-gear"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_sups ?></div>
            <div class="stat-mini-lbl">Supervisores</div>
        </div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-icon smi-green"><i class="fas fa-user-tie"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_asesores_suma ?></div>
            <div class="stat-mini-lbl">Asesores activos</div>
        </div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-icon smi-purple"><i class="fas fa-address-book"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_clientes_suma ?></div>
            <div class="stat-mini-lbl">Clientes totales</div>
        </div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-icon smi-red"><i class="fas fa-bell"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_alertas_suma ?></div>
            <div class="stat-mini-lbl">Alertas sin ver</div>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <form method="get" action="" style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
        <div class="search-box">
            <i class="fas fa-search" style="color:#94a3b8;"></i>
            <input type="text" name="q" placeholder="Buscar supervisor..." value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="filter-btns">
            <?php
            $estados = ['todos' => 'Todos', 'activo' => 'Activos', 'inactivo' => 'Inactivos', 'pendiente' => 'Pendientes'];
            foreach ($estados as $val => $lbl):
                $extra = ($filtro_est === $val) ? ' active' : '';
            ?>
            <button type="submit" name="estado" value="<?= $val ?>" class="fbtn<?= $extra ?>"><?= $lbl ?></button>
            <?php endforeach; ?>
        </div>
    </form>
    <a href="registro_supervisor.php" class="btn-navy">
        <i class="fas fa-user-plus"></i> Crear Supervisor
    </a>
    <a href="administrar_solicitudes_supervisor.php" class="btn-outline-navy" style="position:relative;">
        <i class="fas fa-file-circle-check"></i> Solicitudes de Supervisores
        <?php if ($solicitudes_supervisores > 0): ?>
        <span class="sol-badge"><?= $solicitudes_supervisores ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if (empty($supervisores)): ?>
<div class="empty-state">
    <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
    <?php if ($q || $filtro_est !== 'todos'): ?>
        <h3>Sin resultados</h3>
        <p>No se encontraron supervisores con esos filtros. <a href="mis_supervisores.php" style="color:#0a2748;">Limpiar filtros</a></p>
    <?php elseif (empty($pre_sup_ids)): ?>
        <h3>Sin supervisores asignados</h3>
        <p>Tu cuenta aún no tiene supervisores a cargo. Verifica que tu perfil esté vinculado a una agencia o contacta al administrador.</p>
    <?php else: ?>
        <h3>No hay supervisores registrados</h3>
        <p>Aún no tienes supervisores asignados. <a href="registro_supervisor.php" style="color:#0a2748;">Agregar el primero</a></p>
    <?php endif; ?>
</div>

<?php else: ?>

<!-- Cards Grid -->
<div class="sups-grid">
<?php foreach ($supervisores as $sup):
    $nombre    = $sup['nombre'] ?? '—';
    $email     = $sup['email']  ?? '';
    $activo    = (bool)($sup['activo'] ?? 0);
    $aprobado  = $sup['estado_aprobacion'] ?? 'pendiente';
    $asesores_ = (int)($sup['total_asesores'] ?? 0);
    $clientes_ = (int)($sup['total_clientes'] ?? 0);
    $tareas_   = (int)($sup['tareas_hoy']     ?? 0);
    $alertas_  = (int)($sup['alertas_sin_ver'] ?? 0);
    $meta_     = (int)($sup['meta_asesores']  ?? 0);
    $uid       = $sup['usuario_id']         ?? '';
    $sid       = $sup['supervisor_table_id'] ?? '';
    $supKey    = $sid;
?>
<div class="sup-card" id="sup-card-<?= $supKey ?>">
    <div class="sup-stripe"></div>
    <div class="sup-card-body">
        <div class="sup-card-header">
            <div class="sup-avatar"><?= iniciales($nombre) ?></div>
            <div style="flex:1;min-width:0;">
                <div class="sup-name"><?= htmlspecialchars($nombre) ?></div>
                <div class="sup-email"><?= htmlspecialchars($email) ?></div>
                <div style="margin-top:5px;">
                    <?= badge_estado($sup) ?>
                    <?php if ($alertas_ > 0): ?>
                    <span class="alert-chip" style="margin-left:4px;"><i class="fas fa-bell"></i> <?= $alertas_ ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="sup-card-stats">
            <div class="sc-stat">
                <div class="sc-stat-val"><?= $asesores_ ?></div>
                <div class="sc-stat-lbl">Asesores</div>
            </div>
            <div class="sc-stat">
                <div class="sc-stat-val"><?= $clientes_ ?></div>
                <div class="sc-stat-lbl">Clientes</div>
            </div>
            <div class="sc-stat">
                <div class="sc-stat-val"><?= $tareas_ ?></div>
                <div class="sc-stat-lbl">Tareas hoy</div>
            </div>
        </div>

        <?php if ($meta_ > 0): ?>
        <div class="meta-bar">
            <div class="meta-bar-row">
                <span class="meta-bar-label">Meta asesores</span>
                <span class="meta-bar-value"><?= $asesores_ ?> / <?= $meta_ ?></span>
            </div>
            <div class="meta-track">
                <div class="meta-fill" style="width:<?= min(100, $meta_ > 0 ? round($asesores_*100/$meta_) : 0) ?>%;"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="sup-card-footer">
        <div class="sup-actions">
            <a href="perfil_supervisor.php?id=<?= urlencode($uid) ?>" class="btn-sm btn-outline">
                <i class="fas fa-eye"></i> Ver perfil
            </a>
            <?php if ($alertas_ > 0): ?>
            <a href="alertas.php?supervisor_id=<?= urlencode($sid) ?>" class="btn-sm btn-danger-sm">
                <i class="fas fa-bell"></i>
            </a>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:.72rem;color:#64748b;font-weight:600;"><?= $asesores_ ?> asesores</span>
            <button class="sup-arrow" id="arrow-<?= $supKey ?>" onclick="toggleAsesores('<?= $supKey ?>')">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <!-- Panel expandible de asesores -->
    <div class="asesores-panel" id="panel-<?= $supKey ?>">
        <div class="ap-header">
            <div class="ap-title">
                <i class="fas fa-users"></i>
                Asesores de <strong style="margin-left:4px;"><?= htmlspecialchars($nombre) ?></strong>
                <span style="background:#e0f2fe;color:#0369a1;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:800;margin-left:6px;"><?= $asesores_ ?></span>
            </div>
            <button class="ap-close" onclick="toggleAsesores('<?= $supKey ?>')"><i class="fas fa-times"></i></button>
        </div>
        <div class="asesores-grid" id="asesores-grid-<?= $supKey ?>">
            <?php
            $lista = $asesores_por_sup[$sid] ?? [];
            if (empty($lista)): ?>
            <div class="ap-empty" style="grid-column:1/-1;">
                <i class="fas fa-inbox"></i>
                Sin asesores activos asignados
            </div>
            <?php else:
            foreach ($lista as $ase):
                $aid     = htmlspecialchars($ase['asesor_id'] ?? '', ENT_QUOTES, 'UTF-8');
                $anombre = htmlspecialchars($ase['nombre'] ?? '—');
                $aemail  = htmlspecialchars($ase['email'] ?? '');
                $atel    = htmlspecialchars($ase['telefono'] ?? '');
                $aclientes = (int)($ase['total_clientes'] ?? 0);
                $ainicial = mb_strtoupper(mb_substr(trim($ase['nombre'] ?? 'A'), 0, 1));
            ?>
            <div class="asesor-mini" id="ase-<?= $aid ?>" onclick="toggleClientes('<?= $aid ?>')" title="Ver clientes de <?= $anombre ?>">
                <div class="asesor-mini-av"><?= $ainicial ?></div>
                <div class="asesor-mini-info">
                    <div class="asesor-mini-name" title="<?= $anombre ?>"><?= $anombre ?></div>
                    <div class="asesor-mini-meta"><i class="fas fa-envelope" style="margin-right:3px;"></i><?= $aemail ?></div>
                </div>
                <span class="asesor-mini-clientes"><i class="fas fa-user-group"></i> <?= $aclientes ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Paneles de clientes por asesor -->
        <?php foreach ($lista as $ase):
            $aid      = htmlspecialchars($ase['asesor_id'] ?? '', ENT_QUOTES, 'UTF-8');
            $anombre  = htmlspecialchars($ase['nombre'] ?? '—');
            $clientes = $clientes_por_asesor[$ase['asesor_id'] ?? ''] ?? [];
        ?>
        <div class="cp-panel" id="cpanel-<?= $aid ?>">
            <div class="cp-panel-header">
                <div class="cp-panel-title">
                    <i class="fas fa-users"></i>
                    Clientes de <strong style="margin-left:4px;"><?= $anombre ?></strong>
                    <span style="background:#e0f2fe;color:#0369a1;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:800;margin-left:6px;"><?= count($clientes) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php if (!empty($clientes)): ?>
                    <div class="cp-search">
                        <i class="fas fa-search"></i>
                        <input type="text" class="client-search-input" placeholder="Buscar cliente..." data-asesor-id="<?= $aid ?>" oninput="filterClients(this)">
                    </div>
                    <?php endif; ?>
                    <button class="cp-close" onclick="cerrarClientes('<?= $aid ?>')"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="clientes-grid">
                <?php if (empty($clientes)): ?>
                    <div class="cc-empty"><i class="fas fa-inbox"></i>Sin clientes asignados aún</div>
                <?php else: foreach ($clientes as $c):
                    $cnombre = trim($c['nombre'] ?? '');
                    $cnombre = htmlspecialchars($cnombre !== '' ? $cnombre : 'Sin nombre');
                    $ccedula = htmlspecialchars($c['cedula'] ?? '');
                    $cemail  = htmlspecialchars($c['email'] ?? '');
                    $ctel    = htmlspecialchars($c['telefono2'] ?: ($c['telefono'] ?? ''));
                    $activo  = !empty($c['activo']);
                ?>
                <div class="cc"
                     data-search-name="<?= mb_strtolower($cnombre) ?>"
                     data-search-cedula="<?= mb_strtolower($ccedula) ?>">
                    <div class="cc-top">
                        <div class="cc-icon"><i class="fas fa-user"></i></div>
                        <div style="min-width:0;">
                            <p class="cc-name"><?= $cnombre ?></p>
                            <?php if ($ccedula): ?>
                            <p class="cc-ci"><i class="fas fa-id-card" style="margin-right:3px;"></i>CI: <?= $ccedula ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cc-contact">
                        <?php if ($cemail): ?><span><i class="fas fa-envelope"></i><?= $cemail ?></span><?php endif; ?>
                        <?php if ($ctel): ?><span><i class="fas fa-phone"></i><?= $ctel ?></span><?php endif; ?>
                    </div>
                    <div class="cc-status">
                        <?php if ($activo): ?>
                            <span class="pill-activo"><i class="fas fa-check-circle"></i> Activo</span>
                        <?php else: ?>
                            <span class="pill-inactivo"><i class="fas fa-times-circle"></i> Inactivo</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="cc-empty d-none client-search-empty">
                    <i class="fas fa-search-minus"></i>
                    No se encontraron clientes coincidentes
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<script>
var supervisorActivo = null;

function toggleAsesores(id) {
    if (supervisorActivo === id) {
        cerrarPanel(id);
        return;
    }
    if (supervisorActivo) cerrarPanel(supervisorActivo);

    supervisorActivo = id;
    var card = document.getElementById('sup-card-' + id);
    if (card) card.classList.add('active');
    var arrow = document.getElementById('arrow-' + id);
    if (arrow) arrow.classList.add('rotated');
    var panel = document.getElementById('panel-' + id);
    if (panel) {
        panel.classList.add('show');
        setTimeout(function(){ panel.scrollIntoView({behavior:'smooth', block:'nearest'}); }, 80);
    }
}

function cerrarPanel(id) {
    var card = document.getElementById('sup-card-' + id);
    if (card) card.classList.remove('active');
    var arrow = document.getElementById('arrow-' + id);
    if (arrow) arrow.classList.remove('rotated');
    var panel = document.getElementById('panel-' + id);
    if (panel) panel.classList.remove('show');
    if (supervisorActivo === id) supervisorActivo = null;
    if (asesorActivo) cerrarClientes(asesorActivo);
}

/* ── Panel de clientes por asesor ── */
var asesorActivo = null;

function toggleClientes(id) {
    if (asesorActivo === id) {
        cerrarClientes(id);
        return;
    }
    if (asesorActivo) cerrarClientes(asesorActivo);

    asesorActivo = id;
    var mini = document.getElementById('ase-' + id);
    if (mini) mini.classList.add('active');
    var panel = document.getElementById('cpanel-' + id);
    if (panel) {
        panel.classList.add('show');
        setTimeout(function(){ panel.scrollIntoView({behavior:'smooth', block:'nearest'}); }, 80);
    }
}

function cerrarClientes(id) {
    var mini = document.getElementById('ase-' + id);
    if (mini) mini.classList.remove('active');
    var panel = document.getElementById('cpanel-' + id);
    if (panel) {
        panel.classList.remove('show');
        var inp = panel.querySelector('.client-search-input');
        if (inp) { inp.value = ''; filterClients(inp); }
    }
    if (asesorActivo === id) asesorActivo = null;
}

function filterClients(input) {
    var query = input.value.toLowerCase().trim();
    var panel = document.getElementById('cpanel-' + input.getAttribute('data-asesor-id'));
    if (!panel) return;

    var cards = panel.querySelectorAll('.clientes-grid .cc');
    var visibles = 0;
    cards.forEach(function(card) {
        var name = card.getAttribute('data-search-name') || '';
        var cedula = card.getAttribute('data-search-cedula') || '';
        if (name.includes(query) || cedula.includes(query)) {
            card.style.display = '';
            visibles++;
        } else {
            card.style.display = 'none';
        }
    });

    var emptyEl = panel.querySelector('.client-search-empty');
    if (emptyEl) emptyEl.classList.toggle('d-none', visibles > 0);
}
</script>

</div><!-- .content-area -->
</div><!-- .main-content -->
</body>
</html>
