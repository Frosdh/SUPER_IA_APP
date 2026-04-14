-- ============================================================
-- migracion_registro_dual.sql
-- Ejecutar en la base de datos corporat_fuber_db
-- ============================================================

-- 1. Crear tabla de cooperativas
CREATE TABLE IF NOT EXISTS cooperativas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Insertar cooperativas iniciales
INSERT IGNORE INTO cooperativas (nombre) VALUES ('Azutaxi'), ('Azugruas');

-- 3. Modificar tabla de conductores
ALTER TABLE conductores
    ADD COLUMN IF NOT EXISTS tipo_conductor ENUM('independiente', 'cooperativa') NOT NULL DEFAULT 'independiente' AFTER pass_hash,
    ADD COLUMN IF NOT EXISTS cooperativa_id INT NULL AFTER tipo_conductor,
    ADD CONSTRAINT fk_conductor_cooperativa FOREIGN KEY (cooperativa_id) REFERENCES cooperativas(id) ON DELETE SET NULL;

-- 4. Modificar el ENUM de documentos para incluir vinculacion_cooperativa
ALTER TABLE documentos_conductor 
    MODIFY COLUMN tipo ENUM(
        'licencia_frente',
        'licencia_reverso',
        'cedula',
        'soat',
        'matricula',
        'vinculacion_cooperativa'
    ) NOT NULL;

-- 5. Insertar categoría Taxi
-- Primero verificamos si ya existe para no duplicar (basado en el nombre)
INSERT IGNORE INTO categorias (id, nombre, tarifa_base, precio_km, precio_minuto) 
VALUES (4, 'Taxi', 1.50, 0.40, 0.10);
