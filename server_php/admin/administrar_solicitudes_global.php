<?php
// ============================================================
// admin/administrar_solicitudes_global.php
// Aprobación/denegación de solicitudes de registro (gerente,
// supervisor, asesor, administrador) de TODAS las cooperativas
// / bancos del sistema. Solo accesible por el Super Administrador
// (usuario.rol IN ('gerente_general','administrador') logueado
// vía login.php?role=super_admin).
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
    header('Location: login.php?role=super_admin');
    exit;
}

$admin_usuario_id = $_SESSION['super_admin_id'] ?? null;
$admin_nombre     = $_SESSION['super_admin_nombre'] ?? 'Super Admin';

$mensaje = '';
$mensaje_tipo = '';

// ── Procesar acción (aprobar / denegar) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $solicitud_id = trim($_POST['solicitud_id'] ?? '');
    $accion       = trim($_POST['accion'] ?? '');
    $motivo       = trim($_POST['motivo'] ?? '');

    if ($solicitud_id !== '' && in_array($accion, ['aprobar', 'denegar'], true)) {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare('SELECT id, usuario_id, estado FROM solicitud_registro WHERE id = ? LIMIT 1 FOR UPDATE');
            $st->execute([$solicitud_id]);
            $sol = $st->fetch();

            if (!$sol) {
                throw new Exception('Solicitud no encontrada');
            }
            if ($sol['estado'] !== 'pendiente') {
                throw new Exception('Esta solicitud ya fue procesada anteriormente');
            }

            if ($accion === 'aprobar') {
                $stUp = $pdo->prepare(
                    "UPDATE solicitud_registro SET estado='aprobado', revisado_por=?, revisado_at=NOW() WHERE id=?"
                );
                $stUp->execute([$admin_usuario_id, $solicitud_id]);

                // Explícito además del trigger trg_aprobar_registro (por si
                // el entorno no lo tiene creado, no rompe si ya corrió).
                $stU = $pdo->prepare(
                    "UPDATE usuario SET activo=1, estado_aprobacion='aprobado', aprobado_por=?, fecha_aprobacion=NOW() WHERE id=?"
                );
                $stU->execute([$admin_usuario_id, $sol['usuario_id']]);

                $mensaje = 'Solicitud aprobada correctamente. El usuario ya puede iniciar sesión.';
            } else {
                $stUp = $pdo->prepare(
                    "UPDATE solicitud_registro SET estado='denegado', revisado_por=?, revisado_at=NOW(), motivo_denegacion=? WHERE id=?"
                );
                $stUp->execute([$admin_usuario_id, ($motivo !== '' ? $motivo : null), $solicitud_id]);

                $stU = $pdo->prepare(
                    "UPDATE usuario SET activo=0, estado_aprobacion='denegado' WHERE id=?"
                );
                $stU->execute([$sol['usuario_id']]);

                $mensaje = 'Solicitud denegada.';
            }

            $pdo->commit();
            $mensaje_tipo = 'success';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $mensaje = 'Error: ' . $e->getMessage();
            $mensaje_tipo = 'error';
        }
    } else {
        $mensaje = 'Datos de la solicitud inválidos.';
        $mensaje_tipo = 'error';
    }

    // Post-Redirect-Get para evitar reenvío del formulario al refrescar
    $_SESSION['_asg_mensaje']      = $mensaje;
    $_SESSION['_asg_mensaje_tipo'] = $mensaje_tipo;
    header('Location: administrar_solicitudes_global.php');
    exit;
}

if (isset($_SESSION['_asg_mensaje'])) {
    $mensaje      = $_SESSION['_asg_mensaje'];
    $mensaje_tipo = $_SESSION['_asg_mensaje_tipo'] ?? '';
    unset($_SESSION['_asg_mensaje'], $_SESSION['_asg_mensaje_tipo']);
}

// ── Listado de solicitudes pendientes, de TODAS las cooperativas ────
// Resuelve el nombre del banco/cooperativa probando las 3 rutas posibles
// (gerente_general directo, supervisor vía agencia, asesor vía su
// supervisor y agencia). El que aplique según el rol solicitado será el
// único que devuelva algo distinto de NULL.
$sql = "
    SELECT
        sr.id, sr.usuario_id, sr.rol_solicitado, sr.documento_url,
        sr.documento_nombre_original, sr.documento_tipo, sr.created_at,
        u.nombre, u.email, u.telefono,
        COALESCE(ub_ger.nombre, ub_sup.nombre, ub_ase.nombre) AS banco_nombre
    FROM solicitud_registro sr
    JOIN usuario u ON u.id = sr.usuario_id
    LEFT JOIN gerente_general gg   ON gg.usuario_id = u.id
    LEFT JOIN unidad_bancaria ub_ger ON ub_ger.id = gg.unidad_bancaria_id
    LEFT JOIN supervisor sv        ON sv.usuario_id = u.id
    LEFT JOIN jefe_agencia ja_sv   ON ja_sv.id = sv.jefe_agencia_id
    LEFT JOIN agencia ag_sv        ON ag_sv.id = ja_sv.agencia_id
    LEFT JOIN unidad_bancaria ub_sup ON ub_sup.id = ag_sv.unidad_bancaria_id
    LEFT JOIN asesor a2            ON a2.usuario_id = u.id
    LEFT JOIN supervisor sv2       ON sv2.id = a2.supervisor_id
    LEFT JOIN jefe_agencia ja_a    ON ja_a.id = sv2.jefe_agencia_id
    LEFT JOIN agencia ag_a         ON ag_a.id = ja_a.agencia_id
    LEFT JOIN unidad_bancaria ub_ase ON ub_ase.id = ag_a.unidad_bancaria_id
    WHERE sr.estado = 'pendiente'
      AND sr.rol_solicitado IN ('gerente_general','jefe_regional','jefe_agencia','supervisor','asesor','administrador')
    ORDER BY sr.created_at ASC
";
$pendientes = [];
try {
    $pendientes = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    $pendientes = [];
}

// ── Últimas resueltas (auditoría rápida, cualquier cooperativa) ──
$resueltas = [];
try {
    $resueltas = $pdo->query("
        SELECT sr.id, sr.rol_solicitado, sr.estado, sr.revisado_at, sr.motivo_denegacion,
               u.nombre, u.email, ru.nombre AS revisor_nombre
        FROM solicitud_registro sr
        JOIN usuario u ON u.id = sr.usuario_id
        LEFT JOIN usuario ru ON ru.id = sr.revisado_por
        WHERE sr.estado <> 'pendiente'
        ORDER BY sr.revisado_at DESC
        LIMIT 20
    ")->fetchAll();
} catch (Throwable $e) {
    $resueltas = [];
}

$rol_labels = [
    'gerente_general' => 'Gerente General',
    'jefe_regional'   => 'Jefe Regional',
    'jefe_agencia'    => 'Jefe de Agencia',
    'supervisor'      => 'Supervisor',
    'asesor'          => 'Asesor',
    'administrador'   => 'Administrador',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes Pendientes (Todos los bancos) — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; min-height: 100vh; }
        .sidebar { width: 230px; background: linear-gradient(180deg, #2d1b69 0%, #1a0f3d 100%); color: #fff; padding: 20px 0; overflow-y: auto; position: sticky; height: 100vh; top: 0; flex-shrink: 0; }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: #fbbf24; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #fbbf24, #f59e0b); color: #1a0f3d; }
        .badge-nav { background: #dc2626; color: #fff; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 10px; margin-left: auto; }
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: #1a0f3d; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(0, 0, 0, 0.1); color: #1a0f3d; border: 1px solid #1a0f3d; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header h1 { margin: 0 0 6px; font-size: 26px; font-weight: 800; color: #1f2937; }
        .page-header p { color: #6b7280; font-size: 14px; }
        .alert-box { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
        .alert-box.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06); overflow: hidden; margin-bottom: 30px; }
        .table-card h3 { padding: 18px 20px 0; font-size: 15px; font-weight: 800; color: #1f2937; }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f8f9fa; text-align: left; padding: 12px 16px; font-size: 11.5px; text-transform: uppercase; color: #6b7280; letter-spacing: .3px; }
        tbody td { padding: 12px 16px; font-size: 13.5px; color: #1f2937; border-top: 1px solid #f1f5f9; vertical-align: middle; }
        .badge-rol { background: #ede9fe; color: #6d28d9; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; display: inline-block; }
        .badge-banco { background: #eef2ff; color: #4338ca; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; display: inline-block; }
        .badge-banco.sin { background: #f3f4f6; color: #9ca3af; font-style: italic; font-weight: 600; }
        .badge-estado { padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; display: inline-block; }
        .badge-estado.aprobado { background: #dcfce7; color: #166534; }
        .badge-estado.denegado { background: #fee2e2; color: #991b1b; }
        .btn-accion { border: none; border-radius: 8px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; margin-right: 6px; }
        .btn-aprobar { background: #10b981; color: #fff; }
        .btn-aprobar:hover { background: #059669; }
        .btn-denegar { background: #fff; color: #dc2626; border: 1.5px solid #dc2626; }
        .btn-denegar:hover { background: #dc2626; color: #fff; }
        .btn-doc { background: #f3f4f6; color: #374151; text-decoration: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .empty-state { text-align: center; padding: 40px 20px; color: #9ca3af; }
        .empty-state i { font-size: 32px; display: block; margin-bottom: 10px; opacity: .6; }
        .motivo-txt { color: #6b7280; font-size: 12px; max-width: 220px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-crown"></i> Super_IA</div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="super_admin_index.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <a href="usuarios.php" class="sidebar-link"><i class="fas fa-users"></i> Usuarios</a>
        <a href="clientes.php" class="sidebar-link"><i class="fas fa-briefcase"></i> Clientes</a>
        <a href="operaciones.php" class="sidebar-link"><i class="fas fa-handshake"></i> Operaciones</a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Super Administración</div>
        <a href="administrar_solicitudes_global.php" class="sidebar-link active">
            <i class="fas fa-file-signature"></i> Solicitudes Pendientes
            <?php if (count($pendientes) > 0): ?><span class="badge-nav"><?= count($pendientes) ?></span><?php endif; ?>
        </a>
        <a href="crear_asesor_admin.php" class="sidebar-link"><i class="fas fa-user-plus"></i> Crear Asesor</a>
        <a href="administrar_asesores.php" class="sidebar-link"><i class="fas fa-users-cog"></i> Administrar Asesores</a>
    </div>
</div>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-file-signature me-2"></i>Solicitudes Pendientes — Todos los bancos</h2>
        <div class="user-info">
            <div><strong><?= htmlspecialchars($admin_nombre) ?></strong><br><small>Super Administrador</small></div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content-area">
        <div class="page-header" style="margin-bottom:20px;">
            <h1>Solicitudes de registro</h1>
            <p>Aprueba o deniega cuentas nuevas de gerente, supervisor, asesor o administrador de cualquier cooperativa/banco del sistema.</p>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert-box <?= $mensaje_tipo === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <div class="table-card">
            <h3>Pendientes (<?= count($pendientes) ?>)</h3>
            <?php if (empty($pendientes)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No hay solicitudes pendientes por revisar.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Solicitante</th>
                            <th>Rol solicitado</th>
                            <th>Banco / Cooperativa</th>
                            <th>Documento</th>
                            <th>Fecha</th>
                            <th class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendientes as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($p['nombre'] ?? '—') ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($p['email'] ?? '') ?><?= !empty($p['telefono']) ? ' · ' . htmlspecialchars($p['telefono']) : '' ?></small>
                                </td>
                                <td><span class="badge-rol"><?= htmlspecialchars($rol_labels[$p['rol_solicitado']] ?? $p['rol_solicitado']) ?></span></td>
                                <td>
                                    <?php if (!empty($p['banco_nombre'])): ?>
                                        <span class="badge-banco"><i class="fas fa-university"></i> <?= htmlspecialchars($p['banco_nombre']) ?></span>
                                    <?php else: ?>
                                        <span class="badge-banco sin">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($p['documento_url'])): ?>
                                        <a class="btn-doc" href="<?= htmlspecialchars($p['documento_url']) ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-file"></i> Ver
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($p['created_at']) ? date('d/m/Y H:i', strtotime($p['created_at'])) : '—' ?></td>
                                <td class="text-end">
                                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Aprobar esta solicitud?');">
                                        <input type="hidden" name="solicitud_id" value="<?= htmlspecialchars($p['id']) ?>">
                                        <input type="hidden" name="accion" value="aprobar">
                                        <button type="submit" class="btn-accion btn-aprobar"><i class="fas fa-check"></i> Aprobar</button>
                                    </form>
                                    <button type="button" class="btn-accion btn-denegar"
                                        onclick="abrirDenegar('<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['nombre'] ?? '', ENT_QUOTES) ?>')">
                                        <i class="fas fa-times"></i> Denegar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="table-card">
            <h3>Últimas resueltas</h3>
            <?php if (empty($resueltas)): ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>Todavía no se ha resuelto ninguna solicitud.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Solicitante</th>
                            <th>Rol solicitado</th>
                            <th>Resultado</th>
                            <th>Revisado por</th>
                            <th>Fecha</th>
                            <th>Motivo (si denegado)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resueltas as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['nombre'] ?? '—') ?></strong><br><small class="text-muted"><?= htmlspecialchars($r['email'] ?? '') ?></small></td>
                                <td><span class="badge-rol"><?= htmlspecialchars($rol_labels[$r['rol_solicitado']] ?? $r['rol_solicitado']) ?></span></td>
                                <td><span class="badge-estado <?= $r['estado'] === 'aprobado' ? 'aprobado' : 'denegado' ?>"><?= $r['estado'] === 'aprobado' ? 'Aprobado' : 'Denegado' ?></span></td>
                                <td><?= htmlspecialchars($r['revisor_nombre'] ?? '—') ?></td>
                                <td><?= !empty($r['revisado_at']) ? date('d/m/Y H:i', strtotime($r['revisado_at'])) : '—' ?></td>
                                <td class="motivo-txt"><?= htmlspecialchars($r['motivo_denegacion'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Denegar -->
<div id="modalDenegar" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:400px;max-width:95vw;overflow:hidden;box-shadow:0 24px 48px rgba(0,0,0,.2);">
        <div style="background:linear-gradient(135deg,#991b1b,#7f1d1d);color:#fff;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:15px;"><i class="fas fa-times-circle me-2"></i>Denegar solicitud</strong>
            <button onclick="cerrarDenegar()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;">×</button>
        </div>
        <form method="post">
            <div style="padding:22px;">
                <p style="font-size:13.5px;color:#374151;margin-bottom:14px;">Solicitante: <strong id="denegarNombre"></strong></p>
                <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Motivo (opcional):</label>
                <textarea name="motivo" rows="3" style="width:100%;padding:10px;border:1.5px solid #d7e0ea;border-radius:9px;font-size:13.5px;" placeholder="Ej. Documento ilegible, información incompleta…"></textarea>
                <input type="hidden" name="solicitud_id" id="denegarSolicitudId">
                <input type="hidden" name="accion" value="denegar">
            </div>
            <div style="padding:0 22px 22px;display:flex;gap:10px;">
                <button type="button" onclick="cerrarDenegar()" style="flex:1;padding:10px;border:1.5px solid #d7e0ea;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Cancelar</button>
                <button type="submit" style="flex:1;padding:10px;background:#dc2626;border:none;border-radius:9px;font-weight:800;color:#fff;cursor:pointer;"><i class="fas fa-times me-1"></i>Denegar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function abrirDenegar(id, nombre) {
        document.getElementById('denegarSolicitudId').value = id;
        document.getElementById('denegarNombre').textContent = nombre;
        document.getElementById('modalDenegar').style.display = 'flex';
    }
    function cerrarDenegar() { document.getElementById('modalDenegar').style.display = 'none'; }
</script>

</body>
</html>
