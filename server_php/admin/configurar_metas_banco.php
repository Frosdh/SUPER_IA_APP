<?php
// ============================================================
// admin/configurar_metas_banco.php
// Panel SuperAdmin: habilita, por banco/cooperativa, qué tipos de
// meta (diaria / semanal / mensual) puede asignar el supervisor de
// ese banco. El SuperAdmin NO elige días de la semana aquí — eso lo
// decide el supervisor por cada asesor, al momento de asignar la
// meta semanal (un asesor puede trabajar lunes/martes/miércoles y
// otro martes/jueves/viernes). metas.php lee esta configuración
// para mostrar solo los tipos habilitados al supervisor.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;
if (!$is_super_admin) {
    header('Location: login.php?role=super_admin');
    exit;
}

$admin_usuario_id = $_SESSION['super_admin_id'] ?? null;
$admin_nombre     = $_SESSION['super_admin_nombre'] ?? 'Super Admin';

// ── Auto-crear tabla de configuración (no destructivo) ───────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config_metas_banco (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            unidad_bancaria_id VARCHAR(36) NOT NULL,
            permite_diaria TINYINT(1) NOT NULL DEFAULT 1,
            permite_semanal TINYINT(1) NOT NULL DEFAULT 0,
            permite_mensual TINYINT(1) NOT NULL DEFAULT 1,
            dias_semana_habilitados VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
            actualizado_por VARCHAR(36) DEFAULT NULL,
            actualizado_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_config_metas_banco_ub (unidad_bancaria_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    // Si el hosting bloquea CREATE TABLE, se reintentará en cada carga;
    // el resto de la página maneja los errores de consulta con try/catch.
}

$mensaje = '';
$mensaje_tipo = '';

// ── Cargar lista de bancos/cooperativas ──────────────────────
$bancos = [];
try {
    $bancos = $pdo->query("SELECT id, nombre FROM unidad_bancaria WHERE activo = 1 ORDER BY nombre")->fetchAll();
} catch (Throwable $e) {
    $bancos = [];
}
$bancos_ids = array_map(fn($b) => (string)$b['id'], $bancos);

// ── Procesar guardado ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $banco_id_post = trim($_POST['banco_id'] ?? '');

    if ($banco_id_post === '' || !in_array($banco_id_post, $bancos_ids, true)) {
        $mensaje = 'Selecciona un banco/cooperativa válido.';
        $mensaje_tipo = 'error';
    } else {
        $permite_diaria  = isset($_POST['permite_diaria'])  ? 1 : 0;
        $permite_semanal = isset($_POST['permite_semanal']) ? 1 : 0;
        $permite_mensual = isset($_POST['permite_mensual']) ? 1 : 0;

        try {
            $st = $pdo->prepare("
                INSERT INTO config_metas_banco
                    (id, unidad_bancaria_id, permite_diaria, permite_semanal, permite_mensual, dias_semana_habilitados, actualizado_por, actualizado_at)
                VALUES
                    (UUID(), ?, ?, ?, ?, '1,2,3,4,5,6,7', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    permite_diaria = VALUES(permite_diaria),
                    permite_semanal = VALUES(permite_semanal),
                    permite_mensual = VALUES(permite_mensual),
                    actualizado_por = VALUES(actualizado_por),
                    actualizado_at = NOW()
            ");
            $st->execute([$banco_id_post, $permite_diaria, $permite_semanal, $permite_mensual, $admin_usuario_id]);

            $_SESSION['_cmb_mensaje']      = 'Configuración guardada correctamente.';
            $_SESSION['_cmb_mensaje_tipo'] = 'success';
        } catch (Throwable $e) {
            $_SESSION['_cmb_mensaje']      = 'Error al guardar: ' . $e->getMessage();
            $_SESSION['_cmb_mensaje_tipo'] = 'error';
        }

        header('Location: configurar_metas_banco.php?banco_id=' . urlencode($banco_id_post));
        exit;
    }
}

if (isset($_SESSION['_cmb_mensaje'])) {
    $mensaje      = $_SESSION['_cmb_mensaje'];
    $mensaje_tipo = $_SESSION['_cmb_mensaje_tipo'] ?? '';
    unset($_SESSION['_cmb_mensaje'], $_SESSION['_cmb_mensaje_tipo']);
}

// ── Banco seleccionado ────────────────────────────────────────
$banco_id_sel = trim($_GET['banco_id'] ?? '');
if ($banco_id_sel !== '' && !in_array($banco_id_sel, $bancos_ids, true)) {
    $banco_id_sel = '';
}

$config_sel = [
    'permite_diaria' => 1,
    'permite_semanal' => 0,
    'permite_mensual' => 1,
];
if ($banco_id_sel !== '') {
    try {
        $st = $pdo->prepare("SELECT permite_diaria, permite_semanal, permite_mensual FROM config_metas_banco WHERE unidad_bancaria_id = ? LIMIT 1");
        $st->execute([$banco_id_sel]);
        $row = $st->fetch();
        if ($row) $config_sel = $row;
    } catch (Throwable $e) {}
}

// ── Resumen de todos los bancos ──────────────────────────────
$resumen = [];
try {
    $resumen = $pdo->query("
        SELECT ub.id, ub.nombre,
               c.permite_diaria, c.permite_semanal, c.permite_mensual, c.actualizado_at
        FROM unidad_bancaria ub
        LEFT JOIN config_metas_banco c ON c.unidad_bancaria_id = ub.id
        WHERE ub.activo = 1
        ORDER BY ub.nombre
    ")->fetchAll();
} catch (Throwable $e) {
    $resumen = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Metas por Banco — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; min-height: 100vh; }
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: #fff; padding: 20px 0; overflow-y: auto; position: sticky; height: 100vh; top: 0; flex-shrink: 0; }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: #fff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .navbar-custom h2 i { color: var(--brand-yellow); }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: var(--brand-yellow); color: var(--brand-navy-deep); border: 1px solid var(--brand-yellow-deep); padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: 700; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header h1 { margin: 0 0 6px; font-size: 26px; font-weight: 800; color: #1f2937; }
        .page-header p { color: #6b7280; font-size: 14px; }
        .alert-box { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
        .alert-box.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .card-box { background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 24px; margin-bottom: 26px; }
        .card-box h3 { font-size: 15px; font-weight: 800; color: #1f2937; margin-bottom: 16px; }
        .selector-banco { max-width: 420px; }
        .form-check-tipo { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1.5px solid #e5e7eb; border-radius: 12px; margin-bottom: 12px; transition: all .2s ease; }
        .form-check-tipo.enabled { border-color: var(--brand-navy); background: #f0f5ff; }
        .form-check-tipo input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: var(--brand-navy); }
        .form-check-tipo label { font-weight: 700; color: #1f2937; cursor: pointer; margin: 0; flex: 1; }
        .form-check-tipo small { display: block; font-weight: 500; color: #6b7280; font-size: 12px; margin-top: 2px; }
        .dias-semana-box { margin-top: 10px; margin-left: 32px; padding: 14px; background: #f8fafc; border-radius: 10px; border: 1px solid #eef2f7; display: none; }
        .dias-semana-box.show { display: block; }
        .dia-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 20px; border: 1.5px solid #e5e7eb; margin: 3px; font-size: 13px; font-weight: 600; color: #374151; cursor: pointer; }
        .dia-chip input { accent-color: var(--brand-navy); cursor: pointer; }
        .btn-guardar { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: #fff; border: none; padding: 12px 28px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 14.5px; }
        .btn-guardar:hover { opacity: .92; }
        table.resumen { width: 100%; border-collapse: collapse; }
        table.resumen thead th { background: #f8f9fa; text-align: left; padding: 12px 16px; font-size: 11.5px; text-transform: uppercase; color: #6b7280; letter-spacing: .3px; }
        table.resumen tbody td { padding: 12px 16px; font-size: 13.5px; color: #1f2937; border-top: 1px solid #f1f5f9; vertical-align: middle; }
        table.resumen tbody tr.current-row { background: #f0f5ff; }
        .badge-onoff { padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; display: inline-block; }
        .badge-onoff.on { background: #dcfce7; color: #166534; }
        .badge-onoff.off { background: #f3f4f6; color: #9ca3af; }
        .badge-dia { background: #eef2ff; color: #4338ca; padding: 2px 8px; border-radius: 20px; font-size: 10.5px; font-weight: 700; margin-right: 3px; display: inline-block; }
        .empty-state { text-align: center; padding: 40px 20px; color: #9ca3af; }
        .empty-state i { font-size: 32px; display: block; margin-bottom: 10px; opacity: .6; }
        select.form-select-banco { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1.5px solid #e5e7eb; font-size: 14px; font-weight: 600; }
    </style>
</head>
<body>

<?php $currentPage = 'config_metas'; require_once '_sidebar_super_admin.php'; ?>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-sliders-h me-2"></i>Configurar Metas por Banco</h2>
        <div class="user-info">
            <div><strong><?= htmlspecialchars($admin_nombre) ?></strong><br><small>Super Administrador</small></div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content-area">
        <div class="page-header" style="margin-bottom:20px;">
            <h1>Tipos de meta habilitados por banco/cooperativa</h1>
            <p>Elige un banco o cooperativa y define qué tipos de meta podrá asignar su supervisor (diaria, semanal, mensual). Los días concretos de la semana para la meta semanal los define el supervisor por cada asesor al asignarla (cada asesor puede tener días distintos).</p>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert-box <?= $mensaje_tipo === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <div class="card-box">
            <h3><i class="fas fa-university me-1"></i> Selecciona el banco / cooperativa</h3>
            <div class="selector-banco">
                <select class="form-select-banco" onchange="if(this.value) window.location.href='configurar_metas_banco.php?banco_id=' + encodeURIComponent(this.value); else window.location.href='configurar_metas_banco.php';">
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($bancos as $b): ?>
                        <option value="<?= htmlspecialchars($b['id']) ?>" <?= $banco_id_sel === $b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($banco_id_sel !== ''): ?>
            <?php
            $nombre_banco_sel = '';
            foreach ($bancos as $b) { if ($b['id'] === $banco_id_sel) { $nombre_banco_sel = $b['nombre']; break; } }
            ?>
            <div class="card-box">
                <h3><i class="fas fa-bullseye me-1"></i> Configuración para: <?= htmlspecialchars($nombre_banco_sel) ?></h3>
                <form method="post" action="configurar_metas_banco.php">
                    <input type="hidden" name="banco_id" value="<?= htmlspecialchars($banco_id_sel) ?>">

                    <div class="form-check-tipo <?= $config_sel['permite_diaria'] ? 'enabled' : '' ?>">
                        <input type="checkbox" id="chkDiaria" name="permite_diaria" value="1" <?= $config_sel['permite_diaria'] ? 'checked' : '' ?>>
                        <label for="chkDiaria">
                            <i class="fas fa-calendar-day me-1"></i> Meta Diaria
                            <small>El supervisor puede asignar objetivos para un día específico.</small>
                        </label>
                    </div>

                    <div class="form-check-tipo <?= $config_sel['permite_semanal'] ? 'enabled' : '' ?>">
                        <input type="checkbox" id="chkSemanal" name="permite_semanal" value="1" <?= $config_sel['permite_semanal'] ? 'checked' : '' ?>>
                        <label for="chkSemanal">
                            <i class="fas fa-calendar-week me-1"></i> Meta Semanal
                            <small>El supervisor reparte un objetivo semanal entre los días que él elija para cada asesor (por ejemplo, un asesor lunes/martes/miércoles y otro martes/jueves/viernes).</small>
                        </label>
                    </div>

                    <div class="form-check-tipo <?= $config_sel['permite_mensual'] ? 'enabled' : '' ?>" style="margin-top:12px;">
                        <input type="checkbox" id="chkMensual" name="permite_mensual" value="1" <?= $config_sel['permite_mensual'] ? 'checked' : '' ?>>
                        <label for="chkMensual">
                            <i class="fas fa-calendar-alt me-1"></i> Meta Mensual
                            <small>El supervisor reparte un objetivo del mes entre los días laborales (lunes a viernes) restantes.</small>
                        </label>
                    </div>

                    <div class="text-end mt-3">
                        <button type="submit" class="btn-guardar"><i class="fas fa-save me-2"></i>Guardar Configuración</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card-box">
            <h3><i class="fas fa-list-check me-1"></i> Resumen de todos los bancos/cooperativas</h3>
            <?php if (empty($resumen)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No hay bancos/cooperativas activos registrados.</p>
                </div>
            <?php else: ?>
                <table class="resumen">
                    <thead>
                        <tr>
                            <th>Banco / Cooperativa</th>
                            <th class="text-center">Diaria</th>
                            <th class="text-center">Semanal</th>
                            <th class="text-center">Mensual</th>
                            <th>Última actualización</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resumen as $r): ?>
                            <?php
                            $configurado = $r['permite_diaria'] !== null;
                            $pd = $configurado ? (int)$r['permite_diaria']  : 1;
                            $ps = $configurado ? (int)$r['permite_semanal'] : 0;
                            $pm = $configurado ? (int)$r['permite_mensual'] : 1;
                            ?>
                            <tr class="<?= $r['id'] === $banco_id_sel ? 'current-row' : '' ?>">
                                <td><strong><?= htmlspecialchars($r['nombre']) ?></strong></td>
                                <td class="text-center"><span class="badge-onoff <?= $pd ? 'on' : 'off' ?>"><?= $pd ? 'Sí' : 'No' ?></span></td>
                                <td class="text-center"><span class="badge-onoff <?= $ps ? 'on' : 'off' ?>"><?= $ps ? 'Sí' : 'No' ?></span></td>
                                <td class="text-center"><span class="badge-onoff <?= $pm ? 'on' : 'off' ?>"><?= $pm ? 'Sí' : 'No' ?></span></td>
                                <td class="text-muted small"><?= $r['actualizado_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($r['actualizado_at']))) : 'Sin configurar (usa valores por defecto)' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
