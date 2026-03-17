<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403); exit('Acceso denegado');
}
require_once __DIR__ . '/db_admin.php';

$tipo  = $_GET['tipo']  ?? 'viajes';
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$fechaDesdeSQL = $desde . ' 00:00:00';
$fechaHastaSQL = $hasta . ' 23:59:59';
$bom = "\xEF\xBB\xBF";

function clean($v) { return str_replace(["\r","\n","\t"],' ', $v ?? ''); }

// ── VIAJES ───────────────────────────────────────────────────────────
if ($tipo === 'viajes') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fuber_viajes_'.$desde.'_'.$hasta.'.csv"');
    $stmt = $pdo->prepare("
        SELECT v.id, v.fecha_pedido, v.estado,
               u.nombre AS pasajero, u.telefono AS tel_pasajero,
               c.nombre AS cond_nombre, c.telefono AS tel_conductor,
               v.origen_texto, v.destino_texto,
               v.distancia_km, v.duracion_min, v.tarifa_total,
               v.calificacion, v.descuento, v.codigo_descuento
        FROM viajes v
        LEFT JOIN usuarios    u ON u.id = v.usuario_id
        LEFT JOIN conductores c ON c.id = v.conductor_id
        WHERE v.fecha_pedido BETWEEN ? AND ?
        ORDER BY v.fecha_pedido DESC
    ");
    $stmt->execute([$fechaDesdeSQL, $fechaHastaSQL]);
    $out = fopen('php://output','w');
    fputs($out, $bom);
    fputcsv($out, ['ID','Fecha','Estado','Pasajero','Tel. Pasajero','Conductor','Tel. Conductor','Origen','Destino','Distancia (km)','Duración (min)','Tarifa ($)','Calificación','Descuento ($)','Código Descuento'], ';');
    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, [
            $r['id'], date('d/m/Y H:i', strtotime($r['fecha_pedido'])), $r['estado'],
            clean($r['pasajero']), $r['tel_pasajero'],
            clean($r['cond_nombre']), $r['tel_conductor'],
            clean($r['origen_texto']), clean($r['destino_texto']),
            number_format($r['distancia_km']??0,2,'.',''),
            $r['duracion_min']??0,
            number_format($r['tarifa_total']??0,2,'.',''),
            $r['calificacion']??'',
            number_format($r['descuento']??0,2,'.',''),
            $r['codigo_descuento']??''
        ], ';');
    }
    fclose($out); exit;
}

// ── CONDUCTORES ──────────────────────────────────────────────────────
if ($tipo === 'conductores') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fuber_conductores_'.$desde.'_'.$hasta.'.csv"');
    $cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave='comision_empresa'")->fetch();
    $comision = floatval($cfg['valor'] ?? 20);
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.telefono, c.email, c.verificado,
               COUNT(v.id) AS total_viajes,
               COALESCE(SUM(CASE WHEN v.estado='terminado' THEN v.tarifa_total ELSE 0 END),0) AS ingresos,
               COALESCE(AVG(CASE WHEN v.estado='terminado' THEN v.calificacion END),0) AS cal_prom,
               SUM(CASE WHEN v.estado='cancelado' THEN 1 ELSE 0 END) AS cancelaciones
        FROM conductores c
        LEFT JOIN viajes v ON v.conductor_id = c.id AND v.fecha_pedido BETWEEN ? AND ?
        GROUP BY c.id
        ORDER BY ingresos DESC
    ");
    $stmt->execute([$fechaDesdeSQL, $fechaHastaSQL]);
    $out = fopen('php://output','w');
    fputs($out, $bom);
    fputcsv($out, ['ID','Nombre','Teléfono','Email','Verificado','Viajes','Ingresos Brutos ($)','Comisión Empresa ($)','Ganancias Netas ($)','Calificación Prom.','Cancelaciones'], ';');
    foreach ($stmt->fetchAll() as $r) {
        $bruto  = floatval($r['ingresos']);
        $comEmp = $bruto * ($comision/100);
        fputcsv($out, [
            $r['id'], clean($r['nombre']),
            $r['telefono'], $r['email']??'',
            $r['verificado'] ? 'Sí' : 'No',
            $r['total_viajes'],
            number_format($bruto,2,'.',''),
            number_format($comEmp,2,'.',''),
            number_format($bruto-$comEmp,2,'.',''),
            number_format($r['cal_prom'],1,'.',''),
            $r['cancelaciones']
        ], ';');
    }
    fclose($out); exit;
}

// ── PASAJEROS ────────────────────────────────────────────────────────
if ($tipo === 'pasajeros') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fuber_pasajeros_'.$desde.'_'.$hasta.'.csv"');
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.telefono, u.email, u.activo, u.creado_en,
               COUNT(v.id) AS total_viajes,
               COALESCE(SUM(CASE WHEN v.estado='terminado' THEN v.tarifa_total ELSE 0 END),0) AS total_gastado,
               SUM(CASE WHEN v.estado='cancelado' THEN 1 ELSE 0 END) AS cancelaciones
        FROM usuarios u
        LEFT JOIN viajes v ON v.usuario_id = u.id AND v.fecha_pedido BETWEEN ? AND ?
        GROUP BY u.id
        ORDER BY total_gastado DESC
    ");
    $stmt->execute([$fechaDesdeSQL, $fechaHastaSQL]);
    $out = fopen('php://output','w');
    fputs($out, $bom);
    fputcsv($out, ['ID','Nombre','Teléfono','Email','Estado','Fecha Registro','Viajes','Total Gastado ($)','Cancelaciones'], ';');
    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, [
            $r['id'], clean($r['nombre']), $r['telefono'], $r['email']??'',
            $r['activo'] ? 'Activo' : 'Inactivo',
            date('d/m/Y', strtotime($r['creado_en'])),
            $r['total_viajes'],
            number_format($r['total_gastado'],2,'.',''),
            $r['cancelaciones']
        ], ';');
    }
    fclose($out); exit;
}

// ── INGRESOS POR DÍA ─────────────────────────────────────────────────
if ($tipo === 'ingresos') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fuber_ingresos_'.$desde.'_'.$hasta.'.csv"');
    $cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave='comision_empresa'")->fetch();
    $comision = floatval($cfg['valor'] ?? 20);
    $stmt = $pdo->prepare("
        SELECT DATE(fecha_pedido) AS dia,
               COUNT(*) AS total_viajes,
               SUM(CASE WHEN estado='terminado' THEN 1 ELSE 0 END) AS terminados,
               SUM(CASE WHEN estado='cancelado' THEN 1 ELSE 0 END) AS cancelados,
               COALESCE(SUM(CASE WHEN estado='terminado' THEN tarifa_total ELSE 0 END),0) AS ingresos
        FROM viajes
        WHERE fecha_pedido BETWEEN ? AND ?
        GROUP BY dia ORDER BY dia
    ");
    $stmt->execute([$fechaDesdeSQL, $fechaHastaSQL]);
    $out = fopen('php://output','w');
    fputs($out, $bom);
    fputcsv($out, ['Fecha','Total Viajes','Terminados','Cancelados','Ingresos Brutos ($)','Comisión Empresa ($)','Ganancias Conductores ($)'], ';');
    foreach ($stmt->fetchAll() as $r) {
        $bruto  = floatval($r['ingresos']);
        $comEmp = $bruto * ($comision/100);
        fputcsv($out, [
            date('d/m/Y', strtotime($r['dia'])),
            $r['total_viajes'], $r['terminados'], $r['cancelados'],
            number_format($bruto,2,'.',''),
            number_format($comEmp,2,'.',''),
            number_format($bruto-$comEmp,2,'.','')
        ], ';');
    }
    fclose($out); exit;
}

http_response_code(400);
echo 'Tipo no válido.';
