<?php
require_once 'db_admin.php';

if (!isset($_GET['id'])) {
    header('Location: alertas.php'); exit;
}
$id = $_GET['id'];

$stmt = $pdo->prepare('SELECT am.*, t.id as tarea_ref, t.cliente_prospecto_id as cliente_id, cp.nombre as cliente_nombre, a.id as asesor_table_id, u.nombre as asesor_nombre FROM alerta_modificacion am JOIN tarea t ON am.tarea_id = t.id LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id LEFT JOIN asesor a ON am.asesor_id = a.id LEFT JOIN usuario u ON a.usuario_id = u.id WHERE am.id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) { header('Location: alertas.php'); exit; }

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
} catch (	hrowable $_) {
    // ignore DB fallback failures
}

function pretty_json($v, $raw = null) {
    if ($v === null) {
        if ($raw !== null && trim($raw) !== '') return '<pre style="white-space:pre-wrap;max-height:600px;overflow:auto;">' . htmlspecialchars($raw) . '</pre>';
        return '<em>N/A</em>';
    }
    return '<pre style="white-space:pre-wrap;max-height:600px;overflow:auto;">' . htmlspecialchars(json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}

// Mark as reviewed action
if (isset($_POST['marcar_revisada'])) {
    $up = $pdo->prepare('UPDATE alerta_modificacion SET vista_supervisor = 1, vista_at = NOW() WHERE id = :id');
    $up->execute([':id' => $id]);
    header('Location: alertas.php'); exit;
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Detalle Alerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; }
        .col-json { border:1px solid #e9ecef; background:#fff; padding:12px; border-radius:8px; }
        .diff-table { width:100%; border-collapse: collapse; margin-bottom: 14px; }
        .diff-table th, .diff-table td { padding: 8px 10px; border: 1px solid #e9ecef; vertical-align: top; }
        .diff-key { width:30%; font-weight:700; background:#f8fafc; }
        .changed-old { background: #ffecec; }
        .changed-new { background: #eaffea; }
        .unchanged { background: transparent; }
        .section-title { margin-top: 12px; margin-bottom:6px; font-weight:700; }
        .changed-count { margin-left:10px; font-size:0.9rem; }
        .diff-key.changed { background:#fff4cc; }
        .badge-changed { background:#d9534f; color:white; margin-left:8px; }
    </style>
</head>
<body>
    <a href="alertas.php" class="btn btn-secondary mb-3">← Volver</a>
    <h3>Alerta: <?php echo htmlspecialchars(substr($row['id'],0,8)); ?> — <?php echo htmlspecialchars($row['campo_modificado']); ?></h3>
    <p><strong>Tarea:</strong> <?php echo htmlspecialchars($row['tarea_id']); ?> &nbsp; <strong>Asesor:</strong> <?php echo htmlspecialchars($row['asesor_nombre'] ?? ''); ?> &nbsp; <strong>Cliente:</strong> <?php echo htmlspecialchars($row['cliente_nombre'] ?? ''); ?></p>

    <div class="row">
        <div class="col-12">
            <h5 class="section-title">Comparación por secciones</h5>
            <?php
            // Normalizar recursivamente arrays/objetos para comparación
            function normalize_for_compare($v) {
                if ($v === null) return '';
                if (is_bool($v)) return $v ? '1' : '0';
                if (is_scalar($v)) return trim((string)$v);
                if (is_array($v)) {
                    // ordenar por claves para comparar independientemente del orden
                    ksort_recursive($v);
                    return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
                // objetos -> array
                if (is_object($v)) {
                    $arr = (array)$v;
                    ksort_recursive($arr);
                    return json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
                return trim((string)$v);
            }

            function ksort_recursive(&$arr) {
                if (!is_array($arr)) return;
                ksort($arr);
                foreach ($arr as &$v) { if (is_array($v)) ksort_recursive($v); }
            }

            // Helper que imprime una tabla comparando dos arrays asociativos
            function render_compare_section($title, $a, $b) {
                echo '<div class="mb-3"><h6 style="font-weight:700">' . htmlspecialchars($title);
                $a = is_array($a) ? $a : [];
                $b = is_array($b) ? $b : [];
                $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
                $changed_count = 0;
                foreach ($keys as $k) {
                    $av = $a[$k] ?? null;
                    $bv = $b[$k] ?? null;
                    if (normalize_for_compare($av) !== normalize_for_compare($bv)) $changed_count++;
                }
                if ($changed_count > 0) {
                    echo ' <span class="badge badge-changed">' . $changed_count . ' cambiado(s)</span>';
                }
                echo '</h6>';
                if (empty($keys)) {
                    echo '<div class="col-json"><em>Sin datos</em></div></div>';
                    return;
                }
                echo '<table class="diff-table">';
                echo '<thead><tr><th class="diff-key">Campo</th><th>Antes</th><th>Ahora</th></tr></thead><tbody>';
                foreach ($keys as $k) {
                    $av = $a[$k] ?? null;
                    $bv = $b[$k] ?? null;
                    $av_show = is_array($av) || is_object($av) ? json_encode($av, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : ($av === null ? '' : (string)$av);
                    $bv_show = is_array($bv) || is_object($bv) ? json_encode($bv, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : ($bv === null ? '' : (string)$bv);
                    $changed = (normalize_for_compare($av) !== normalize_for_compare($bv));
                    $classA = $changed ? 'changed-old' : 'unchanged';
                    $classB = $changed ? 'changed-new' : 'unchanged';
                    $keyClass = $changed ? 'diff-key changed' : 'diff-key';
                    echo '<tr>';
                    echo '<td class="' . $keyClass . '">' . htmlspecialchars($k) . '</td>';
                    echo '<td class="' . $classA . '"><div style="max-height:160px;overflow:auto;">' . htmlspecialchars($av_show) . '</div></td>';
                    echo '<td class="' . $classB . '"><div style="max-height:160px;overflow:auto;">' . htmlspecialchars($bv_show) . '</div></td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // Renderizar comparaciones por secciones esperadas
            render_compare_section('Cliente', $ant['cliente'] ?? null, $new['cliente'] ?? null);
            render_compare_section('Encuesta comercial', $ant['encuesta_comercial'] ?? null, $new['encuesta_comercial'] ?? null);
            render_compare_section('Encuesta negocio', $ant['encuesta_negocio'] ?? null, $new['encuesta_negocio'] ?? null);
            render_compare_section('Acuerdo visita', $ant['acuerdo_visita'] ?? null, $new['acuerdo_visita'] ?? null);
            ?>
        </div>
    </div>

    <hr/>
    <div class="row">
        <div class="col-md-6">
            <h5>Original (raw)</h5>
            <div class="col-json">
                <?php echo pretty_json($ant, $ant_raw); ?>
            </div>
        </div>
        <div class="col-md-6">
            <h5>Modificada (raw)</h5>
            <div class="col-json">
                <?php echo pretty_json($new, $new_raw); ?>
            </div>
        </div>
    </div>

    <form method="post" class="mt-3">
        <button name="marcar_revisada" class="btn btn-success">Marcar como revisada</button>
    </form>
</body>
</html>
