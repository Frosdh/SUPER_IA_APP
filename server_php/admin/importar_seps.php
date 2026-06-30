<?php
// ============================================================
// importar_seps.php — Importador del Catastro SEPS al sistema
// Sube catastro_cooperativas_ecuador.csv y lo carga a seps_cooperativas
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

// Auth: admin o super_admin o administrador
$ok = (isset($_SESSION['admin_logged_in'])         && $_SESSION['admin_logged_in']         === true)
   || (isset($_SESSION['super_admin_logged_in'])   && $_SESSION['super_admin_logged_in']   === true)
   || (isset($_SESSION['administrador_logged_in']) && $_SESSION['administrador_logged_in'] === true);

if (!$ok) { header('Location: login.php?role=admin'); exit; }

// ── Mapas de columnas SEPS conocidas ─────────────────────────
// El CSV de SEPS puede tener nombres de columna distintos según versión
$MAP_COLS = [
    'razon_social'        => ['Razón Social', 'Razon Social', 'razon_social', 'RAZON SOCIAL', 'Nombre'],
    'ruc'                 => ['RUC', 'ruc', 'Ruc', 'NRO. RUC', 'Nro. RUC'],
    'nombre_comercial'    => ['Nombre Comercial', 'nombre_comercial', 'NOMBRE COMERCIAL'],
    'tipo_organizacion'   => ['Tipo de Organización', 'Tipo Organización', 'tipo_organizacion', 'TIPO', 'Tipo', 'Segmento'],
    'segmento'            => ['Segmento', 'segmento', 'SEGMENTO', 'Nivel'],
    'estado'              => ['Estado', 'estado', 'ESTADO', 'Situación'],
    'provincia'           => ['Provincia', 'provincia', 'PROVINCIA'],
    'canton'              => ['Cantón', 'Canton', 'canton', 'CANTON', 'Cantón de Domicilio'],
    'parroquia'           => ['Parroquia', 'parroquia', 'PARROQUIA'],
    'direccion'           => ['Dirección', 'Direccion', 'direccion', 'DIRECCIÓN', 'Dirección Domicilio'],
    'telefono'            => ['Teléfono', 'Telefono', 'telefono', 'TELÉFONO', 'Número Teléfono'],
    'correo'              => ['Correo', 'correo', 'Email', 'email', 'CORREO', 'Correo Electrónico'],
    'representante_legal' => ['Representante Legal', 'representante_legal', 'REPRESENTANTE LEGAL', 'Nombre Representante'],
    'fecha_constitucion'  => ['Fecha Constitución', 'Fecha Constitucion', 'fecha_constitucion', 'FECHA CONSTITUCIÓN'],
];

// ── Resultado de importación ──────────────────────────────────
$resultado = null;
$errores   = [];

// ── Asegurar que la tabla existe ─────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `seps_cooperativas` (
        `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
        `ruc`                 VARCHAR(20)  DEFAULT NULL,
        `razon_social`        VARCHAR(300) NOT NULL,
        `nombre_comercial`    VARCHAR(300) DEFAULT NULL,
        `tipo_organizacion`   VARCHAR(100) DEFAULT NULL,
        `segmento`            VARCHAR(50)  DEFAULT NULL,
        `estado`              VARCHAR(50)  DEFAULT NULL,
        `provincia`           VARCHAR(100) DEFAULT NULL,
        `canton`              VARCHAR(100) DEFAULT NULL,
        `parroquia`           VARCHAR(100) DEFAULT NULL,
        `direccion`           TEXT         DEFAULT NULL,
        `telefono`            VARCHAR(50)  DEFAULT NULL,
        `correo`              VARCHAR(200) DEFAULT NULL,
        `representante_legal` VARCHAR(200) DEFAULT NULL,
        `fecha_constitucion`  DATE         DEFAULT NULL,
        `extra_json`          LONGTEXT     DEFAULT NULL,
        `activo`              TINYINT(1)   NOT NULL DEFAULT 1,
        `importado_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_seps_ruc` (`ruc`),
        KEY `idx_seps_razon` (`razon_social`(100)),
        KEY `idx_seps_provincia` (`provincia`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // tabla ya existe o error no crítico
}

// ── Estadísticas actuales ─────────────────────────────────────
$total_seps    = (int)($pdo->query("SELECT COUNT(*) FROM seps_cooperativas")->fetchColumn() ?? 0);
$total_ub      = (int)($pdo->query("SELECT COUNT(*) FROM unidad_bancaria")->fetchColumn() ?? 0);
$ultima_import = $pdo->query("SELECT MAX(importado_at) FROM seps_cooperativas")->fetchColumn();

// ── Muestra de datos actuales ─────────────────────────────────
$muestra = [];
try {
    $muestra = $pdo->query("SELECT razon_social, ruc, provincia, segmento FROM seps_cooperativas ORDER BY razon_social LIMIT 6")->fetchAll();
} catch(PDOException $e){}

// ── Helper: resolver columna ──────────────────────────────────
function resolverCol(array $headers, array $posibles): ?int {
    foreach ($posibles as $nombre) {
        $k = array_search($nombre, $headers, true);
        if ($k !== false) return $k;
        // case-insensitive
        foreach ($headers as $i => $h) {
            if (mb_strtolower(trim($h)) === mb_strtolower(trim($nombre))) return $i;
        }
    }
    return null;
}

// ── POST: Importar CSV ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'Error al subir el archivo: ' . $file['error'];
    } else {
        $tmpPath = $file['tmp_name'];
        $handle  = fopen($tmpPath, 'r');

        if (!$handle) {
            $errores[] = 'No se pudo abrir el archivo CSV.';
        } else {
            // Detectar separador (coma o punto y coma)
            $firstLine = fgets($handle);
            rewind($handle);
            $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

            // Leer encabezados
            $headers = fgetcsv($handle, 0, $sep);
            if (!$headers) {
                $errores[] = 'El archivo no tiene encabezados válidos.';
            } else {
                // Limpiar BOM y espacios
                $headers = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), $headers);

                // Resolver índices de columnas conocidas
                $idx = [];
                foreach ($MAP_COLS as $campo => $posibles) {
                    $idx[$campo] = resolverCol($headers, $posibles);
                }

                // Columna razon_social es obligatoria
                if ($idx['razon_social'] === null) {
                    // Intentar usar la 2da columna como fallback
                    $idx['razon_social'] = 1;
                }

                $mode    = $_POST['modo'] ?? 'upsert';
                if ($mode === 'truncate') {
                    $pdo->exec("DELETE FROM seps_cooperativas");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO seps_cooperativas
                        (ruc, razon_social, nombre_comercial, tipo_organizacion, segmento,
                         estado, provincia, canton, parroquia, direccion, telefono,
                         correo, representante_legal, fecha_constitucion, extra_json)
                    VALUES
                        (:ruc, :razon_social, :nombre_comercial, :tipo_organizacion, :segmento,
                         :estado, :provincia, :canton, :parroquia, :direccion, :telefono,
                         :correo, :representante_legal, :fecha_constitucion, :extra_json)
                    ON DUPLICATE KEY UPDATE
                        razon_social        = VALUES(razon_social),
                        nombre_comercial    = VALUES(nombre_comercial),
                        tipo_organizacion   = VALUES(tipo_organizacion),
                        segmento            = VALUES(segmento),
                        estado              = VALUES(estado),
                        provincia           = VALUES(provincia),
                        canton              = VALUES(canton),
                        parroquia           = VALUES(parroquia),
                        direccion           = VALUES(direccion),
                        telefono            = VALUES(telefono),
                        correo              = VALUES(correo),
                        representante_legal = VALUES(representante_legal),
                        fecha_constitucion  = VALUES(fecha_constitucion),
                        extra_json          = VALUES(extra_json),
                        updated_at          = NOW()
                ");

                $insertadas = $actualizadas = $omitidas = 0;
                $rowNum = 0;

                while (($row = fgetcsv($handle, 0, $sep)) !== false) {
                    $rowNum++;
                    if (count($row) < 2) { $omitidas++; continue; }

                    $get = fn($campo) => isset($idx[$campo]) && $idx[$campo] !== null
                        ? (trim($row[$idx[$campo]] ?? '') ?: null)
                        : null;

                    $razon = $get('razon_social');
                    if (!$razon) { $omitidas++; continue; }

                    // Columnas "extra" no mapeadas → JSON
                    $mapeados = array_filter(array_values($idx), fn($v) => $v !== null);
                    $extra = [];
                    foreach ($headers as $i => $h) {
                        if (!in_array($i, $mapeados, true) && isset($row[$i]) && trim($row[$i]) !== '') {
                            $extra[$h] = trim($row[$i]);
                        }
                    }

                    // Parsear fecha
                    $rawFecha = $get('fecha_constitucion');
                    $fecha    = null;
                    if ($rawFecha) {
                        foreach (['d/m/Y','Y-m-d','d-m-Y','m/d/Y'] as $fmt) {
                            $dt = DateTime::createFromFormat($fmt, $rawFecha);
                            if ($dt) { $fecha = $dt->format('Y-m-d'); break; }
                        }
                    }

                    try {
                        $stmt->execute([
                            ':ruc'                 => $get('ruc'),
                            ':razon_social'        => mb_substr($razon, 0, 300),
                            ':nombre_comercial'    => $get('nombre_comercial'),
                            ':tipo_organizacion'   => $get('tipo_organizacion'),
                            ':segmento'            => $get('segmento'),
                            ':estado'              => $get('estado'),
                            ':provincia'           => $get('provincia'),
                            ':canton'              => $get('canton'),
                            ':parroquia'           => $get('parroquia'),
                            ':direccion'           => $get('direccion'),
                            ':telefono'            => $get('telefono'),
                            ':correo'              => $get('correo'),
                            ':representante_legal' => $get('representante_legal'),
                            ':fecha_constitucion'  => $fecha,
                            ':extra_json'          => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
                        ]);
                        $affected = $stmt->rowCount();
                        if ($affected === 1) $insertadas++;
                        elseif ($affected === 2) $actualizadas++;
                    } catch (PDOException $e) {
                        $errores[] = "Fila $rowNum: " . $e->getMessage();
                        if (count($errores) > 10) { $errores[] = '...más errores omitidos'; break; }
                    }
                }
                fclose($handle);

                $resultado = compact('insertadas', 'actualizadas', 'omitidas', 'rowNum');

                // Recargar estadísticas
                $total_seps = (int)($pdo->query("SELECT COUNT(*) FROM seps_cooperativas")->fetchColumn() ?? 0);
                $muestra    = $pdo->query("SELECT razon_social, ruc, provincia, segmento FROM seps_cooperativas ORDER BY razon_social LIMIT 6")->fetchAll();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Importar Catastro SEPS — SUPER_IA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#0b1120;color:#f0f4ff;min-height:100vh;padding:28px}
.card-panel{background:#111827;border:1px solid #1f3050;border-radius:16px;padding:28px;max-width:860px;margin:0 auto}
h2{font-size:22px;font-weight:800;margin-bottom:4px}
.sub{color:#8a9ab8;font-size:14px}
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:20px 0}
.stat{background:#1a2540;border:1px solid #1f3050;border-radius:12px;padding:16px;text-align:center}
.stat-val{font-size:26px;font-weight:800}
.stat-lbl{font-size:12px;color:#8a9ab8;margin-top:2px}
.drop-zone{
  border:2px dashed #334155;border-radius:14px;padding:36px;text-align:center;
  background:#0f172a;cursor:pointer;transition:.2s;margin:20px 0;
}
.drop-zone:hover,.drop-zone.over{border-color:#6366f1;background:rgba(99,102,241,.06)}
.drop-zone i{font-size:36px;color:#6366f1;margin-bottom:12px;display:block}
.btn-import{background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;border-radius:10px;color:#fff;padding:12px 28px;font-weight:700;font-size:15px;cursor:pointer;width:100%;margin-top:8px}
.btn-import:hover{background:linear-gradient(135deg,#4f46e5,#7c3aed)}
.alert-ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:16px;color:#34d399;margin:16px 0}
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:16px;color:#f87171;margin:16px 0}
.steps{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin:20px 0}
.step{background:#1a2540;border-radius:10px;padding:12px 14px;border-left:3px solid #6366f1;font-size:13px}
.step-num{font-weight:800;color:#818cf8;font-size:16px;display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px}
th{padding:8px 10px;color:#4a5f7a;font-size:10px;letter-spacing:.8px;text-transform:uppercase;border-bottom:1px solid #1f3050}
td{padding:8px 10px;border-bottom:1px solid rgba(31,48,80,.5);color:#cbd5e1}
tr:hover td{background:rgba(99,102,241,.04)}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}
.p-green{background:rgba(16,185,129,.15);color:#34d399}
.p-blue{background:rgba(99,102,241,.15);color:#818cf8}
.p-amber{background:rgba(245,158,11,.15);color:#fbbf24}
.mode-row{display:flex;gap:12px;margin-bottom:16px}
.mode-opt{flex:1;background:#0f172a;border:1px solid #1f3050;border-radius:10px;padding:12px;cursor:pointer}
.mode-opt.sel{border-color:#6366f1;background:rgba(99,102,241,.08)}
.mode-opt input{display:none}
label.mode-opt{display:block}
</style>
</head>
<body>
<div class="card-panel">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
    <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:grid;place-items:center;font-size:22px;flex-shrink:0">🏦</div>
    <div>
      <h2>Importar Catastro SEPS</h2>
      <div class="sub">Carga el CSV del catastro de entidades activas — Ecuador</div>
    </div>
    <a href="administrador_index.php" style="margin-left:auto;color:#8a9ab8;font-size:13px;text-decoration:none"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>
  </div>

  <!-- Stats -->
  <div class="stat-grid">
    <div class="stat">
      <div class="stat-val" style="color:#818cf8"><?=number_format($total_seps)?></div>
      <div class="stat-lbl">Cooperativas SEPS en BD</div>
    </div>
    <div class="stat">
      <div class="stat-val" style="color:#34d399"><?=number_format($total_ub)?></div>
      <div class="stat-lbl">Bancos/Coops internas</div>
    </div>
    <div class="stat">
      <div class="stat-val" style="color:#fbbf24"><?=number_format($total_seps + $total_ub)?></div>
      <div class="stat-lbl">Total disponibles en API</div>
    </div>
  </div>
  <?php if ($ultima_import):?>
  <div style="font-size:12px;color:#4a5f7a;text-align:center;margin-top:-8px;margin-bottom:16px">
    Última importación: <?=date('d/m/Y H:i',strtotime($ultima_import))?>
  </div>
  <?php endif?>

  <!-- Pasos -->
  <div class="steps">
    <div class="step"><span class="step-num">① Python</span>Ejecuta <code>python probar_cooperativas.py</code> → genera el CSV</div>
    <div class="step"><span class="step-num">② Subir</span>Arrastra o selecciona el archivo <code>catastro_cooperativas_ecuador.csv</code> aquí</div>
    <div class="step"><span class="step-num">③ Listo</span>Las cooperativas aparecen automáticamente en la app móvil</div>
  </div>

  <!-- Resultado -->
  <?php if ($resultado):?>
  <div class="alert-ok">
    <strong><i class="fa-solid fa-circle-check me-2"></i>Importación completada</strong><br>
    <span style="font-size:13px">
      ✅ Nuevas: <strong><?=$resultado['insertadas']?></strong> &nbsp;
      🔄 Actualizadas: <strong><?=$resultado['actualizadas']?></strong> &nbsp;
      ⏭️ Omitidas: <strong><?=$resultado['omitidas']?></strong> &nbsp;
      (de <?=$resultado['rowNum']?> filas totales)
    </span>
  </div>
  <?php endif?>
  <?php if (!empty($errores)):?>
  <div class="alert-err">
    <strong><i class="fa-solid fa-triangle-exclamation me-2"></i>Advertencias</strong><br>
    <?php foreach($errores as $e):?><div style="font-size:12px;margin-top:4px"><?=htmlspecialchars($e)?></div><?php endforeach?>
  </div>
  <?php endif?>

  <!-- Formulario upload -->
  <form method="POST" enctype="multipart/form-data" id="formImport">
    <div class="mode-row">
      <label class="mode-opt sel" id="opt_upsert">
        <input type="radio" name="modo" value="upsert" checked>
        <div style="font-weight:700;font-size:13px;margin-bottom:4px">🔄 Actualizar</div>
        <div style="font-size:12px;color:#8a9ab8">Agrega nuevas y actualiza existentes (recomendado)</div>
      </label>
      <label class="mode-opt" id="opt_truncate">
        <input type="radio" name="modo" value="truncate">
        <div style="font-weight:700;font-size:13px;margin-bottom:4px">🔃 Reemplazar todo</div>
        <div style="font-size:12px;color:#8a9ab8">Borra todo y reimporta desde cero</div>
      </label>
    </div>

    <div class="drop-zone" id="dropZone" onclick="document.getElementById('csvInput').click()">
      <i class="fa-solid fa-file-csv"></i>
      <div style="font-weight:700;font-size:15px;margin-bottom:6px">Arrastra el CSV aquí o haz clic</div>
      <div style="font-size:13px;color:#8a9ab8">catastro_cooperativas_ecuador.csv</div>
      <div id="fileName" style="font-size:13px;color:#818cf8;margin-top:10px;display:none"></div>
    </div>
    <input type="file" name="csv_file" id="csvInput" accept=".csv,.txt" style="display:none" required onchange="mostrarNombre(this)">

    <button type="submit" class="btn-import">
      <i class="fa-solid fa-upload me-2"></i>Importar Catastro SEPS
    </button>
  </form>

  <!-- Muestra datos actuales -->
  <?php if (!empty($muestra)):?>
  <div style="margin-top:28px">
    <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:#8a9ab8">
      <i class="fa-solid fa-table me-1"></i>Muestra de datos importados
    </div>
    <table>
      <thead><tr><th>Razón Social</th><th>RUC</th><th>Provincia</th><th>Segmento</th></tr></thead>
      <tbody>
      <?php foreach($muestra as $m):?>
      <tr>
        <td><?=htmlspecialchars($m['razon_social'])?></td>
        <td style="font-family:monospace"><?=htmlspecialchars($m['ruc']??'—')?></td>
        <td><?=htmlspecialchars($m['provincia']??'—')?></td>
        <td><?php $s=$m['segmento']??''; echo $s?'<span class="pill '.($s=='1'?'p-green':($s=='2'?'p-blue':'p-amber')).'">Seg. '.htmlspecialchars($s).'</span>':'—' ?></td>
      </tr>
      <?php endforeach?>
      </tbody>
    </table>
  </div>
  <?php endif?>
</div>

<script>
// Drag & drop
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('over'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('over');
  const f = e.dataTransfer.files[0];
  if (f) {
    const inp = document.getElementById('csvInput');
    const dt  = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
    mostrarNombre(inp);
  }
});

function mostrarNombre(inp) {
  const fn = document.getElementById('fileName');
  fn.textContent = '📄 ' + inp.files[0]?.name;
  fn.style.display = 'block';
}

// Mode selector
document.querySelectorAll('.mode-opt').forEach(el => {
  el.addEventListener('click', () => {
    document.querySelectorAll('.mode-opt').forEach(e => e.classList.remove('sel'));
    el.classList.add('sel');
  });
});
</script>
</body>
</html>
