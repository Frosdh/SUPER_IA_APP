-- Crear tabla para el historial de rutas de los conductores
CREATE TABLE IF NOT EXISTS conductores_rutas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conductor_id INT NOT NULL,
    latitud DECIMAL(10, 8) NOT NULL,
    longitud DECIMAL(11, 8) NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (conductor_id),
    INDEX (fecha_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
