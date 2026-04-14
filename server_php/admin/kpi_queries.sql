-- ============================================================
-- admin/kpi_queries.sql — Consultas SQL para KPIs Super_IA Logan
-- Estas consultas se pueden usar directly en PHP o para triggers
-- ============================================================

-- ============================================================
-- VISTA 1: Desempeño diario del asesor
-- ============================================================
CREATE OR REPLACE VIEW v_desempeno_diario_asesor AS
SELECT
    u.nombre AS asesor_nombre,
    a.id AS asesor_id,
    s.id AS supervisor_id,
    ad.fecha,
    ad.total_tareas_programadas,
    ad.tareas_realizadas,
    ad.tareas_postergadas,
    ad.tareas_pendientes,
    ROUND(
        100.0 * ad.tareas_realizadas
        / NULLIF(ad.total_tareas_programadas, 0),
        2
    ) AS pct_cumplimiento,
    CASE
        WHEN ad.tareas_realizadas >= a.meta_tareas_diarias THEN 'CUMPLE'
        ELSE 'PENDIENTE'
    END AS estado_meta
FROM asesor a
JOIN usuario u ON u.id = a.usuario_id
JOIN supervisor s ON s.id = a.supervisor_id
LEFT JOIN agenda_dia ad ON ad.asesor_id = a.id;

-- ============================================================
-- VISTA 2: Resumen mensual por asesor
-- ============================================================
CREATE OR REPLACE VIEW v_resumen_asesor_mensual AS
SELECT
    a.id,
    u.nombre,
    DATE_FORMAT(t.fecha_programada, '%Y-%m') AS periodo,
    COUNT(DISTINCT t.id) as total_tareas,
    SUM(CASE WHEN t.estado = 'completada' THEN 1 ELSE 0 END) as tareas_completadas,
    SUM(CASE WHEN t.estado = 'postergada' THEN 1 ELSE 0 END) as tareas_postergadas,
    COUNT(DISTINCT cp.id) as clientes_visitados,
    SUM(CASE WHEN ec.id IS NOT NULL THEN 1 ELSE 0 END) as encuestas_comerciales,
    SUM(CASE WHEN ecr.id IS NOT NULL THEN 1 ELSE 0 END) as encuestas_crediticias,
    COUNT(DISTINCT cr.id) as creditos_en_proceso
FROM asesor a
JOIN usuario u ON u.id = a.usuario_id
LEFT JOIN tarea t ON t.asesor_id = a.id
LEFT JOIN cliente_prospecto cp ON cp.asesor_id = a.id AND MONTH(cp.created_at) = MONTH(t.fecha_programada)
LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id
LEFT JOIN encuesta_crediticia ecr ON ecr.tarea_id = t.id
LEFT JOIN credito_proceso cr ON cr.asesor_id = a.id
WHERE t.fecha_programada IS NOT NULL
GROUP BY a.id, u.nombre, DATE_FORMAT(t.fecha_programada, '%Y-%m');

-- ============================================================
-- CONSULTA 1: Calcular tiempo promedio de estados en crédito
-- ============================================================
-- Dias promedio que tarda un crédito pasar por cada estado
SELECT
    cp.estado_credito,
    COUNT(*) as num_creditos,
    ROUND(AVG(DATEDIFF(
        COALESCE(fecha_desembolso, NOW()),
        fecha_prospeccion
    )), 2) as dias_promedio_estado
FROM credito_proceso cp
WHERE cp.fecha_prospeccion IS NOT NULL
GROUP BY cp.estado_credito
ORDER BY dias_promedio_estado DESC;

-- ============================================================
-- CONSULTA 2: Tasa de conversión por asesor
-- ============================================================
-- Porcentaje de prospectos que se convierten en clientes
SELECT
    a.id, u.nombre,
    COUNT(DISTINCT cp_prospecto.id) as total_prospectos,
    COUNT(DISTINCT cp_cliente.id) as total_clientes,
    ROUND(
        100.0 * COUNT(DISTINCT cp_cliente.id) 
        / NULLIF(COUNT(DISTINCT cp_prospecto.id), 0), 2
    ) as pct_conversion
FROM asesor a
JOIN usuario u ON u.id = a.usuario_id
LEFT JOIN cliente_prospecto cp_prospecto ON cp_prospecto.asesor_id = a.id 
    AND cp_prospecto.estado IN ('prospecto', 'pendiente')
LEFT JOIN cliente_prospecto cp_cliente ON cp_cliente.asesor_id = a.id 
    AND cp_cliente.estado = 'cliente'
GROUP BY a.id, u.nombre
ORDER BY pct_conversion DESC;

-- ============================================================
-- CONSULTA 3: Interés de clientes por producto bancario
-- ============================================================
-- Agregar intereses de clientes por producto
SELECT
    'Cuenta de Ahorro' as producto,
    SUM(CASE WHEN ec.interes_ahorro = 1 THEN 1 ELSE 0 END) as num_interesados,
    ROUND(
        100.0 * SUM(CASE WHEN ec.interes_ahorro = 1 THEN 1 ELSE 0 END)
        / NULLIF(COUNT(*), 0), 2
    ) as pct_interes
FROM encuesta_comercial ec

UNION ALL

SELECT
    'Cuenta Corriente' as producto,
    SUM(CASE WHEN ec.interes_cc = 1 THEN 1 ELSE 0 END),
    ROUND(
        100.0 * SUM(CASE WHEN ec.interes_cc = 1 THEN 1 ELSE 0 END)
        / NULLIF(COUNT(*), 0), 2
    )
FROM encuesta_comercial ec

UNION ALL

SELECT
    'Inversiones' as producto,
    SUM(CASE WHEN ec.interes_inversion = 1 THEN 1 ELSE 0 END),
    ROUND(
        100.0 * SUM(CASE WHEN ec.interes_inversion = 1 THEN 1 ELSE 0 END)
        / NULLIF(COUNT(*), 0), 2
    )
FROM encuesta_comercial ec

UNION ALL

SELECT
    'Crédito' as producto,
    SUM(CASE WHEN ec.interes_credito = 1 THEN 1 ELSE 0 END),
    ROUND(
        100.0 * SUM(CASE WHEN ec.interes_credito = 1 THEN 1 ELSE 0 END)
        / NULLIF(COUNT(*), 0), 2
    )
FROM encuesta_comercial ec;

-- ============================================================
-- CONSULTA 4: Destinos de crédito más solicitados
-- ============================================================
SELECT
    ecr.destino_credito,
    COUNT(*) as num_solicitudes,
    ROUND(AVG(ecr.monto_solicitado), 2) as monto_promedio,
    SUM(CASE WHEN ecr.monto_solicitado > 0 THEN ecr.monto_solicitado ELSE 0 END) as monto_total
FROM encuesta_crediticia ecr
WHERE ecr.destino_credito IS NOT NULL
GROUP BY ecr.destino_credito
ORDER BY num_solicitudes DESC;

-- ============================================================
-- CONSULTA 5: Encuestas por estado de crédito
-- ============================================================
SELECT
    cp.estado_credito,
    COUNT(ec.id) as encuestas_comerciales,
    COUNT(ecr.id) as encuestas_crediticias,
    COUNT(DISTINCT cp.id) as total_creditos
FROM credito_proceso cp
LEFT JOIN tarea t ON t.id IN (
    SELECT tarea_id FROM encuesta_comercial WHERE tarea_id IS NOT NULL
    UNION
    SELECT tarea_id FROM encuesta_crediticia WHERE tarea_id IS NOT NULL
)
LEFT JOIN encuesta_comercial ec ON ec.id IS NOT NULL
LEFT JOIN encuesta_crediticia ecr ON ecr.id IS NOT NULL
GROUP BY cp.estado_credito;

-- ============================================================
-- CONSULTA 6: Desempeño del equipo del supervisor
-- ============================================================
-- Para cada supervisor, ver el promedio de su equipo
SELECT
    s.id,
    u_sup.nombre as supervisor,
    COUNT(DISTINCT a.id) as num_asesores,
    ROUND(AVG(k.pct_cumplimiento_prospectos), 2) as pct_cumpl_promedio_equipo,
    SUM(k.num_operaciones_desembolsadas) as total_operaciones_mes,
    ROUND(AVG(k.tiempo_prospeccion_desembolso), 1) as dias_promedio_prospeccion_desembolso
FROM supervisor s
JOIN usuario u_sup ON u_sup.id = s.usuario_id
JOIN asesor a ON a.supervisor_id = s.id
LEFT JOIN kpi_asesor k ON k.asesor_id = a.id 
    AND k.mes = MONTH(NOW())
    AND k.anio = YEAR(NOW())
GROUP BY s.id, u_sup.nombre;

-- ============================================================
-- CONSULTA 7: Alertas de modificaciones no vistas
-- ============================================================
SELECT
    s.id as supervisor_id,
    COUNT(am.id) as alertas_pendientes,
    COUNT(DISTINCT am.asesor_id) as asesores_con_cambios,
    MAX(am.created_at) as alerta_mas_reciente
FROM supervisor s
LEFT JOIN alerta_modificacion am ON am.supervisor_id = s.id 
    AND am.vista_supervisor = 0
GROUP BY s.id;

-- ============================================================
-- CONSULTA 8: Ubicaciones fuera de zona
-- ============================================================
SELECT
    u.nombre as asesor,
    COUNT(*) as tareas_fuera_zona,
    GROUP_CONCAT(DISTINCT t.zona SEPARATOR ', ') as zonas_visitadas
FROM tarea t
JOIN asesor a ON a.id = t.asesor_id
JOIN usuario u ON u.id = a.usuario_id
WHERE t.fuera_de_zona = 1
AND DATE(t.fecha_realizada) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY t.asesor_id, u.nombre;

-- ============================================================
-- CONSULTA 9: Métricas de llamadas de seguimiento
-- ============================================================
-- Cuando fue la última contacto con cada cliente
SELECT
    cp.id, cp.nombre,
    u.nombre as asesor,
    MAX(t.fecha_realizada) as ultimo_contacto,
    DATEDIFF(NOW(), MAX(t.fecha_realizada)) as dias_sin_contacto,
    cp.estado
FROM cliente_prospecto cp
JOIN asesor a ON a.id = cp.asesor_id
JOIN usuario u ON u.id = a.usuario_id
LEFT JOIN tarea t ON t.cliente_prospecto_id = cp.id
GROUP BY cp.id
ORDER BY dias_sin_contacto DESC
LIMIT 50;

-- ============================================================
-- CONSULTA 10: Análisis de tiempos de encuestas
-- ============================================================
SELECT
    'Comercial' as tipo_encuesta,
    COUNT(ec.id) as num_encuestas,
    AVG(DATEDIFF(ec.created_at, t.fecha_realizada)) as dias_promedio_registr,
    MIN(ec.fecha_acuerdo) as primer_acuerdo,
    MAX(ec.fecha_acuerdo) as ultimo_acuerdo
FROM encuesta_comercial ec
JOIN tarea t ON t.id = ec.tarea_id
WHERE t.fecha_realizada IS NOT NULL

UNION ALL

SELECT
    'Crediticia' as tipo_encuesta,
    COUNT(ecr.id),
    AVG(DATEDIFF(ecr.created_at, t.fecha_realizada)),
    MIN(ecr.fecha_acuerdo),
    MAX(ecr.fecha_acuerdo)
FROM encuesta_crediticia ecr
JOIN tarea t ON t.id = ecr.tarea_id
WHERE t.fecha_realizada IS NOT NULL;
