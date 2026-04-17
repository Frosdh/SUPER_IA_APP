<?php
/**
 * debug_operaciones.php — Diagnóstico completo de Operaciones de Crédito
 * Abre este archivo en el navegador estando logueado como supervisor.
 * BORRAR en producción cuando todo funcione.
 */
require_once 'db_admin.php';
if (!isset($_SESSION['supervisor_logged_in']) && !isset($_SESSION['admin_logged_in']) && !isset($_SESSION['super_admin_logged_in'])) {
    die('No autorizado — inicia sesión primero.');
}
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;font-size:13px;padding:20px;background:#111;color:#0f0;overflow-x:auto;">';

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║       DEBUG OPERACIONES DE CRÉDITO                  ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ── 1. SESIÓN ──────────────────────────────────────────────
echo "【1】 SESIÓN\n";
$sv_id  = $_SESSION['supervisor_id']  ?? null;
$adm_id = $_SESSION['admin_id']       ?? null;
echo "  supervisor_id  : " . ($sv_id  ?? '(no set)') . "\n";
echo "  admin_id       : " . ($adm_id ?? '(no set)') . "\n\n";

$user_id = $sv_id ?? $adm_id;

// ── 2. RESOLVER supervisor.id ──────────────────────────────
echo "【2】 RESOLVER supervisor.id\n";
$supervisor_table_id = $user_id;
try {
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$user_id]);
    $sid_found = $st->fetchColumn();
    if ($sid_found) {
        $supervisor_table_id = $sid_found;
        echo "  ✓ supervisor.id encontrado : $supervisor_table_id\n";
    } else {
        echo "  ⚠ No hay fila en tabla supervisor para usuario_id='$user_id'\n";
        echo "  → fallback: sid = uid = $user_id\n";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}
$sid = $supervisor_table_id;
$uid = $user_id;
echo "  sid = $sid\n";
echo "  uid = $uid\n\n";

// ── 3. TABLA supervisor ────────────────────────────────────
echo "【3】 TABLA supervisor\n";
try {
    $rows = $pdo->query("SELECT id, usuario_id FROM supervisor LIMIT 10")->fetchAll();
    if (empty($rows)) echo "  (tabla vacía)\n";
    foreach ($rows as $r) echo "  id=" . $r['id'] . "  usuario_id=" . $r['usuario_id'] . "\n";
} catch (Exception $e) { echo "  ✗ " . $e->getMessage() . "\n"; }
echo "\n";

// ── 4. ASESORES ────────────────────────────────────────────
echo "【4】 ASESORES en BD\n";
try {
    $rows = $pdo->query("SELECT a.id, a.usuario_id, a.supervisor_id, u.nombre
                         FROM asesor a LEFT JOIN usuario u ON u.id=a.usuario_id LIMIT 20")->fetchAll();
    if (empty($rows)) echo "  (ninguno)\n";
    foreach ($rows as $r) {
        $ms = ($r['supervisor_id'] === $sid) ? '✓sid' : '   ';
        $mu = ($r['supervisor_id'] === $uid) ? '✓uid' : '   ';
        echo "  $ms $mu  nombre=" . str_pad($r['nombre'] ?? '?', 22) .
             "  asesor.id=" . substr($r['id'],0,16) .
             "  supervisor_id=" . ($r['supervisor_id'] ?? 'NULL') . "\n";
    }
} catch (Exception $e) { echo "  ✗ " . $e->getMessage() . "\n"; }
echo "\n";

// ── 5. TABLAS FICHA ────────────────────────────────────────
echo "【5】 TABLAS\n";
foreach (['ficha_producto','ficha_credito','ficha_cuenta_corriente','ficha_cuenta_ahorros','ficha_inversiones'] as $t) {
    try {
        $exists = $pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
        $cnt = $exists ? $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() : '—';
        echo "  " . ($exists ? "✓" : "✗") . " $t  → $cnt reg\n";
    } catch (Exception $e) { echo "  ✗ $t ERROR\n"; }
}
echo "\n";

// ── 6. FICHA_PRODUCTO (credito) ────────────────────────────
echo "【6】 FICHA_PRODUCTO donde producto_tipo='credito'\n";
try {
    $rows = $pdo->query("SELECT id, usuario_id, asesor_id, cliente_nombre, created_at
                         FROM ficha_producto WHERE producto_tipo='credito'
                         ORDER BY created_at DESC LIMIT 10")->fetchAll();
    if (empty($rows)) {
        echo "  (NINGUNA — las fichas de crédito no están guardadas)\n";
    } else {
        foreach ($rows as $r) {
            echo "  id=" . substr($r['id'],0,18) .
                 "  usuario_id=" . ($r['usuario_id'] ?? 'NULL') .
                 "  asesor_id=" . ($r['asesor_id'] ?? 'NULL') .
                 "  cliente=" . ($r['cliente_nombre'] ?? '?') . "\n";
        }
    }
} catch (Exception $e) { echo "  ✗ " . $e->getMessage() . "\n"; }
echo "\n";

// ── 7. DISTINCT producto_tipo ──────────────────────────────
echo "【7】 VALORES DISTINTOS de producto_tipo en ficha_producto\n";
try {
    $tipos = $pdo->query("SELECT producto_tipo, COUNT(*) as cnt FROM ficha_producto GROUP BY producto_tipo")->fetchAll();
    if (empty($tipos)) echo "  (ficha_producto vacía)\n";
    foreach ($tipos as $r) echo "  producto_tipo='" . $r['producto_tipo'] . "'  cnt=" . $r['cnt'] . "\n";
} catch (Exception $e) { echo "  ✗ " . $e->getMessage() . "\n"; }
echo "\n";

// ── 8. JOIN COMPLETO sin filtro supervisor ─────────────────
echo "【8】 JOIN ficha_producto + ficha_credito + asesor (sin filtro)\n";
try {
    $rows = $pdo->query("
        SELECT fp.id as fp_id, fp.usuario_id, fp.asesor_id, fp.cliente_nombre,
               a.id as a_id, a.supervisor_id as a_sup_id,
               u.nombre as asesor_nombre
        FROM ficha_producto fp
        JOIN ficha_credito fc ON fc.ficha_id COLLATE utf8mb4_unicode_ci = fp.id COLLATE utf8mb4_unicode_ci
        LEFT JOIN asesor a ON (
            a.id        = fp.asesor_id  COLLATE utf8mb4_unicode_ci
            OR a.usuario_id = fp.usuario_id COLLATE utf8mb4_unicode_ci
            OR a.id        = fp.usuario_id COLLATE utf8mb4_unicode_ci
        )
        LEFT JOIN usuario u ON u.id = a.usuario_id
        WHERE fp.producto_tipo = 'credito'
        ORDER BY fp.created_at DESC LIMIT 10
    ")->fetchAll();
    if (empty($rows)) {
        echo "  RESULTADO VACÍO — el JOIN no devuelve nada\n";
        echo "  Verificar si ficha_credito.ficha_id coincide con ficha_producto.id\n";
    } else {
        foreach ($rows as $r) {
            $ms = ($r['a_sup_id'] === $sid) ? '✓sid' : '   ';
            $mu = ($r['a_sup_id'] === $uid) ? '✓uid' : '   ';
            echo "  $ms $mu  fp=" . substr($r['fp_id'],0,16) .
                 "  asesor=" . str_pad($r['asesor_nombre'] ?? 'NULL',20) .
                 "  a.supervisor_id=" . ($r['a_sup_id'] ?? 'NULL') . "\n";
        }
    }
} catch (Exception $e) {
    echo "  ✗ SQL ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// ── 9. QUERY EXACTA DE OPERACIONES.PHP ────────────────────
echo "【9】 QUERY EXACTA del supervisor (como en operaciones.php)\n";
try {
    $q = "SELECT fp.id as id_credito,
                 COALESCE(cp.nombre, fp.cliente_nombre) as cliente_nombre,
                 fc.monto_credito as cantidad,
                 fp.created_at as fecha_creacion,
                 u.nombre as asesor_nombre
          FROM ficha_producto fp
          JOIN ficha_credito fc ON fc.ficha_id COLLATE utf8mb4_unicode_ci = fp.id COLLATE utf8mb4_unicode_ci
          LEFT JOIN asesor a ON (
              a.id        = fp.asesor_id  COLLATE utf8mb4_unicode_ci
              OR a.usuario_id = fp.usuario_id COLLATE utf8mb4_unicode_ci
              OR a.id        = fp.usuario_id COLLATE utf8mb4_unicode_ci
          )
          LEFT JOIN usuario u ON u.id = a.usuario_id
          LEFT JOIN cliente_prospecto cp ON cp.cedula = fp.cliente_cedula COLLATE utf8mb4_unicode_ci
          WHERE fp.producto_tipo = 'credito'
            AND (
                a.supervisor_id IN (?, ?)
                OR fp.asesor_id  COLLATE utf8mb4_unicode_ci IN (SELECT id         FROM asesor WHERE supervisor_id IN (?, ?))
                OR fp.usuario_id COLLATE utf8mb4_unicode_ci IN (SELECT usuario_id FROM asesor WHERE supervisor_id IN (?, ?))
                OR fp.usuario_id COLLATE utf8mb4_unicode_ci IN (SELECT id         FROM asesor WHERE supervisor_id IN (?, ?))
            )
          ORDER BY fp.created_at DESC";
    $st = $pdo->prepare($q);
    $st->execute([$sid, $uid, $sid, $uid, $sid, $uid, $sid, $uid]);
    $rows = $st->fetchAll();
    if (empty($rows)) {
        echo "  RESULTADO VACÍO — la query del supervisor devuelve 0\n";
        echo "  → El supervisor_id en asesor no coincide con sid=$sid ni uid=$uid\n";
    } else {
        echo "  ✓ " . count($rows) . " resultado(s):\n";
        foreach ($rows as $r) {
            echo "  " . $r['cliente_nombre'] . "  monto=" . ($r['cantidad'] ?? '?') .
                 "  asesor=" . ($r['asesor_nombre'] ?? '?') . "\n";
        }
    }
} catch (Exception $e) {
    echo "  ✗ SQL ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

echo "═══════════════════════════════════════════════════════\n";
echo "FIN DEL DIAGNÓSTICO\n";
echo '</pre>';
