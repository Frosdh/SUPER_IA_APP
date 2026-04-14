-- ============================================================
-- migracion_fcm_conductores.sql
-- Ejecutar una sola vez en tu base de datos corporat_fuber_db
-- Agrega: token_fcm y ultima_ubicacion a la tabla conductores
-- ============================================================

ALTER TABLE `conductores`
  ADD COLUMN `token_fcm` VARCHAR(255) DEFAULT NULL
    COMMENT 'Token FCM del dispositivo del conductor para notificaciones push',
  ADD COLUMN `ultima_ubicacion` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Última vez que el conductor envió su ubicación GPS';
