<?php
/**
 * modulo.php
 * Punto de entrada para los módulos del menú lateral: Facturas,
 * Planilla, Clientes/Proveedores, Reportes, Configuración y
 * Auditoría (esta última solo para superadmin).
 * Cada módulo tiene su lógica en includes/modulos/<clave>.php y
 * debe dejar lista una variable $contenidoModulo con el HTML a
 * mostrar (ver esos archivos para el detalle de cada uno).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$usuario = requerirLoginPagina();

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
asegurarTablasBaseDeDatos($pdo);
$config = obtenerConfiguracion($pdo);

$modulosConLogica = ['facturas', 'gastos', 'planilla', 'clientes', 'reportes', 'configuracion', 'auditoria'];

// Módulos que solo un superadmin puede abrir
$modulosSoloSuperadmin = ['auditoria'];

$titulos = [
    'facturas'      => 'Ingresos por clientes',
    'gastos'        => 'Gastos',
    'planilla'      => 'Nómina',
    'clientes'      => 'Clientes / Proveedores',
    'reportes'      => 'Reportes',
    'configuracion' => 'Configuración',
    'auditoria'     => 'Auditoría y capacitación',
];

$claveModulo = (string) ($_GET['p'] ?? '');

// Si el módulo es exclusivo del superadmin y el usuario no lo es, volvemos al dashboard
if (in_array($claveModulo, $modulosSoloSuperadmin, true) && !esSuperadmin()) {
    header('Location: index.php');
    exit;
}

$paginaActiva = in_array($claveModulo, $modulosConLogica, true) ? $claveModulo : 'dashboard';
$tituloModulo = $titulos[$claveModulo] ?? 'Módulo';

// Mensaje de retroalimentación tras una acción (guardado con éxito, error, etc.)
$mensajeExito = $_GET['ok'] ?? null;
$mensajeError = $_GET['error'] ?? null;

// --- Carga la lógica propia de cada módulo (deja $contenidoModulo listo) ---
$contenidoModulo = null;
$archivoModulo = __DIR__ . '/includes/modulos/' . $claveModulo . '.php';

if (in_array($claveModulo, $modulosConLogica, true) && file_exists($archivoModulo)) {
    require $archivoModulo;
} else {
    $contenidoModulo = '<div class="panel-detalle text-center py-16">'
        . '<p class="etiqueta-mono mb-3">Próximamente</p>'
        . '<h2 class="font-display text-2xl font-semibold mb-3">Módulo</h2>'
        . '<p style="color:var(--color-ink-soft)">Esta sección está en construcción.</p>'
        . '</div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($tituloModulo) ?> — <?= htmlspecialchars($config['nombre_negocio']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<?php if ($claveModulo === 'reportes'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<?php endif; ?>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="min-h-screen <?= $config['tema'] === 'oscuro' ? 'tema-oscuro' : '' ?>">

<div class="app-shell" id="app-shell">
    <script>
        if (window.innerWidth >= 1024 && localStorage.getItem('sidebar_oculto_desktop') === 'true') {
            document.getElementById('app-shell').classList.add('sidebar-oculto');
        }
    </script>
    <!-- Anti-FOUC tema -->
    <script>
        (function() {
            try {
                var t = localStorage.getItem('tema_dashboard');
                if (t === 'oscuro') document.documentElement.classList.add('tema-oscuro');
            } catch(e) {}
        })();
    </script>
    <?php require __DIR__ . '/includes/sidebar.php'; ?>

    <div class="contenido-principal">
        <header class="encabezado">
            <div class="px-3 py-3">
                <!-- Fila 1: Menú + Título (izquierda) | Tema (derecha) -->
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <button type="button" class="btn-toggle-sidebar" onclick="toggleSidebar()" aria-label="Mostrar u ocultar menú lateral" title="Mostrar u ocultar menú lateral">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <line x1="9" y1="3" x2="9" y2="21"/>
                            </svg>
                        </button>
                        <h1 class="font-display text-lg font-semibold truncate"><?= htmlspecialchars($tituloModulo) ?></h1>
                    </div>
                    <button type="button" data-tema-toggle class="btn-tema-toggle flex-shrink-0" aria-label="Alternar tema" title="Alternar tema">
                        <svg class="icono-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        <svg class="icono-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-6xl mx-auto px-5 py-8 animar-entrada">

            <?php if ($mensajeExito): ?>
                <div class="aviso aviso-exito mb-5"><?= htmlspecialchars($mensajeExito) ?></div>
            <?php endif; ?>
            <?php if ($mensajeError): ?>
                <div class="aviso aviso-error mb-5"><?= htmlspecialchars($mensajeError) ?></div>
            <?php endif; ?>

            <?= $contenidoModulo ?>

        </main>
    </div>
</div>

<div id="toast" class="toast"></div>

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

<script src="assets/js/main.js"></script>
<?php if (in_array($claveModulo, $modulosConLogica, true)): ?>
<script src="assets/js/modulos.js"></script>
<?php endif; ?>
</body>
</html>
