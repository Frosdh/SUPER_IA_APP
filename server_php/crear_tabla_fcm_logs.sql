-- Tabla para depurar envíos FCM
CREATE TABLE IF NOT EXISTS fcm_debug_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    viaje_id INT,
    conductor_id INT,
    token_fcm TEXT,
    response_code INT,
    response_text TEXT,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
