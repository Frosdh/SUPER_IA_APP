-- ============================================================
-- migracion_roles_web.sql
-- Ejecutar en la base de datos corporat_fuber_db
-- ============================================================

-- 1. Crear tabla de secretarias
CREATE TABLE IF NOT EXISTS secretarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    pass_hash VARCHAR(255) NOT NULL,
    cooperativa_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_secretaria_cooperativa FOREIGN KEY (cooperativa_id) REFERENCES cooperativas(id) ON DELETE CASCADE
);

-- 2. Insertar secretaria de prueba (opcional, para verificación)
-- Usuario: secretaria_azul, Contraseña: Secretaria123!
-- El hash se puede generar en PHP con password_hash('Secretaria123!', PASSWORD_DEFAULT)
-- INSERT INTO secretarias (usuario, pass_hash, cooperativa_id, nombre) 
-- VALUES ('secretaria_azul', '$2y$10$WkL9.vG0f8RkLkJ8QG5X.eX2zB7yPq.FvG0f8RkLkJ8QG5X.eX2zB', 1, 'Secretaria Azutaxi');
