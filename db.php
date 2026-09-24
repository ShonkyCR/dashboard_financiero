<?php
/**
 * db.php
 * Conexión centralizada a la base de datos usando PDO (Adaptable Local / Producción).
 */

declare(strict_types=1);

// Detecta si estás en tu entorno local (XAMPP) o en el servidor real (InfinityFree)
$esLocal = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1');

if ($esLocal) {
    // Configuración para XAMPP
    define('DB_HOST', '127.0.0.1;port=3307');
    define('DB_NAME', 'finanzas_dashboard');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // Configuración para InfinityFree
    define('DB_HOST', 'sql207.infinityfree.com');
    define('DB_NAME', 'if0_42994515_finanzas');
    define('DB_USER', 'if0_42994515');
    define('DB_PASS', 'tuanis2020'); // contraseña de InfinityFree
}

// Moneda del negocio. El mercado objetivo es EE. UU.: dólares (USD).
const MONEDA = '$';

function conectarDB(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $opciones = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        die('No se pudo conectar a la base de datos. Detalle: ' . htmlspecialchars($e->getMessage()));
    }
}