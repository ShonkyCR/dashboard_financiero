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

-- Columna para el logo del negocio (se sube desde Configuración):
ALTER TABLE configuracion ADD COLUMN logo VARCHAR(255) DEFAULT NULL;

-- Vincular gastos/ingresos a un cliente del directorio (reportes por cliente):
ALTER TABLE transacciones ADD COLUMN contacto_id INT DEFAULT NULL;

-- Pagos de planilla materializados como gastos ( Nómina ) en reportes:
ALTER TABLE transacciones ADD COLUMN origen ENUM('manual', 'planilla') NOT NULL DEFAULT 'manual';
ALTER TABLE transacciones ADD COLUMN colaborador_id INT DEFAULT NULL;
ALTER TABLE transacciones ADD COLUMN frecuencia_planilla ENUM('mes', 'quincena', 'hora') DEFAULT NULL;

-- Estado del proyecto del cliente (activa / finalizada — sale del filtro de reportes):
ALTER TABLE contactos ADD COLUMN proyecto_finalizado TINYINT(1) NOT NULL DEFAULT 0;

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
