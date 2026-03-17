-- ============================================================
-- crear_tabla_tickets.sql
-- Ejecutar este script UNA SOLA VEZ en tu base de datos
-- corporat_fuber_db para habilitar el sistema de soporte.
-- ============================================================

CREATE TABLE IF NOT EXISTS `tickets_soporte` (
  `id`            int(11)       NOT NULL AUTO_INCREMENT,
  `usuario_id`    int(11)       NOT NULL,
  `viaje_id`      int(11)       DEFAULT NULL,
  `tipo`          enum(
                    'problema_tecnico',
                    'pago',
                    'conductor',
                    'cuenta',
                    'otro'
                  ) NOT NULL DEFAULT 'otro',
  `asunto`        varchar(150)  NOT NULL,
  `mensaje`       text          NOT NULL,
  `estado`        enum(
                    'abierto',
                    'en_proceso',
                    'resuelto',
                    'cerrado'
                  ) NOT NULL DEFAULT 'abierto',
  `respuesta`     text          DEFAULT NULL,
  `respondido_en` datetime      DEFAULT NULL,
  `creado_en`     timestamp     NOT NULL DEFAULT current_timestamp(),
  `actualizado_en`timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `viaje_id`   (`viaje_id`),
  KEY `estado`     (`estado`),
  CONSTRAINT `fk_ticket_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_viaje`   FOREIGN KEY (`viaje_id`)   REFERENCES `viajes`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
