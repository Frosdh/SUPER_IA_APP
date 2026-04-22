<?php
// admin/recuperacion.php — Página para gestionar recuperaciones (créditos aprobados)
if (session_status()===PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';
if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in']!==true) { header('Location: login.php?role=supervisor'); exit; }
$supervisor_usuario_id = $_SESSION['supervisor_id'];
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? '';
// Resolver supervisor table id
$supervisor_table_id = null; try { $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1'); $st->execute([$supervisor_usuario_id]); $supervisor_table_id = $st->fetchColumn() ?: null; } catch (Throwable $_) {}
$creditos = [];
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare("SELECT cp.id, cp.cliente_prospecto_id, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula, cp.monto_aprobado, cp.fecha_desembolso, cp.created_at, a.id as asesor_id, u.nombre as asesor_nombre FROM credito_proceso cp JOIN cliente_prospecto cl ON cl.id = cp.cliente_prospecto_id LEFT JOIN asesor a ON a.id = cp.asesor_id LEFT JOIN usuario u ON u.id = a.usuario_id WHERE a.supervisor_id = ? AND cp.estado_credito IN('aprobado','desembolsado') ORDER BY cp.created_at DESC");
        $st->execute([$supervisor_table_id]); $creditos = $st->fetchAll();
    } catch (Throwable $_) {}
}

// Si se solicita como fragmento para inyección en el centro (ajax_center=1), devolver solo el bloque central
if (isset($_GET['ajax_center']) && $_GET['ajax_center'] === '1') {
    ?>
    <div class="section-card">
        <div class="section-header"><h5>Recuperación — Créditos Aprobados</h5></div>
        <div style="padding:12px;">
            <?php if (empty($creditos)): ?>
                <div class="empty-msg">No se encontraron créditos aprobados.</div>
            <?php else: ?>
                <table class="table table-sm">
                    <thead><tr><th></th><th>Cliente</th><th>Asesor</th><th>Monto</th><th>Desembolso</th><th>Meses</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($creditos as $cr):
                        $fecha = $cr['fecha_desembolso'] ?: $cr['created_at'];
                        $dt0 = new DateTime($fecha); $dt1 = new DateTime();
                        $diff = ($dt1->y - $dt0->y) * 12 + ($dt1->m - $dt0->m);
                        $meses = max(0,(int)$diff);
                    ?>
                    <tr>
                        <td><input type="checkbox" class="chk-rec" data-credito-id="<?= htmlspecialchars($cr['id']) ?>"></td>
                        <td><?= htmlspecialchars($cr['cliente_nombre'] ?? $cr['cliente_cedula']) ?><br><small class="text-muted"><?= htmlspecialchars($cr['cliente_cedula'] ?? '') ?></small></td>
                        <td><?= htmlspecialchars($cr['asesor_nombre'] ?? '') ?></td>
                        <td>$<?= number_format((float)($cr['monto_aprobado'] ?? 0),2) ?></td>
                        <td><?= $fecha ? date('d/m/Y', strtotime($fecha)) : '—' ?></td>
                        <td><?= $meses ?></td>
                        <td><button class="btn btn-sm btn-warning btn-crear-rec" data-credito-id="<?= htmlspecialchars($cr['id']) ?>" data-asesor-id="<?= htmlspecialchars($cr['asesor_id'] ?? '') ?>">Crear tarea</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:8px;">
                    <select id="bulk_asesor_sel" class="form-select form-select-sm" style="width:280px;display:inline-block;margin-right:8px;">
                        <option value="">Usar asesor original</option>
                        <?php try { $st = $pdo->prepare('SELECT id, (SELECT nombre FROM usuario u WHERE u.id = a.usuario_id) as nombre FROM asesor a WHERE a.supervisor_id = ?'); $st->execute([$supervisor_table_id]); $asesList = $st->fetchAll(); foreach ($asesList as $as) echo '<option value="'.htmlspecialchars($as['id']).'">'.htmlspecialchars($as['nombre']??$as['id']).'</option>'; } catch (Throwable $_) {}
                        ?>
                    </select>
                    <button id="bulk_create_rec" class="btn btn-sm btn-danger">Crear tareas (seleccionados)</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    document.addEventListener('click', function(e){
        if (e.target && e.target.matches('.btn-crear-rec')){
            var btn = e.target; btn.disabled=true;
            var creditoId = btn.getAttribute('data-credito-id'); var asesorId = btn.getAttribute('data-asesor-id')||'';
            fetch('crear_tarea_recuperacion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({credito_id:creditoId,asesor_id:asesorId})}).then(r=>r.json()).then(j=>{btn.disabled=false; if(j.status==='success') alert('Tarea creada'); else alert('Error:'+ (j.message||'') ); }).catch(()=>{btn.disabled=false; alert('Error de red');});
        }
        if (e.target && e.target.id==='bulk_create_rec'){
            var checks = Array.from(document.querySelectorAll('.chk-rec:checked')).map(c=>c.getAttribute('data-credito-id'));
            if (checks.length===0){ alert('Seleccione al menos un crédito'); return; }
            var asesorSel = document.getElementById('bulk_asesor_sel'); var asesorId = asesorSel? asesorSel.value : '';
            var btn = e.target; btn.disabled=true;
            fetch('crear_tarea_recuperacion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({credito_ids:checks,asesor_id:asesorId})}).then(r=>r.json()).then(j=>{btn.disabled=false; if(j.status==='success') { alert('Tareas creadas: '+(j.created?j.created.length:0)); window.location.reload(); } else alert('Error:'+ (j.message||'')); }).catch(()=>{btn.disabled=false; alert('Error red');});
        }
    });
    </script>
    <?php
    exit;
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Recuperación — Supervisor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head><body>
<?php require_once '_sidebar_supervisor.php'; ?>
<div class="main-content" style="margin-left:230px;padding:28px;">
    <div class="navbar-custom" style="margin-left:0;">
        <h2>Recuperación — Créditos Aprobados</h2>
    </div>
    <div class="content-area" style="padding-top:18px;">
        <div class="section-card">
            <div class="section-header"><h5>Créditos</h5></div>
            <div style="padding:12px;">
            <?php if (empty($creditos)): ?>
                <div class="empty-msg">No se encontraron créditos aprobados.</div>
            <?php else: ?>
                <table class="table table-sm">
                    <thead><tr><th></th><th>Cliente</th><th>Asesor</th><th>Monto</th><th>Desembolso</th><th>Meses</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($creditos as $cr):
                        $fecha = $cr['fecha_desembolso'] ?: $cr['created_at'];
                        $dt0 = new DateTime($fecha); $dt1 = new DateTime();
                        $diff = ($dt1->y - $dt0->y) * 12 + ($dt1->m - $dt0->m);
                        $meses = max(0,(int)$diff);
                    ?>
                    <tr>
                        <td><input type="checkbox" class="chk-rec" data-credito-id="<?= htmlspecialchars($cr['id']) ?>"></td>
                        <td><?= htmlspecialchars($cr['cliente_nombre'] ?? $cr['cliente_cedula']) ?><br><small class="text-muted"><?= htmlspecialchars($cr['cliente_cedula'] ?? '') ?></small></td>
                        <td><?= htmlspecialchars($cr['asesor_nombre'] ?? '') ?></td>
                        <td>$<?= number_format((float)($cr['monto_aprobado'] ?? 0),2) ?></td>
                        <td><?= $fecha ? date('d/m/Y', strtotime($fecha)) : '—' ?></td>
                        <td><?= $meses ?></td>
                        <td><button class="btn btn-sm btn-warning btn-crear-rec" data-credito-id="<?= htmlspecialchars($cr['id']) ?>" data-asesor-id="<?= htmlspecialchars($cr['asesor_id'] ?? '') ?>">Crear tarea</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:8px;">
                    <select id="bulk_asesor_sel" class="form-select form-select-sm" style="width:280px;display:inline-block;margin-right:8px;">
                        <option value="">Usar asesor original</option>
                        <?php try { $st = $pdo->prepare('SELECT id, (SELECT nombre FROM usuario u WHERE u.id = a.usuario_id) as nombre FROM asesor a WHERE a.supervisor_id = ?'); $st->execute([$supervisor_table_id]); $asesList = $st->fetchAll(); foreach ($asesList as $as) echo '<option value="'.htmlspecialchars($as['id']).'">'.htmlspecialchars($as['nombre']??$as['id']).'</option>'; } catch (Throwable $_) {}
                        ?>
                    </select>
                    <button id="bulk_create_rec" class="btn btn-sm btn-danger">Crear tareas (seleccionados)</button>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('click', function(e){
    if (e.target && e.target.matches('.btn-crear-rec')){
        var btn = e.target; btn.disabled=true;
        var creditoId = btn.getAttribute('data-credito-id'); var asesorId = btn.getAttribute('data-asesor-id')||'';
        fetch('crear_tarea_recuperacion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({credito_id:creditoId,asesor_id:asesorId})}).then(r=>r.json()).then(j=>{btn.disabled=false; if(j.status==='success') alert('Tarea creada'); else alert('Error:'+ (j.message||'') ); }).catch(()=>{btn.disabled=false; alert('Error de red');});
    }
    if (e.target && e.target.id==='bulk_create_rec'){
        var checks = Array.from(document.querySelectorAll('.chk-rec:checked')).map(c=>c.getAttribute('data-credito-id'));
        if (checks.length===0){ alert('Seleccione al menos un crédito'); return; }
        var asesorSel = document.getElementById('bulk_asesor_sel'); var asesorId = asesorSel? asesorSel.value : '';
        var btn = e.target; btn.disabled=true;
        fetch('crear_tarea_recuperacion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({credito_ids:checks,asesor_id:asesorId})}).then(r=>r.json()).then(j=>{btn.disabled=false; if(j.status==='success') { alert('Tareas creadas: '+(j.created?j.created.length:0)); window.location.reload(); } else alert('Error:'+ (j.message||'')); }).catch(()=>{btn.disabled=false; alert('Error red');});
    }
});
</script>
</body></html>