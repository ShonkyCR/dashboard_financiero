-- =========================================================
-- Base de datos: finanzas_dashboard
-- Importar este archivo desde phpMyAdmin o desde la consola:
--   mysql -u root -p < schema.sql
--
-- Moneda: dólares (USD) — mercado objetivo EE. UU.
-- Rubro ejemplo: remodelación, construcción y limpieza.
--
-- NOTA: la tabla `usuarios` se crea aquí vacía; el sistema crea los
-- usuarios de ejemplo (dueno@demo.com / super@demo.com) automáticamente
-- en la primera visita a login.php.
-- =========================================================

CREATE DATABASE IF NOT EXISTS finanzas_dashboard
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE finanzas_dashboard;

DROP TABLE IF EXISTS transacciones;
DROP TABLE IF EXISTS facturas;
DROP TABLE IF EXISTS colaboradores;
DROP TABLE IF EXISTS contactos;
DROP TABLE IF EXISTS configuracion;
DROP TABLE IF EXISTS usuarios;

CREATE TABLE transacciones (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tipo               ENUM('ingreso', 'gasto') NOT NULL,
    monto              DECIMAL(12,2) NOT NULL,
    categoria          VARCHAR(60) NOT NULL,
    descripcion        VARCHAR(255) DEFAULT NULL,
    fecha              DATE NOT NULL,
    contacto_id        INT          DEFAULT NULL,
    origen             ENUM('manual', 'planilla') NOT NULL DEFAULT 'manual',
    colaborador_id     INT          DEFAULT NULL,
    frecuencia_planilla ENUM('mes', 'quincena', 'semana', 'hora') DEFAULT NULL,
    creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================================
-- Usuarios (roles). Las filas las crea login.php automáticamente.
-- =========================================================
CREATE TABLE usuarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol           ENUM('dueno','superadmin') NOT NULL DEFAULT 'dueno',
    activo        TINYINT(1) NOT NULL DEFAULT 1,
    creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB;

-- =========================================================
-- Facturas (con vinculación a directorio y a su ingreso)
-- =========================================================
CREATE TABLE facturas (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    numero             VARCHAR(30)  NOT NULL,
    cliente            VARCHAR(120) NOT NULL,
    contacto_id        INT          DEFAULT NULL,
    transaccion_id     INT          DEFAULT NULL,
    monto              DECIMAL(12,2) NOT NULL,
    estado             ENUM('pendiente', 'pagada', 'vencida') NOT NULL DEFAULT 'pendiente',
    fecha_emision      DATE NOT NULL,
    fecha_vencimiento  DATE DEFAULT NULL,
    descripcion        VARCHAR(255) DEFAULT NULL,
    creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================================
-- Colaboradores (planilla)
-- =========================================================
CREATE TABLE colaboradores (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(120) NOT NULL,
    puesto      VARCHAR(80)  DEFAULT NULL,
    tipo_pago   ENUM('hora', 'semana', 'quincena', 'mes') NOT NULL DEFAULT 'mes',
    tarifa      DECIMAL(12,2) NOT NULL DEFAULT 0,
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    creado_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================================
-- Contactos (clientes y proveedores)
-- =========================================================
CREATE TABLE contactos (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tipo               ENUM('cliente', 'proveedor') NOT NULL,
    nombre             VARCHAR(120) NOT NULL,
    telefono           VARCHAR(40)  DEFAULT NULL,
    email              VARCHAR(120) DEFAULT NULL,
    gasto_periodico    TINYINT(1) NOT NULL DEFAULT 0,
    monto_periodico    DECIMAL(12,2) DEFAULT NULL,
    frecuencia         ENUM('semanal', 'quincenal', 'mensual') DEFAULT NULL,
    notas              VARCHAR(255) DEFAULT NULL,
    proyecto_finalizado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================================
-- Configuración del negocio
-- =========================================================
CREATE TABLE configuracion (
    id                 INT PRIMARY KEY DEFAULT 1,
    nombre_negocio     VARCHAR(120) DEFAULT 'Mi Negocio',
    nombre_admin       VARCHAR(120) DEFAULT NULL,
    email_admin        VARCHAR(120) DEFAULT NULL,
    telefono_admin     VARCHAR(40)  DEFAULT NULL,
    tema               ENUM('claro', 'oscuro') NOT NULL DEFAULT 'claro',
    logo               VARCHAR(255) DEFAULT NULL,
    imagen_dashboard   VARCHAR(255) DEFAULT NULL,
    categorias_ingreso TEXT DEFAULT NULL,
    categorias_gasto   TEXT DEFAULT NULL
) ENGINE=InnoDB;

INSERT INTO configuracion (id, nombre_negocio, nombre_admin) VALUES (1, 'Remodelaciones Rivera', 'Dueño Demo');
