-- ============================================================
-- DATABASE MONITOREO COMERCIAL - Sistema de Gestión y Monitoreo
-- ============================================================
-- Autor: Sistema de Monitoreo
-- Fecha: 2026-04-08
-- Descripción: Base de datos para aplicación de monitoreo comercial
-- Roles: Super Admin, Admin, Supervisor, Asesor
-- ============================================================

-- Usar base de datos existente
USE corporat_radix_copia;

-- ============================================================
-- TABLA: roles
-- Descripción: Roles disponibles en el sistema
-- ============================================================
CREATE TABLE IF NOT EXISTS roles (
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(255),
    nivel_acceso INT DEFAULT 0,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: regiones
-- Descripción: Regiones geográficas del país
-- ============================================================
CREATE TABLE IF NOT EXISTS regiones (
    id_region INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    codigo VARCHAR(10),
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: provincias
-- Descripción: Provincias dentro de cada región
-- ============================================================
CREATE TABLE IF NOT EXISTS provincias (
    id_provincia INT AUTO_INCREMENT PRIMARY KEY,
    id_region INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    codigo VARCHAR(10),
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_region) REFERENCES regiones(id_region) ON DELETE RESTRICT,
    UNIQUE KEY unique_provincia_region (id_region, nombre),
    INDEX idx_region (id_region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: ciudades
-- Descripción: Ciudades dentro de cada provincia
-- ============================================================
CREATE TABLE IF NOT EXISTS ciudades (
    id_ciudad INT AUTO_INCREMENT PRIMARY KEY,
    id_provincia INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    codigo VARCHAR(10),
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_provincia) REFERENCES provincias(id_provincia) ON DELETE RESTRICT,
    UNIQUE KEY unique_ciudad_provincia (id_provincia, nombre),
    INDEX idx_provincia (id_provincia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: usuarios
-- Descripción: Usuarios del sistema (Super Admin, Admin, Supervisor, Asesor)
-- ============================================================
CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    telefono VARCHAR(20),
    id_rol INT NOT NULL,
    id_region INT,
    id_provincia INT,
    id_ciudad INT,
    zona_trabajo VARCHAR(100),
    agencia VARCHAR(100),
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ultimo_acceso DATETIME,
    FOREIGN KEY (id_rol) REFERENCES roles(id_rol) ON DELETE RESTRICT,
    FOREIGN KEY (id_region) REFERENCES regiones(id_region) ON DELETE SET NULL,
    FOREIGN KEY (id_provincia) REFERENCES provincias(id_provincia) ON DELETE SET NULL,
    FOREIGN KEY (id_ciudad) REFERENCES ciudades(id_ciudad) ON DELETE SET NULL,
    INDEX idx_rol (id_rol),
    INDEX idx_usuario (usuario),
    INDEX idx_email (email),
    INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: clientes
-- Descripción: Clientes que serán monitoreados
-- ============================================================
CREATE TABLE IF NOT EXISTS clientes (
    id_cliente INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    apellidos VARCHAR(150),
    cedula VARCHAR(20) UNIQUE,
    email VARCHAR(100),
    telefono VARCHAR(20),
    telefonos_adicionales TEXT,
    direccion VARCHAR(255),
    id_region INT,
    id_provincia INT,
    id_ciudad INT,
    canton VARCHAR(100),
    referencias_ubicacion TEXT,
    latitud DECIMAL(10, 8),
    longitud DECIMAL(11, 8),
    tipo_cliente ENUM('persona_natural', 'negocio_propio', 'empleado_privado', 'empleado_publico', 'profesional') DEFAULT 'persona_natural',
    ruc_rise VARCHAR(20),
    empresa VARCHAR(150),
    actividad VARCHAR(200),
    fecha_contacto DATE,
    interés_institucion TINYINT(1) DEFAULT 0,
    observaciones TEXT,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_region) REFERENCES regiones(id_region) ON DELETE SET NULL,
    FOREIGN KEY (id_provincia) REFERENCES provincias(id_provincia) ON DELETE SET NULL,
    FOREIGN KEY (id_ciudad) REFERENCES ciudades(id_ciudad) ON DELETE SET NULL,
    INDEX idx_cedula (cedula),
    INDEX idx_email (email),
    INDEX idx_telefono (telefono),
    INDEX idx_activo (activo),
    INDEX idx_ubicacion (id_region, id_provincia, id_ciudad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: asignacion_asesor_cliente
-- Descripción: Relación entre asesores y los clientes que atienden
-- ============================================================
CREATE TABLE IF NOT EXISTS asignacion_asesor_cliente (
    id_asignacion INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_cliente INT NOT NULL,
    fecha_asignacion DATE NOT NULL,
    fecha_inicio DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_fin DATETIME,
    estado ENUM('activo', 'inactivo', 'finalizado') DEFAULT 'activo',
    prioridad ENUM('baja', 'media', 'alta') DEFAULT 'media',
    observaciones TEXT,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE RESTRICT,
    UNIQUE KEY unique_asesor_cliente_activo (id_usuario, id_cliente, estado),
    INDEX idx_usuario (id_usuario),
    INDEX idx_cliente (id_cliente),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: lugares_interes
-- Descripción: Puntos de interés, puntos de venta o sucursales
-- ============================================================
CREATE TABLE IF NOT EXISTS lugares_interes (
    id_lugar INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    tipo ENUM('sucursal', 'punto_venta', 'bodega', 'oficina', 'otro') DEFAULT 'sucursal',
    direccion VARCHAR(255),
    id_ciudad INT,
    latitud DECIMAL(10, 8),
    longitud DECIMAL(11, 8),
    telefono VARCHAR(20),
    email VARCHAR(100),
    horario_apertura TIME,
    horario_cierre TIME,
    dias_operacion VARCHAR(50),
    responsable VARCHAR(100),
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_ciudad) REFERENCES ciudades(id_ciudad) ON DELETE SET NULL,
    INDEX idx_ciudad (id_ciudad),
    INDEX idx_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: productos_servicios
-- Descripción: Catálogo de productos y servicios ofrecidos
-- ============================================================
CREATE TABLE IF NOT EXISTS productos_servicios (
    id_producto INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,
    tipo ENUM('producto', 'servicio', 'credito') DEFAULT 'producto',
    categoria VARCHAR(100),
    precio_base DECIMAL(12, 2),
    margen_ganancia DECIMAL(5, 2),
    stock INT DEFAULT 0,
    requiere_aprobacion TINYINT(1) DEFAULT 0,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tipo (tipo),
    INDEX idx_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: gestiones_cliente
-- Descripción: Registro de todas las gestiones/actividades realizadas con clientes
-- ============================================================
CREATE TABLE IF NOT EXISTS gestiones_cliente (
    id_gestion INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    id_usuario INT NOT NULL,
    fecha_gestion DATE NOT NULL,
    hora_gestion TIME,
    tipo_gestion VARCHAR(100) NOT NULL,
    resultado ENUM('exitoso', 'no_exitoso', 'pendiente', 'no_contacto', 'rechazado') DEFAULT 'pendiente',
    descripcion TEXT,
    compromiso TEXT,
    fecha_compromiso DATE,
    observaciones TEXT,
    duracion_minutos INT,
    ubicacion_gestion VARCHAR(255),
    latitud DECIMAL(10, 8),
    longitud DECIMAL(11, 8),
    archivo_adjunto VARCHAR(255),
    seguimiento_necesario TINYINT(1) DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE RESTRICT,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
    INDEX idx_cliente (id_cliente),
    INDEX idx_usuario (id_usuario),
    INDEX idx_fecha_gestion (fecha_gestion),
    INDEX idx_resultado (resultado),
    INDEX idx_tipo (tipo_gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: documentos_cliente
-- Descripción: Información financiera y documentos del cliente
-- ============================================================
CREATE TABLE IF NOT EXISTS documentos_cliente (
    id_documento INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    tipo_documento VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255),
    ruta_archivo VARCHAR(255),
    fecha_documento DATE,
    fecha_vencimiento DATE,
    estado ENUM('vigente', 'vencido', 'cancelado', 'archivado') DEFAULT 'vigente',
    observaciones TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE CASCADE,
    INDEX idx_cliente (id_cliente),
    INDEX idx_tipo (tipo_documento),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: activos_cliente
-- Descripción: Activos reportados por el cliente
-- ============================================================
CREATE TABLE IF NOT EXISTS activos_cliente (
    id_activo INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    descripcion VARCHAR(100),
    marca VARCHAR(50),
    modelo VARCHAR(50),
    serie VARCHAR(50),
    valor DECIMAL(12, 2),
    estado_fisico ENUM('excelente', 'bueno', 'regular', 'malo', 'deteriorado') DEFAULT 'bueno',
    fecha_adquisicion DATE,
    observaciones TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE CASCADE,
    INDEX idx_cliente (id_cliente),
    INDEX idx_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: pasivos_cliente
-- Descripción: Deudas y pasivos del cliente
-- ============================================================
CREATE TABLE IF NOT EXISTS pasivos_cliente (
    id_pasivo INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    institucion VARCHAR(100) NOT NULL,
    destino VARCHAR(100),
    monto_inicial DECIMAL(12, 2),
    plazo INT,
    cuotas_pagadas INT DEFAULT 0,
    saldo_actual DECIMAL(12, 2),
    pago_mes DECIMAL(12, 2),
    estado ENUM('vigente', 'vencido', 'cancelado', 'en_mora') DEFAULT 'vigente',
    dias_mora INT DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE CASCADE,
    INDEX idx_cliente (id_cliente),
    INDEX idx_institucion (institucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: operaciones_cliente
-- Descripción: Operaciones comerciales, transacciones y créditos
-- ============================================================
CREATE TABLE IF NOT EXISTS operaciones_cliente (
    id_operacion INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    id_producto INT,
    tipo_operacion ENUM('venta', 'credito', 'servicio', 'consulta') DEFAULT 'venta',
    monto DECIMAL(12, 2) NOT NULL,
    estado ENUM('pendiente', 'aprobado', 'rechazado', 'completado', 'cancelado') DEFAULT 'pendiente',
    descripcion TEXT,
    fecha_operacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_vencimiento DATE,
    cumplimiento_pago DECIMAL(5, 2) DEFAULT 0,
    observaciones TEXT,
    aprobado_por INT,
    fecha_aprobacion DATETIME,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE RESTRICT,
    FOREIGN KEY (id_producto) REFERENCES productos_servicios(id_producto) ON DELETE SET NULL,
    FOREIGN KEY (aprobado_por) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
    INDEX idx_cliente (id_cliente),
    INDEX idx_estado (estado),
    INDEX idx_fecha (fecha_operacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: reportes_desempenio
-- Descripción: Métricas y reportes de desempeño de asesores
-- ============================================================
CREATE TABLE IF NOT EXISTS reportes_desempenio (
    id_reporte INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_supervisor INT,
    fecha_reporte DATE NOT NULL,
    periodo ENUM('diario', 'semanal', 'mensual') DEFAULT 'diario',
    clientes_asignados INT DEFAULT 0,
    clientes_contactados INT DEFAULT 0,
    gestiones_realizadas INT DEFAULT 0,
    tasa_exito DECIMAL(5, 2) DEFAULT 0,
    monto_operaciones DECIMAL(12, 2) DEFAULT 0,
    operaciones_aprobadas INT DEFAULT 0,
    observaciones TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
    FOREIGN KEY (id_supervisor) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
    INDEX idx_usuario (id_usuario),
    INDEX idx_fecha (fecha_reporte),
    INDEX idx_supervisor (id_supervisor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: alertas_monitoreo
-- Descripción: Alertas generadas por el sistema para seguimiento
-- ============================================================
CREATE TABLE IF NOT EXISTS alertas_monitoreo (
    id_alerta INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT,
    id_cliente INT,
    tipo_alerta VARCHAR(100) NOT NULL,
    severidad ENUM('baja', 'media', 'alta', 'critica') DEFAULT 'media',
    descripcion TEXT,
    estado ENUM('abierta', 'en_proceso', 'cerrada') DEFAULT 'abierta',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fecha_cierre DATETIME,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE SET NULL,
    INDEX idx_estado (estado),
    INDEX idx_severidad (severidad),
    INDEX idx_fecha (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: auditoria
-- Descripción: Registro de auditoría de cambios importantes
-- ============================================================
CREATE TABLE IF NOT EXISTS auditoria (
    id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    tabla_afectada VARCHAR(100),
    id_registro INT,
    tipo_cambio ENUM('crear', 'actualizar', 'eliminar') DEFAULT 'actualizar',
    valores_anteriores JSON,
    valores_nuevos JSON,
    descripcion TEXT,
    ip_origen VARCHAR(45),
    fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
    INDEX idx_usuario (id_usuario),
    INDEX idx_tabla (tabla_afectada),
    INDEX idx_fecha (fecha_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATOS INICIALES
-- ============================================================

-- Insertar roles
INSERT INTO roles (nombre, descripcion, nivel_acceso) VALUES
('SuperAdmin', 'Acceso total al sistema', 100),
('Admin', 'Administración de usuarios y reportes', 80),
('Supervisor', 'Supervisión de asesores y clientes', 50),
('Asesor', 'Gestión de clientes', 10);

-- Insertar Regiones del Ecuador (ejemplo)
INSERT INTO regiones (nombre, codigo) VALUES
('Región Sierra', 'SIERRA'),
('Región Costa', 'COSTA'),
('Región Oriente', 'ORIENTE'),
('Región Insular', 'INSULAR');

-- Insertar provincias de ejemplo (Sierra)
INSERT INTO provincias (id_region, nombre, codigo) VALUES
(1, 'Pichincha', 'PIC'),
(1, 'Tungurahua', 'TUN'),
(1, 'Imbabura', 'IMB'),
(2, 'Guayas', 'GUA'),
(2, 'Manabí', 'MAN'),
(3, 'Pastaza', 'PAS');

-- Insertar ciudades de ejemplo
INSERT INTO ciudades (id_provincia, nombre, codigo) VALUES
(1, 'Quito', 'QTO'),
(1, 'Cayambe', 'CAY'),
(2, 'Ambato', 'AMB'),
(4, 'Guayaquil', 'GYE'),
(4, 'Salinas', 'SAL'),
(5, 'Manta', 'MTA');

-- Crear usuario Super Admin inicial (cambiar contraseña después)
INSERT INTO usuarios (usuario, password, nombres, apellidos, email, id_rol, id_ciudad, activo) VALUES
('admin', SHA2('admin123', 256), 'Administrador', 'Sistema', 'admin@monitoreo.local', 1, 1, 1);

-- ============================================================
-- Fin del script SQL
-- ============================================================
