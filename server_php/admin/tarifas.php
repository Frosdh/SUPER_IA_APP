<?php
require_once 'db_admin.php';

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSuperAdmin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$isAdmin && !$isSuperAdmin) {
    header('Location: login.php');
    exit;
}

$msg = ''; $msgType = 'success';

// ══════════════════════════════════════════════════════════════
//  ACCIONES POST
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Guardar categoría existente ──
    if ($_POST['action'] === 'editar_categoria') {
        $id           = intval($_POST['id']);
        $nombre       = trim($_POST['nombre']);
        $tarifa_base  = floatval($_POST['tarifa_base']);
        $precio_km    = floatval($_POST['precio_km']);
        $precio_min   = floatval($_POST['precio_minuto']);

        if ($id > 0 && $nombre !== '') {
            $pdo->prepare("
                UPDATE categorias
                SET nombre=?, tarifa_base=?, precio_km=?, precio_minuto=?
                WHERE id=?
            ")->execute([$nombre, $tarifa_base, $precio_km, $precio_min, $id]);
            $msg = "Categoría «{$nombre}» actualizada correctamente.";
        } else {
            $msg = 'Datos inválidos.'; $msgType = 'danger';
        }
    }

    // ── Crear nueva categoría ──
    elseif ($_POST['action'] === 'nueva_categoria') {
        $nombre      = trim($_POST['nombre']);
        $tarifa_base = floatval($_POST['tarifa_base']);
        $precio_km   = floatval($_POST['precio_km']);
        $precio_min  = floatval($_POST['precio_minuto']);

        if ($nombre !== '') {
            $pdo->prepare("
                INSERT INTO categorias (nombre, tarifa_base, precio_km, precio_minuto)
                VALUES (?, ?, ?, ?)
            ")->execute([$nombre, $tarifa_base, $precio_km, $precio_min]);
            $msg = "Categoría «{$nombre}» creada correctamente.";
        } else {
            $msg = 'El nombre es obligatorio.'; $msgType = 'danger';
        }
    }

    // ── Eliminar categoría ──
    elseif ($_POST['action'] === 'eliminar_categoria') {
        $id = intval($_POST['id']);
        // Verificar que no tenga vehículos o viajes asociados
        $enUso = $pdo->prepare("SELECT COUNT(*) FROM vehiculos WHERE categoria_id=?")->execute([$id]);
        $usosV = $pdo->query("SELECT COUNT(*) FROM vehiculos WHERE categoria_id=$id")->fetchColumn();
        $usosJ = $pdo->query("SELECT COUNT(*) FROM viajes WHERE categoria_id=$id")->fetchColumn();

        if ($usosV > 0 || $usosJ > 0) {
            $msg = "No se puede eliminar: esta categoría tiene {$usosV} vehículo(s) y {$usosJ} viaje(s) asociados.";
            $msgType = 'danger';
        } else {
            $pdo->prepare("DELETE FROM categorias WHERE id=?")->execute([$id]);
            $msg = 'Categoría eliminada.'; $msgType = 'warning';
        }
    }

    // ── Guardar configuración del sistema ──
    elseif ($_POST['action'] === 'guardar_config') {
        $configs = [
            'radio_busqueda_conductores_km'  => floatval($_POST['radio_km']),
            'comision_empresa_porcentaje'    => floatval($_POST['comision']),
            'mantenimiento_modo'             => ($_POST['mantenimiento'] === '1') ? 'true' : 'false',
        ];
        $stmt = $pdo->prepare("
            INSERT INTO configuracion (clave, valor)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)
        ");
        foreach ($configs as $clave => $valor) {
            $stmt->execute([$clave, $valor]);
        }
        $msg = 'Configuración del sistema guardada correctamente.';
    }
}

// ══════════════════════════════════════════════════════════════
//  DATOS
// ══════════════════════════════════════════════════════════════
$categorias = $pdo->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM vehiculos v WHERE v.categoria_id = c.id) AS vehiculos,
           (SELECT COUNT(*) FROM viajes   j WHERE j.categoria_id = c.id) AS viajes
    FROM categorias c
    ORDER BY c.id ASC
")->fetchAll();

// Configuración del sistema
$cfgRows = $pdo->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$radioKm     = $cfgRows['radio_busqueda_conductores_km'] ?? 5;
$comision    = $cfgRows['comision_empresa_porcentaje']   ?? 15;
$mantenimiento = ($cfgRows['mantenimiento_modo'] ?? 'false') === 'true';

try {
    $totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado=0")->fetchColumn();
} catch (Exception $e) {
    $totalPendientes = 0;
}

$currentPage = 'tarifas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA Admin — Tarifas y Categorías</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .card-section { background:#fff; border-radius:14px; padding:24px; box-shadow:0 4px 16px rgba(0,0,0,.06); margin-bottom:24px; }
        .card-section .section-title { font-size:16px; font-weight:700; color:#24243e; margin-bottom:4px; }
        .card-section .section-sub   { font-size:13px; color:#6c757d; margin-bottom:20px; }

        /* Tarjeta de categoría */
        .cat-card { background:#f8f9fa; border:2px solid #e9ecef; border-radius:12px; padding:20px; transition:.2s; }
        .cat-card:hover { border-color:#6b11ff33; box-shadow:0 4px 16px rgba(107,17,255,.08); }
        .cat-card.editing { border-color:#6b11ff; background:#fdf8ff; }

        .cat-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; color:#fff; flex-shrink:0; }
        .bg-purple { background:linear-gradient(135deg,#6b11ff,#9b51e0); }
        .bg-blue   { background:linear-gradient(135deg,#3182fe,#5b9cfc); }
        .bg-orange { background:linear-gradient(135deg,#f46b45,#eea849); }
        .bg-green  { background:linear-gradient(135deg,#11998e,#38ef7d); }

        .price-badge { background:#f0f4ff; color:#4a6cf7; border-radius:8px; padding:4px 10px; font-size:13px; font-weight:600; }

        /* Simulador */
        .simulator { background:linear-gradient(135deg,#6b11ff15,#3182fe15); border:1px solid #6b11ff33; border-radius:12px; padding:20px; }

        /* Config */
        .config-item { background:#f8f9fa; border-radius:10px; padding:16px 20px; margin-bottom:12px; }
        .config-item label { font-weight:600; font-size:14px; margin-bottom:6px; display:block; }
        .config-item .config-desc { font-size:12px; color:#6c757d; margin-bottom:8px; }

        .form-control:focus, .form-select:focus { border-color:#6b11ff; box-shadow:0 0 0 .2rem rgba(107,17,255,.15); }
        .btn-purple { background:#6b11ff; color:#fff; border:none; }
        .btn-purple:hover { background:#5a0de0; color:#fff; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <!-- ── CONTENIDO ── -->
    <div class="col-md-10 content">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0"><i class="fas fa-tags me-2 text-primary"></i>Tarifas y Categorías</h4>
                <small class="text-muted">Gestiona los precios y categorías de servicio de la plataforma</small>
            </div>
        </div>

        <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
            <i class="fas fa-<?= $msgType==='success' ? 'check-circle' : ($msgType==='warning' ? 'exclamation-triangle' : 'times-circle') ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════
             SECCIÓN 1: CATEGORÍAS
        ════════════════════════════════════════════════════ -->
        <div class="card-section">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="section-title"><i class="fas fa-car me-2 text-primary"></i>Categorías de Servicio</div>
                    <div class="section-sub">Edita nombre y precios de cada categoría. Los cambios aplican inmediatamente en la app.</div>
                </div>
                <button class="btn btn-purple btn-sm" onclick="mostrarFormNueva()">
                    <i class="fas fa-plus me-1"></i>Nueva Categoría
                </button>
            </div>

            <!-- Formulario nueva categoría (oculto por defecto) -->
            <div id="formNueva" class="cat-card editing mb-4" style="display:none">
                <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle text-success me-2"></i>Nueva Categoría</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="nueva_categoria">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" class="form-control"
                                   placeholder="Ej: Fuber-Van" required maxlength="50">
                            <div class="form-text">Nombre visible para pasajeros y conductores.</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Tarifa Base ($)</label>
                            <input type="number" name="tarifa_base" class="form-control"
                                   step="0.01" min="0" value="1.50" required>
                            <div class="form-text">Cobro al iniciar el viaje.</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Precio por Km ($)</label>
                            <input type="number" name="precio_km" class="form-control"
                                   step="0.01" min="0" value="0.45" required>
                            <div class="form-text">Cobro por cada kilómetro.</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Precio por Min ($)</label>
                            <input type="number" name="precio_minuto" class="form-control"
                                   step="0.01" min="0" value="0.10" required>
                            <div class="form-text">Cobro por cada minuto.</div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-save me-1"></i>Guardar
                            </button>
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="document.getElementById('formNueva').style.display='none'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Lista de categorías -->
            <div class="row g-3">
                <?php
                $iconos = ['bg-purple','bg-blue','bg-orange','bg-green'];
                $icons  = ['fa-car','fa-car-side','fa-motorcycle','fa-shuttle-van'];
                foreach ($categorias as $i => $cat):
                    $ic  = $iconos[$i % count($iconos)];
                    $ico = $icons[$i % count($icons)];
                ?>
                <div class="col-md-6">
                    <div class="cat-card" id="card-<?= $cat['id'] ?>">
                        <!-- Vista normal -->
                        <div id="view-<?= $cat['id'] ?>">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="cat-icon <?= $ic ?>">
                                        <i class="fas <?= $ico ?>"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold mb-0"><?= htmlspecialchars($cat['nombre']) ?></h6>
                                        <small class="text-muted">
                                            <?= $cat['vehiculos'] ?> vehículo(s) · <?= $cat['viajes'] ?> viaje(s)
                                        </small>
                                    </div>
                                </div>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="editarCategoria(<?= $cat['id'] ?>)"
                                            title="Editar">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <?php if ($cat['vehiculos'] == 0 && $cat['viajes'] == 0): ?>
                                    <form method="POST" class="m-0"
                                          onsubmit="return confirm('¿Eliminar la categoría «<?= htmlspecialchars($cat['nombre'], ENT_QUOTES) ?>»? Esta acción no se puede deshacer.')">
                                        <input type="hidden" name="action" value="eliminar_categoria">
                                        <input type="hidden" name="id"     value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled
                                            title="No se puede eliminar: tiene vehículos o viajes asociados">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Precios -->
                            <div class="d-flex gap-2 flex-wrap">
                                <span class="price-badge">
                                    <i class="fas fa-flag me-1"></i>Base: $<?= number_format($cat['tarifa_base'], 2) ?>
                                </span>
                                <span class="price-badge">
                                    <i class="fas fa-road me-1"></i>Km: $<?= number_format($cat['precio_km'], 2) ?>
                                </span>
                                <span class="price-badge">
                                    <i class="fas fa-clock me-1"></i>Min: $<?= number_format($cat['precio_minuto'], 2) ?>
                                </span>
                            </div>
                            <!-- Ejemplo de precio para 5km/10min -->
                            <?php $ejemplo = $cat['tarifa_base'] + (5 * $cat['precio_km']) + (10 * $cat['precio_minuto']); ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-calculator me-1"></i>
                                    Ejemplo (5km, 10min): <strong class="text-success">$<?= number_format($ejemplo, 2) ?></strong>
                                </small>
                            </div>
                        </div>

                        <!-- Formulario de edición (oculto) -->
                        <div id="edit-<?= $cat['id'] ?>" style="display:none">
                            <form method="POST">
                                <input type="hidden" name="action" value="editar_categoria">
                                <input type="hidden" name="id"     value="<?= $cat['id'] ?>">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <label class="form-label fw-semibold small">Nombre</label>
                                        <input type="text" name="nombre" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($cat['nombre']) ?>" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label fw-semibold small">Tarifa Base ($)</label>
                                        <input type="number" name="tarifa_base" class="form-control form-control-sm"
                                               step="0.01" min="0" value="<?= $cat['tarifa_base'] ?>" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label fw-semibold small">Precio x Km ($)</label>
                                        <input type="number" name="precio_km" class="form-control form-control-sm"
                                               step="0.01" min="0" value="<?= $cat['precio_km'] ?>" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label fw-semibold small">Precio x Min ($)</label>
                                        <input type="number" name="precio_minuto" class="form-control form-control-sm"
                                               step="0.01" min="0" value="<?= $cat['precio_minuto'] ?>" required>
                                    </div>
                                    <div class="col-12 d-flex gap-2 mt-1">
                                        <button type="submit" class="btn btn-success btn-sm flex-fill">
                                            <i class="fas fa-save me-1"></i>Guardar cambios
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                onclick="cancelarEdicion(<?= $cat['id'] ?>)">
                                            Cancelar
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════
             SECCIÓN 2: SIMULADOR DE TARIFA
        ════════════════════════════════════════════════════ -->
        <div class="card-section">
            <div class="section-title"><i class="fas fa-calculator me-2 text-warning"></i>Simulador de Tarifa</div>
            <div class="section-sub">Calcula cuánto costaría un viaje con las tarifas actuales.</div>

            <div class="row g-3">
                <div class="col-md-5">
                    <div class="simulator">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Distancia (km)</label>
                                <input type="number" id="sim_km" class="form-control" step="0.1" min="0" value="5" oninput="simular()">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Duración (min)</label>
                                <input type="number" id="sim_min" class="form-control" step="1" min="0" value="10" oninput="simular()">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="row g-2" id="sim_resultados">
                        <?php foreach ($categorias as $i => $cat):
                            $ic = $iconos[$i % count($iconos)];
                        ?>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center gap-2 p-3 bg-white rounded-3 border"
                                 id="simcard-<?= $cat['id'] ?>">
                                <div class="cat-icon <?= $ic ?>" style="width:36px;height:36px;font-size:16px">
                                    <i class="fas fa-car"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold small"><?= htmlspecialchars($cat['nombre']) ?></div>
                                    <div class="text-success fw-bold" id="sim-precio-<?= $cat['id'] ?>">
                                        $<?= number_format($cat['tarifa_base'] + 5*$cat['precio_km'] + 10*$cat['precio_minuto'], 2) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════
             SECCIÓN 3: CONFIGURACIÓN DEL SISTEMA
        ════════════════════════════════════════════════════ -->
        <div class="card-section">
            <div class="section-title"><i class="fas fa-sliders-h me-2 text-info"></i>Configuración del Sistema</div>
            <div class="section-sub">Parámetros globales de la plataforma. Aplican a todos los conductores y pasajeros.</div>

            <form method="POST">
                <input type="hidden" name="action" value="guardar_config">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="config-item">
                            <label><i class="fas fa-search-location me-2 text-primary"></i>Radio de búsqueda (km)</label>
                            <div class="config-desc">
                                Distancia máxima en km para buscar conductores disponibles cerca del pasajero.
                            </div>
                            <div class="input-group">
                                <input type="number" name="radio_km" class="form-control"
                                       step="0.5" min="1" max="50" value="<?= $radioKm ?>">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="config-item">
                            <label><i class="fas fa-percent me-2 text-success"></i>Comisión de la empresa (%)</label>
                            <div class="config-desc">
                                Porcentaje que retiene la plataforma de cada viaje completado.
                                El conductor recibe el resto.
                            </div>
                            <div class="input-group">
                                <input type="number" name="comision" class="form-control"
                                       step="0.5" min="0" max="50" value="<?= $comision ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="config-item">
                            <label><i class="fas fa-tools me-2 text-warning"></i>Modo mantenimiento</label>
                            <div class="config-desc">
                                Cuando está activo, los pasajeros no pueden solicitar viajes nuevos.
                                Útil para actualizaciones del sistema.
                            </div>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="switchMant" name="mantenimiento" value="1"
                                       <?= $mantenimiento ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="switchMant">
                                    <?= $mantenimiento
                                        ? '<span class="text-danger">Activo — la app está en mantenimiento</span>'
                                        : '<span class="text-success">Desactivado — app funcionando</span>'
                                    ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-end mt-2">
                    <button type="submit" class="btn btn-purple px-4">
                        <i class="fas fa-save me-2"></i>Guardar configuración
                    </button>
                </div>
            </form>
        </div>

    </div><!-- /content -->
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Datos de categorías para el simulador
const categorias = <?= json_encode(array_map(fn($c) => [
    'id'           => $c['id'],
    'tarifa_base'  => (float)$c['tarifa_base'],
    'precio_km'    => (float)$c['precio_km'],
    'precio_minuto'=> (float)$c['precio_minuto'],
], $categorias)) ?>;

function simular() {
    const km  = parseFloat(document.getElementById('sim_km').value)  || 0;
    const min = parseFloat(document.getElementById('sim_min').value) || 0;
    categorias.forEach(c => {
        const precio = c.tarifa_base + (km * c.precio_km) + (min * c.precio_minuto);
        const el = document.getElementById('sim-precio-' + c.id);
        if (el) el.textContent = '$' + precio.toFixed(2);
    });
}

function editarCategoria(id) {
    document.getElementById('view-' + id).style.display = 'none';
    document.getElementById('edit-' + id).style.display = 'block';
    document.getElementById('card-' + id).classList.add('editing');
}

function cancelarEdicion(id) {
    document.getElementById('edit-' + id).style.display = 'none';
    document.getElementById('view-' + id).style.display = 'block';
    document.getElementById('card-' + id).classList.remove('editing');
}

function mostrarFormNueva() {
    const f = document.getElementById('formNueva');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
    if (f.style.display === 'block') f.scrollIntoView({ behavior:'smooth', block:'start' });
}
</script>
</body>
</html>
