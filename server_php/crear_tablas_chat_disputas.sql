-- ============================================================
-- crear_tablas_chat_disputas.sql
-- Ejecutar UNA SOLA VEZ en la base de datos corporat_fuber_db
-- ============================================================

-- ── Tabla: chat_mensajes ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `chat_mensajes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `viaje_id`   INT UNSIGNED NOT NULL,
  `remitente`  ENUM('pasajero','conductor') NOT NULL,
  `mensaje`    TEXT NOT NULL,
  `fecha`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_viaje_id` (`viaje_id`),
  KEY `idx_viaje_fecha` (`viaje_id`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tabla: disputas ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `disputas` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `viaje_id`        INT UNSIGNED NOT NULL,
  `motivo`          VARCHAR(100) NOT NULL,
  `descripcion`     TEXT,
  `telefono_usuario` VARCHAR(20),
  `estado`          ENUM('abierta','en_revision','resuelta','cerrada') NOT NULL DEFAULT 'abierta',
  `fecha`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_resolucion` DATETIME DEFAULT NULL,
  `notas_admin`     TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_viaje_id` (`viaje_id`),
  KEY `idx_estado`   (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
