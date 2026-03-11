-- ============================================================
-- MIGRACIÓN: Agregar columnas de descuento a la tabla viajes
-- Base de datos: fuber_db
--
-- ✅ La tabla codigos_descuento ya existe con sus datos
-- ✅ Solo falta añadir 2 columnas a la tabla viajes
--
-- Ejecutar en phpMyAdmin → selecciona fuber_db → pestaña SQL
-- ============================================================

ALTER TABLE `viajes`
  ADD COLUMN `descuento`        DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Monto descontado por cupón (0 si no hubo)',
  ADD COLUMN `codigo_descuento` VARCHAR(50)   NOT NULL DEFAULT ''
    COMMENT 'Código del cupón aplicado (vacío si no hubo)';
