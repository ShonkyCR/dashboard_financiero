<?php
/**
 * includes/auth.php
 * Sesión, roles (dueno / superadmin) y guardas de acceso.
 *
 * Roles:
 *  - dueno      → ve el Resumen y una lista básica de sus movimientos.
 *  - superadmin → además ve el Detalle de auditoría y el módulo
 *                 "Auditoría y capacitación" (análisis para exponerle
 *                 al dueño sus falencias y buenas prácticas).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function usuarioActual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function estaLogueado(): bool
{
    return isset($_SESSION['usuario']);
}

function esSuperadmin(): bool
{
    return (usuarioActual()['rol'] ?? '') === 'superadmin';
}

/**
 * Crea la tabla de usuarios, agrega columnas nuevas a facturas si
 * faltan (migración suave) y siembra los usuarios de ejemplo cuando
 * la tabla queda vacía. Se ejecuta una sola vez por petición.
 */
function asegurarEsquemaAuth(PDO $pdo, bool $forzar = false): void
{
    static $hecho = false;
    if ($hecho && !$forzar) {
        return;
    }
    if (!$forzar && !empty($_SESSION['auth_esquema_listo'])) {
        $hecho = true;
        return;
    }
    $hecho = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        nombre        VARCHAR(120) NOT NULL,
        email         VARCHAR(160) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        rol           ENUM('dueno','superadmin') NOT NULL DEFAULT 'dueno',
        activo        TINYINT(1) NOT NULL DEFAULT 1,
        creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_usuarios_email (email)
    ) ENGINE=InnoDB");

    // Columnas nuevas en facturas y transacciones (para instalaciones anteriores)
    foreach ([
        'ALTER TABLE facturas ADD COLUMN contacto_id INT DEFAULT NULL',
        'ALTER TABLE facturas ADD COLUMN transaccion_id INT DEFAULT NULL',
        'ALTER TABLE transacciones ADD COLUMN contacto_id INT DEFAULT NULL',
        'ALTER TABLE transacciones ADD COLUMN origen ENUM(\'manual\', \'planilla\') NOT NULL DEFAULT \'manual\'',
        'ALTER TABLE transacciones ADD COLUMN colaborador_id INT DEFAULT NULL',
        'ALTER TABLE transacciones MODIFY COLUMN frecuencia_planilla ENUM(\'mes\', \'quincena\', \'semana\', \'hora\') DEFAULT NULL',
        'ALTER TABLE contactos ADD COLUMN proyecto_finalizado TINYINT(1) NOT NULL DEFAULT 0',
    ] as $sqlAlter) {
        try {
            $pdo->exec($sqlAlter);
        } catch (Throwable $e) {
            // La columna ya existe: se ignora.
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['auth_esquema_listo'] = true;
    }

    // Usuarios de ejemplo solo si nadie se ha registrado todavía
    $total = (int) $pdo->query('SELECT COUNT(*) AS c FROM usuarios')->fetch()['c'];
    if ($total === 0) {
        $semilla = [
            ['Dueño del negocio (demo)', 'dueno@demo.com',  'dueno123',  'dueno'],
            ['Superadmin (demo)',        'super@demo.com',  'super123',  'superadmin'],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (:n, :e, :p, :r)'
        );
        foreach ($semilla as [$nombre, $email, $clave, $rol]) {
            $stmt->execute([
                ':n' => $nombre,
                ':e' => $email,
                ':p' => password_hash($clave, PASSWORD_DEFAULT),
                ':r' => $rol,
            ]);
        }
    }
}

/** Intenta iniciar sesión. Devuelve el usuario o false si no coincide. */
function intentarLogin(PDO $pdo, string $email, string $clave): array|false
{
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = :e AND activo = 1 LIMIT 1');
    $stmt->execute([':e' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($clave, $usuario['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'     => (int) $usuario['id'],
        'nombre' => $usuario['nombre'],
        'email'  => $usuario['email'],
        'rol'    => $usuario['rol'],
    ];

    return $_SESSION['usuario'];
}

function cerrarSesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Ruta al login correcta según estemos en la raíz o en /api. */
function rutaLogin(): string
{
    $enApi = basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'api';
    return ($enApi ? '../' : '') . 'login.php';
}

/** Guarda para páginas HTML: redirige al login si no hay sesión. */
function requerirLoginPagina(): array
{
    if (!estaLogueado()) {
        header('Location: ' . rutaLogin());
        exit;
    }
    return usuarioActual();
}

/** Guarda para endpoints JSON (fetch): responde 401 en JSON. */
function requerirLoginApiJson(): array
{
    if (!estaLogueado()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'mensaje' => 'Tu sesión expiró. Vuelve a iniciar sesión.']);
        exit;
    }
    return usuarioActual();
}

/** Guarda para endpoints de formulario (POST normal) que redirigen. */
function requerirLoginApiForm(): array
{
    if (!estaLogueado()) {
        header('Location: ' . rutaLogin());
        exit;
    }
    return usuarioActual();
}

/** Guarda para secciones exclusivas del superadmin. */
function requerirSuperadmin(): array
{
    $usuario = requerirLoginPagina();
    if ($usuario['rol'] !== 'superadmin') {
        header('Location: index.php');
        exit;
    }
    return $usuario;
}
