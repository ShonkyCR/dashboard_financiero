<?php
/**
 * login.php
 * Inicio de sesión. Crea los usuarios de ejemplo la primera vez.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (estaLogueado()) {
    header('Location: index.php');
    exit;
}

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
$config = obtenerConfiguracion($pdo);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $clave = (string) ($_POST['password'] ?? '');

    if ($email === '' || $clave === '') {
        $error = 'Ingresa tu correo y tu contraseña.';
    } elseif (intentarLogin($pdo, $email, $clave) === false) {
        $error = 'Correo o contraseña incorrectos.';
    } else {
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Iniciar sesión — <?= htmlspecialchars($config['nombre_negocio'] ?? 'Panel Financiero') ?></title>
<link rel="stylesheet" href="assets/css/styles.css">
<!-- Anti-FOUC tema -->
<script>
    (function() {
        try {
            var t = localStorage.getItem('tema_dashboard');
            if (t === 'oscuro') document.documentElement.classList.add('tema-oscuro');
        } catch(e) {}
    })();
</script>
</head>
<body class="min-h-screen <?= $config['tema'] === 'oscuro' ? 'tema-oscuro' : '' ?>">

<div class="pantalla-login">
    <form method="post" action="login.php" class="tarjeta login-tarjeta">
        <div class="login-fila-tema">
            <button type="button" data-tema-toggle class="btn-tema-toggle" aria-label="Alternar tema" title="Alternar tema">
                <svg class="icono-sun" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg class="icono-moon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
        </div>
        <?php if (!empty($config['logo'])): ?>
            <img src="<?= htmlspecialchars($config['logo']) ?>" alt="Logo de <?= htmlspecialchars($config['nombre_negocio'] ?? '') ?>" class="logo-visual logo-login">
        <?php endif; ?>

        <p class="etiqueta-mono login-eyebrow"><?= htmlspecialchars($config['nombre_negocio'] ?? 'Panel de gestión') ?></p>
        <h1 class="font-display login-titulo">Iniciar sesión</h1>
        <p class="login-sub">Entra con tu cuenta de dueño o de superadmin.</p>

        <?php if ($error): ?>
            <div class="aviso aviso-error login-aviso"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="login-campo">
            <label for="email">Correo</label>
            <input type="email" id="email" name="email" required autofocus
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   autocomplete="username" class="campo-claro" placeholder="tu@correo.com">
        </div>

        <div class="login-campo">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password" class="campo-claro" placeholder="••••••••">
        </div>

        <button type="submit" class="btn-entrar" id="btn-entrar">
            <span class="btn-entrar-texto">Entrar</span>
            <svg class="flecha" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="12" x2="19" y2="12"/><polyline points="13 6 19 12 13 18"/></svg>
        </button>

        <p class="login-nota">
            Cuentas de prueba: <strong>dueno@demo.com</strong> / dueno123 ·
            <strong>super@demo.com</strong> / super123
        </p>
    </form>
</div>

<div id="carga-pagina" class="carga-pagina" aria-hidden="true">
    <div class="carga-interior">
        <?php if (!empty($config['logo'])): ?>
            <img src="<?= htmlspecialchars($config['logo']) ?>" alt="" class="carga-logotipo">
        <?php else: ?>
            <span class="carga-monograma"><?= htmlspecialchars(mb_strtoupper(mb_substr($config['nombre_negocio'] ?? 'P', 0, 1))) ?></span>
        <?php endif; ?>
        <p class="carga-nombre"><?= htmlspecialchars($config['nombre_negocio'] ?? 'Panel Financiero') ?></p>
        <div class="carga-barra"><span></span></div>
    </div>
</div>

<div id="toast" class="toast"></div>
<script src="assets/js/main.js"></script>
<script>
(function() {
    var form = document.querySelector('.login-tarjeta');
    var btn = document.getElementById('btn-entrar');
    if (!form || !btn) return;
    form.addEventListener('submit', function(e) {
        if (btn.classList.contains('enviando')) { e.preventDefault(); return; }
        btn.classList.add('enviando');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner" aria-hidden="true"></span><span>Entrando…</span>';
    });
})();
</script>
</body>
</html>
