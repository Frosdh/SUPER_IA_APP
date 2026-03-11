-- ============================================================
-- Agregar columna 'comentario' a la tabla viajes
-- Ejecutar en phpMyAdmin o MySQL
-- ============================================================

ALTER TABLE viajes
ADD COLUMN IF NOT EXISTS comentario TEXT DEFAULT NULL AFTER calificacion;
