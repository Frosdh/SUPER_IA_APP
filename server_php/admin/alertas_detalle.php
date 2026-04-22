<?php
require_once 'db_admin.php';

if (!isset($_GET['id'])) {
    header('Location: alertas.php'); exit;
}
$id = $_GET['id'];

// Detectar modo AJAX: si viene ?ajax=1 o header X-Requested-With, devolver solo el contenido
$is_ajax = (
    (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);

$stmt = $pdo->prepare('SELECT am.*, t.id as tarea_ref, t.cliente_prospecto_id as cliente_id, cp.nombre as cliente_nombre, a.id as asesor_table_id, u.nombre as asesor_nombre FROM alerta_modificacion am JOIN tarea t ON am.tarea_id = t.id LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id LEFT JOIN asesor a ON am.asesor_id = a.id LEFT JOIN usuario u ON a.usuario_id = u.id WHERE am.id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    if ($is_ajax) { http_response_code(404); echo '<div class="p-4 text-danger">Alerta no encontrada</div>'; exit; }
    header('Location: alertas.php'); exit;
}

$valor_ant = $row['valor_anterior'];
$valor_new = $row['valor_nuevo'];

// Decode JSON if possible
$ant = null; $new = null;
$ant_raw = $valor_ant;
$new_raw = $valor_new;
if ($valor_ant !== null && $valor_ant !== '') {
    $decoded = json_decode($valor_ant, true);
    $ant = $decoded !== null ? $decoded : null;
}
if ($valor_new !== null && $valor_new !== '') {
    $decoded2 = json_decode($valor_new, true);
    $new = $decoded2 !== null ? $decoded2 : null;
}

// If JSON snapshots are missing, attempt DB fallback to show current rows
$tarea_ref = $row['tarea_ref'] ?? null;
$cliente_table_id = $row['cliente_id'] ?? null;
try {
    if ($ant === null) {
        $ant = [
            'cliente' => null,
            'encuesta_comercial' => null,
            'encuesta_negocio' => null,
            'acuerdo_visita' => null,
            'fallback_from_db' => true,
        ];
        if ($cliente_table_id) {
            $s = $pdo->prepare('SELECT * FROM cliente_prospecto WHERE id = :id LIMIT 1');
            $s->execute([':id' => $cliente_table_id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $ant['cliente'] = $r;
        }
        if ($tarea_ref) {
            $s = $pdo->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id = :t ORDER BY id DESC LIMIT 1');
            $s->execute([':t' => $tarea_ref]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $ant['encuesta_comercial'] = $r;
            $s = $pdo->prepare('SELECT * FROM encuesta_negocio WHERE tarea_id = :t ORDER BY id DESC LIMIT 1');
            $s->execute([':t' => $tarea_ref]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $ant['encuesta_negocio'] = $r;
            $s = $pdo->prepare('SELECT * FROM acuerdo_visita WHERE tarea_id = :t ORDER BY id DESC LIMIT 1');
            $s->execute([':t' => $tarea_ref]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $ant['acuerdo_visita'] = $r;
        }
        $ant_raw = json_encode($ant, JSON_UNESCAPED_UNICODE);
    }
    if ($new === null) {
        $new = [
            'cliente' => null,
            'encuesta_comercial' => null,
            'encuesta_negocio' => null,
            'acuerdo_visita' => null,
            'fallback_from_db' => true,
        ];
        if ($cliente_table_id) {
            $s = $pdo->prepare('SELECT * FROM cliente_prospecto WHERE id = :id LIMIT 1');
            $s->execute([':id' => $cliente_table_id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $new['cliente'] = $r;
        }
        if ($tarea_ref) {
            $s = $pdo->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id = :t ORDER BY id DESC LIMIT 1');
            $s->execute([':t' => $tarea_ref]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $new['encuesta_comercial'] = $r;
            $s = $pdo->prepare('SELECT * FROM encuesta_negocio WHERE tarea_id = :t ORDER BY id DESC LIMIT 1');
            $s->execute([':t' => $tarea_ref]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $new['encuesta_negocio'] = $r;
            $s = $pdo->prepare('SELECT * FROM acuerdo_visita WHERE tarea_id = :t ORDER BY id DESC LIMIT 1');
            $s->execute([':t' => $tarea_ref]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $new['acuerdo_visita'] = $r;
        }
        $new_raw = json_encode($new, JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $_) {}

// Mark as reviewed action — acepta POST normal o GET ?marcar_revisada=1 en modo AJAX
$_is_marcar = isset($_POST['marcar_revisada']) || (isset($_GET['marcar_revisada']) && $_GET['marcar_revisada'] == '1');
if ($_is_marcar) {
    $up = $pdo->prepare('UPDATE alerta_modificacion SET vista_supervisor = 1, vista_at = NOW() WHERE id = :id');
    $up->execute([':id' => $id]);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'id' => $id]); exit;
    }
    header('Location: alertas.php'); exit;
}

function normalize_for_compare($v) {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_scalar($v)) return trim((string)$v);
    if (is_array($v)) { ksort_recursive($v); return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    if (is_object($v)) { $arr=(array)$v; ksort_recursive($arr); return json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    return trim((string)$v);
}
function ksort_recursive(&$arr) {
    if (!is_array($arr)) return;
    ksort($arr);
    foreach ($arr as &$v) { if (is_array($v)) ksort_recursive($v); }
}
function format_field_name($k) {
    $t = str_replace(['_','-'], ' ', $k);
    $t = mb_strtolower($t, 'UTF-8');
    return mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
}
function render_value_html($v) {
    if ($v === null || $v === '') return '<span class="adx-empty">—</span>';
    if (is_bool($v)) return $v ? '<span class="adx-pill adx-pill-ok">Sí</span>' : '<span class="adx-pill adx-pill-no">No</span>';
    if (is_array($v) || is_object($v)) {
        $j = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        return '<pre class="adx-json">' . htmlspecialchars($j) . '</pre>';
    }
    return htmlspecialchars((string)$v);
}
function render_compare_section($title, $icon, $a, $b) {
    $a = is_array($a) ? $a : [];
    $b = is_array($b) ? $b : [];
    $hide = ['id','created_at','updated_at','tarea_id','cliente_prospecto_id','asesor_id','supervisor_id','fallback_from_db'];
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    $keys_shown = array_values(array_filter($keys, function($k) use ($hide){ return !in_array($k, $hide); }));
    $changed_count = 0;
    foreach ($keys_shown as $k) {
        if (normalize_for_compare($a[$k] ?? null) !== normalize_for_compare($b[$k] ?? null)) $changed_count++;
    }
    echo '<div class="adx-section">';
    echo '<div class="adx-section-head">';
    echo '<h5><span class="adx-icon">' . $icon . '</span>' . htmlspecialchars($title) . '</h5>';
    if ($changed_count > 0) {
        echo '<span class="adx-badge">' . $changed_count . ' cambio' . ($changed_count === 1 ? '' : 's') . '</span>';
    } else {
        echo '<span class="adx-badge adx-badge-ok">Sin cambios</span>';
    }
    echo '</div>';
    if (empty($keys_shown)) {
        echo '<p class="adx-empty-box">Sin datos registrados</p></div>';
        return;
    }
    echo '<div class="adx-grid">';
    foreach ($keys_shown as $k) {
        $av = $a[$k] ?? null;
        $bv = $b[$k] ?? null;
        $changed = (normalize_for_compare($av) !== normalize_for_compare($bv));
        echo '<div class="adx-item' . ($changed ? ' adx-changed' : '') . '">';
        echo '<div class="adx-key">' . htmlspecialchars(format_field_name($k));
        if ($changed) echo '<span class="adx-tag">modificado</span>';
        echo '</div>';
        echo '<div class="adx-cols">';
        echo '<div class="adx-col adx-before' . ($changed ? ' changed' : '') . '">';
        echo '<span class="adx-col-lbl">ANTES</span>';
        echo '<div class="adx-val">' . render_value_html($av) . '</div>';
        echo '</div>';
        echo '<div class="adx-col adx-after' . ($changed ? ' changed' : '') . '">';
        echo '<span class="adx-col-lbl">AHORA</span>';
        echo '<div class="adx-val">' . render_value_html($bv) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div></div>';
}
function count_changes_section($a, $b) {
    $a = is_array($a) ? $a : [];
    $b = is_array($b) ? $b : [];
    $hide = ['id','created_at','updated_at','tarea_id','cliente_prospecto_id','asesor_id','supervisor_id','fallback_from_db'];
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    $c = 0;
    foreach ($keys as $k) {
        if (in_array($k, $hide)) continue;
        if (normalize_for_compare($a[$k] ?? null) !== normalize_for_compare($b[$k] ?? null)) $c++;
    }
    return $c;
}

$total_cambios =
    count_changes_section($ant['cliente'] ?? null, $new['cliente'] ?? null) +
    count_changes_section($ant['encuesta_comercial'] ?? null, $new['encuesta_comercial'] ?? null) +
    count_changes_section($ant['encuesta_negocio'] ?? null, $new['encuesta_negocio'] ?? null) +
    count_changes_section($ant['acuerdo_visita'] ?? null, $new['acuerdo_visita'] ?? null);

$cliente_mostrar = $ant['cliente']['nombre'] ?? $new['cliente']['nombre'] ?? $row['cliente_nombre'] ?? 'Sin cliente';
$fecha_fmt = !empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '';
$estado_txt = !empty($row['vista_supervisor']) ? 'Revisada' : 'Pendiente';
$estado_cls = !empty($row['vista_supervisor']) ? 'adx-pill-ok' : 'adx-pill-alert';

// =============================================
// RENDER: si es AJAX solo contenido (sin HTML wrapper), si es normal página completa
// =============================================
if (!$is_ajax) {
    // Cabecera completa de la página standalone (fallback antiguo)
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle Alerta - Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { background:#f5f7fb; padding:20px; font-family:'Inter','Segoe UI',sans-serif; }</style>
    <?php // Los estilos del contenido se imprimen abajo dentro del contenedor ?>
</head>
<body>
    <div style="max-width:1200px;margin:0 auto;">
        <a href="alertas.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Volver</a>
    <?php
}
?>
<div class="adx-wrap" data-alerta-id="<?php echo htmlspecialchars($row['id']); ?>">
    <style>
        .adx-wrap { padding: 0; }
        .adx-hero {
            background: linear-gradient(135deg, #0a2748 0%, #123a6d 100%);
            color: #fff; border-radius: 14px; padding: 20px 22px;
            display: flex; justify-content: space-between; gap: 22px;
            margin-bottom: 18px; flex-wrap: wrap;
            box-shadow: 0 10px 26px rgba(10, 39, 72, 0.18);
        }
        .adx-hero-chip {
            display:inline-flex; align-items:center; gap:8px;
            background:rgba(255,221,0,.18); color:#ffdd00;
            padding:5px 12px; border-radius:20px;
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.5px; border:1px solid rgba(255,221,0,.35);
            margin-bottom:8px;
        }
        .adx-hero h3 { font-size:22px; font-weight:800; margin:0 0 8px; }
        .adx-hero-sub { display:flex; flex-wrap:wrap; gap:10px 18px; font-size:12.5px; color:rgba(255,255,255,.9); }
        .adx-hero-sub i { color:#ffdd00; margin-right:4px; }
        .adx-hero-right { display:flex; gap:16px; align-items:center; }
        .adx-stat { background:rgba(255,255,255,.08); border:1px solid rgba(255,221,0,.25); border-radius:12px; padding:12px 16px; text-align:center; min-width:110px; }
        .adx-stat-num { font-size:30px; font-weight:900; color:#ffdd00; line-height:1; }
        .adx-stat-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.5px; margin-top:6px; opacity:.9; }
        .adx-ids { display:flex; flex-direction:column; gap:6px; font-size:11.5px; }
        .adx-ids > div { display:flex; gap:8px; align-items:center; }
        .adx-ids span { opacity:.7; min-width:65px; }
        .adx-ids code { background:rgba(255,255,255,.12); color:#ffdd00; padding:2px 8px; border-radius:5px; font-size:10.5px; }

        .adx-legend {
            display:flex; flex-wrap:wrap; gap:14px;
            padding:8px 12px; background:#fff;
            border:1px solid #e5e9f0; border-radius:10px;
            margin-bottom:14px; font-size:11.5px;
        }
        .adx-legend .adx-leg { display:inline-flex; align-items:center; gap:6px; color:#374151; font-weight:600; }
        .adx-legend .adx-dot { width:12px; height:12px; border-radius:3px; display:inline-block; }
        .adx-leg-b .adx-dot { background:#fee2e2; border:1px solid #ef4444; }
        .adx-leg-a .adx-dot { background:#d1fae5; border:1px solid #10b981; }
        .adx-leg-c .adx-dot { background:#fef3c7; border:1px solid #f59e0b; }

        .adx-section { background:#fff; border-radius:12px; margin-bottom:14px; overflow:hidden; border:1px solid #e5e9f0; box-shadow:0 2px 8px rgba(10,23,45,.04); }
        .adx-section-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #eef1f6; background:linear-gradient(180deg,#fafbfc 0%,#fff 100%); }
        .adx-section-head h5 { margin:0; font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:#0a2748; display:flex; align-items:center; gap:10px; }
        .adx-icon { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:8px; background:linear-gradient(135deg,#ffdd00,#f4c400); color:#0a2748; }
        .adx-badge { background:#ef4444; color:#fff; padding:4px 11px; border-radius:10px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
        .adx-badge-ok { background:#10b981; }
        .adx-empty-box { padding:16px; color:#6b7280; margin:0; }

        .adx-grid { display:grid; gap:10px; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); padding:16px; }
        .adx-item { border:1px solid #e5e9f0; border-radius:10px; padding:10px; background:#fafbfc; transition:all .2s; }
        .adx-item.adx-changed { background:#fffbeb; border-color:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.08); }
        .adx-key { font-weight:700; color:#0a2748; font-size:11.5px; text-transform:uppercase; letter-spacing:.3px; margin-bottom:8px; padding-bottom:7px; border-bottom:2px solid #e5e9f0; display:flex; justify-content:space-between; align-items:center; gap:6px; }
        .adx-tag { background:#f59e0b; color:#fff; padding:2px 7px; border-radius:7px; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
        .adx-cols { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .adx-col { padding:9px; border-radius:7px; font-size:12.5px; min-height:46px; }
        .adx-col-lbl { display:block; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; opacity:.8; }
        .adx-before { background:#fef2f2; border-left:3px solid #ef4444; }
        .adx-before.changed { background:#ffebee; border-left-color:#d32f2f; }
        .adx-before .adx-col-lbl { color:#7f1d1d; }
        .adx-after { background:#f0fdf4; border-left:3px solid #10b981; }
        .adx-after.changed { background:#e8f5e9; border-left-color:#1b5e20; }
        .adx-after .adx-col-lbl { color:#065f46; }
        .adx-val { word-break:break-word; color:#1f2937; line-height:1.4; }
        .adx-empty { color:#9ca3af; font-style:italic; }
        .adx-json { background:#0f172a; color:#e2e8f0; padding:7px; border-radius:6px; font-size:10.5px; max-height:150px; overflow:auto; margin:0; white-space:pre-wrap; }

        .adx-pill { display:inline-block; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:700; }
        .adx-pill-ok { background:#d1fae5; color:#065f46; }
        .adx-pill-no { background:#fee2e2; color:#7f1d1d; }
        .adx-pill-alert { background:#fee2e2; color:#991b1b; }

        @media (max-width:768px) {
            .adx-hero { flex-direction:column; align-items:flex-start; }
            .adx-hero-right { width:100%; justify-content:space-between; }
            .adx-cols { grid-template-columns:1fr; }
            .adx-grid { grid-template-columns:1fr; padding:12px; }
        }
    </style>

    <div class="adx-hero">
        <div>
            <div class="adx-hero-chip"><i class="fas fa-triangle-exclamation"></i> Alerta de modificación</div>
            <h3><?php echo htmlspecialchars($cliente_mostrar); ?></h3>
            <div class="adx-hero-sub">
                <?php if (!empty($row['asesor_nombre'])): ?>
                    <span><i class="fas fa-user-tie"></i> Asesor: <strong><?php echo htmlspecialchars($row['asesor_nombre']); ?></strong></span>
                <?php endif; ?>
                <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars($fecha_fmt); ?></span>
                <span class="adx-pill <?php echo $estado_cls; ?>"><?php echo $estado_txt; ?></span>
            </div>
        </div>
        <div class="adx-hero-right">
            <div class="adx-stat">
                <div class="adx-stat-num"><?php echo $total_cambios; ?></div>
                <div class="adx-stat-lbl">Campo<?php echo $total_cambios === 1 ? '' : 's'; ?> modificado<?php echo $total_cambios === 1 ? '' : 's'; ?></div>
            </div>
            <div class="adx-ids">
                <div><span>Alerta</span><code>#<?php echo htmlspecialchars(substr($row['id'], 0, 12)); ?></code></div>
                <div><span>Tarea</span><code>#<?php echo htmlspecialchars(substr($row['tarea_id'], 0, 12)); ?></code></div>
            </div>
        </div>
    </div>

    <div class="adx-legend">
        <span class="adx-leg adx-leg-b"><span class="adx-dot"></span> ANTES (valor previo)</span>
        <span class="adx-leg adx-leg-a"><span class="adx-dot"></span> AHORA (valor modificado)</span>
        <span class="adx-leg adx-leg-c"><span class="adx-dot"></span> Campo modificado</span>
    </div>

    <?php
        render_compare_section('Datos del Cliente', '<i class="fas fa-user"></i>', $ant['cliente'] ?? null, $new['cliente'] ?? null);
        render_compare_section('Encuesta Comercial', '<i class="fas fa-briefcase"></i>', $ant['encuesta_comercial'] ?? null, $new['encuesta_comercial'] ?? null);
        render_compare_section('Encuesta Negocio', '<i class="fas fa-store"></i>', $ant['encuesta_negocio'] ?? null, $new['encuesta_negocio'] ?? null);
        render_compare_section('Acuerdo de Visita', '<i class="fas fa-handshake"></i>', $ant['acuerdo_visita'] ?? null, $new['acuerdo_visita'] ?? null);
    ?>
</div>
<?php if (!$is_ajax): ?>
    </div><!-- /max-width wrapper -->
</body>
</html>
<?php endif; ?>