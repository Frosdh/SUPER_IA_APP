-- ============================================================
-- SUPER_IA LOGAN — Metodología Comercial y Crediticia
-- Base de datos MySQL (phpMyAdmin / XAMPP)
-- Versión con verificación documental y aprobación jerárquica
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "-05:00"; -- Hora Ecuador

CREATE DATABASE IF NOT EXISTS `super_ia_logan`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `super_ia_logan`;

-- ============================================================
-- 1. ESTRUCTURA ORGANIZACIONAL
-- ============================================================

CREATE TABLE `unidad_bancaria` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nombre`      VARCHAR(200) NOT NULL,
    `codigo`      VARCHAR(20)  NOT NULL UNIQUE,
    `descripcion` TEXT,
    `activo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `zona` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nombre`      VARCHAR(100) NOT NULL,
    `ciudad`      VARCHAR(100),
    `descripcion` TEXT,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `agencia` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `zona_id`    INT UNSIGNED NOT NULL,
    `nombre`     VARCHAR(200) NOT NULL,
    `ciudad`     VARCHAR(100),
    `direccion`  TEXT,
    `telefono`   VARCHAR(20),
    `activo`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_agencia_zona` FOREIGN KEY (`zona_id`) REFERENCES `zona`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. USUARIOS Y ROLES (con verificación documental)
-- ============================================================

CREATE TABLE `usuario` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nombre`        VARCHAR(200) NOT NULL,
    `email`         VARCHAR(200) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `rol`           ENUM('gerente_general','jefe_regional','jefe_agencia','supervisor','asesor') NOT NULL,
    `agencia_id`    INT UNSIGNED,
    `activo`        TINYINT(1)  NOT NULL DEFAULT 1,
    `ultimo_login`  DATETIME,
    -- Nuevos campos para verificación y aprobación
    `estado_registro` ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    `aprobado_por`    INT UNSIGNED NULL,
    `fecha_aprobacion` DATETIME NULL,
    `motivo_rechazo`   TEXT NULL,
    `created_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_usuario_agencia` FOREIGN KEY (`agencia_id`) REFERENCES `agencia`(`id`),
    CONSTRAINT `fk_usuario_aprobado_por` FOREIGN KEY (`aprobado_por`) REFERENCES `usuario`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de documentos adjuntos para verificación de usuarios
CREATE TABLE `documento_usuario` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`     INT UNSIGNED NOT NULL,
    `tipo_documento` ENUM('cedula','credencial_laboral','carta_banco','foto_perfil','otro') NOT NULL,
    `nombre_archivo` VARCHAR(255) NOT NULL,
    `ruta_archivo`   VARCHAR(500) NOT NULL,   -- Ruta en servidor, o puede ser MEDIUMBLOB si se guarda en BD
    `estado`         ENUM('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
    `observaciones`  TEXT,
    `subido_en`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `revisado_en`    DATETIME NULL,
    INDEX (`usuario_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuario`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla opcional para historial de solicitudes de registro
CREATE TABLE `solicitud_registro` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`     INT UNSIGNED NOT NULL,
    `fecha_solicitud` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `estado`         ENUM('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
    `aprobado_por`   INT UNSIGNED NULL,
    `fecha_resolucion` DATETIME NULL,
    `comentarios`    TEXT,
    FOREIGN KEY (`usuario_id`) REFERENCES `usuario`(`id`),
    FOREIGN KEY (`aprobado_por`) REFERENCES `usuario`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tablas de roles (sin cambios, pero ahora dependen de usuario.estado_registro)
CREATE TABLE `gerente_general` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`         INT UNSIGNED NOT NULL UNIQUE,
    `unidad_bancaria_id` INT UNSIGNED NOT NULL,
    `codigo_gerente`     VARCHAR(30) UNIQUE,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_gg_usuario`   FOREIGN KEY (`usuario_id`)         REFERENCES `usuario`(`id`),
    CONSTRAINT `fk_gg_unidad`    FOREIGN KEY (`unidad_bancaria_id`) REFERENCES `unidad_bancaria`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `gerente_zona` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`         INT UNSIGNED NOT NULL UNIQUE,
    `zona_id`            INT UNSIGNED NOT NULL,
    `gerente_general_id` INT UNSIGNED,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_gz_usuario` FOREIGN KEY (`usuario_id`)         REFERENCES `usuario`(`id`),
    CONSTRAINT `fk_gz_zona`    FOREIGN KEY (`zona_id`)            REFERENCES `zona`(`id`),
    CONSTRAINT `fk_gz_gg`      FOREIGN KEY (`gerente_general_id`) REFERENCES `gerente_general`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `jefe_agencia` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id` INT UNSIGNED NOT NULL UNIQUE,
    `agencia_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ja_usuario`  FOREIGN KEY (`usuario_id`) REFERENCES `usuario`(`id`),
    CONSTRAINT `fk_ja_agencia`  FOREIGN KEY (`agencia_id`) REFERENCES `agencia`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `supervisor` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`       INT UNSIGNED NOT NULL UNIQUE,
    `jefe_agencia_id`  INT UNSIGNED NOT NULL,
    `meta_asesores`    INT NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sup_usuario` FOREIGN KEY (`usuario_id`)      REFERENCES `usuario`(`id`),
    CONSTRAINT `fk_sup_ja`      FOREIGN KEY (`jefe_agencia_id`) REFERENCES `jefe_agencia`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `asesor` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`          INT UNSIGNED NOT NULL UNIQUE,
    `supervisor_id`       INT UNSIGNED NOT NULL,
    `meta_tareas_diarias` INT NOT NULL DEFAULT 8,
    `meta_visitas_mes`    INT NOT NULL DEFAULT 0,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_asesor_usuario`     FOREIGN KEY (`usuario_id`)    REFERENCES `usuario`(`id`),
    CONSTRAINT `fk_asesor_supervisor`  FOREIGN KEY (`supervisor_id`) REFERENCES `supervisor`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. CLIENTES / PROSPECTOS (sin cambios)
-- ============================================================

CREATE TABLE `cliente_prospecto` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nombre`          VARCHAR(200) NOT NULL,
    `cedula`          VARCHAR(20),
    `cedula_conyuge`  VARCHAR(20),
    `telefono`        VARCHAR(20),
    `telefono2`       VARCHAR(20),
    `email`           VARCHAR(200),
    `direccion`       TEXT,
    `ciudad`          VARCHAR(100),
    `zona`            VARCHAR(100),
    `subzona`         VARCHAR(100),
    `latitud`         DECIMAL(10,8),
    `longitud`        DECIMAL(11,8),
    `actividad`       ENUM('negocio_propio','empleado_privado','empleado_publico','profesional'),
    `nombre_empresa`  VARCHAR(200),
    `tiene_ruc`       TINYINT(1) DEFAULT 0,
    `tiene_rise`      TINYINT(1) DEFAULT 0,
    `estado`          ENUM('prospecto','cliente','pendiente','descartado') NOT NULL DEFAULT 'prospecto',
    `asesor_id`       INT UNSIGNED,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cedula`  (`cedula`),
    INDEX `idx_estado`  (`estado`),
    INDEX `idx_asesor`  (`asesor_id`),
    CONSTRAINT `fk_cp_asesor` FOREIGN KEY (`asesor_id`) REFERENCES `asesor`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. TAREAS / VISITAS (con georeferenciación)
-- ============================================================

CREATE TABLE `tarea` (
    `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asesor_id`            INT UNSIGNED NOT NULL,
    `cliente_prospecto_id` INT UNSIGNED,
    `tipo_tarea`           ENUM(
                               'prospecto_nuevo','visita_frio','evaluacion',
                               'recuperacion','documentos_pendientes',
                               'post_venta','nueva_cita_campo',
                               'nueva_cita_oficina','levantamiento'
                           ) NOT NULL,
    `estado`               ENUM(
                               'programada','en_proceso','completada',
                               'postergada','pendiente','cancelada'
                           ) NOT NULL DEFAULT 'programada',
    `fecha_programada`     DATE    NOT NULL,
    `hora_programada`      TIME,
    `fecha_realizada`      DATE,
    `hora_realizada`       TIME,
    -- Georeferenciación inicio / fin
    `latitud_inicio`       DECIMAL(10,8),
    `longitud_inicio`      DECIMAL(11,8),
    `latitud_fin`          DECIMAL(10,8),
    `longitud_fin`         DECIMAL(11,8),
    `ciudad`               VARCHAR(100),
    `zona`                 VARCHAR(100),
    `subzona`              VARCHAR(100),
    `fuera_de_zona`        TINYINT(1) DEFAULT 0,
    `motivo_postergacion`  TEXT,
    `modificada`           TINYINT(1) DEFAULT 0,
    `modificada_at`        DATETIME,
    `modificada_por`       INT UNSIGNED,
    `observaciones`        TEXT,
    `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tarea_asesor_fecha` (`asesor_id`, `fecha_programada`),
    INDEX `idx_tarea_estado`       (`estado`),
    INDEX `idx_tarea_tipo`         (`tipo_tarea`),
    CONSTRAINT `fk_tarea_asesor`  FOREIGN KEY (`asesor_id`)            REFERENCES `asesor`(`id`),
    CONSTRAINT `fk_tarea_cliente` FOREIGN KEY (`cliente_prospecto_id`) REFERENCES `cliente_prospecto`(`id`),
    CONSTRAINT `fk_tarea_modpor`  FOREIGN KEY (`modificada_por`)       REFERENCES `usuario`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. ENCUESTA COMERCIAL (bancaria)
-- ============================================================

CREATE TABLE `encuesta_comercial` (
    `id`                             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tarea_id`                       INT UNSIGNED NOT NULL UNIQUE,
    `mantiene_cuenta_ahorro`         TINYINT(1),
    `mantiene_cuenta_corriente`      TINYINT(1),
    `tiene_inversiones`              TINYINT(1),
    `institucion_inversiones`        VARCHAR(200),
    `valor_inversion`                DECIMAL(15,2),
    `plazo_inversion`                VARCHAR(50),
    `fecha_vencimiento_inversion`    DATE,
    `interes_propuesta_previa`       TINYINT(1),
    `fecha_nuevo_contacto`           DATE,
    `tiene_operaciones_crediticias`  TINYINT(1),
    `institucion_credito`            VARCHAR(200),
    `interes_conocer_productos`      TINYINT(1),
    `nivel_interes_captado`          ENUM('ninguno','bajo','alto'),
    `interes_cc`                     TINYINT(1) DEFAULT 0,
    `interes_ahorro`                 TINYINT(1) DEFAULT 0,
    `interes_inversion`              TINYINT(1) DEFAULT 0,
    `interes_credito`                TINYINT(1) DEFAULT 0,
    `razon_ya_trabaja_institucion`   TINYINT(1) DEFAULT 0,
    `razon_desconfia_servicios`      TINYINT(1) DEFAULT 0,
    `razon_agusto_actual`            TINYINT(1) DEFAULT 0,
    `razon_mala_experiencia`         TINYINT(1) DEFAULT 0,
    `razon_otros`                    TEXT,
    `acuerdo_logrado`                ENUM('nueva_cita_campo','nueva_cita_oficina','recolectar_documentacion','ninguno','levantamiento_campo'),
    `fecha_acuerdo`                  DATE,
    `hora_acuerdo`                   TIME,
    `observaciones`                  TEXT,
    `created_at`                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ec_tarea` FOREIGN KEY (`tarea_id`) REFERENCES `tarea`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. ENCUESTA CREDITICIA (microcrédito)
-- ============================================================

CREATE TABLE `encuesta_crediticia` (
    `id`                           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tarea_id`                     INT UNSIGNED NOT NULL UNIQUE,
    `tiene_producto_financiero`    TINYINT(1),
    `institucion_actual`           VARCHAR(200),
    `tipo_producto_actual`         VARCHAR(100),
    `tiene_cdp`                    TINYINT(1),
    `fecha_vencimiento_cdp`        DATE,
    `interes_propuesta_cdp`        TINYINT(1),
    `fecha_contacto_cdp`           DATE,
    `requiere_credito`             TINYINT(1),
    `destino_credito`              ENUM(
                                       'capital_trabajo','activos_fijos','pago_deudas',
                                       'consolidacion_deudas','compra_vehiculo',
                                       'compra_vivienda','arreglos_vivienda',
                                       'gastos_educacion','viajes','otros'
                                   ),
    `monto_solicitado`             DECIMAL(15,2),
    `institucion_credito_actual`   VARCHAR(200),
    `que_busca_agilidad`           TINYINT(1) DEFAULT 0,
    `que_busca_cajeros`            TINYINT(1) DEFAULT 0,
    `que_busca_banca_linea`        TINYINT(1) DEFAULT 0,
    `que_busca_agencias`           TINYINT(1) DEFAULT 0,
    `que_busca_credito_rapido`     TINYINT(1) DEFAULT 0,
    `que_busca_tarjeta_debito`     TINYINT(1) DEFAULT 0,
    `que_busca_tarjeta_credito`    TINYINT(1) DEFAULT 0,
    `que_busca_otros`              TEXT,
    `interes_productos`            TINYINT(1),
    `nivel_interes`                ENUM('ninguno','bajo','alto'),
    `interes_cc`                   TINYINT(1) DEFAULT 0,
    `interes_ahorro`               TINYINT(1) DEFAULT 0,
    `interes_inversion`            TINYINT(1) DEFAULT 0,
    `interes_credito`              TINYINT(1) DEFAULT 0,
    `interes_otros`                TEXT,
    `razon_ya_trabaja_institucion` TINYINT(1) DEFAULT 0,
    `razon_desconfia_servicios`    TINYINT(1) DEFAULT 0,
    `razon_agusto_actual`          TINYINT(1) DEFAULT 0,
    `razon_mala_experiencia`       TINYINT(1) DEFAULT 0,
    `razon_otros`                  TEXT,
    `acuerdo_logrado`              ENUM('nueva_cita_campo','nueva_cita_oficina','recolectar_documentacion','ninguno','levantamiento_campo'),
    `fecha_acuerdo`                DATE,
    `hora_acuerdo`                 TIME,
    `observaciones`                TEXT,
    `created_at`                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ecr_tarea` FOREIGN KEY (`tarea_id`) REFERENCES `tarea`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. COMPORTAMIENTO VENTAS / COMPRAS (microempresa)
-- ============================================================

CREATE TABLE `comportamiento_ventas` (
    `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tarea_id`                INT UNSIGNED NOT NULL,
    `dia_semana`              VARCHAR(15) NOT NULL,
    `calificacion`            ENUM('bueno','regular','malo'),
    `valor_venta`             DECIMAL(12,2) DEFAULT 0.00,
    `valor_compra`            DECIMAL(12,2) DEFAULT 0.00,
    `venta_promedio_semanal`  DECIMAL(12,2),
    `venta_promedio_mes`      DECIMAL(12,2),
    `compra_promedio_semanal` DECIMAL(12,2),
    `compra_promedio_mes`     DECIMAL(12,2),
    `porcentaje_contado`      INT DEFAULT 100,
    `porcentaje_credito`      INT DEFAULT 0,
    `dias_atencion_semana`    INT DEFAULT 6,
    CONSTRAINT `fk_cv_tarea` FOREIGN KEY (`tarea_id`) REFERENCES `tarea`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `venta_mensual_historico` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tarea_id`   INT UNSIGNED NOT NULL,
    `mes`        TINYINT UNSIGNED NOT NULL COMMENT '1-12',
    `anio`       YEAR NOT NULL,
    `venta_mes`  DECIMAL(12,2) DEFAULT 0.00,
    `compra_mes` DECIMAL(12,2) DEFAULT 0.00,
    `mes_alto`   TINYINT(1) DEFAULT 0,
    CONSTRAINT `fk_vmh_tarea` FOREIGN KEY (`tarea_id`) REFERENCES `tarea`(`id`),
    CONSTRAINT `chk_mes` CHECK (`mes` BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `producto_comercializado` (
    `id`                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `encuesta_crediticia_id`    INT UNSIGNED NOT NULL,
    `nombre_producto`           VARCHAR(200) NOT NULL,
    `precio_venta_unidad`       DECIMAL(10,2),
    `costo_unidad`              DECIMAL(10,2),
    `cantidad_vendida_mes`      INT DEFAULT 0,
    `margen_utilidad_pct`       DECIMAL(5,2),
    `total_venta_mes`           DECIMAL(12,2),
    `inventario_existente`      INT DEFAULT 0,
    `monto_compra_promedio_sem` DECIMAL(12,2),
    CONSTRAINT `fk_pc_encuesta` FOREIGN KEY (`encuesta_crediticia_id`) REFERENCES `encuesta_crediticia`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. ACUERDOS DE VISITA
-- ============================================================

CREATE TABLE `acuerdo_visita` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tarea_id`     INT UNSIGNED NOT NULL,
    `tipo_acuerdo` ENUM('nueva_cita_campo','nueva_cita_oficina','recolectar_documentacion','ninguno','levantamiento_campo') NOT NULL,
    `fecha`        DATE,
    `hora`         TIME,
    `descripcion`  TEXT,
    `completado`   TINYINT(1) DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_av_tarea` FOREIGN KEY (`tarea_id`) REFERENCES `tarea`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. CRÉDITOS EN PROCESO
-- ============================================================

CREATE TABLE `credito_proceso` (
    `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cliente_prospecto_id`    INT UNSIGNED NOT NULL,
    `asesor_id`               INT UNSIGNED NOT NULL,
    `cedula_deudor`           VARCHAR(20),
    `cedula_conyuge_deudor`   VARCHAR(20),
    `cedula_garante`          VARCHAR(20),
    `cedula_conyuge_garante`  VARCHAR(20),
    `numero_cuenta`           VARCHAR(50),
    `actividad`               ENUM('negocio_propio','empleado_privado','empleado_publico','profesional'),
    `documentos_completos`    TINYINT(1) DEFAULT 0,
    `documentos_faltantes`    TEXT,
    `es_microcredito`         TINYINT(1) DEFAULT 0,
    `fecha_visita_programada` DATE,
    `hora_visita_programada`  TIME,
    `latitud_georef`          DECIMAL(10,8),
    `longitud_georef`         DECIMAL(11,8),
    `estado_credito`          ENUM(
                                  'prospectado','entrevista_venta','levantamiento',
                                  'solicitud','analisis','aprobado',
                                  'desembolsado','rechazado','recuperacion'
                              ) NOT NULL DEFAULT 'prospectado',
    `buro_deudor`             SMALLINT,
    `buro_garante`            SMALLINT,
    `fecha_prospeccion`       DATE,
    `fecha_entrevista_venta`  DATE,
    `fecha_levantamiento`     DATE,
    `fecha_solicitud`         DATE,
    `fecha_desembolso`        DATE,
    `monto_aprobado`          DECIMAL(15,2),
    `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_credito_estado` (`estado_credito`),
    CONSTRAINT `fk_crp_cliente` FOREIGN KEY (`cliente_prospecto_id`) REFERENCES `cliente_prospecto`(`id`),
    CONSTRAINT `fk_crp_asesor`  FOREIGN KEY (`asesor_id`)            REFERENCES `asesor`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. AGENDA / PLANIFICACIÓN DEL DÍA
-- ============================================================

CREATE TABLE `agenda_dia` (
    `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asesor_id`                INT UNSIGNED NOT NULL,
    `fecha`                    DATE NOT NULL,
    `ciudad`                   VARCHAR(100),
    `zona`                     VARCHAR(100),
    `subzona`                  VARCHAR(100),
    `total_tareas_programadas` INT DEFAULT 0,
    `tareas_realizadas`        INT DEFAULT 0,
    `tareas_pendientes`        INT DEFAULT 0,
    `tareas_postergadas`       INT DEFAULT 0,
    `ruta_establecida`         TINYINT(1) DEFAULT 0,
    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_asesor_fecha` (`asesor_id`, `fecha`),
    INDEX `idx_agenda_fecha`    (`asesor_id`, `fecha`),
    CONSTRAINT `fk_ad_asesor` FOREIGN KEY (`asesor_id`) REFERENCES `asesor`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `agenda_item` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agenda_dia_id`    INT UNSIGNED NOT NULL,
    `tarea_id`         INT UNSIGNED,
    `orden_ruta`       TINYINT UNSIGNED,
    `tipo`             ENUM('prospecto','evaluacion','recuperacion','documentos_pendientes','postergado'),
    `hora_establecida` TIME,
    `fuera_de_zona`    TINYINT(1) DEFAULT 0,
    `completado`       TINYINT(1) DEFAULT 0,
    `postergado`       TINYINT(1) DEFAULT 0,
    `fecha_nueva`      DATE,
    `hora_nueva`       TIME,
    `razon_postergacion` TEXT,
    CONSTRAINT `fk_ai_agenda` FOREIGN KEY (`agenda_dia_id`) REFERENCES `agenda_dia`(`id`),
    CONSTRAINT `fk_ai_tarea`  FOREIGN KEY (`tarea_id`)     REFERENCES `tarea`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 11. KPIs DE ASESOR
-- ============================================================

CREATE TABLE `kpi_asesor` (
    `id`                               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asesor_id`                        INT UNSIGNED NOT NULL,
    `mes`                              TINYINT UNSIGNED NOT NULL COMMENT '1-12',
    `anio`                             YEAR NOT NULL,
    `semana`                           TINYINT UNSIGNED COMMENT '1-5',
    `num_prospectos`                   INT DEFAULT 0,
    `num_visitas_frio`                 INT DEFAULT 0,
    `num_evaluaciones`                 INT DEFAULT 0,
    `num_visitas_recuperacion`         INT DEFAULT 0,
    `num_operaciones_desembolsadas`    INT DEFAULT 0,
    `num_entrevistas_venta`            INT DEFAULT 0,
    `num_levantamientos`               INT DEFAULT 0,
    `num_solicitudes_desembolsadas`    INT DEFAULT 0,
    `num_post_venta`                   INT DEFAULT 0,
    `num_represtamos`                  INT DEFAULT 0,
    `tiempo_prospeccion_desembolso`    DECIMAL(6,2),
    `tiempo_entrevista_desembolso`     DECIMAL(6,2),
    `tiempo_levantamiento_desembolso`  DECIMAL(6,2),
    `pct_cumplimiento_prospectos`      DECIMAL(5,2),
    `pct_cumplimiento_visitas`         DECIMAL(5,2),
    `pct_cumplimiento_evaluaciones`    DECIMAL(5,2),
    `pct_cumplimiento_recuperacion`    DECIMAL(5,2),
    `pct_cumplimiento_desembolsos`     DECIMAL(5,2),
    `pct_represtamos_vs_total`         DECIMAL(5,2),
    `pct_post_vs_total_visitas`        DECIMAL(5,2),
    `comparacion_prospectos`           ENUM('superior','igual','inferior'),
    `comparacion_visitas`              ENUM('superior','igual','inferior'),
    `comparacion_evaluaciones`         ENUM('superior','igual','inferior'),
    `comparacion_recuperacion`         ENUM('superior','igual','inferior'),
    `comparacion_desembolsos`          ENUM('superior','igual','inferior'),
    `meta_asignada_supervisor`         INT DEFAULT 0,
    `updated_at`                       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_kpi_periodo` (`asesor_id`, `mes`, `anio`, `semana`),
    INDEX `idx_kpi_periodo` (`asesor_id`, `anio`, `mes`),
    CONSTRAINT `fk_kpi_asesor` FOREIGN KEY (`asesor_id`) REFERENCES `asesor`(`id`),
    CONSTRAINT `chk_kpi_mes`   CHECK (`mes` BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 12. ALERTAS POR MODIFICACIÓN DE TAREA
-- ============================================================

CREATE TABLE `alerta_modificacion` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tarea_id`         INT UNSIGNED NOT NULL,
    `asesor_id`        INT UNSIGNED NOT NULL,
    `supervisor_id`    INT UNSIGNED,
    `campo_modificado` VARCHAR(100),
    `valor_anterior`   TEXT,
    `valor_nuevo`      TEXT,
    `vista_supervisor` TINYINT(1) DEFAULT 0,
    `vista_at`         DATETIME,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_alerta_supervisor` (`supervisor_id`, `vista_supervisor`),
    CONSTRAINT `fk_am_tarea`      FOREIGN KEY (`tarea_id`)      REFERENCES `tarea`(`id`),
    CONSTRAINT `fk_am_asesor`     FOREIGN KEY (`asesor_id`)     REFERENCES `asesor`(`id`),
    CONSTRAINT `fk_am_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `supervisor`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 13. SEGUIMIENTO GPS / RUTAS EN TIEMPO REAL (para mapas de calor y trayectorias)
-- ============================================================

CREATE TABLE `ubicacion_asesor` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asesor_id`       INT UNSIGNED NOT NULL,
    `latitud`         DECIMAL(10,8) NOT NULL,
    `longitud`        DECIMAL(11,8) NOT NULL,
    `precision_m`     DECIMAL(8,2),
    `tarea_activa_id` INT UNSIGNED,
    `timestamp`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ubicacion_ts` (`asesor_id`, `timestamp`),
    CONSTRAINT `fk_ua_asesor` FOREIGN KEY (`asesor_id`)       REFERENCES `asesor`(`id`),
    CONSTRAINT `fk_ua_tarea`  FOREIGN KEY (`tarea_activa_id`) REFERENCES `tarea`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 14. REPORTE PENETRACIÓN DE MERCADO (supervisor)
-- ============================================================

CREATE TABLE `reporte_penetracion` (
    `id`                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `supervisor_id`               INT UNSIGNED NOT NULL,
    `mes`                         TINYINT UNSIGNED NOT NULL,
    `anio`                        YEAR NOT NULL,
    `total_prospectos`            INT DEFAULT 0,
    `pct_ya_clientes`             DECIMAL(5,2) DEFAULT 0.00,
    `pct_cuenta_ahorro`           DECIMAL(5,2) DEFAULT 0.00,
    `pct_cuenta_corriente`        DECIMAL(5,2) DEFAULT 0.00,
    `pct_inversiones`             DECIMAL(5,2) DEFAULT 0.00,
    `pct_interes_productos`       DECIMAL(5,2) DEFAULT 0.00,
    `pct_interes_credito`         DECIMAL(5,2) DEFAULT 0.00,
    `pct_interes_ahorro`          DECIMAL(5,2) DEFAULT 0.00,
    `pct_interes_cc`              DECIMAL(5,2) DEFAULT 0.00,
    `pct_destino_capital_trabajo` DECIMAL(5,2) DEFAULT 0.00,
    `pct_destino_activos`         DECIMAL(5,2) DEFAULT 0.00,
    `pct_destino_vehiculo`        DECIMAL(5,2) DEFAULT 0.00,
    `pct_destino_vivienda`        DECIMAL(5,2) DEFAULT 0.00,
    `pct_destino_educacion`       DECIMAL(5,2) DEFAULT 0.00,
    `pct_destino_consolidacion`   DECIMAL(5,2) DEFAULT 0.00,
    `updated_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_rp_periodo` (`supervisor_id`, `mes`, `anio`),
    CONSTRAINT `fk_rp_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `supervisor`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TRIGGERS
-- ============================================================

DELIMITER $$

CREATE TRIGGER `trg_tarea_modificada`
BEFORE UPDATE ON `tarea`
FOR EACH ROW
BEGIN
    DECLARE v_supervisor_id INT UNSIGNED;

    IF (OLD.estado <> NEW.estado OR
        IFNULL(OLD.observaciones,'') <> IFNULL(NEW.observaciones,'')) THEN

        SET NEW.modificada    = 1;
        SET NEW.modificada_at = NOW();

        SELECT a.supervisor_id INTO v_supervisor_id
        FROM asesor a WHERE a.id = NEW.asesor_id LIMIT 1;

        INSERT INTO `alerta_modificacion`
            (`tarea_id`, `asesor_id`, `supervisor_id`, `campo_modificado`, `valor_anterior`, `valor_nuevo`)
        VALUES
            (NEW.id, NEW.asesor_id, v_supervisor_id,
             'estado/observaciones', OLD.estado, NEW.estado);
    END IF;
END$$

CREATE TRIGGER `trg_cliente_updated`
BEFORE UPDATE ON `cliente_prospecto`
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NOW();
END$$

CREATE TRIGGER `trg_credito_updated`
BEFORE UPDATE ON `credito_proceso`
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NOW();
END$$

DELIMITER ;

-- ============================================================
-- VISTAS ÚTILES
-- ============================================================

CREATE OR REPLACE VIEW `v_desempeno_asesor_hoy` AS
SELECT
    u.nombre                                                              AS asesor_nombre,
    a.id                                                                  AS asesor_id,
    a.supervisor_id,
    ad.fecha,
    ad.total_tareas_programadas,
    ad.tareas_realizadas,
    ad.tareas_postergadas,
    ad.tareas_pendientes,
    ROUND(100.0 * ad.tareas_realizadas / NULLIF(ad.total_tareas_programadas, 0), 2) AS pct_cumplimiento
FROM `asesor` a
JOIN `usuario` u   ON u.id = a.usuario_id
JOIN `supervisor` s ON s.id = a.supervisor_id
LEFT JOIN `agenda_dia` ad ON ad.asesor_id = a.id AND ad.fecha = CURDATE();

CREATE OR REPLACE VIEW `v_pipeline_credito` AS
SELECT
    cp.asesor_id,
    u.nombre                                        AS asesor,
    cp.estado_credito,
    COUNT(*)                                        AS total,
    ROUND(AVG(DATEDIFF(NOW(), cp.created_at)), 1)  AS dias_promedio_en_estado
FROM `credito_proceso` cp
JOIN `asesor` a  ON a.id  = cp.asesor_id
JOIN `usuario` u ON u.id  = a.usuario_id
GROUP BY cp.asesor_id, u.nombre, cp.estado_credito;

CREATE OR REPLACE VIEW `v_tareas_incumplidas` AS
SELECT
    t.asesor_id,
    u.nombre           AS asesor_nombre,
    t.fecha_programada AS fecha,
    t.tipo_tarea,
    t.estado,
    t.motivo_postergacion,
    cp.nombre          AS cliente_nombre
FROM `tarea` t
JOIN `asesor` a           ON a.id  = t.asesor_id
JOIN `usuario` u          ON u.id  = a.usuario_id
LEFT JOIN `cliente_prospecto` cp ON cp.id = t.cliente_prospecto_id
WHERE t.estado IN ('postergada','pendiente');

CREATE OR REPLACE VIEW `v_kpi_equipo_supervisor` AS
SELECT
    a.supervisor_id,
    k.mes,
    k.anio,
    k.semana,
    u.nombre                          AS asesor_nombre,
    k.num_prospectos,
    k.num_visitas_frio,
    k.num_evaluaciones,
    k.num_visitas_recuperacion,
    k.num_operaciones_desembolsadas,
    k.pct_cumplimiento_prospectos,
    k.pct_cumplimiento_desembolsos,
    k.comparacion_prospectos,
    k.comparacion_desembolsos
FROM `kpi_asesor` k
JOIN `asesor` a  ON a.id  = k.asesor_id
JOIN `usuario` u ON u.id  = a.usuario_id;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DEL SCRIPT — SUPER_IA LOGAN (con verificación documental)
-- ============================================================
