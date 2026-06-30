<?php
// ============================================================
// api_cooperativas.php — Lista unificada: BD interna + SEPS
// GET /api_cooperativas.php
// GET /api_cooperativas.php?q=juventud          → búsqueda nombre/codigo
// GET /api_cooperativas.php?provincia=AZUAY     → filtro provincia (solo SEPS)
// GET /api_cooperativas.php?fuente=seps         → solo SEPS
// GET /api_cooperativas.php?fuente=interna      → solo internas
// GET /api_cooperativas.php?limit=500&offset=0  → paginación
//
// Respuesta compatible con CooperativaModel.fromJson:
//   { id: String, nombre: String, codigo: String, ciudad: String? }
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_config.php';

try {
    $q         = trim($_GET['q']         ?? '');
    $provincia = trim($_GET['provincia'] ?? '');
    $fuente    = trim($_GET['fuente']    ?? '');
    $limit     = min((int)($_GET['limit'] ?? 1000), 5000);
    $offset    = (int)($_GET['offset'] ?? 0);

    // ── ¿Existe la tabla SEPS? ────────────────────────────────
    $tbl_check  = $conn->query("SHOW TABLES LIKE 'seps_cooperativas'");
    $tiene_seps = $tbl_check && $tbl_check->num_rows > 0;

    $cooperativas = [];

    // ── 1. Unidades bancarias internas ────────────────────────
    if ($fuente !== 'seps') {
        $where = '1=1';
        $vals  = [];
        $types = '';
        if ($q !== '') {
            $like  = "%$q%";
            $where .= " AND (nombre LIKE ? OR codigo LIKE ? OR ciudad LIKE ?)";
            $vals   = [$like, $like, $like];
            $types  = 'sss';
        }
        $sql = "SELECT id, nombre, COALESCE(codigo,'') AS codigo,
                       COALESCE(ciudad,'') AS ciudad
                FROM unidad_bancaria
                WHERE $where
                ORDER BY nombre ASC";
        $st  = $conn->prepare($sql);
        if ($vals) { $st->bind_param($types, ...$vals); }
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $cooperativas[] = [
                'id'     => (string)$r['id'],
                'nombre' => $r['nombre'],
                'codigo' => $r['codigo'] ?? '',
                'ciudad' => $r['ciudad'] ?: null,
                '_fuente'=> 'interna',
            ];
        }
        $st->close();
    }

    // ── 2. Catastro SEPS ──────────────────────────────────────
    if ($tiene_seps && $fuente !== 'interna') {
        $where = 'activo = 1';
        $vals  = [];
        $types = '';

        if ($q !== '') {
            $like  = "%$q%";
            $where .= " AND (razon_social LIKE ? OR nombre_comercial LIKE ? OR ruc LIKE ?)";
            $vals   = array_merge($vals, [$like, $like, $like]);
            $types .= 'sss';
        }
        if ($provincia !== '') {
            $where  .= " AND provincia LIKE ?";
            $vals[]  = "%$provincia%";
            $types  .= 's';
        }

        // Si hay búsqueda, no paginamos (el usuario quiere ver todo lo que coincide)
        $limit_clause = ($q !== '') ? '' : "LIMIT ? OFFSET ?";

        $sql = "SELECT id, razon_social, COALESCE(ruc,'') AS ruc,
                       COALESCE(canton, provincia, '') AS ciudad_val
                FROM seps_cooperativas
                WHERE $where
                ORDER BY razon_social ASC
                $limit_clause";

        $st = $conn->prepare($sql);
        if ($limit_clause !== '') {
            $vals[]  = $limit;
            $vals[]  = $offset;
            $types  .= 'ii';
        }
        if ($vals) { $st->bind_param($types, ...$vals); }
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $cooperativas[] = [
                'id'     => 'seps_' . $r['id'],          // siempre String
                'nombre' => $r['razon_social'],
                'codigo' => $r['ruc'],
                'ciudad' => $r['ciudad_val'] ?: null,
                '_fuente'=> 'seps',
            ];
        }
        $st->close();
    }

    // ── Ordenar: internas primero, luego alfabético ───────────
    usort($cooperativas, function($a, $b) {
        if ($a['_fuente'] === 'interna' && $b['_fuente'] !== 'interna') return -1;
        if ($a['_fuente'] !== 'interna' && $b['_fuente'] === 'interna') return 1;
        return mb_strtolower($a['nombre']) <=> mb_strtolower($b['nombre']);
    });

    // ── Quitar campo interno _fuente del output ───────────────
    $data = array_map(function($c) {
        unset($c['_fuente']);
        return $c;
    }, $cooperativas);

    // ── Provincias disponibles ────────────────────────────────
    $provincias = [];
    if ($tiene_seps) {
        $res_p = $conn->query(
            "SELECT DISTINCT provincia FROM seps_cooperativas
             WHERE provincia IS NOT NULL AND provincia != '' AND activo = 1
             ORDER BY provincia"
        );
        if ($res_p) {
            while ($p = $res_p->fetch_assoc()) $provincias[] = $p['provincia'];
        }
    }

    // ── Conteos ───────────────────────────────────────────────
    $cnt_ub   = 0;
    $cnt_seps = 0;
    $r_ub = $conn->query("SELECT COUNT(*) AS c FROM unidad_bancaria WHERE activo=1");
    if ($r_ub) $cnt_ub = (int)$r_ub->fetch_assoc()['c'];
    if ($tiene_seps) {
        $r_sp = $conn->query("SELECT COUNT(*) AS c FROM seps_cooperativas WHERE activo=1");
        if ($r_sp) $cnt_seps = (int)$r_sp->fetch_assoc()['c'];
    }

    echo json_encode([
        'status'    => 'success',
        'total'     => count($data),
        'fuentes'   => [
            'interna'   => $cnt_ub,
            'seps'      => $cnt_seps,
            'seps_activa' => $tiene_seps,
        ],
        'provincias'=> $provincias,
        'data'      => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
