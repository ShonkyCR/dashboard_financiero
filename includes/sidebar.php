<?php
/**
 * includes/sidebar.php
 * Menú lateral reutilizable. Cada página que lo incluya debe definir
 * antes la variable $paginaActiva con uno de estos valores:
 * 'dashboard', 'facturas', 'planilla', 'clientes', 'reportes',
 * 'auditoria', 'configuracion'.
 *
 * El enlace "Auditoría" solo aparece para usuarios superadmin.
 */
$paginaActiva = $paginaActiva ?? 'dashboard';

$usuario = function_exists('usuarioActual') ? usuarioActual() : null;
$esSuperadmin = ($usuario['rol'] ?? '') === 'superadmin';

$enlaces = [
    [
        'clave' => 'dashboard',
        'texto' => 'Dashboard',
        'href' => 'index.php',
        'icono' => '<path d="M4 4h7v7H4V4Z"/><path d="M13 4h7v4h-7V4Z"/><path d="M13 11h7v9h-7v-9Z"/><path d="M4 14h7v6H4v-6Z"/>',
    ],
    [
        'clave' => 'facturas',
        'texto' => 'Ingresos por clientes',
        'href' => 'modulo.php?p=facturas',
        'icono' => '<path d="M6 3h9l4 4v14H6V3Z"/><path d="M15 3v4h4"/><path d="M9 12h6"/><path d="M9 16h6"/><path d="M9 8h2"/>',
    ],
    [
        'clave' => 'gastos',
        'texto' => 'Gastos',
        'href' => 'modulo.php?p=gastos',
        'icono' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
    ],
    [
        'clave' => 'planilla',
        'texto' => 'Nómina',
        'href' => 'modulo.php?p=planilla',
        'icono' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 4.2a3 3 0 0 1 0 5.6"/><path d="M20.5 20c0-2.6-1.8-4.8-4.2-5.6"/>',
    ],
    [
        'clave' => 'clientes',
        'texto' => 'Clientes / Proveedores',
        'href' => 'modulo.php?p=clientes',
        'icono' => '<path d="M3 20V9l7-5 7 5v11"/><path d="M9 20v-6h4v6"/><path d="M14 9h.01"/>',
    ],
    [
        'clave' => 'reportes',
        'texto' => 'Reportes',
        'href' => 'modulo.php?p=reportes',
        'icono' => '<path d="M4 20V10"/><path d="M11 20V4"/><path d="M18 20v-7"/><path d="M2 20h20"/>',
    ],
    [
        'clave' => 'configuracion',
        'texto' => 'Configuración',
        'href' => 'modulo.php?p=configuracion',
        'icono' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.36a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.64 15a1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.64 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.64a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15 4.64a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.36 9a1.7 1.7 0 0 0 1.56 1.04H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1.04Z"/>',
    ],
    [
        'clave' => 'auditoria',
        'texto' => 'Auditoría',
        'href' => 'modulo.php?p=auditoria',
        'solo_superadmin' => true,
        'icono' => '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
    ],
];
?>
<div id="fondo-sidebar" class="fondo-sidebar oculto" onclick="cerrarMenuLateral()"></div>

<aside id="sidebar" class="sidebar">
    <div class="sidebar-marca">
        <div>
            <?php if (!empty($config['logo'])): ?>
                <img src="<?= htmlspecialchars($config['logo']) ?>" alt="Logo de <?= htmlspecialchars($config['nombre_negocio'] ?? 'Mi Negocio') ?>" class="logo-visual logo-sidebar">
            <?php endif; ?>
            <p class="etiqueta-mono font-mono text-[0.68rem] uppercase tracking-wide">Panel de gestión</p>
            <p class="nombre-negocio"><?= htmlspecialchars($config['nombre_negocio'] ?? 'Mi Negocio') ?></p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <p class="sidebar-seccion">General</p>
        <?php foreach ($enlaces as $enlace): ?>
            <?php if (!empty($enlace['solo_superadmin']) && !$esSuperadmin) continue; ?>
            <a href="<?= htmlspecialchars($enlace['href']) ?>"
               class="sidebar-link <?= $paginaActiva === $enlace['clave'] ? 'activo' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $enlace['icono'] ?></svg>
                <span><?= htmlspecialchars($enlace['texto']) ?></span>
                <?php if (!empty($enlace['solo_superadmin'])): ?>
                    <span class="etiqueta-proximamente">Admin</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-pie">
        <?php if ($usuario): ?>
            <div class="sidebar-usuario">
                <div class="sidebar-usuario-datos">
                    <p class="sidebar-usuario-nombre"><?= htmlspecialchars($usuario['nombre']) ?></p>
                    <p class="sidebar-usuario-rol"><?= $esSuperadmin ? 'Superadmin' : 'Dueño' ?></p>
                </div>
                <a href="logout.php" class="sidebar-salir" title="Cerrar sesión" aria-label="Cerrar sesión">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>
                    </svg>
                </a>
            </div>
        <?php else: ?>
            Panel financiero para tu negocio.
        <?php endif; ?>
    </div>
</aside>
