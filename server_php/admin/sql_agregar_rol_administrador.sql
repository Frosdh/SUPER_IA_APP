-- ============================================================
-- SQL: Agregar rol ADMINISTRADOR al sistema SUPER_IA
-- Base de datos: corporat_base_super_ia
-- Ejecutar en phpMyAdmin → pestaña SQL
-- ============================================================

-- ──────────────────────────────────────────────────────────────
-- PASO 1: Agregar 'administrador' al enum de usuario.rol
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `usuario`
  MODIFY COLUMN `rol`
    ENUM('gerente_general','jefe_regional','jefe_agencia','supervisor','asesor','administrador')
    NOT NULL
    COMMENT 'administrador = acceso total: supervisor + gerente';

-- ──────────────────────────────────────────────────────────────
-- PASO 2: Agregar 'administrador' al enum de solicitud_registro
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `solicitud_registro`
  MODIFY COLUMN `rol_solicitado`
    ENUM('gerente_general','jefe_regional','jefe_agencia','supervisor','asesor','administrador')
    NOT NULL DEFAULT 'asesor';

-- ──────────────────────────────────────────────────────────────
-- PASO 3: Insertar el usuario administrador
-- IMPORTANTE: La contraseña real la genera setup_administrador.php
-- Password de ejemplo: Admin2024!
-- ──────────────────────────────────────────────────────────────
INSERT INTO `usuario`
  (`id`, `nombre`, `email`, `telefono`, `password_hash`, `rol`,
   `activo`, `estado_aprobacion`, `fecha_aprobacion`, `created_at`)
VALUES
  (
    UUID(),
    'Administrador Sistema',
    'admin@superIA.local',
    '0999000000',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'administrador',
    1,
    'aprobado',
    NOW(),
    NOW()
  );

-- ──────────────────────────────────────────────────────────────
-- PASO 4: Vistas para el panel administrador
-- ──────────────────────────────────────────────────────────────

-- Resumen diario global
CREATE OR REPLACE VIEW `v_admin_resumen_diario` AS
SELECT
    DATE(t.fecha_programada)                                        AS fecha,
    COUNT(t.id)                                                     AS total_tareas,
    SUM(t.estado = 'completada')                                    AS completadas,
    SUM(t.estado = 'postergada')                                    AS postergadas,
    SUM(t.estado IN ('programada','pendiente'))                     AS pendientes,
    ROUND(100.0 * SUM(t.estado='completada') / NULLIF(COUNT(t.id),0), 2) AS pct_cumplimiento,
    COUNT(DISTINCT t.asesor_id)                                     AS asesores_activos
FROM tarea t
GROUP BY DATE(t.fecha_programada);

-- KPI global por asesor
CREATE OR REPLACE VIEW `v_admin_kpi_asesores` AS
SELECT
    a.id                        AS asesor_id,
    u.nombre                    AS asesor_nombre,
    u.email                     AS asesor_email,
    su.nombre                   AS supervisor_nombre,
    COUNT(t.id)                 AS total_tareas_mes,
    SUM(t.estado = 'completada') AS completadas_mes,
    SUM(t.estado = 'postergada') AS postergadas_mes,
    COUNT(DISTINCT cp.id)       AS clientes_registrados,
    COUNT(DISTINCT cr.id)       AS creditos_iniciados
FROM asesor a
JOIN usuario u   ON u.id  = a.usuario_id
JOIN supervisor s ON s.id = a.supervisor_id
JOIN usuario su  ON su.id = s.usuario_id
LEFT JOIN tarea t ON t.asesor_id = a.id
    AND YEAR(t.fecha_programada)  = YEAR(CURDATE())
    AND MONTH(t.fecha_programada) = MONTH(CURDATE())
LEFT JOIN cliente_prospecto cp ON cp.asesor_id = a.id
LEFT JOIN credito_proceso cr   ON cr.asesor_id = a.id
GROUP BY a.id, u.nombre, u.email, su.nombre;

-- Pipeline de créditos con contexto
CREATE OR REPLACE VIEW `v_admin_pipeline` AS
SELECT
    cr.id,
    cr.estado_credito,
    cr.monto_aprobado,
    cr.created_at,
    cr.updated_at,
    cp.nombre    AS cliente_nombre,
    cp.cedula,
    u_a.nombre   AS asesor_nombre,
    u_s.nombre   AS supervisor_nombre
FROM credito_proceso cr
JOIN asesor a     ON a.id  = cr.asesor_id
JOIN usuario u_a  ON u_a.id = a.usuario_id
JOIN supervisor s ON s.id  = a.supervisor_id
JOIN usuario u_s  ON u_s.id = s.usuario_id
LEFT JOIN cliente_prospecto cp ON cp.id = cr.cliente_prospecto_id;

-- Alertas con contexto completo
CREATE OR REPLACE VIEW `v_admin_alertas` AS
SELECT
    am.id,
    am.created_at,
    u_a.nombre  AS asesor_nombre,
    u_s.nombre  AS supervisor_nombre,
    t.tipo_tarea,
    t.estado    AS estado_tarea,
    am.campo_modificado,
    am.valor_anterior,
    am.valor_nuevo,
    am.vista_supervisor
FROM alerta_modificacion am
JOIN tarea t      ON t.id  = am.tarea_id
JOIN asesor a     ON a.id  = am.asesor_id
JOIN usuario u_a  ON u_a.id = a.usuario_id
JOIN supervisor s ON s.id  = a.supervisor_id
JOIN usuario u_s  ON u_s.id = s.usuario_id
ORDER BY am.created_at DESC;

-- Solicitudes de registro con detalle
CREATE OR REPLACE VIEW `v_admin_solicitudes` AS
SELECT
    sr.id              AS solicitud_id,
    sr.rol_solicitado,
    sr.estado,
    sr.created_at,
    u.nombre           AS solicitante_nombre,
    u.email            AS solicitante_email,
    u.telefono,
    sr.documento_tipo,
    sr.documento_url
FROM solicitud_registro sr
JOIN usuario u ON u.id = sr.usuario_id
ORDER BY sr.created_at DESC;

-- ──────────────────────────────────────────────────────────────
-- VERIFICACION FINAL
-- ──────────────────────────────────────────────────────────────
SELECT id, nombre, email, rol, activo, estado_aprobacion, created_at
FROM usuario
WHERE rol = 'administrador';
