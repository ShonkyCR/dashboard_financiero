-- =========================================================
-- Migración v2 — para instalaciones que ya tenían datos
-- (si empiezas de cero, importa schema.sql en su lugar)
--
-- Agrega: tabla usuarios, columnas de vinculación en facturas,
-- y estado del proyecto en contactos (proyecto_finalizado).
-- Los usuarios de ejemplo los crea el sistema automáticamente
-- en la primera visita a login.php.
-- =========================================================



CREATE TABLE IF NOT EXISTS usuarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol           ENUM('dueno','superadmin') NOT NULL DEFAULT 'dueno',
    activo        TINYINT(1) NOT NULL DEFAULT 1,
    creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB;

-- Estas dos líneas fallan (y se ignoran) si las columnas ya existen:
ALTER TABLE facturas ADD COLUMN contacto_id INT DEFAULT NULL;
ALTER TABLE facturas ADD COLUMN transaccion_id INT DEFAULT NULL;

-- Columna para el logo del negocio, portada del dashboard y categorías personalizadas:
ALTER TABLE configuracion ADD COLUMN logo VARCHAR(255) DEFAULT NULL;
ALTER TABLE configuracion ADD COLUMN imagen_dashboard VARCHAR(255) DEFAULT NULL;
ALTER TABLE configuracion ADD COLUMN categorias_ingreso TEXT DEFAULT NULL;
ALTER TABLE configuracion ADD COLUMN categorias_gasto TEXT DEFAULT NULL;

-- Vincular gastos/ingresos a un cliente del directorio (reportes por cliente):
ALTER TABLE transacciones ADD COLUMN contacto_id INT DEFAULT NULL;

-- Pagos de planilla materializados como gastos ( Nómina ) en reportes:
ALTER TABLE transacciones ADD COLUMN origen ENUM('manual', 'planilla') NOT NULL DEFAULT 'manual';
ALTER TABLE transacciones ADD COLUMN colaborador_id INT DEFAULT NULL;
ALTER TABLE transacciones MODIFY COLUMN frecuencia_planilla ENUM('mes', 'quincena', 'semana', 'hora') DEFAULT NULL;
ALTER TABLE colaboradores MODIFY COLUMN tipo_pago ENUM('hora', 'semana', 'quincena', 'mes') NOT NULL DEFAULT 'mes';

-- Estado del proyecto del cliente (activa / finalizada — sale del filtro de reportes):
ALTER TABLE contactos ADD COLUMN proyecto_finalizado TINYINT(1) NOT NULL DEFAULT 0;

-- ---------------------------------------------------------
-- Módulo de Nómina Semanal (Estándar USA)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS nominas_semanales (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id         INT NOT NULL DEFAULT 1,
    periodo_inicio     DATE NOT NULL,
    periodo_fin        DATE NOT NULL,
    fecha_pago         DATE NOT NULL,
    estado             ENUM('borrador', 'confirmada') NOT NULL DEFAULT 'borrador',
    total              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notas              VARCHAR(255) DEFAULT NULL,
    actualizado_por    VARCHAR(120) DEFAULT NULL,
    creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_empresa_periodo (empresa_id, periodo_inicio, periodo_fin),
    INDEX idx_fecha_pago (fecha_pago)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nomina_detalles (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    nomina_id          INT NOT NULL,
    colaborador_id     INT DEFAULT NULL,
    nombre_colaborador VARCHAR(120) NOT NULL,
    monto              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sin_pago           TINYINT(1) NOT NULL DEFAULT 0,
    notas              VARCHAR(255) DEFAULT NULL,
    creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nomina (nomina_id),
    INDEX idx_colaborador (colaborador_id)
) ENGINE=InnoDB;

ALTER TABLE transacciones ADD COLUMN nomina_id INT DEFAULT NULL;

-- ---------------------------------------------------------
-- IMPORTANTE si tu base todavía tiene montos en colones (₡):
-- la app ahora muestra dólares ($). Puedes convertir los montos
-- existentes (ejemplo a ~520₡ por USD):
--
--   UPDATE transacciones SET monto = ROUND(monto / 520, 2);
--   UPDATE facturas      SET monto = ROUND(monto / 520, 2);
--
-- O reimporta sql/schema.sql desde cero si solo eran datos de prueba.
-- ---------------------------------------------------------
