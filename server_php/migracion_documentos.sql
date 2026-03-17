-- ============================================================
-- migracion_documentos.sql
-- Ejecutar una sola vez en la base de datos corporat_fuber_db
-- ============================================================

-- 1) Nuevas columnas en conductores
ALTER TABLE conductores
  ADD COLUMN IF NOT EXISTS email   VARCHAR(255) NULL AFTER cedula,
  ADD COLUMN IF NOT EXISTS ciudad  VARCHAR(100) NOT NULL DEFAULT 'Cuenca' AFTER email,
  ADD COLUMN IF NOT EXISTS foto_perfil MEDIUMTEXT NULL AFTER ciudad;

-- 2) Tabla de documentos del conductor
CREATE TABLE IF NOT EXISTS documentos_conductor (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  conductor_id INT NOT NULL,
  tipo         ENUM(
                 'licencia_frente',
                 'licencia_reverso',
                 'cedula',
                 'soat',
                 'matricula'
               ) NOT NULL,
  imagen       MEDIUMTEXT NULL,          -- base64 de la imagen
  estado       ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
  notas        TEXT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (conductor_id) REFERENCES conductores(id) ON DELETE CASCADE,
  UNIQUE KEY uk_conductor_tipo (conductor_id, tipo)
);
