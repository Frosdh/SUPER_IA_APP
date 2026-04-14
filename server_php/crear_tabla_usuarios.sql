-- ============================================================
-- Script SQL para crear la base de datos y tabla de usuarios
-- Ejecutar este script en phpMyAdmin o MySQL Workbench
-- ============================================================

-- 1. Crear la base de datos (si no existe)
CREATE DATABASE IF NOT EXISTS fuber_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE fuber_db;

-- 2. Crear la tabla de usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(100)  NOT NULL DEFAULT 'Cliente',
    telefono        VARCHAR(20)   NOT NULL UNIQUE,
    password        VARCHAR(255)  NOT NULL,
    fecha_registro  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    activo          TINYINT(1)    DEFAULT 1
);

-- 3. Verificar que se creo correctamente
DESCRIBE usuarios;
