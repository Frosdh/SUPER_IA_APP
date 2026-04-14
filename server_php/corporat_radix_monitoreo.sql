-- ============================================================
-- DATABASE: corporat_radix_copia
-- Sistema de Monitoreo Comercial - Adaptado a estructura existente
-- ============================================================

-- Usar base de datos existente
USE corporat_radix_copia;

-- Deshabilitar verificación de claves foráneas temporalmente
SET FOREIGN_KEY_CHECKS=0;

-- ============================================================
-- TABLA: roles
-- Descripción: Roles disponibles en el sistema (SuperAdmin, Admin, Supervisor, Asesor)
-- ============================================================
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id_rol INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE,
  descripcion VARCHAR(255),
  nivel_acceso INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: usuarios
-- Descripción: Usuarios del sistema con roles
-- ============================================================
DROP TABLE IF EXISTS usuarios;
CREATE TABLE usuarios (
  id_usuario INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  clave VARCHAR(255) NOT NULL,
  nombres VARCHAR(100),
  apellidos VARCHAR(100),
  email VARCHAR(100) UNIQUE,
  telefono VARCHAR(20),
  ciudad VARCHAR(100),
  provincia VARCHAR(100),
  canton VARCHAR(100),
  activo TINYINT(1) DEFAULT 1,
  token_fcm TEXT,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso DATETIME,
  id_rol_fk INT NOT NULL,
  FOREIGN KEY (id_rol_fk) REFERENCES roles(id_rol),
  INDEX idx_usuario (usuario),
  INDEX idx_email (email),
  INDEX idx_rol (id_rol_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: region
-- Descripción: Información geográfica de regiones
-- ============================================================
DROP TABLE IF EXISTS region;
CREATE TABLE region (
  id_region INT AUTO_INCREMENT PRIMARY KEY,
  pais VARCHAR(100) DEFAULT 'Ecuador',
  provincia VARCHAR(100) NOT NULL,
  ciudad VARCHAR(100) NOT NULL,
  canton VARCHAR(100),
  UNIQUE KEY unique_location (provincia, ciudad, canton)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: cooperativa
-- Descripción: Cooperativas o instituciones
-- ============================================================
DROP TABLE IF EXISTS cooperativa;
CREATE TABLE cooperativa (
  id_cooperativa INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL UNIQUE,
  fecha_crea TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: empresa
-- Descripción: Empresas asociadas a cooperativas
-- ============================================================
DROP TABLE IF EXISTS empresa;
CREATE TABLE empresa (
  id_empresa INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  nombre_cooperativa VARCHAR(100),
  id_cooperativa_fk INT,
  cuenta_estado VARCHAR(50),
  cdp VARCHAR(50),
  fecha_fin_cdp DATE,
  activo TINYINT(1) DEFAULT 1,
  FOREIGN KEY (id_cooperativa_fk) REFERENCES cooperativa(id_cooperativa) ON DELETE SET NULL,
  INDEX idx_cooperativa (id_cooperativa_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: actividad_cliente
-- Descripción: Actividades comerciales de clientes
-- ============================================================
DROP TABLE IF EXISTS actividad_cliente;
CREATE TABLE actividad_cliente (
  id_activ_cliente INT AUTO_INCREMENT PRIMARY KEY,
  nombre_actividad VARCHAR(100) NOT NULL,
  id_empresa_fk INT,
  FOREIGN KEY (id_empresa_fk) REFERENCES empresa(id_empresa) ON DELETE SET NULL,
  INDEX idx_empresa (id_empresa_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: clientes
-- Descripción: Clientes principales a monitorear
-- ============================================================
DROP TABLE IF EXISTS clientes;
CREATE TABLE clientes (
  id_cliente INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100),
  email VARCHAR(100),
  telefono VARCHAR(20),
  direccion VARCHAR(255),
  ruc_rise VARCHAR(20) UNIQUE,
  requisitos TEXT,
  id_region_fk INT,
  id_activ_cliente_fk INT,
  activo TINYINT(1) DEFAULT 1,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_region_fk) REFERENCES region(id_region) ON DELETE SET NULL,
  FOREIGN KEY (id_activ_cliente_fk) REFERENCES actividad_cliente(id_activ_cliente) ON DELETE SET NULL,
  INDEX idx_nombre (nombre, apellidos),
  INDEX idx_ruc (ruc_rise),
  INDEX idx_region (id_region_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: operacion_credito
-- Descripción: Operaciones crediticias de clientes
-- ============================================================
DROP TABLE IF EXISTS operacion_credito;
CREATE TABLE operacion_credito (
  id_opera_creditito INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  seleccion_cred VARCHAR(50),
  cantidad DECIMAL(12,2) NOT NULL,
  destino_cred VARCHAR(100),
  tipo_credito ENUM('consumo','microempresa','comercial','educativo') DEFAULT 'microempresa',
  tipo_interes ENUM('ninguno','bajo','alto') DEFAULT 'bajo',
  fecha_fin_creado DATE,
  estado ENUM('pendiente','aprobado','rechazado','completado','cancelado') DEFAULT 'pendiente',
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id_cliente) ON DELETE RESTRICT,
  INDEX idx_cliente (cliente_id),
  INDEX idx_tipo_credito (tipo_credito),
  INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: gestiones_cobranza
-- Descripción: Gestiones de cobranza y seguimiento
-- ============================================================
DROP TABLE IF EXISTS gestiones_cobranza;
CREATE TABLE gestiones_cobranza (
  id INT AUTO_INCREMENT PRIMARY KEY,
  credito_id INT NOT NULL,
  usuario_id INT,
  cliente_id INT,
  tipo ENUM('preventiva','recuperacion','contension') DEFAULT 'preventiva',
  fecha DATE NOT NULL,
  hora TIME,
  resultado ENUM('compromiso','no_localizado','no_paga','sin_solucion','contactado') DEFAULT 'contactado',
  observaciones TEXT,
  siguiente_gestion DATE,
  FOREIGN KEY (credito_id) REFERENCES operacion_credito(id_opera_creditito) ON DELETE RESTRICT,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id_cliente) ON DELETE SET NULL,
  INDEX idx_credito (credito_id),
  INDEX idx_fecha (fecha),
  INDEX idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: activos
-- Descripción: Activos reportados por clientes
-- ============================================================
DROP TABLE IF EXISTS activos;
CREATE TABLE activos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  tipo ENUM('vehiculo','inmueble','mueble','equipo','otros') DEFAULT 'otros',
  descripcion VARCHAR(100),
  marca VARCHAR(50),
  modelo VARCHAR(50),
  serie VARCHAR(50),
  valor DECIMAL(12,2),
  estado_fisico VARCHAR(50),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id_cliente) ON DELETE CASCADE,
  INDEX idx_cliente (cliente_id),
  INDEX idx_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: pasivos
-- Descripción: Pasivos/deudas del cliente
-- ============================================================
DROP TABLE IF EXISTS pasivos;
CREATE TABLE pasivos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  credito_id INT,
  institucion VARCHAR(100) NOT NULL,
  destino VARCHAR(100),
  monto_inicial DECIMAL(12,2),
  saldo_actual DECIMAL(12,2),
  pago_mes DECIMAL(12,2),
  tipo ENUM('credito','tarjeta','prestamo','otro') DEFAULT 'credito',
  estado ENUM('vigente','vencido','cancelado','mora') DEFAULT 'vigente',
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id_cliente) ON DELETE CASCADE,
  FOREIGN KEY (credito_id) REFERENCES operacion_credito(id_opera_creditito) ON DELETE SET NULL,
  INDEX idx_cliente (cliente_id),
  INDEX idx_institucion (institucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: agenda
-- Descripción: Agenda y planificación de actividades
-- ============================================================
DROP TABLE IF EXISTS agenda;
CREATE TABLE agenda (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  cliente_id INT NOT NULL,
  tipo ENUM('promocion','evaluacion','recuperacion','documentacion','seguimiento') DEFAULT 'seguimiento',
  fecha DATE NOT NULL,
  hora TIME,
  direccion VARCHAR(255),
  telefono VARCHAR(20),
  estado ENUM('pendiente','cumplida','incumplida','cancelada') DEFAULT 'pendiente',
  observaciones TEXT,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id_cliente) ON DELETE RESTRICT,
  INDEX idx_usuario (usuario_id),
  INDEX idx_cliente (cliente_id),
  INDEX idx_fecha (fecha, hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: indicadores_financieros
-- Descripción: Indicadores financieros de clientes
-- ============================================================
DROP TABLE IF EXISTS indicadores_financieros;
CREATE TABLE indicadores_financieros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  capacidad_pago DECIMAL(12,2),
  patrimonio DECIMAL(12,2),
  liquidez DECIMAL(5,2),
  margen DECIMAL(5,2),
  rotacion_inventarios DECIMAL(5,2),
  endeudamiento DECIMAL(5,2),
  fecha_calculo TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id_cliente) ON DELETE CASCADE,
  INDEX idx_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: alertas
-- Descripción: Alertas del sistema para monitoreo
-- ============================================================
DROP TABLE IF EXISTS alertas;
CREATE TABLE alertas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT,
  cliente_id INT,
  tipo_alerta VARCHAR(100) NOT NULL,
  descripcion TEXT,
  severidad ENUM('baja','media','alta','critica') DEFAULT 'media',
  estado ENUM('abierta','en_proceso','cerrada') DEFAULT 'abierta',
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  fecha_cierre DATETIME,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id_cliente) ON DELETE SET NULL,
  INDEX idx_estado (estado),
  INDEX idx_fecha (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATOS INICIALES
-- ============================================================

-- Insertar Roles
INSERT INTO roles (nombre, descripcion, nivel_acceso) VALUES
('SuperAdmin', 'Acceso total al sistema', 100),
('Admin', 'Administración de usuarios y reportes', 80),
('Supervisor', 'Supervisión de asesores y clientes', 50),
('Asesor', 'Gestión de clientes', 10);

-- Insertar Regiones
INSERT INTO region (pais, provincia, ciudad, canton) VALUES
('Ecuador', 'Pichincha', 'Quito', 'Quito'),
('Ecuador', 'Pichincha', 'Cayambe', 'Cayambe'),
('Ecuador', 'Tungurahua', 'Ambato', 'Ambato'),
('Ecuador', 'Guayas', 'Guayaquil', 'Guayaquil'),
('Ecuador', 'Guayas', 'Salinas', 'Salinas'),
('Ecuador', 'Manabí', 'Manta', 'Manta'),
('Ecuador', 'Imbabura', 'Ibarra', 'Ibarra'),
('Ecuador', 'Pastaza', 'Puyo', 'Puyo');

-- Insertar Cooperativa
INSERT INTO cooperativa (nombre, fecha_crea, activo) VALUES
('COAC Matriz', NOW(), 1),
('COAC Sucursal Quito', NOW(), 1),
('COAC Sucursal Guayaquil', NOW(), 1);

-- Insertar Empresa
INSERT INTO empresa (nombre, nombre_cooperativa, id_cooperativa_fk, cuenta_estado, cdp, fecha_fin_cdp, activo) VALUES
('Microfinanzas COAC', 'COAC Matriz', 1, 'activo', 'CDP-001', '2026-12-31', 1),
('Créditos Personales COAC', 'COAC Sucursal Quito', 2, 'activo', 'CDP-002', '2026-12-31', 1),
('Inversiones Comerciales', 'COAC Sucursal Guayaquil', 3, 'activo', 'CDP-003', '2026-12-31', 1);

-- Insertar Actividades de Cliente
INSERT INTO actividad_cliente (nombre_actividad, id_empresa_fk) VALUES
('Comercio Minorista', 1),
('Servicios Profesionales', 1),
('Producción Artesanal', 2),
('Transporte', 2),
('Agricultura', 3),
('Construcción', 3);

-- Insertar Usuario Super Admin
INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, ciudad, provincia, canton, activo, id_rol_fk) VALUES
('admin', SHA2('admin123', 256), 'Administrador', 'Sistema', 'admin@coac.local', '0999999999', 'Quito', 'Pichincha', 'Quito', 1, 1);

-- Insertar Admin
INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, ciudad, provincia, canton, activo, id_rol_fk) VALUES
('adm_quito', SHA2('admin456', 256), 'Admin', 'Regional', 'admin@quito.coac.local', '0998888888', 'Quito', 'Pichincha', 'Quito', 1, 2);

-- Insertar Supervisores
INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, ciudad, provincia, canton, activo, id_rol_fk) VALUES
('supervisor_q1', SHA2('supervisor123', 256), 'Marco', 'Chávez', 'supervisor@quito.coac.local', '0997777777', 'Quito', 'Pichincha', 'Quito', 1, 3),
('supervisor_g1', SHA2('supervisor123', 256), 'Jessica', 'Ramírez', 'supervisor@guayaquil.coac.local', '0996666666', 'Guayaquil', 'Guayas', 'Guayaquil', 1, 3);

-- Insertar Asesores
INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, ciudad, provincia, canton, activo, id_rol_fk) VALUES
('asesor_q1', SHA2('asesor123', 256), 'Carlos', 'Mendoza', 'carlos@coac.local', '0995555555', 'Quito', 'Pichincha', 'Quito', 1, 4),
('asesor_q2', SHA2('asesor123', 256), 'Patricia', 'González', 'patricia@coac.local', '0994444444', 'Quito', 'Pichincha', 'Quito', 1, 4),
('asesor_g1', SHA2('asesor123', 256), 'Roberto', 'Flores', 'roberto@coac.local', '0993333333', 'Guayaquil', 'Guayas', 'Guayaquil', 1, 4),
('asesor_g2', SHA2('asesor123', 256), 'Diana', 'López', 'diana@coac.local', '0992222222', 'Guayaquil', 'Guayas', 'Guayaquil', 1, 4);

-- Insertar Clientes de Ejemplo
INSERT INTO clientes (nombre, apellidos, email, telefono, direccion, ruc_rise, id_region_fk, id_activ_cliente_fk, activo) VALUES
('Juan', 'Pérez García', 'juan.perez@email.com', '0991111111', 'Av. Principal 123', '1710123456001', 1, 1, 1),
('María', 'López Jiménez', 'maria.lopez@email.com', '0992111111', 'Calle Secundaria 456', '1710654321001', 1, 2, 1),
('Roberto', 'Martínez Soto', 'roberto.martinez@email.com', '0993111111', 'Av. Comercial 789', '1710555666001', 4, 1, 1),
('Ana', 'Rodríguez García', 'ana.rodriguez@email.com', '0994111111', 'Calle Industrial 321', '1710777888001', 4, 3, 1),
('Luis', 'Fernández González', 'luis.fernandez@email.com', '0995111111', 'Av. Industrial 654', '1710999000001', 3, 4, 1);

-- Insertar Operaciones de Crédito
INSERT INTO operacion_credito (cliente_id, seleccion_cred, cantidad, destino_cred, tipo_credito, tipo_interes, fecha_fin_creado, estado) VALUES
(1, 'Programa A', 5000.00, 'Capital de Trabajo', 'microempresa', 'bajo', '2026-04-15', 'aprobado'),
(2, 'Programa B', 3000.00, 'Equipamiento', 'comercial', 'bajo', '2026-05-20', 'aprobado'),
(3, 'Programa C', 10000.00, 'Expansión', 'comercial', 'alto', '2026-06-30', 'pendiente'),
(4, 'Programa A', 2000.00, 'Consumo', 'consumo', 'ninguno', '2026-04-30', 'aprobado'),
(5, 'Programa D', 7500.00, 'Producción', 'microempresa', 'bajo', '2026-07-15', 'completado');

-- Insertar Activos
INSERT INTO activos (cliente_id, tipo, descripcion, marca, modelo, valor) VALUES
(1, 'vehiculo', 'Camioneta para reparto', 'Toyota', 'Hilux 2022', 35000.00),
(1, 'equipo', 'Refrigerador comercial', 'Samsung', 'RS25', 8000.00),
(2, 'inmueble', 'Local comercial', '', '', 50000.00),
(3, 'vehiculo', 'Automóvil', 'Chevrolet', 'Spark 2020', 18000.00),
(4, 'equipo', 'Máquina de coser industrial', 'Singer', 'Industrial', 2500.00);

-- Insertar Pasivos
INSERT INTO pasivos (cliente_id, institucion, destino, monto_inicial, saldo_actual, pago_mes, tipo, estado) VALUES
(1, 'Banco Pichincha', 'Crédito Comercial', 40000.00, 32000.00, 2000.00, 'credito', 'vigente'),
(2, 'Banco del Pacífico', 'Tarjeta de Crédito', 15000.00, 8500.00, 850.00, 'tarjeta', 'vigente'),
(3, 'Mutualista Pichincha', 'Préstamo Personal', 30000.00, 25000.00, 1500.00, 'prestamo', 'vigente'),
(4, 'Cooperativa Pichincha', 'Crédito de Consumo', 5000.00, 3000.00, 250.00, 'credito', 'vigente'),
(5, 'Banco Estratégico', 'Línea de Crédito', 50000.00, 40000.00, 2500.00, 'credito', 'mora');

-- Insertar Gestiones de Cobranza
INSERT INTO gestiones_cobranza (credito_id, usuario_id, cliente_id, tipo, fecha, hora, resultado, observaciones) VALUES
(1, 5, 1, 'preventiva', '2026-04-08', '09:00:00', 'contactado', 'Cliente confirmó pago a tiempo'),
(2, 6, 2, 'preventiva', '2026-04-07', '14:30:00', 'compromiso', 'Compromiso de pago para siguiente semana'),
(3, 5, 3, 'recuperacion', '2026-04-06', '10:15:00', 'no_localizado', 'Domicilio no encontrado'),
(4, 7, 4, 'preventiva', '2026-04-08', '11:00:00', 'contactado', 'Cliente pagó a tiempo'),
(5, 8, 5, 'contension', '2026-04-05', '15:45:00', 'no_paga', 'Cliente en dificultades financieras');

-- Insertar Agenda
INSERT INTO agenda (usuario_id, cliente_id, tipo, fecha, hora, direccion, telefono, estado) VALUES
(5, 1, 'evaluacion', '2026-04-15', '09:00:00', 'Av. Principal 123', '0991111111', 'pendiente'),
(6, 2, 'seguimiento', '2026-04-16', '14:00:00', 'Calle Secundaria 456', '0992111111', 'pendiente'),
(5, 3, 'recuperacion', '2026-04-17', '10:30:00', 'Av. Comercial 789', '0993111111', 'pendiente'),
(7, 4, 'documentacion', '2026-04-18', '15:00:00', 'Calle Industrial 321', '0994111111', 'pendiente'),
(8, 5, 'promocion', '2026-04-20', '11:00:00', 'Av. Industrial 654', '0995111111', 'pendiente');

-- Insertar Indicadores Financieros
INSERT INTO indicadores_financieros (cliente_id, capacidad_pago, patrimonio, liquidez, margen, rotacion_inventarios) VALUES
(1, 8000.00, 60000.00, 1.5, 25.00, 12.00),
(2, 5000.00, 45000.00, 1.8, 30.00, 15.00),
(3, 12000.00, 120000.00, 2.0, 22.00, 10.00),
(4, 3000.00, 25000.00, 1.2, 35.00, 18.00),
(5, 7000.00, 75000.00, 1.6, 28.00, 14.00);

-- Insertar Alertas
INSERT INTO alertas (usuario_id, cliente_id, tipo_alerta, descripcion, severidad, estado) VALUES
(5, 1, 'Pago Próximo', 'Cliente Juan Pérez tiene pago vencido en 3 días', 'media', 'abierta'),
(6, 2, 'Límite de Crédito', 'María López está próxima al límite de crédito', 'alta', 'abierta'),
(7, 5, 'Mora Crítica', 'Luis Fernández se encuentra en mora de 45 días', 'critica', 'en_proceso');

-- ============================================================
-- ÍNDICES ADICIONALES
-- ============================================================
ALTER TABLE usuarios ADD INDEX idx_usuario_email (email);
ALTER TABLE usuarios ADD INDEX idx_usuario_activo (activo);
ALTER TABLE clientes ADD INDEX idx_cliente_nombre (nombre, apellidos);
ALTER TABLE clientes ADD INDEX idx_cliente_activo (activo);
ALTER TABLE operacion_credito ADD INDEX idx_credito_tipo (tipo_credito);
ALTER TABLE operacion_credito ADD INDEX idx_credito_estado (estado);
ALTER TABLE gestiones_cobranza ADD INDEX idx_gestion_fecha (fecha);
ALTER TABLE activos ADD INDEX idx_activo_tipo (tipo);
ALTER TABLE pasivos ADD INDEX idx_pasivo_institucion (institucion);
ALTER TABLE pasivos ADD INDEX idx_pasivo_estado (estado);
ALTER TABLE agenda ADD INDEX idx_agenda_fecha (fecha, hora);
ALTER TABLE agenda ADD INDEX idx_agenda_estado (estado);
ALTER TABLE indicadores_financieros ADD INDEX idx_indicador_cliente (cliente_id);
ALTER TABLE alertas ADD INDEX idx_alerta_severidad (severidad);

-- ============================================================
-- FIN DEL SCRIPT
-- ============================================================

-- Habilitar verificación de claves foráneas nuevamente
SET FOREIGN_KEY_CHECKS=1;

COMMIT;
