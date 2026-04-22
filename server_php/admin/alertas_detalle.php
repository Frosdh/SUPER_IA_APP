<?php
require_once 'db_admin.php';

if (!isset($_GET['id'])) { header('Location: alertas.php'); exit; }
$id = $_GET['id'];

// ── Detectar modo AJAX ──────────────────────────────────────────
$is_ajax = (
    (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);

// ── Alerta principal ────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT am.*,
           t.id          AS tarea_ref,
           t.cliente_prospecto_id AS cliente_id,
           cp.nombre     AS cliente_nombre,
           a.id          AS asesor_table_id,
           u.nombre      AS asesor_nombre
    FROM alerta_modificacion am
    JOIN tarea t        ON am.tarea_id = t.id
    LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
    LEFT JOIN asesor a  ON am.asesor_id = a.id
    LEFT JOIN usuario u ON a.usuario_id = u.id
    WHERE am.id = :id LIMIT 1
');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    if ($is_ajax) { http_response_code(404); echo '<div class="p-4 text-danger">Alerta no encontrada</div>'; exit; }
    header('Location: alertas.php'); exit;
}

$tarea_ref        = $row['tarea_ref']  ?? null;
$cliente_table_id = $row['cliente_id'] ?? null;

// ── Decode snapshots JSON ───────────────────────────────────────
$ant_raw = $row['valor_anterior'] ?? '';
$new_raw = $row['valor_nuevo']    ?? '';
$ant = ($ant_raw !== null && $ant_raw !== '') ? json_decode($ant_raw, true) : null;
$new = ($new_raw !== null && $new_raw !== '') ? json_decode($new_raw, true) : null;

// fallback DB si faltan snapshots
if ($ant === null) {
    $ant = ['cliente'=>null,'encuesta_comercial'=>null,'encuesta_negocio'=>null,'acuerdo_visita'=>null];
    try {
        if ($cliente_table_id) { $s=$pdo->prepare('SELECT * FROM cliente_prospecto WHERE id=? LIMIT 1'); $s->execute([$cliente_table_id]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ant['cliente']=$r; }
        if ($tarea_ref) {
            $s=$pdo->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ant['encuesta_comercial']=$r;
            $s=$pdo->prepare('SELECT * FROM encuesta_negocio    WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ant['encuesta_negocio']=$r;
            $s=$pdo->prepare('SELECT * FROM acuerdo_visita      WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ant['acuerdo_visita']=$r;
        }
    } catch (\Throwable $_) {}
}
if ($new === null) {
    $new = ['cliente'=>null,'encuesta_comercial'=>null,'encuesta_negocio'=>null,'acuerdo_visita'=>null];
    try {
        if ($cliente_table_id) { $s=$pdo->prepare('SELECT * FROM cliente_prospecto WHERE id=? LIMIT 1'); $s->execute([$cliente_table_id]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $new['cliente']=$r; }
        if ($tarea_ref) {
            $s=$pdo->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $new['encuesta_comercial']=$r;
            $s=$pdo->prepare('SELECT * FROM encuesta_negocio    WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $new['encuesta_negocio']=$r;
            $s=$pdo->prepare('SELECT * FROM acuerdo_visita      WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $new['acuerdo_visita']=$r;
        }
    } catch (\Throwable $_) {}
}

// ── Datos completos del cliente ─────────────────────────────────
$cliente = null;
try {
    $s = $pdo->prepare('SELECT cp.*, u.nombre AS asesor_nombre FROM cliente_prospecto cp LEFT JOIN asesor a ON a.id=cp.asesor_id LEFT JOIN usuario u ON u.id=a.usuario_id WHERE cp.id=? LIMIT 1');
    $s->execute([$cliente_table_id]);
    $cliente = $s->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

// ── Encuesta comercial (más reciente del cliente) ───────────────
$encuesta_com = null;
try {
    $s=$pdo->prepare('SELECT * FROM encuesta_comercial WHERE cliente_prospecto_id=? ORDER BY id DESC LIMIT 1');
    $s->execute([$cliente_table_id]); $encuesta_com=$s->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

// ── Encuesta negocio ────────────────────────────────────────────
$encuesta_neg = null;
try {
    $s=$pdo->prepare('SELECT * FROM encuesta_negocio WHERE cliente_prospecto_id=? ORDER BY id DESC LIMIT 1');
    $s->execute([$cliente_table_id]); $encuesta_neg=$s->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    // fallback: buscar por tarea_id
    try {
        if ($tarea_ref) { $s=$pdo->prepare('SELECT * FROM encuesta_negocio WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $encuesta_neg=$s->fetch(PDO::FETCH_ASSOC); }
    } catch (\Throwable $_) {}
}

// ── Acuerdo de visita (más reciente) ───────────────────────────
$acuerdo = null;
try {
    if ($tarea_ref) { $s=$pdo->prepare('SELECT * FROM acuerdo_visita WHERE tarea_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$tarea_ref]); $acuerdo=$s->fetch(PDO::FETCH_ASSOC); }
    if (!$acuerdo && $cliente_table_id) { $s=$pdo->prepare('SELECT av.* FROM acuerdo_visita av JOIN tarea t ON t.id=av.tarea_id WHERE t.cliente_prospecto_id=? ORDER BY av.id DESC LIMIT 1'); $s->execute([$cliente_table_id]); $acuerdo=$s->fetch(PDO::FETCH_ASSOC); }
} catch (\Throwable $_) {}

// ── Todas las tareas del cliente ────────────────────────────────
$tareas = [];
try {
    $s=$pdo->prepare('SELECT t.*, u.nombre AS asesor_nombre FROM tarea t LEFT JOIN asesor a ON a.id=t.asesor_id LEFT JOIN usuario u ON u.id=a.usuario_id WHERE t.cliente_prospecto_id=? ORDER BY t.created_at DESC');
    $s->execute([$cliente_table_id]); $tareas=$s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

// ── Fichas de producto ──────────────────────────────────────────
$ficha_credito=$ficha_corriente=$ficha_ahorros=$ficha_inversiones=null;
try {
    $s=$pdo->prepare('SELECT * FROM ficha_producto WHERE cliente_cedula=? ORDER BY created_at DESC');
    $s->execute([$cliente['cedula'] ?? '']); $fichas=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fichas as $fp) {
        try {
            switch ($fp['producto_tipo']) {
                case 'credito':          if (!$ficha_credito)     { $s=$pdo->prepare('SELECT * FROM ficha_credito WHERE ficha_id=? LIMIT 1');          $s->execute([$fp['id']]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ficha_credito    =array_merge($fp,$r); } break;
                case 'cuenta_corriente': if (!$ficha_corriente)   { $s=$pdo->prepare('SELECT * FROM ficha_cuenta_corriente WHERE ficha_id=? LIMIT 1'); $s->execute([$fp['id']]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ficha_corriente =array_merge($fp,$r); } break;
                case 'cuenta_ahorros':  if (!$ficha_ahorros)     { $s=$pdo->prepare('SELECT * FROM ficha_cuenta_ahorros WHERE ficha_id=? LIMIT 1');   $s->execute([$fp['id']]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ficha_ahorros   =array_merge($fp,$r); } break;
                case 'inversiones':     if (!$ficha_inversiones)  { $s=$pdo->prepare('SELECT * FROM ficha_inversiones WHERE ficha_id=? LIMIT 1');      $s->execute([$fp['id']]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) $ficha_inversiones=array_merge($fp,$r); } break;
            }
        } catch (\Throwable $_) {}
    }
} catch (\Throwable $_) {}

// ── Trámites de crédito ─────────────────────────────────────────
$tramites = [];
try {
    $s=$pdo->prepare('SELECT cp.*, u.nombre AS asesor_nombre FROM credito_proceso cp LEFT JOIN asesor a ON a.id=cp.asesor_id LEFT JOIN usuario u ON u.id=a.usuario_id WHERE cp.cliente_prospecto_id=? ORDER BY cp.created_at DESC');
    $s->execute([$cliente_table_id]); $tramites=$s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

// ── Helpers ─────────────────────────────────────────────────────
function normalize_for_compare($v) {
    if ($v===null) return '';
    if (is_bool($v)) return $v?'1':'0';
    if (is_scalar($v)) return trim((string)$v);
    if (is_array($v)||is_object($v)) { $a=is_array($v)?$v:(array)$v; ksort($a); return json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    return trim((string)$v);
}
function dat($v, $suf='') {
    if ($v===null||trim((string)$v)==='') return '<span class="dat-empty">—</span>';
    return '<strong>'.htmlspecialchars($v).'</strong>'.($suf?" $suf":'');
}
function erow($label,$val,$suf='') {
    return '<div class="d-row"><span class="d-lbl">'.htmlspecialchars($label).'</span><span class="d-val">'.dat($val,$suf).'</span></div>';
}
function eyn($label,$v) {
    if ($v===null||$v==='') $chip='<span class="dat-empty">—</span>';
    elseif (intval($v)===1||$v==='si'||$v===true) $chip='<span class="chip-si">Sí</span>';
    else $chip='<span class="chip-no">No</span>';
    return '<div class="d-row"><span class="d-lbl">'.htmlspecialchars($label).'</span><span class="d-val">'.$chip.'</span></div>';
}
function estado_badge($e) {
    $map=['completada'=>['#ecfdf5','#065f46','✓'],'en_proceso'=>['#dbeafe','#1e40af','⚡'],'programada'=>['#fffbeb','#92400e','📅'],'pendiente'=>['#fffbeb','#92400e','⏳'],'cancelada'=>['#fef2f2','#991b1b','✗'],'postergada'=>['#f3e8ff','#6b21a8','↺']];
    [$bg,$cl,$ic]=$map[$e]??['#f3f4f6','#6b7280','?'];
    return "<span style='background:$bg;color:$cl;border-radius:6px;padding:2px 9px;font-size:12px;font-weight:700;'>$ic ".htmlspecialchars(ucfirst(str_replace('_',' ',$e)))."</span>";
}
function tipo_tarea_label($t) {
    return ['prospecto_nuevo'=>'Prospecto nuevo','nueva_cita_campo'=>'Nueva cita campo','nueva_cita_oficina'=>'Nueva cita oficina','documentos_pendientes'=>'Documentación pendiente','levantamiento'=>'Levantamiento','recuperacion'=>'Recuperación','post_venta'=>'Post-venta'][$t]??ucfirst(str_replace('_',' ',$t));
}

// ── Contar cambios totales ──────────────────────────────────────
function count_sec_changes($a,$b) {
    $a=is_array($a)?$a:[]; $b=is_array($b)?$b:[];
    $skip=['id','created_at','updated_at','tarea_id','cliente_prospecto_id','asesor_id','supervisor_id','fallback_from_db'];
    $keys=array_unique(array_merge(array_keys($a),array_keys($b)));
    $c=0; foreach($keys as $k) { if(in_array($k,$skip)) continue; if(normalize_for_compare($a[$k]??null)!==normalize_for_compare($b[$k]??null)) $c++; }
    return $c;
}
$total_cambios = count_sec_changes($ant['cliente']??null,$new['cliente']??null)
               + count_sec_changes($ant['encuesta_comercial']??null,$new['encuesta_comercial']??null)
               + count_sec_changes($ant['encuesta_negocio']??null,$new['encuesta_negocio']??null)
               + count_sec_changes($ant['acuerdo_visita']??null,$new['acuerdo_visita']??null);

$fecha_alerta = !empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '';
$revisada = !empty($row['vista_supervisor']);

// ── AJAX mode: return only content fragment ─────────────────────
if ($is_ajax) {
    // CSS injected once inside the fragment (scoped to .alm-detalle)
    echo '<style>
.alm-detalle{font-family:"Inter","Segoe UI",sans-serif;color:#0a2748;}
.alm-detalle *{box-sizing:border-box;}
.alm-detalle .alert-hero{background:linear-gradient(135deg,#0a2748,#123a6d);border-radius:14px;padding:20px 22px;color:#fff;display:flex;gap:22px;align-items:flex-start;margin-bottom:18px;box-shadow:0 6px 20px rgba(10,39,72,.18);flex-wrap:wrap;}
.alm-detalle .hero-chip{display:inline-flex;align-items:center;gap:7px;background:rgba(255,221,0,.18);color:#ffdd00;padding:3px 11px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border:1px solid rgba(255,221,0,.3);margin-bottom:7px;}
.alm-detalle .hero-title{font-size:19px;font-weight:900;margin:0 0 7px;}
.alm-detalle .hero-sub{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12.5px;opacity:.9;}
.alm-detalle .hero-sub i{color:#ffdd00;margin-right:4px;}
.alm-detalle .hero-stats{display:flex;gap:12px;align-items:center;flex-shrink:0;}
.alm-detalle .stat-box{background:rgba(255,255,255,.09);border:1px solid rgba(255,221,0,.25);border-radius:10px;padding:10px 15px;text-align:center;min-width:90px;}
.alm-detalle .stat-num{font-size:24px;font-weight:900;color:#ffdd00;line-height:1;}
.alm-detalle .stat-lbl{font-size:9.5px;text-transform:uppercase;letter-spacing:.4px;opacity:.85;margin-top:3px;}
.alm-detalle .sec-card{background:#fff;border-radius:14px;border:1px solid #d7e0ea;box-shadow:0 2px 10px rgba(10,39,72,.06);margin-bottom:16px;overflow:hidden;}
.alm-detalle .sec-head{display:flex;align-items:center;gap:10px;padding:13px 18px;border-bottom:1px solid #d7e0ea;background:#fafbfc;}
.alm-detalle .sec-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.alm-detalle .ic-blue{background:rgba(18,58,109,.10);color:#123a6d;}
.alm-detalle .ic-green{background:rgba(16,185,129,.12);color:#059669;}
.alm-detalle .ic-yellow{background:rgba(245,158,11,.12);color:#d97706;}
.alm-detalle .ic-red{background:rgba(220,38,38,.10);color:#dc2626;}
.alm-detalle .ic-purple{background:rgba(124,58,237,.10);color:#7c3aed;}
.alm-detalle .ic-teal{background:rgba(20,184,166,.12);color:#0d9488;}
.alm-detalle .ic-orange{background:rgba(234,88,12,.10);color:#ea580c;}
.alm-detalle .sec-head h5{font-size:13.5px;font-weight:800;color:#0a2748;margin:0;}
.alm-detalle .badge-cnt{background:#ef4444;color:#fff;border-radius:7px;padding:2px 8px;font-size:10px;font-weight:700;margin-left:auto;}
.alm-detalle .badge-ok{background:#10b981;color:#fff;border-radius:7px;padding:2px 8px;font-size:10px;font-weight:700;margin-left:auto;}
.alm-detalle .sec-body{padding:16px 18px;}
.alm-detalle .legend{display:flex;gap:12px;padding:7px 10px;background:#fff;border:1px solid #e5e9f0;border-radius:8px;margin-bottom:12px;font-size:11px;flex-wrap:wrap;}
.alm-detalle .leg{display:inline-flex;align-items:center;gap:5px;color:#374151;font-weight:600;}
.alm-detalle .leg-dot{width:11px;height:11px;border-radius:3px;}
.alm-detalle .leg-b .leg-dot{background:#fee2e2;border:1px solid #ef4444;}
.alm-detalle .leg-a .leg-dot{background:#d1fae5;border:1px solid #10b981;}
.alm-detalle .leg-c .leg-dot{background:#fef3c7;border:1px solid #f59e0b;}
.alm-detalle .diff-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:12px;}
.alm-detalle .diff-table thead th{background:#f8fafc;color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;padding:9px 10px;text-align:left;border-bottom:2px solid #d7e0ea;}
.alm-detalle .diff-table tbody td{padding:8px 10px;border-bottom:1px solid #f0f2f5;vertical-align:top;}
.alm-detalle .diff-table tbody tr:last-child td{border-bottom:none;}
.alm-detalle .dk{font-weight:700;color:#0a2748;width:25%;white-space:nowrap;}
.alm-detalle .dk.changed{background:#fffbeb;}
.alm-detalle .da{background:#fef2f2;max-width:35%;}
.alm-detalle .db{background:#f0fdf4;max-width:35%;}
.alm-detalle .du{background:#fafbfc;}
.alm-detalle .sec-sub{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:#123a6d;margin:14px 0 7px;padding-bottom:4px;border-bottom:2px solid #ffdd00;display:flex;align-items:center;gap:6px;}
.alm-detalle .empty-note{color:#9ca3af;font-style:italic;font-size:12.5px;padding:10px 0;}
.alm-detalle .d-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:0;}
.alm-detalle .d-row{display:flex;flex-direction:column;padding:8px 0;border-bottom:1px solid rgba(215,224,234,.45);}
.alm-detalle .d-row:last-child{border-bottom:none;}
.alm-detalle .d-lbl{font-size:10.5px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px;}
.alm-detalle .d-val{font-size:13px;color:#0a2748;}
.alm-detalle .dat-empty{color:#b0bac5;font-style:italic;}
.alm-detalle .chip-si{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:6px;padding:2px 8px;font-size:11.5px;font-weight:700;}
.alm-detalle .chip-no{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:2px 8px;font-size:11.5px;font-weight:700;}
.alm-detalle .chip-prod{background:linear-gradient(135deg,#0a2748,#123a6d);color:#fff;border-radius:20px;padding:2px 10px;font-size:11.5px;font-weight:700;display:inline-block;margin:2px;}
.alm-detalle .t-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.alm-detalle .t-table th{background:#f8fafc;color:#6b7280;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;padding:9px 10px;text-align:left;border-bottom:2px solid #d7e0ea;}
.alm-detalle .t-table td{padding:9px 10px;border-bottom:1px solid #f0f2f5;vertical-align:middle;}
.alm-detalle .t-table tr:last-child td{border-bottom:none;}
.alm-detalle .t-table tr.highlight-row{background:rgba(255,221,0,.06);border-left:3px solid #ffdd00;}
.alm-detalle .cred-estado{display:inline-block;border-radius:6px;padding:2px 9px;font-size:11.5px;font-weight:700;}
.alm-detalle .pill-ok{background:#d1fae5;color:#065f46;border-radius:9px;padding:2px 10px;font-size:11px;font-weight:700;}
.alm-detalle .pill-alert{background:#fee2e2;color:#991b1b;border-radius:9px;padding:2px 10px;font-size:11px;font-weight:700;}
</style>';
    echo '<div class="alm-detalle">';
    // sections rendered below; at end of file we close </div> and exit
}

if (!$is_ajax):
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Detalle Alerta — Super_IA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0a2748;--navy2:#123a6d;--yellow:#ffdd00;--gray:#6b7280;--border:#d7e0ea;--bg:#f4f6f9;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter','Segoe UI',sans-serif;background:var(--bg);color:var(--navy);padding:0;}
.topbar{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:16px 32px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 18px rgba(10,39,72,.18);}
.topbar h1{font-size:18px;font-weight:800;margin:0;flex:1;}
.btn-back{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:10px;padding:8px 18px;text-decoration:none;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:8px;}
.btn-back:hover{background:rgba(255,221,0,.18);color:#ffdd00;}
.page-body{padding:28px 32px;max-width:1280px;margin:0 auto;}

/* Hero alerta */
.alert-hero{background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:18px;padding:24px 28px;color:#fff;display:flex;gap:28px;align-items:flex-start;margin-bottom:24px;box-shadow:0 8px 28px rgba(10,39,72,.18);flex-wrap:wrap;}
.hero-chip{display:inline-flex;align-items:center;gap:7px;background:rgba(255,221,0,.18);color:#ffdd00;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border:1px solid rgba(255,221,0,.3);margin-bottom:8px;}
.hero-title{font-size:22px;font-weight:900;margin:0 0 8px;}
.hero-sub{display:flex;flex-wrap:wrap;gap:8px 18px;font-size:13px;opacity:.9;}
.hero-sub i{color:#ffdd00;margin-right:4px;}
.hero-stats{display:flex;gap:14px;align-items:center;flex-shrink:0;}
.stat-box{background:rgba(255,255,255,.09);border:1px solid rgba(255,221,0,.25);border-radius:12px;padding:12px 18px;text-align:center;min-width:100px;}
.stat-num{font-size:28px;font-weight:900;color:#ffdd00;line-height:1;}
.stat-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.4px;opacity:.85;margin-top:4px;}
.pill-ok{background:#d1fae5;color:#065f46;border-radius:10px;padding:3px 11px;font-size:11px;font-weight:700;}
.pill-alert{background:#fee2e2;color:#991b1b;border-radius:10px;padding:3px 11px;font-size:11px;font-weight:700;}

/* Card sections */
.sec-card{background:#fff;border-radius:16px;border:1px solid var(--border);box-shadow:0 2px 12px rgba(10,39,72,.06);margin-bottom:20px;overflow:hidden;}
.sec-head{display:flex;align-items:center;gap:12px;padding:15px 20px;border-bottom:1px solid var(--border);background:#fafbfc;}
.sec-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.ic-blue{background:rgba(18,58,109,.10);color:var(--navy2);}
.ic-green{background:rgba(16,185,129,.12);color:#059669;}
.ic-yellow{background:rgba(245,158,11,.12);color:#d97706;}
.ic-red{background:rgba(220,38,38,.10);color:#dc2626;}
.ic-purple{background:rgba(124,58,237,.10);color:#7c3aed;}
.ic-teal{background:rgba(20,184,166,.12);color:#0d9488;}
.ic-orange{background:rgba(234,88,12,.10);color:#ea580c;}
.sec-head h5{font-size:14px;font-weight:800;color:var(--navy);margin:0;}
.badge-cnt{background:#ef4444;color:#fff;border-radius:8px;padding:2px 9px;font-size:10.5px;font-weight:700;margin-left:auto;}
.badge-ok{background:#10b981;color:#fff;border-radius:8px;padding:2px 9px;font-size:10.5px;font-weight:700;margin-left:auto;}
.sec-body{padding:18px 20px;}

/* Comparison diff */
.legend{display:flex;gap:14px;padding:8px 12px;background:#fff;border:1px solid #e5e9f0;border-radius:9px;margin-bottom:14px;font-size:11.5px;flex-wrap:wrap;}
.leg{display:inline-flex;align-items:center;gap:6px;color:#374151;font-weight:600;}
.leg-dot{width:12px;height:12px;border-radius:3px;}
.leg-b .leg-dot{background:#fee2e2;border:1px solid #ef4444;}
.leg-a .leg-dot{background:#d1fae5;border:1px solid #10b981;}
.leg-c .leg-dot{background:#fef3c7;border:1px solid #f59e0b;}

.diff-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px;}
.diff-table thead th{background:#f8fafc;color:var(--gray);font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;padding:10px 12px;text-align:left;border-bottom:2px solid var(--border);}
.diff-table tbody td{padding:9px 12px;border-bottom:1px solid #f0f2f5;vertical-align:top;}
.diff-table tbody tr:last-child td{border-bottom:none;}
.dk{font-weight:700;color:var(--navy);width:25%;white-space:nowrap;}
.dk.changed{background:#fffbeb;}
.da{background:#fef2f2;max-width:35%;}
.db{background:#f0fdf4;max-width:35%;}
.du{background:#fafbfc;}
.sec-sub{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--navy2);margin:16px 0 8px;padding-bottom:5px;border-bottom:2px solid var(--yellow);display:flex;align-items:center;gap:7px;}
.empty-note{color:#9ca3af;font-style:italic;font-size:13px;padding:12px 0;}

/* Data grid */
.d-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:0;}
.d-row{display:flex;flex-direction:column;padding:9px 0;border-bottom:1px solid rgba(215,224,234,.45);}
.d-row:last-child{border-bottom:none;}
.d-lbl{font-size:11px;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px;}
.d-val{font-size:13.5px;color:var(--navy);}
.dat-empty{color:#b0bac5;font-style:italic;}
.chip-si{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:6px;padding:2px 9px;font-size:12px;font-weight:700;}
.chip-no{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:2px 9px;font-size:12px;font-weight:700;}
.chip-prod{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;display:inline-block;margin:2px;}

/* Task table */
.t-table{width:100%;border-collapse:collapse;font-size:13px;}
.t-table th{background:#f8fafc;color:var(--gray);font-size:11px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;padding:10px 12px;text-align:left;border-bottom:2px solid var(--border);}
.t-table td{padding:10px 12px;border-bottom:1px solid #f0f2f5;vertical-align:middle;}
.t-table tr:last-child td{border-bottom:none;}
.t-table tr.highlight-row{background:rgba(255,221,0,.06);border-left:3px solid var(--yellow);}

/* Credit */
.cred-estado{display:inline-block;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:700;}

@media(max-width:768px){.alert-hero{flex-direction:column;}.d-grid{grid-template-columns:1fr;}.page-body{padding:16px;}}
</style>
</head>
<body>

<div class="topbar">
    <a href="alertas.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver a Alertas</a>
    <h1><i class="fas fa-triangle-exclamation" style="color:#ffdd00;margin-right:8px;"></i>Detalle de Alerta</h1>
    <span class="<?= $revisada?'pill-ok':'pill-alert' ?>">
        <?= $revisada ? '✓ Revisada' : '⏳ Pendiente' ?>
    </span>
</div>

<div class="page-body">
<?php endif; // !$is_ajax ?>

<!-- ══ HERO ══════════════════════════════════════════════════════ -->
<div class="alert-hero">
    <div style="flex:1;min-width:0;">
        <div class="hero-chip"><i class="fas fa-triangle-exclamation"></i> Alerta de Modificación</div>
        <h2 class="hero-title"><?= htmlspecialchars($row['cliente_nombre'] ?? 'Sin cliente') ?></h2>
        <div class="hero-sub">
            <?php if(!empty($row['asesor_nombre'])): ?>
            <span><i class="fas fa-user-tie"></i> <?= htmlspecialchars($row['asesor_nombre']) ?></span>
            <?php endif; ?>
            <span><i class="fas fa-clock"></i> <?= $fecha_alerta ?></span>
            <?php if(!empty($row['campo_modificado'])): ?>
            <span><i class="fas fa-pen"></i> Campo: <strong><?= htmlspecialchars($row['campo_modificado']) ?></strong></span>
            <?php endif; ?>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
            <code style="background:rgba(255,255,255,.12);color:#ffdd00;padding:2px 9px;border-radius:5px;font-size:11px;">Alerta #<?= htmlspecialchars(substr($row['id'],0,12)) ?></code>
            <code style="background:rgba(255,255,255,.12);color:#ffdd00;padding:2px 9px;border-radius:5px;font-size:11px;">Tarea #<?= htmlspecialchars(substr($tarea_ref??'',0,12)) ?></code>
        </div>
    </div>
    <div class="hero-stats">
        <div class="stat-box"><div class="stat-num"><?= $total_cambios ?></div><div class="stat-lbl">Campo<?= $total_cambios===1?'':'s' ?> modificado<?= $total_cambios===1?'':'s' ?></div></div>
        <div class="stat-box"><div class="stat-num"><?= count($tareas) ?></div><div class="stat-lbl">Tareas del cliente</div></div>
    </div>
</div>

<!-- ══ COMPARACIÓN (ANTES / AHORA) ═══════════════════════════════ -->
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-red"><i class="fas fa-code-compare"></i></div>
        <h5>Comparación de Cambios</h5>
        <?php if($total_cambios>0): ?>
            <span class="badge-cnt"><?= $total_cambios ?> cambio<?= $total_cambios===1?'':'s' ?></span>
        <?php else: ?>
            <span class="badge-ok">Sin diferencias</span>
        <?php endif; ?>
    </div>
    <div class="sec-body">
        <div class="legend">
            <span class="leg leg-b"><span class="leg-dot"></span> ANTES (valor previo)</span>
            <span class="leg leg-a"><span class="leg-dot"></span> AHORA (valor nuevo)</span>
            <span class="leg leg-c"><span class="leg-dot"></span> Campo con cambio</span>
        </div>
        <?php
        $hide_keys=['id','created_at','updated_at','tarea_id','cliente_prospecto_id','asesor_id','supervisor_id','fallback_from_db'];

        function render_diff_section($title, $a, $b, $hide) {
            $a=is_array($a)?$a:[]; $b=is_array($b)?$b:[];
            $keys=array_values(array_filter(array_unique(array_merge(array_keys($a),array_keys($b))),fn($k)=>!in_array($k,$hide)));
            $changed=0; foreach($keys as $k) if(normalize_for_compare($a[$k]??null)!==normalize_for_compare($b[$k]??null)) $changed++;
            echo '<div class="sec-sub"><i class="fas fa-layer-group"></i>'.htmlspecialchars($title);
            if($changed>0) echo ' <span style="background:#f59e0b;color:#fff;border-radius:5px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:6px;">'.$changed.' cambio'.($changed===1?'':'s').'</span>';
            else echo ' <span style="background:#10b981;color:#fff;border-radius:5px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:6px;">Sin cambios</span>';
            echo '</div>';
            if(empty($keys)){echo '<p class="empty-note">Sin datos registrados</p>'; return;}
            echo '<table class="diff-table"><thead><tr><th>Campo</th><th>ANTES</th><th>AHORA</th></tr></thead><tbody>';
            foreach($keys as $k) {
                $av=$a[$k]??null; $bv=$b[$k]??null;
                $avs=is_array($av)||is_object($av)?json_encode($av,JSON_UNESCAPED_UNICODE):($av===null?'':htmlspecialchars((string)$av));
                $bvs=is_array($bv)||is_object($bv)?json_encode($bv,JSON_UNESCAPED_UNICODE):($bv===null?'':htmlspecialchars((string)$bv));
                $ch=(normalize_for_compare($av)!==normalize_for_compare($bv));
                echo '<tr>';
                echo '<td class="dk'.($ch?' changed':'').'">'.htmlspecialchars(ucwords(str_replace('_',' ',$k))).'</td>';
                echo '<td class="'.($ch?'da':'du').'"><div style="max-height:120px;overflow:auto;word-break:break-all;">'.$avs.'</div></td>';
                echo '<td class="'.($ch?'db':'du').'"><div style="max-height:120px;overflow:auto;word-break:break-all;">'.$bvs.'</div></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        render_diff_section('Datos del Cliente',      $ant['cliente']??null,            $new['cliente']??null,            $hide_keys);
        render_diff_section('Encuesta Comercial',     $ant['encuesta_comercial']??null,  $new['encuesta_comercial']??null,  $hide_keys);
        render_diff_section('Encuesta Negocio',       $ant['encuesta_negocio']??null,    $new['encuesta_negocio']??null,    $hide_keys);
        render_diff_section('Acuerdo de Visita',      $ant['acuerdo_visita']??null,      $new['acuerdo_visita']??null,      $hide_keys);
        ?>
    </div>
</div>

<!-- ══ DATOS COMPLETOS DEL CLIENTE ═══════════════════════════════ -->
<?php if($cliente): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-blue"><i class="fas fa-user"></i></div>
        <h5>Datos Completos del Cliente</h5>
    </div>
    <div class="sec-body">
        <div class="d-grid">
            <?= erow('Nombre completo', ($cliente['nombre']??'').' '.($cliente['apellidos']??'')) ?>
            <?= erow('Cédula / RUC', $cliente['cedula']??'') ?>
            <?= erow('Teléfono', $cliente['telefono']??'') ?>
            <?= erow('Celular', $cliente['celular']??'') ?>
            <?= erow('Email', $cliente['email']??'') ?>
            <?= erow('Ciudad', $cliente['ciudad']??'') ?>
            <?= erow('Dirección', $cliente['direccion']??'') ?>
            <?= erow('Zona', $cliente['zona']??'') ?>
            <?= erow('Actividad económica', $cliente['actividad']??'') ?>
            <?= erow('Género', $cliente['genero']??'') ?>
            <?= erow('Fecha nacimiento', $cliente['fecha_nacimiento']??'') ?>
            <?= erow('Estado civil', $cliente['estado_civil']??'') ?>
            <?= erow('Nivel educación', $cliente['nivel_educacion']??'') ?>
            <?= erow('Tipo vivienda', $cliente['tipo_vivienda']??'') ?>
            <?= erow('Dependientes', $cliente['num_dependientes']??'') ?>
            <?= eyn('Tiene RUC', $cliente['tiene_ruc']??null) ?>
            <?= eyn('Tiene RISE', $cliente['tiene_rise']??null) ?>
            <?= erow('Número RUC', $cliente['numero_ruc']??'') ?>
            <?= erow('Asesor asignado', $cliente['asesor_nombre']??'') ?>
            <?= erow('Origen prospecto', $cliente['origen_prospecto']??'') ?>
            <?= erow('Fecha registro', !empty($cliente['created_at'])?date('d/m/Y H:i',strtotime($cliente['created_at'])):'') ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ ENCUESTA COMERCIAL ══════════════════════════════════════ -->
<?php if($encuesta_com): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-teal"><i class="fas fa-briefcase"></i></div>
        <h5>Encuesta Comercial</h5>
        <?php if(!empty($encuesta_com['created_at'])): ?><small style="margin-left:auto;color:var(--gray);font-size:12px;"><?= date('d/m/Y H:i',strtotime($encuesta_com['created_at'])) ?></small><?php endif; ?>
    </div>
    <div class="sec-body">
        <div class="d-grid">
            <?= erow('Ingreso mensual', $encuesta_com['ingreso_mensual']??'', 'USD') ?>
            <?= erow('Ingreso familiar', $encuesta_com['ingreso_familiar']??'', 'USD') ?>
            <?= erow('Gasto mensual', $encuesta_com['gasto_mensual']??'', 'USD') ?>
            <?= erow('Deuda actual', $encuesta_com['deuda_actual']??'', 'USD') ?>
            <?= erow('Cuota máx. que puede pagar', $encuesta_com['cuota_maxima']??'', 'USD') ?>
            <?= erow('Monto solicitado', $encuesta_com['monto_solicitado']??'', 'USD') ?>
            <?= erow('Plazo deseado (meses)', $encuesta_com['plazo_deseado']??'') ?>
            <?= erow('Destino del crédito', $encuesta_com['destino_credito']??'') ?>
            <?= erow('Institución actual', $encuesta_com['institucion_actual']??'') ?>
            <?= eyn('Tiene historial crediticio', $encuesta_com['tiene_historial']??null) ?>
            <?= eyn('Está en buró sin problemas', $encuesta_com['buro_sin_problemas']??null) ?>
            <?= erow('Calificación buró', $encuesta_com['calificacion_buro']??'') ?>
            <?= erow('Observaciones', $encuesta_com['observaciones']??'') ?>
        </div>
        <?php
        $prods_inter=[];
        $pfull=['credito'=>'Crédito','cuenta_corriente'=>'Cuenta Corriente','cuenta_ahorros'=>'Cuenta de Ahorros','inversiones'=>'Inversiones','seguro_vida'=>'Seguro de Vida','tarjeta_credito'=>'Tarjeta de Crédito'];
        foreach($pfull as $k=>$l) if(!empty($encuesta_com[$k])||!empty($encuesta_com['interes_'.$k])) $prods_inter[]=$l;
        if($prods_inter): ?>
        <div class="sec-sub" style="margin-top:12px;"><i class="fas fa-star"></i> Productos de Interés</div>
        <div><?php foreach($prods_inter as $p) echo '<span class="chip-prod">'.$p.'</span> '; ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══ ENCUESTA NEGOCIO ════════════════════════════════════════ -->
<?php if($encuesta_neg): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-yellow"><i class="fas fa-store"></i></div>
        <h5>Encuesta de Negocio / Empresa</h5>
        <?php if(!empty($encuesta_neg['created_at'])): ?><small style="margin-left:auto;color:var(--gray);font-size:12px;"><?= date('d/m/Y H:i',strtotime($encuesta_neg['created_at'])) ?></small><?php endif; ?>
    </div>
    <div class="sec-body">
        <div class="d-grid">
            <?= erow('Nombre negocio', $encuesta_neg['nombre_empresa']??$encuesta_neg['nombre_negocio']??'') ?>
            <?= erow('Tipo negocio', $encuesta_neg['tipo_negocio']??'') ?>
            <?= erow('Actividad', $encuesta_neg['actividad']??'') ?>
            <?= erow('Antigüedad (años)', $encuesta_neg['antiguedad_anios']??'') ?>
            <?= erow('Número empleados', $encuesta_neg['num_empleados']??'') ?>
            <?= erow('Venta Lun-Vie', $encuesta_neg['venta_lv']??'', 'USD') ?>
            <?= erow('Venta Sábado', $encuesta_neg['venta_sabado']??'', 'USD') ?>
            <?= erow('Venta Domingo', $encuesta_neg['venta_domingo']??'', 'USD') ?>
            <?= erow('Compra Lun-Vie', $encuesta_neg['compra_lv']??'', 'USD') ?>
            <?= erow('Compra Sábado', $encuesta_neg['compra_sabado']??'', 'USD') ?>
            <?= erow('Compra Domingo', $encuesta_neg['compra_domingo']??'', 'USD') ?>
            <?= erow('Mes alta venta', $encuesta_neg['mes_alta_venta']??'') ?>
            <?= erow('Mes baja venta', $encuesta_neg['mes_baja_venta']??'') ?>
            <?= eyn('Declara IVA', $encuesta_neg['declara_iva']??null) ?>
            <?= eyn('Emite facturas', $encuesta_neg['emite_facturas']??null) ?>
            <?= eyn('Lleva contabilidad', $encuesta_neg['lleva_contabilidad']??null) ?>
            <?= erow('Local (propio/arriendo)', $encuesta_neg['tipo_local']??'') ?>
            <?= erow('Valor arriendo', $encuesta_neg['valor_arriendo']??'', 'USD') ?>
            <?= erow('Observaciones', $encuesta_neg['observaciones']??'') ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ ACUERDO DE VISITA ═══════════════════════════════════════ -->
<?php if($acuerdo): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-green"><i class="fas fa-handshake"></i></div>
        <h5>Acuerdo de Visita</h5>
        <?php if(!empty($acuerdo['created_at'])): ?><small style="margin-left:auto;color:var(--gray);font-size:12px;"><?= date('d/m/Y H:i',strtotime($acuerdo['created_at'])) ?></small><?php endif; ?>
    </div>
    <div class="sec-body">
        <div class="d-grid">
            <?= erow('Acuerdo', $acuerdo['acuerdo']??'') ?>
            <?= erow('Fecha acordada', $acuerdo['fecha_acuerdo']??'') ?>
            <?= erow('Hora acordada', $acuerdo['hora_acuerdo']??'') ?>
            <?= erow('Lugar', $acuerdo['lugar']??'') ?>
            <?= eyn('Fue encuestado', $acuerdo['fue_encuestado']??null) ?>
            <?= erow('Observaciones', $acuerdo['observaciones']??'') ?>
            <?= erow('Resultado visita', $acuerdo['resultado']??'') ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ FICHAS DE PRODUCTO ══════════════════════════════════════ -->
<?php if($ficha_credito||$ficha_corriente||$ficha_ahorros||$ficha_inversiones): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-purple"><i class="fas fa-folder-open"></i></div>
        <h5>Fichas de Productos Solicitados</h5>
    </div>
    <div class="sec-body">

    <?php if($ficha_credito): ?>
        <div class="sec-sub" style="margin-bottom:12px;"><i class="fas fa-hand-holding-usd" style="color:#d97706;"></i> Ficha de Crédito <small style="font-weight:400;font-size:11px;color:var(--gray);margin-left:6px;"><?= !empty($ficha_credito['created_at'])?date('d/m/Y H:i',strtotime($ficha_credito['created_at'])):'' ?></small></div>
        <div class="d-grid">
            <?= eyn('Requiere crédito', $ficha_credito['requiere_credito']??null) ?>
            <?= erow('Monto solicitado', $ficha_credito['monto_credito']??'', 'USD') ?>
            <?= erow('Plazo (meses)', $ficha_credito['plazo_credito_meses']??'') ?>
            <?= erow('Solicitante', $ficha_credito['solicitante_nombre']??'') ?>
            <?= erow('Cédula solicitante', $ficha_credito['solicitante_cedula']??'') ?>
            <?= erow('Garante', $ficha_credito['garante_nombre']??'') ?>
            <?= erow('Cédula garante', $ficha_credito['garante_cedula']??'') ?>
        </div>
        <?php
        $dests=[];
        if(!empty($ficha_credito['dest_capital_trabajo']))  $dests[]='Capital de trabajo';
        if(!empty($ficha_credito['dest_activos_fijos']))    $dests[]='Activos fijos';
        if(!empty($ficha_credito['dest_pago_deudas']))      $dests[]='Pago de deudas';
        if(!empty($ficha_credito['dest_consolidacion']))    $dests[]='Consolidación';
        if(!empty($ficha_credito['dest_vehiculo']))         $dests[]='Vehículo';
        if(!empty($ficha_credito['dest_vivienda_compra']))  $dests[]='Compra vivienda';
        if(!empty($ficha_credito['dest_arreglos_vivienda']))$dests[]='Arreglos vivienda';
        if(!empty($ficha_credito['dest_educacion']))        $dests[]='Educación';
        if(!empty($ficha_credito['dest_viajes']))           $dests[]='Viajes';
        if(!empty($ficha_credito['dest_otros']))            $dests[]='Otros: '.htmlspecialchars($ficha_credito['dest_otros_detalle']??'');
        if($dests): ?><div style="margin-top:8px;"><strong style="font-size:12px;color:var(--gray);">Destino del crédito:</strong><br><?php foreach($dests as $d) echo '<span class="chip-prod" style="background:linear-gradient(135deg,#d97706,#f59e0b);">'.$d.'</span> '; ?></div><?php endif; ?>
    <?php endif; ?>

    <?php if($ficha_corriente): ?>
        <div class="sec-sub" style="margin:16px 0 12px;"><i class="fas fa-university" style="color:#0d9488;"></i> Cuenta Corriente</div>
        <div class="d-grid">
            <?= eyn('Requiere cuenta corriente', $ficha_corriente['requiere_corriente']??null) ?>
            <?= erow('Tipo uso', $ficha_corriente['tipo_uso']??'') ?>
            <?= erow('Monto promedio', $ficha_corriente['monto_promedio']??'', 'USD') ?>
            <?= erow('Observaciones', $ficha_corriente['observaciones']??'') ?>
        </div>
    <?php endif; ?>

    <?php if($ficha_ahorros): ?>
        <div class="sec-sub" style="margin:16px 0 12px;"><i class="fas fa-piggy-bank" style="color:#059669;"></i> Cuenta de Ahorros</div>
        <div class="d-grid">
            <?= eyn('Requiere cuenta de ahorros', $ficha_ahorros['requiere_ahorros']??null) ?>
            <?= erow('Monto a depositar inicial', $ficha_ahorros['monto_inicial']??'', 'USD') ?>
            <?= erow('Objetivo de ahorro', $ficha_ahorros['objetivo']??'') ?>
            <?= erow('Observaciones', $ficha_ahorros['observaciones']??'') ?>
        </div>
    <?php endif; ?>

    <?php if($ficha_inversiones): ?>
        <div class="sec-sub" style="margin:16px 0 12px;"><i class="fas fa-chart-line" style="color:#7c3aed;"></i> Inversiones</div>
        <div class="d-grid">
            <?= eyn('Interesado en inversiones', $ficha_inversiones['requiere_inversiones']??null) ?>
            <?= erow('Monto a invertir', $ficha_inversiones['monto_inversion']??'', 'USD') ?>
            <?= erow('Plazo deseado (meses)', $ficha_inversiones['plazo_meses']??'') ?>
            <?= erow('Perfil de riesgo', $ficha_inversiones['perfil_riesgo']??'') ?>
            <?= erow('Observaciones', $ficha_inversiones['observaciones']??'') ?>
        </div>
    <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<!-- ══ TRÁMITES / CRÉDITO FORMAL ═══════════════════════════════ -->
<?php if($tramites): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-orange"><i class="fas fa-file-invoice-dollar"></i></div>
        <h5>Trámites Formales de Crédito</h5>
        <span class="badge-cnt" style="background:#f59e0b;"><?= count($tramites) ?></span>
    </div>
    <div class="sec-body">
        <table class="t-table">
            <thead><tr><th>Estado</th><th>Monto aprobado</th><th>Actividad</th><th>Microcrédito</th><th>Asesor</th><th>Documentos</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach($tramites as $tr):
                $ec=$tr['estado_credito']??'prospectado';
                $ecols=['desembolsado'=>['#10b981','✓ Desembolsado'],'aprobado'=>['#22c55e','✓ Aprobado'],'analisis'=>['#3b82f6','🔍 En análisis'],'solicitud'=>['#6366f1','📋 Solicitud'],'levantamiento'=>['#f59e0b','📐 Levantamiento'],'entrevista_venta'=>['#8b5cf6','🗣 Entrevista'],'rechazado'=>['#ef4444','✗ Rechazado'],'recuperacion'=>['#dc2626','⚠ Recuperación'],'prospectado'=>['#9ca3af','🔎 Prospectado']];
                [$ec_col,$ec_lbl]=$ecols[$ec]??['#9ca3af',ucfirst($ec)];
            ?>
            <tr>
                <td><span class="cred-estado" style="background:<?=$ec_col?>;color:#fff;"><?=$ec_lbl?></span></td>
                <td><?= $tr['monto_aprobado']?('<strong>$'.number_format($tr['monto_aprobado'],2).'</strong>'):'<span class="dat-empty">—</span>' ?></td>
                <td><?= htmlspecialchars(ucfirst(str_replace('_',' ',$tr['actividad']??''))) ?: '<span class="dat-empty">—</span>' ?></td>
                <td><?= ($tr['es_microcredito']??0)?'<span class="chip-si">Sí</span>':'<span class="chip-no">No</span>' ?></td>
                <td><?= htmlspecialchars($tr['asesor_nombre']??'—') ?></td>
                <td><?= ($tr['documentos_completos']??0)?'<span class="chip-si">Completos</span>':'<span class="chip-no">Incompletos</span>' ?></td>
                <td style="white-space:nowrap;font-size:12px;"><?= !empty($tr['created_at'])?date('d/m/Y',strtotime($tr['created_at'])):'—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══ HISTORIAL DE TAREAS ═════════════════════════════════════ -->
<?php if($tareas): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-blue"><i class="fas fa-list-check"></i></div>
        <h5>Historial de Tareas del Cliente</h5>
        <span class="badge-cnt" style="background:var(--navy2);"><?= count($tareas) ?></span>
    </div>
    <div class="sec-body">
        <table class="t-table">
            <thead><tr><th>Tipo</th><th>Estado</th><th>Fecha programada</th><th>Fecha realizada</th><th>Asesor</th><th>Observaciones</th></tr></thead>
            <tbody>
            <?php foreach($tareas as $t):
                $highlight = ($t['id']===$tarea_ref);
            ?>
            <tr class="<?=$highlight?'highlight-row':''?>">
                <td><?php if($highlight) echo '<i class="fas fa-triangle-exclamation" style="color:#f59e0b;margin-right:4px;" title="Esta alerta corresponde a esta tarea"></i>'; echo '<strong>'.htmlspecialchars(tipo_tarea_label($t['tipo_tarea']??'')).'</strong>'; ?></td>
                <td><?= estado_badge($t['estado']??'programada') ?></td>
                <td style="white-space:nowrap;font-size:12px;"><?= !empty($t['fecha_programada'])?date('d/m/Y',strtotime($t['fecha_programada'])):'<span class="dat-empty">—</span>' ?><?= !empty($t['hora_programada'])?' '.$t['hora_programada']:'' ?></td>
                <td style="white-space:nowrap;font-size:12px;"><?= !empty($t['fecha_realizada'])?date('d/m/Y',strtotime($t['fecha_realizada'])):'<span class="dat-empty">—</span>' ?></td>
                <td><?= htmlspecialchars($t['asesor_nombre']??'—') ?></td>
                <td style="font-size:12px;max-width:220px;"><?= htmlspecialchars(mb_substr($t['observaciones']??'',0,80)).(mb_strlen($t['observaciones']??'')>80?'…':'') ?: '<span class="dat-empty">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:11.5px;color:var(--gray);margin-top:10px;"><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> La fila resaltada corresponde a la tarea relacionada con esta alerta.</p>
    </div>
</div>
<?php endif; ?>

<?php if ($is_ajax): ?>
</div><!-- /alm-detalle -->
<?php exit; else: ?>
</div><!-- /page-body -->
</body>
</html>
<?php endif; ?>
