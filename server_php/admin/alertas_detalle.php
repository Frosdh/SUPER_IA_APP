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
function render_json_to_html($val, $fieldName = '') {
    if ($val === null || $val === '') return '<span class="dat-empty">Sin datos</span>';
    
    // Si es un string, intentamos decodificarlo
    $data = is_string($val) ? json_decode($val, true) : $val;
    
    // Si no es un array o falla la decodificación, mostramos el valor original
    if ($data === null || !is_array($data)) return htmlspecialchars((string)$val);
    if (empty($data)) return '<span class="json-empty">Vacío</span>';

    // Eliminar elementos vacíos
    $data = array_filter($data, function($item) {
        if (!is_array($item)) return !empty($item);
        foreach ($item as $v) if (!empty($v)) return true;
        return false;
    });
    if (empty($data)) return '<span class="json-empty">Sin datos registrados</span>';

    $html = '<table class="json-table" style="border: 1px solid #e2e8f0;"><thead><tr>';
    
    $first = reset($data);
    if (!is_array($first)) {
        $html .= '<th style="background:#f8fafc;border-bottom:2px solid #cbd5e1;">Valor</th></tr></thead><tbody>';
        foreach ($data as $v) $html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;">' . htmlspecialchars((string)$v) . '</td></tr>';
        $html .= '</tbody></table>';
        return $html;
    }

    $cols = array_keys($first);
    foreach ($cols as $col) {
        if ($col === 'materias') continue;
        $html .= '<th style="background:#f8fafc;border-bottom:2px solid #cbd5e1;">' . htmlspecialchars(ucwords(str_replace(['_','json'],' ',$col))) . '</th>';
    }
    if (isset($first['materias'])) $html .= '<th style="background:#f8fafc;border-bottom:2px solid #cbd5e1;">Materias Primas</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($data as $item) {
        $html .= '<tr>';
        foreach ($cols as $col) {
            if ($col === 'materias') continue;
            $v = $item[$col] ?? '';
            // Si es un valor numérico que no es año, ponerlo en negrita
            $is_money = (is_numeric($v) && !preg_match('/^(20\d{2}|19\d{2})$/', $v) && strpos($col, 'cantidad') === false);
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;">' . ($is_money ? '<strong>'.htmlspecialchars((string)$v).'</strong>' : htmlspecialchars((string)$v)) . '</td>';
        }
        if (isset($item['materias']) && is_array($item['materias'])) {
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;"><div class="nested-json">';
            foreach ($item['materias'] as $m) {
                if (!empty($m['nombre'])) {
                    $html .= '<div style="margin-bottom:2px;"><span class="json-tag" style="background:#e0f2fe;color:#0369a1;">' . htmlspecialchars($m['nombre']) . '</span> <span style="font-weight:700;">$' . htmlspecialchars($m['valor'] ?? '0') . '</span></div>';
                }
            }
            $html .= '</div></td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

function normalize_for_compare($v) {
    if ($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === '0' || $v === '0.00' || $v === '0.0000') return '';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_numeric($v)) return (string)round((float)$v, 4);
    if (is_array($v) || is_object($v)) {
        $a = (array)$v; ksort($a);
        return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return trim((string)$v);
}

function dat($v, $suf = '') {
    $sv = trim((string)$v);
    if ($v === null || $sv === '') return '<span class="dat-empty">—</span>';
    // Si es un número puro 0, lo mostramos como 0.00 para finanzas
    if (($v === 0 || $v === '0') && $suf === 'USD') return '<strong>0.00</strong> ' . $suf;
    return '<strong>' . htmlspecialchars($sv) . '</strong>' . ($suf ? " $suf" : '');
}

function erow($label, $new_val, $old_val = null, $suf = '') {
    $has_change = (normalize_for_compare($new_val) !== normalize_for_compare($old_val));
    
    // Si la etiqueta o el valor sugieren JSON
    if (strpos(strtolower($label), 'json') !== false || (is_string($new_val) && (strpos($new_val, '[{') === 0 || strpos($new_val, '{"') === 0))) {
        $cleanLabel = str_replace(['Json', 'JSON'], '', $label);
        $html = '<tr><td colspan="3" style="background:#f8fafc;padding:12px;font-weight:800;color:var(--navy2);border-bottom:1px solid var(--border);">';
        $html .= '<div style="display:flex;align-items:center;gap:8px;"><i class="fas fa-table-list" style="color:#f59e0b;"></i> ' . htmlspecialchars($cleanLabel) . ($has_change ? ' <span class="badge-cnt" style="margin-left:0;font-size:9px;">MODIFICADO</span>' : '') . '</div></td></tr>';
        
        if ($has_change) {
            $html .= '<tr><td class="dk changed" style="width:20%;">Snapshot</td>';
            $html .= '<td class="da" style="width:40%;"><div class="v-old" style="margin-bottom:4px;font-weight:700;">VALOR ANTERIOR:</div>' . render_json_to_html($old_val) . '</td>';
            $html .= '<td class="db" style="width:40%;"><div class="v-new" style="margin-bottom:4px;font-weight:700;">VALOR ACTUALIZADO:</div>' . render_json_to_html($new_val) . '</td></tr>';
        } else {
            $html .= '<tr><td class="dk" style="width:20%;">Datos</td><td colspan="2" class="du">' . render_json_to_html($new_val) . '</td></tr>';
        }
        return $html;
    }

    $avs = dat($old_val, $suf);
    $nvs = dat($new_val, $suf);
    $ch = $has_change;

    $html = '<tr>';
    $html .= '<td class="dk' . ($ch ? ' changed' : '') . '">' . htmlspecialchars($label) . ($ch ? ' <i class="fas fa-pen v-ch-icon" style="color:#f59e0b;"></i>' : '') . '</td>';
    $html .= '<td class="' . ($ch ? 'da' : 'du') . '">' . ($ch ? $avs : $nvs) . '</td>';
    $html .= '<td class="' . ($ch ? 'db' : 'du') . '">' . $nvs . '</td>';
    $html .= '</tr>';
    
    return $html;
}

function eyn($label, $new_val, $old_val = null) {
    $has_change = (normalize_for_compare($new_val) !== normalize_for_compare($old_val));
    
    $render_chip = function($v) {
        if ($v === null || $v === '') return '<span class="dat-empty">—</span>';
        if (intval($v) === 1 || $v === 'si' || $v === '1') return '<span class="chip-si">Sí</span>';
        return '<span class="chip-no">No</span>';
    };

    $ch = $has_change;
    $avs = $render_chip($old_val);
    $nvs = $render_chip($new_val);

    $html = '<tr>';
    $html .= '<td class="dk' . ($ch ? ' changed' : '') . '">' . htmlspecialchars($label) . ($ch ? ' <i class="fas fa-pen v-ch-icon" style="color:#f59e0b;"></i>' : '') . '</td>';
    $html .= '<td class="' . ($ch ? 'da' : 'du') . '">' . ($ch ? $avs : $nvs) . '</td>';
    $html .= '<td class="' . ($ch ? 'db' : 'du') . '">' . $nvs . '</td>';
    $html .= '</tr>';
    
    return $html;
}
function estado_badge($e) {
    $map=['completada'=>['#ecfdf5','#065f46','✓'],'en_proceso'=>['#dbeafe','#1e40af','⚡'],'programada'=>['#fffbeb','#92400e','📅'],'pendiente'=>['#fffbeb','#92400e','⏳'],'cancelada'=>['#fef2f2','#991b1b','🚫'],'postergada'=>['#f3e8ff','#6b21a8','↺']];
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
.alm-detalle .dk{font-weight:700;color:#0a2748;width:25%;}
.alm-detalle .dk.changed{background:#fffbeb;}
.alm-detalle .da{background:#fef2f2;width:37.5%;}
.alm-detalle .db{background:#f0fdf4;width:37.5%;}
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
.dk{font-weight:700;color:var(--navy);width:25%;}
.dk.changed{background:#fffbeb;}
.da{background:#fef2f2;width:37.5%;}
.db{background:#f0fdf4;width:37.5%;}
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

/* JSON Pretty Tables */
.json-table{width:100%;border-collapse:collapse;font-size:11px;margin:8px 0;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);border:1px solid #e2e8f0;}
.json-table th{background:#f8fafc;color:#475569;font-weight:700;padding:10px 12px;text-align:left;border-bottom:2px solid #cbd5e1;text-transform:uppercase;font-size:9px;white-space:nowrap;}
.json-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;color:#1e293b;vertical-align:top;line-height:1.4;}
.json-table tr:last-child td{border-bottom:none;}
.json-table tr:hover td{background:#f8fafc;}
.json-empty{color:#94a3b8;font-style:italic;padding:8px 0;}
.json-tag{display:inline-block;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;margin-right:4px;}
.nested-json{margin:4px 0 0 10px;padding-left:10px;border-left:2px solid #e2e8f0;}

/* Comparison in detail */
.val-comp{display:flex;flex-direction:column;gap:2px;}
.v-old{font-size:11px;color:#ef4444;text-decoration:line-through;opacity:0.7;margin-bottom:1px;}
.v-new{font-weight:700;color:#10b981;}
.v-same{font-weight:600;color:var(--navy);}
.v-ch-icon{font-size:10px;margin-right:4px;color:#f59e0b;}

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
        <div class="diff-legend" style="margin-top:20px;">
            <span class="leg leg-b"><span class="leg-dot"></span> ANTES (valor previo)</span>
            <span class="leg leg-a"><span class="leg-dot"></span> AHORA (valor nuevo)</span>
            <span class="leg leg-c"><span class="leg-dot"></span> Campo con cambio</span>
        </div>
    </div>
</div>

<!-- ══ DATOS DEL CLIENTE ════════════════════════════════════════ -->
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-blue"><i class="fas fa-user"></i></div>
        <h5>Datos del Cliente (Comparación)</h5>
    </div>
    <div class="sec-body" style="padding:0;">
        <table class="diff-table" style="margin-bottom:0;">
            <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
            <tbody>
            <?= erow('Nombre', ($new['cliente']['nombre']??$cliente['nombre']??'').' '.($new['cliente']['apellidos']??$cliente['apellidos']??''), ($ant['cliente']['nombre']??'').' '.($ant['cliente']['apellidos']??'')) ?>
            <?= erow('Cédula / RUC', $new['cliente']['cedula']??$cliente['cedula']??'', $ant['cliente']['cedula']??'') ?>
            <?= erow('Teléfono', $new['cliente']['telefono']??$cliente['telefono']??'', $ant['cliente']['telefono']??'') ?>
            <?= erow('Celular', $new['cliente']['celular']??$cliente['celular']??'', $ant['cliente']['celular']??'') ?>
            <?= erow('Email', $new['cliente']['email']??$cliente['email']??'', $ant['cliente']['email']??'') ?>
            <?= erow('Ciudad', $new['cliente']['ciudad']??$cliente['ciudad']??'', $ant['cliente']['ciudad']??'') ?>
            <?= erow('Dirección', $new['cliente']['direccion']??$cliente['direccion']??'', $ant['cliente']['direccion']??'') ?>
            <?= erow('Zona', $new['cliente']['zona']??$cliente['zona']??'', $ant['cliente']['zona']??'') ?>
            <?= erow('Actividad', $new['cliente']['actividad']??$cliente['actividad']??'', $ant['cliente']['actividad']??'') ?>
            <?= eyn('Tiene RUC', $new['cliente']['tiene_ruc']??$cliente['tiene_ruc']??null, $ant['cliente']['tiene_ruc']??null) ?>
            <?= eyn('Tiene RISE', $new['cliente']['tiene_rise']??$cliente['tiene_rise']??null, $ant['cliente']['tiene_rise']??null) ?>
            </tbody>
        </table>
    </div>
</div>



<!-- ══ ENCUESTA COMERCIAL ══════════════════════════════════════ -->
<?php if($encuesta_com): ?>
<div class="sec-card">
    <div class="sec-head">
        <div class="sec-icon ic-teal"><i class="fas fa-briefcase"></i></div>
        <h5>Encuesta Comercial</h5>
        <?php if(!empty($encuesta_com['created_at'])): ?><small style="margin-left:auto;color:var(--gray);font-size:12px;"><?= date('d/m/Y H:i',strtotime($encuesta_com['created_at'])) ?></small><?php endif; ?>
    </div>
    <div class="sec-body" style="padding:0;">
        <table class="diff-table" style="margin-bottom:0;">
            <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
            <tbody>
            <tr><td colspan="3" class="sec-sub"><i class="fas fa-university"></i> Cuentas y Bancos</td></tr>
            <?= eyn('Mantiene Ahorros', $encuesta_com['mantiene_cuenta_ahorro']??null, $ant['encuesta_comercial']['mantiene_cuenta_ahorro']??null) ?>
            <?= erow('Banco Ahorros', $encuesta_com['banco_ahorro']??'', $ant['encuesta_comercial']['banco_ahorro']??'') ?>
            <?= eyn('Mantiene Corriente', $encuesta_com['mantiene_cuenta_corriente']??null, $ant['encuesta_comercial']['mantiene_cuenta_corriente']??null) ?>
            <?= erow('Banco Corriente', $encuesta_com['banco_corriente']??'', $ant['encuesta_comercial']['banco_corriente']??'') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-piggy-bank"></i> Inversiones</td></tr>
            <?= eyn('Tiene Inversiones', $encuesta_com['tiene_inversiones']??null, $ant['encuesta_comercial']['tiene_inversiones']??null) ?>
            <?= erow('Institución Inversión', $encuesta_com['institucion_inversiones']??'', $ant['encuesta_comercial']['institucion_inversiones']??'') ?>
            <?= erow('Valor Inversión', $encuesta_com['valor_inversion']??'', $ant['encuesta_comercial']['valor_inversion']??'', 'USD') ?>
            <?= erow('Plazo Inversión', $encuesta_com['plazo_inversion']??'', $ant['encuesta_comercial']['plazo_inversion']??'') ?>
            <?= erow('Vencimiento Inversión', $encuesta_com['fecha_vencimiento_inversion']??'', $ant['encuesta_comercial']['fecha_vencimiento_inversion']??'') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-hand-holding-dollar"></i> Créditos Vigentes</td></tr>
            <?= eyn('Tiene Ops. Crediticias', $encuesta_com['tiene_operaciones_crediticias']??null, $ant['encuesta_comercial']['tiene_operaciones_crediticias']??null) ?>
            <?= erow('Institución Crédito', $encuesta_com['institucion_credito']??'', $ant['encuesta_comercial']['institucion_credito']??'') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-star"></i> Intereses y Perfil</td></tr>
            <?= erow('Nivel de Interés', $encuesta_com['nivel_interes_captado']??$encuesta_com['nivel_interes']??'', $ant['encuesta_comercial']['nivel_interes_captado']??$ant['encuesta_comercial']['nivel_interes']??'') ?>
            <?= eyn('Interés Cuenta Ahorros', $encuesta_com['interes_ahorro']??null, $ant['encuesta_comercial']['interes_ahorro']??null) ?>
            <?= eyn('Interés Cuenta Corriente', $encuesta_com['interes_cc']??null, $ant['encuesta_comercial']['interes_cc']??null) ?>
            <?= eyn('Interés Inversión', $encuesta_com['interes_inversion']??null, $ant['encuesta_comercial']['interes_inversion']??null) ?>
            <?= eyn('Interés Crédito', $encuesta_com['interes_credito']??null, $ant['encuesta_comercial']['interes_credito']??null) ?>
            
            <tr><td colspan="3" class="sec-sub"><i class="fas fa-question-circle"></i> Razones de no trabajar con nosotros</td></tr>
            <?= eyn('Ya trabaja con institución', $encuesta_com['razon_ya_trabaja_institucion']??null, $ant['encuesta_comercial']['razon_ya_trabaja_institucion']??null) ?>
            <?= eyn('Desconfía servicios', $encuesta_com['razon_desconfia_servicios']??null, $ant['encuesta_comercial']['razon_desconfia_servicios']??null) ?>
            <?= eyn('A gusto con actual', $encuesta_com['razon_agusto_actual']??null, $ant['encuesta_comercial']['razon_agusto_actual']??null) ?>
            <?= eyn('Mala experiencia', $encuesta_com['razon_mala_experiencia']??null, $ant['encuesta_comercial']['razon_mala_experiencia']??null) ?>
            <?= erow('Otras Razones', $encuesta_com['razon_otros']??'', $ant['encuesta_comercial']['razon_otros']??'') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-search-plus"></i> ¿Qué busca en una Institución?</td></tr>
            <?php
            $busca_tags = [];
            if(!empty($encuesta_com['que_busca_agilidad'])) $busca_tags[] = 'Agilidad';
            if(!empty($encuesta_com['que_busca_cajeros'])) $busca_tags[] = 'Cajeros';
            if(!empty($encuesta_com['que_busca_banca_linea'])) $busca_tags[] = 'Banca en Línea';
            if(!empty($encuesta_com['que_busca_agencias'])) $busca_tags[] = 'Agencias';
            if(!empty($encuesta_com['que_busca_credito_rapido'])) $busca_tags[] = 'Crédito Rápido';
            if(!empty($encuesta_com['que_busca_tarjeta_debito'])) $busca_tags[] = 'T. Débito';
            if(!empty($encuesta_com['que_busca_tarjeta_credito'])) $busca_tags[] = 'T. Crédito';
            ?>
            <tr>
                <td class="dk">Preferencias</td>
                <td colspan="2" class="du">
                <?php if($busca_tags): foreach($busca_tags as $tag) echo '<span class="chip-prod">'.$tag.'</span> '; 
                else: echo '<span class="dat-empty">—</span>'; endif; ?>
                </td>
            </tr>

            </tbody>
        </table>
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
    <div class="sec-body" style="padding:0;">
        <table class="diff-table" style="margin-bottom:0;">
            <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
            <tbody>
            <?= erow('Nombre negocio', $new['cliente']['nombre_empresa']??$cliente['nombre_empresa']??'', $ant['cliente']['nombre_empresa']??'', '') ?>
            <?= erow('Actividad', $new['cliente']['actividad']??$cliente['actividad']??'', $ant['cliente']['actividad']??'', '') ?>
            <?= erow('Tipo de Empresa', $new['cliente']['tipo_empresa']??$cliente['tipo_empresa']??'', $ant['cliente']['tipo_empresa']??'', '') ?>
            
            <?php if(!empty($new['cliente']['tiene_ruc']) || !empty($cliente['tiene_ruc'])): ?>
                <?= erow('RUC', $new['cliente']['numero_ruc']??$cliente['numero_ruc']??'', $ant['cliente']['numero_ruc']??'', '') ?>
                <?= erow('Régimen', $new['cliente']['regimen_tributario']??$cliente['regimen_tributario']??'', $ant['cliente']['regimen_tributario']??'', '') ?>
                <?= eyn('Declara IVA', $new['cliente']['declara_iva']??$cliente['declara_iva']??null, $ant['cliente']['declara_iva']??null) ?>
                <?= eyn('Emite facturas', $new['cliente']['emite_facturas']??$cliente['emite_facturas']??null, $ant['cliente']['emite_facturas']??null) ?>
                <?= eyn('Lleva contabilidad', $new['cliente']['lleva_contabilidad']??$cliente['lleva_contabilidad']??null, $ant['cliente']['lleva_contabilidad']??null) ?>
            <?php endif; ?>

            <?php if(!empty($new['cliente']['tiene_rise']) || !empty($cliente['tiene_rise'])): ?>
                <?= erow('Régimen (RISE)', $new['cliente']['regimen_tributario']??$cliente['regimen_tributario']??'', $ant['cliente']['regimen_tributario']??'', '') ?>
                <?= eyn('Paga Cuota RISE', $new['cliente']['paga_cuota_rise']??$cliente['paga_cuota_rise']??null, $ant['cliente']['paga_cuota_rise']??null) ?>
                <?= eyn('Emite Notas Venta', $new['cliente']['emite_notas_venta']??$cliente['emite_notas_venta']??null, $ant['cliente']['emite_notas_venta']??null) ?>
                <?= eyn('Conoce Límite RISE', $new['cliente']['conoce_limite_rise']??$cliente['conoce_limite_rise']??null, $ant['cliente']['conoce_limite_rise']??null) ?>
            <?php endif; ?>
            
            <tr><td colspan="3" class="sec-sub"><i class="fas fa-chart-pie"></i> Balance General / Situación Financiera</td></tr>
            <?= erow('Caja / Efectivo', $new['encuesta_negocio']['caja_efectivo']??$encuesta_neg['caja_efectivo']??'', $ant['encuesta_negocio']['caja_efectivo']??'', 'USD') ?>
            <?= erow('Bancos / Saldo', $new['encuesta_negocio']['bancos_saldo']??$encuesta_neg['bancos_saldo']??'', $ant['encuesta_negocio']['bancos_saldo']??'', 'USD') ?>
            <?= erow('Cuentas por Cobrar', $new['encuesta_negocio']['cxp_netas']??$encuesta_neg['cxp_netas']??'', $ant['encuesta_negocio']['cxp_netas']??'', 'USD') ?>
            <?= erow('Inv. Materia Prima', $new['encuesta_negocio']['inv_mat_prima']??$encuesta_neg['inv_mat_prima']??'', $ant['encuesta_negocio']['inv_mat_prima']??'', 'USD') ?>
            <?= erow('Inv. Prod. Terminado', $new['encuesta_negocio']['inv_prod_proc']??$encuesta_neg['inv_prod_proc']??'', $ant['encuesta_negocio']['inv_prod_proc']??'', 'USD') ?>
            <?= erow('Costos de Ventas', $new['encuesta_negocio']['costos_ventas']??$encuesta_neg['costos_ventas']??'', $ant['encuesta_negocio']['costos_ventas']??'', 'USD') ?>
            <?= erow('Gastos Negocio Tot.', $new['encuesta_negocio']['gastos_negocio']??$encuesta_neg['gastos_negocio']??'', $ant['encuesta_negocio']['gastos_negocio']??'', 'USD') ?>
            <?= erow('Gastos Familia Tot.', $new['encuesta_negocio']['gastos_familiares']??$encuesta_neg['gastos_familiares']??'', $ant['encuesta_negocio']['gastos_familiares']??'', 'USD') ?>
            <?= erow('Otros Ingresos Tot.', $new['encuesta_negocio']['otros_ingresos']??$encuesta_neg['otros_ingresos']??'', $ant['encuesta_negocio']['otros_ingresos']??'', 'USD') ?>
            
            <tr><td colspan="3" class="sec-sub"><i class="fas fa-money-bill-transfer"></i> Desglose de Gastos Negocio</td></tr>
            <?= erow('G. Neg Sueldos', $new['encuesta_negocio']['g_neg_sueldos']??$encuesta_neg['g_neg_sueldos']??'', $ant['encuesta_negocio']['g_neg_sueldos']??'', 'USD') ?>
            <?= erow('G. Neg Arriendo', $new['encuesta_negocio']['g_neg_arriendo']??$encuesta_neg['g_neg_arriendo']??'', $ant['encuesta_negocio']['g_neg_arriendo']??'', 'USD') ?>
            <?= erow('G. Neg Serv Bas.', $new['encuesta_negocio']['g_neg_serv_bas']??$encuesta_neg['g_neg_serv_bas']??'', $ant['encuesta_negocio']['g_neg_serv_bas']??'', 'USD') ?>
            <?= erow('G. Neg Transp.', $new['encuesta_negocio']['g_neg_transporte']??$encuesta_neg['g_neg_transporte']??'', $ant['encuesta_negocio']['g_neg_transporte']??'', 'USD') ?>
            <?= erow('G. Neg Mant.', $new['encuesta_negocio']['g_neg_mantenimiento']??$encuesta_neg['g_neg_mantenimiento']??'', $ant['encuesta_negocio']['g_neg_mantenimiento']??'', 'USD') ?>
            <?= erow('G. Neg Otros', $new['encuesta_negocio']['g_neg_otros']??$encuesta_neg['g_neg_otros']??'', $ant['encuesta_negocio']['g_neg_otros']??'', 'USD') ?>
            <?= erow('G. Neg Imprev.', $new['encuesta_negocio']['g_neg_imprevistos']??$encuesta_neg['g_neg_imprevistos']??'', $ant['encuesta_negocio']['g_neg_imprevistos']??'', 'USD') ?>


            
            <tr><td colspan="3" class="sec-sub"><i class="fas fa-file-invoice-dollar"></i> Pasivos y Deudas</td></tr>
            <?= erow('Créditos por Pagar', $new['encuesta_negocio']['creditos_pagar']??$encuesta_neg['creditos_pagar']??'', $ant['encuesta_negocio']['creditos_pagar']??'', 'USD') ?>
            <?= erow('Proveedores', $new['encuesta_negocio']['proveedores']??$encuesta_neg['proveedores']??'', $ant['encuesta_negocio']['proveedores']??'', 'USD') ?>
            <?= erow('Otras Deudas CP', $new['encuesta_negocio']['otras_deudas_cp']??$encuesta_neg['otras_deudas_cp']??'', $ant['encuesta_negocio']['otras_deudas_cp']??'', 'USD') ?>
            <?= erow('Pasivos Largo Plazo', $new['encuesta_negocio']['pasivos_lp']??$encuesta_neg['pasivos_lp']??'', $ant['encuesta_negocio']['pasivos_lp']??'', 'USD') ?>
            <?= erow('Otras Deudas Detalle Json', $new['encuesta_negocio']['otras_deudas_json']??$encuesta_neg['otras_deudas_json']??'', $ant['encuesta_negocio']['otras_deudas_json']??'', '') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-calendar-alt"></i> Ventas Mensuales</td></tr>
            <?= erow('Venta Lun-Vie', $new['encuesta_negocio']['venta_lv']??$encuesta_neg['venta_lv']??'', $ant['encuesta_negocio']['venta_lv']??'', 'USD') ?>
            <?= erow('Venta Sábado', $new['encuesta_negocio']['venta_sabado']??$encuesta_neg['venta_sabado']??'', $ant['encuesta_negocio']['venta_sabado']??'', 'USD') ?>
            <?= erow('Venta Domingo', $new['encuesta_negocio']['venta_domingo']??$encuesta_neg['venta_domingo']??'', $ant['encuesta_negocio']['venta_domingo']??'', 'USD') ?>
            <?= erow('Venta Lunes', $new['encuesta_negocio']['venta_lunes']??$encuesta_neg['venta_lunes']??'', $ant['encuesta_negocio']['venta_lunes']??'', 'USD') ?>
            <?= erow('Venta Martes', $new['encuesta_negocio']['venta_martes']??$encuesta_neg['venta_martes']??'', $ant['encuesta_negocio']['venta_martes']??'', 'USD') ?>
            <?= erow('Venta Miércoles', $new['encuesta_negocio']['venta_miercoles']??$encuesta_neg['venta_miercoles']??'', $ant['encuesta_negocio']['venta_miercoles']??'', 'USD') ?>
            <?= erow('Venta Jueves', $new['encuesta_negocio']['venta_jueves']??$encuesta_neg['venta_jueves']??'', $ant['encuesta_negocio']['venta_jueves']??'', 'USD') ?>
            <?= erow('Venta Viernes', $new['encuesta_negocio']['venta_viernes']??$encuesta_neg['venta_viernes']??'', $ant['encuesta_negocio']['venta_viernes']??'', 'USD') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-truck-loading"></i> Compras Mensuales</td></tr>
            <?= erow('Compra Lun-Vie', $new['encuesta_negocio']['compra_lv']??$encuesta_neg['compra_lv']??'', $ant['encuesta_negocio']['compra_lv']??'', 'USD') ?>
            <?= erow('Compra Sábado', $new['encuesta_negocio']['compra_sabado']??$encuesta_neg['compra_sabado']??'', $ant['encuesta_negocio']['compra_sabado']??'', 'USD') ?>
            <?= erow('Compra Domingo', $new['encuesta_negocio']['compra_domingo']??$encuesta_neg['compra_domingo']??'', $ant['encuesta_negocio']['compra_domingo']??'', 'USD') ?>
            <?= erow('Compra Lunes', $new['encuesta_negocio']['compra_lunes']??$encuesta_neg['compra_lunes']??'', $ant['encuesta_negocio']['compra_lunes']??'', 'USD') ?>
            <?= erow('Compra Martes', $new['encuesta_negocio']['compra_martes']??$encuesta_neg['compra_martes']??'', $ant['encuesta_negocio']['compra_martes']??'', 'USD') ?>
            <?= erow('Compra Miércoles', $new['encuesta_negocio']['compra_miercoles']??$encuesta_neg['compra_miercoles']??'', $ant['encuesta_negocio']['compra_miercoles']??'', 'USD') ?>
            <?= erow('Compra Jueves', $new['encuesta_negocio']['compra_jueves']??$encuesta_neg['compra_jueves']??'', $ant['encuesta_negocio']['compra_jueves']??'', 'USD') ?>
            <?= erow('Compra Viernes', $new['encuesta_negocio']['compra_viernes']??$encuesta_neg['compra_viernes']??'', $ant['encuesta_negocio']['compra_viernes']??'', 'USD') ?>
            
            <tr><td colspan="3" class="sec-sub"><i class="fas fa-percent"></i> Porcentajes y Plazos</td></tr>
            <?= erow('% Contado', $new['encuesta_negocio']['pct_contado']??$encuesta_neg['pct_contado']??'', $ant['encuesta_negocio']['pct_contado']??'', '%') ?>
            <?= erow('% Crédito', $new['encuesta_negocio']['pct_credito']??$encuesta_neg['pct_credito']??'', $ant['encuesta_negocio']['pct_credito']??'', '%') ?>
            <?= erow('% Efectivo', $new['encuesta_negocio']['pct_efectivo']??$encuesta_neg['pct_efectivo']??'', $ant['encuesta_negocio']['pct_efectivo']??'', '%') ?>
            <?= erow('Recup. Crédito', $new['encuesta_negocio']['recuperacion_credito']??$encuesta_neg['recuperacion_credito']??'', $ant['encuesta_negocio']['recuperacion_credito']??'', 'USD') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-boxes-stacked"></i> Productos e Inventarios</td></tr>
            <?php if (!empty($encuesta_neg['comercio_productos_json'])): ?>
                <?= erow('Productos de Comercio Json', $new['encuesta_negocio']['comercio_productos_json']??$encuesta_neg['comercio_productos_json']??'', $ant['encuesta_negocio']['comercio_productos_json']??'', '') ?>
            <?php endif; ?>
            <?php if (!empty($encuesta_neg['productos_json'])): ?>
                <?= erow('Productos de Producción Json', $new['encuesta_negocio']['productos_json']??$encuesta_neg['productos_json']??'', $ant['encuesta_negocio']['productos_json']??'', '') ?>
            <?php endif; ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-car"></i> Activos Fijos</td></tr>
            <?= erow('Vehículos Negocio Json', $new['encuesta_negocio']['vehiculos_negocio_json']??$encuesta_neg['vehiculos_negocio_json']??'', $ant['encuesta_negocio']['vehiculos_negocio_json']??'', '') ?>
            <?= erow('Vehículos Hogar Json', $new['encuesta_negocio']['vehiculos_hogar_json']??$encuesta_neg['vehiculos_hogar_json']??'', $ant['encuesta_negocio']['vehiculos_hogar_json']??'', '') ?>
            <?= erow('Inmuebles Negocio Json', $new['encuesta_negocio']['inmuebles_negocio_json']??$encuesta_neg['inmuebles_negocio_json']??'', $ant['encuesta_negocio']['inmuebles_negocio_json']??'', '') ?>
            <?= erow('Inmuebles Hogar Json', $new['encuesta_negocio']['inmuebles_hogar_json']??$encuesta_neg['inmuebles_hogar_json']??'', $ant['encuesta_negocio']['inmuebles_hogar_json']??'', '') ?>
            <?= erow('Activos Negocio Json', $new['encuesta_negocio']['activos_negocio_json']??$encuesta_neg['activos_negocio_json']??'', $ant['encuesta_negocio']['activos_negocio_json']??'', '') ?>
            <?= erow('Activos Hogar Json', $new['encuesta_negocio']['activos_hogar_json']??$encuesta_neg['activos_hogar_json']??'', $ant['encuesta_negocio']['activos_hogar_json']??'', '') ?>

            <tr><td colspan="3" class="sec-sub"><i class="fas fa-info-circle"></i> Otros Datos</td></tr>
            <?= erow('Observaciones', $new['encuesta_negocio']['observaciones']??$encuesta_neg['observaciones']??'', $ant['encuesta_negocio']['observaciones']??'', '') ?>
            </tbody>
        </table>
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
    <div class="sec-body" style="padding:0;">
        <table class="diff-table" style="margin-bottom:0;">
            <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
            <tbody>
            <?= erow('Acuerdo', $acuerdo['acuerdo']??'', $ant['acuerdo_visita']['acuerdo']??'') ?>
            <?= erow('Fecha acordada', $acuerdo['fecha_acuerdo']??'', $ant['acuerdo_visita']['fecha_acuerdo']??'') ?>
            <?= erow('Hora acordada', $acuerdo['hora_acuerdo']??'', $ant['acuerdo_visita']['hora_acuerdo']??'') ?>
            <?= erow('Lugar', $acuerdo['lugar']??'', $ant['acuerdo_visita']['lugar']??'') ?>
            <?= eyn('Fue encuestado', $acuerdo['fue_encuestado']??null, $ant['acuerdo_visita']['fue_encuestado']??null) ?>
            <?= erow('Observaciones', $acuerdo['observaciones']??'', $ant['acuerdo_visita']['observaciones']??'') ?>
            <?= erow('Resultado visita', $acuerdo['resultado']??'', $ant['acuerdo_visita']['resultado']??'') ?>
            </tbody>
        </table>
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
    <div class="sec-body" style="padding:0;">
        <?php if($ficha_credito): ?>
            <div class="sec-sub" style="margin:12px;"><i class="fas fa-hand-holding-usd" style="color:#d97706;"></i> Ficha de Crédito <small style="font-weight:400;font-size:11px;color:var(--gray);margin-left:6px;"><?= !empty($ficha_credito['created_at'])?date('d/m/Y H:i',strtotime($ficha_credito['created_at'])):'' ?></small></div>
            <table class="diff-table" style="margin-bottom:0;">
                <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
                <tbody>
                <?= eyn('Requiere crédito', $ficha_credito['requiere_credito']??null) ?>
                <?= erow('Monto solicitado', $ficha_credito['monto_credito']??'', '', 'USD') ?>
                <?= erow('Plazo (meses)', $ficha_credito['plazo_credito_meses']??'') ?>
                <?= erow('Solicitante', $ficha_credito['solicitante_nombre']??'') ?>
                <?= erow('Cédula solicitante', $ficha_credito['solicitante_cedula']??'') ?>
                <?= erow('Garante', $ficha_credito['garante_nombre']??'') ?>
                <?= erow('Cédula garante', $ficha_credito['garante_cedula']??'') ?>
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
                if($dests): ?>
                <tr>
                    <td class="dk">Destino del crédito</td>
                    <td colspan="2" class="du">
                    <?php foreach($dests as $d) echo '<span class="chip-prod" style="background:linear-gradient(135deg,#d97706,#f59e0b);">'.$d.'</span> '; ?>
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if($ficha_corriente): ?>
            <div class="sec-sub" style="margin:16px 12px 12px;"><i class="fas fa-university" style="color:#0d9488;"></i> Cuenta Corriente</div>
            <table class="diff-table" style="margin-bottom:0;">
                <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
                <tbody>
                <?= eyn('Requiere cuenta corriente', $ficha_corriente['requiere_corriente']??null) ?>
                <?= erow('Tipo uso', $ficha_corriente['tipo_uso']??'') ?>
                <?= erow('Monto promedio', $ficha_corriente['monto_promedio']??'', '', 'USD') ?>
                <?= erow('Observaciones', $ficha_corriente['observaciones']??'') ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if($ficha_ahorros): ?>
            <div class="sec-sub" style="margin:16px 12px 12px;"><i class="fas fa-piggy-bank" style="color:#059669;"></i> Cuenta de Ahorros</div>
            <table class="diff-table" style="margin-bottom:0;">
                <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
                <tbody>
                <?= eyn('Requiere cuenta de ahorros', $ficha_ahorros['requiere_ahorros']??null) ?>
                <?= erow('Monto a depositar inicial', $ficha_ahorros['monto_inicial']??'', '', 'USD') ?>
                <?= erow('Objetivo de ahorro', $ficha_ahorros['objetivo']??'') ?>
                <?= erow('Observaciones', $ficha_ahorros['observaciones']??'') ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if($ficha_inversiones): ?>
            <div class="sec-sub" style="margin:16px 12px 12px;"><i class="fas fa-chart-line" style="color:#7c3aed;"></i> Inversiones</div>
            <table class="diff-table" style="margin-bottom:0;">
                <thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
                <tbody>
                <?= eyn('Interesado en inversiones', $ficha_inversiones['requiere_inversiones']??null) ?>
                <?= erow('Monto a invertir', $ficha_inversiones['monto_inversion']??'', '', 'USD') ?>
                <?= erow('Plazo deseado (meses)', $ficha_inversiones['plazo_meses']??'') ?>
                <?= erow('Perfil de riesgo', $ficha_inversiones['perfil_riesgo']??'') ?>
                <?= erow('Observaciones', $ficha_inversiones['observaciones']??'') ?>
                </tbody>
            </table>
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
                $ecols=['desembolsado'=>['#10b981','✓ Desembolsado'],'aprobado'=>['#22c55e','✓ Aprobado'],'analisis'=>['#3b82f6','🔍 En análisis'],'solicitud'=>['#6366f1','📋 Solicitud'],'levantamiento'=>['#f59e0b','📐 Levantamiento'],'entrevista_venta'=>['#8b5cf6','🗣 Entrevista'],'rechazado'=>['#ef4444','🚫 Rechazado'],'recuperacion'=>['#dc2626','⚠ Recuperación'],'prospectado'=>['#9ca3af','🔎 Prospectado']];
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
