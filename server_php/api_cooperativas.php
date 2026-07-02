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
//
// NOTA (2026): este endpoint estaba devolviendo respuesta VACÍA (sin JSON,
// sin error) en producción. Causa: la query de `unidad_bancaria` pedía las
// columnas codigo/ciudad/activo, que en esa tabla podrían no existir con
// esos nombres exactos; al fallar `prepare()` devolvía `false` y la
// siguiente línea (`$st->execute()`/`bind_param()`) tronaba con un Fatal
// Error de PHP. Con `display_errors=0` (ver db_config.php) eso produce una
// respuesta 100% en blanco: el navegador (nueva_encuesta.php) no se veía
// afectado porque consulta la BD directamente con una query más simple
// (solo `nombre`), pero la app Flutter, que sí depende de este endpoint,
// se quedaba sin datos y silenciosamente mostraba la lista vacía.
// Se blindó el endpoint: nunca debe salir sin imprimir un JSON válido, y
// cada query "opcional" (columnas que podrían no existir) tiene un
// fallback a una versión mínima que si funciona.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Red de seguridad: si ocurre un error fatal más adelante (p.ej. columna
// inexistente que ni siquiera este archivo previó), igual se responde JSON
// en vez de dejar el body completamente vacío.
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error interno al listar instituciones: ' . substr($err['message'] ?? '', 0, 200),
        'data'    => [],
    ], JSON_UNESCAPED_UNICODE);
});

require_once __DIR__ . '/db_config.php';

// Modo de reporte predecible: prepare()/query() devuelven false en error
// (en vez de lanzar excepción, cuyo comportamiento varía según versión de
// PHP) para poder detectarlo y aplicar los fallbacks de abajo.
mysqli_report(MYSQLI_REPORT_OFF);

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
        $st = @$conn->prepare($sql);

        if (!$st) {
            // La tabla no tiene columnas codigo/ciudad (u otro problema de
            // esquema): reintentar con la misma query mínima que usa la
            // versión web (server_php/admin/nueva_encuesta.php), que sí
            // funciona: solo id + nombre, sin filtro por texto en esas
            // columnas ausentes.
            error_log('[api_cooperativas] prepare() falló para unidad_bancaria (codigo/ciudad): ' . $conn->error . ' — usando fallback mínimo');
            $whereMin = '1=1';
            $valsMin  = [];
            $typesMin = '';
            if ($q !== '') {
                $whereMin .= ' AND nombre LIKE ?';
                $valsMin   = ["%$q%"];
                $typesMin  = 's';
            }
            $st = @$conn->prepare("SELECT id, nombre FROM unidad_bancaria WHERE $whereMin ORDER BY nombre ASC");
            if ($st) {
                if ($valsMin) { $st->bind_param($typesMin, ...$valsMin); }
                if ($st->execute()) {
                    $res = $st->get_result();
                    while ($r = $res->fetch_assoc()) {
                        $cooperativas[] = [
                            'id'     => (string)$r['id'],
                            'nombre' => $r['nombre'],
                            'codigo' => '',
                            'ciudad' => null,
                            '_fuente'=> 'interna',
                        ];
                    }
                }
                $st->close();
            } else {
                error_log('[api_cooperativas] fallback mínimo de unidad_bancaria también falló: ' . $conn->error);
            }
        } else {
            if ($vals) { $st->bind_param($types, ...$vals); }
            if ($st->execute()) {
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
            }
            $st->close();
        }
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

        $st = @$conn->prepare($sql);
        if ($st) {
            if ($limit_clause !== '') {
                $vals[]  = $limit;
                $vals[]  = $offset;
                $types  .= 'ii';
            }
            if ($vals) { $st->bind_param($types, ...$vals); }
            if ($st->execute()) {
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
            }
            $st->close();
        } else {
            error_log('[api_cooperativas] prepare() falló para seps_cooperativas: ' . $conn->error);
        }
    }

    // ── Ordenar: internas primero, luego alfabético ───────────
    usort($cooperativas, function($a, $b) {
        $orden = ['interna' => 0, 'seps' => 1, 'fallback' => 2];
        $oa = $orden[$a['_fuente']] ?? 9;
        $ob = $orden[$b['_fuente']] ?? 9;
        if ($oa !== $ob) return $oa <=> $ob;
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
        $res_p = @$conn->query(
            "SELECT DISTINCT provincia FROM seps_cooperativas
             WHERE provincia IS NOT NULL AND provincia != '' AND activo = 1
             ORDER BY provincia"
        );
        if ($res_p) {
            while ($p = $res_p->fetch_assoc()) $provincias[] = $p['provincia'];
        }
    }

    // ── Conteos (con fallback si `activo` no existe en unidad_bancaria) ──
    $cnt_ub   = 0;
    $cnt_seps = 0;
    $r_ub = @$conn->query("SELECT COUNT(*) AS c FROM unidad_bancaria WHERE activo=1");
    if (!$r_ub) {
        $r_ub = @$conn->query("SELECT COUNT(*) AS c FROM unidad_bancaria");
    }
    if ($r_ub) $cnt_ub = (int)$r_ub->fetch_assoc()['c'];
    if ($tiene_seps) {
        $r_sp = @$conn->query("SELECT COUNT(*) AS c FROM seps_cooperativas WHERE activo=1");
        if (!$r_sp) {
            $r_sp = @$conn->query("SELECT COUNT(*) AS c FROM seps_cooperativas");
        }
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

} catch (\Throwable $e) {
    error_log('[api_cooperativas] ' . $e);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'data' => []], JSON_UNESCAPED_UNICODE);
}
