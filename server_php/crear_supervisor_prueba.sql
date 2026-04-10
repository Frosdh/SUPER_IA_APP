-- ============================================================
-- base_super_ia — Crear Supervisor de prueba
-- Ejecutar en phpMyAdmin → base de datos: base_super_ia
-- ============================================================

SET @zona_id       = UUID();
SET @agencia_id    = UUID();
SET @usr_jefe_id   = UUID();
SET @jefe_ag_id    = UUID();
SET @usr_sup_id    = UUID();
SET @supervisor_id = UUID();

-- 1. Zona
INSERT INTO zona (id, nombre, ciudad) VALUES
(@zona_id, 'Zona Centro', 'Cuenca');

-- 2. Agencia dentro de esa zona
INSERT INTO agencia (id, zona_id, nombre, ciudad, direccion, activo) VALUES
(@agencia_id, @zona_id, 'Agencia Principal', 'Cuenca', 'Av. Solano 1-23', 1);

-- 3. Usuario Jefe de Agencia
INSERT INTO usuario
  (id, nombre, email, password_hash, rol, agencia_id, activo, estado_aprobacion)
VALUES (
  @usr_jefe_id,
  'Jefe Agencia Prueba',
  'jefe@superialogan.com',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'jefe_agencia',
  @agencia_id,
  1,
  'aprobado'
);

-- 4. Perfil jefe_agencia
INSERT INTO jefe_agencia (id, usuario_id, agencia_id) VALUES
(@jefe_ag_id, @usr_jefe_id, @agencia_id);

-- 5. Usuario Supervisor
INSERT INTO usuario
  (id, nombre, email, password_hash, rol, agencia_id, activo, estado_aprobacion)
VALUES (
  @usr_sup_id,
  'Carlos Supervisor',
  'supervisor@superialogan.com',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'supervisor',
  @agencia_id,
  1,
  'aprobado'
);

-- 6. Perfil supervisor
INSERT INTO supervisor (id, usuario_id, jefe_agencia_id, meta_asesores) VALUES
(@supervisor_id, @usr_sup_id, @jefe_ag_id, 5);

-- Verificación
SELECT
  s.id        AS supervisor_id,
  u.nombre,
  u.email,
  u.activo,
  a.nombre    AS agencia,
  z.nombre    AS zona
FROM supervisor s
JOIN usuario u       ON u.id  = s.usuario_id
JOIN jefe_agencia ja ON ja.id = s.jefe_agencia_id
JOIN agencia a       ON a.id  = ja.agencia_id
JOIN zona z          ON z.id  = a.zona_id;
