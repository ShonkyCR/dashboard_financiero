<?php
/**
 * index.php
 * Vista única con dos modos: Resumen (dueño) y Detalle (administrador).
 * Los filtros del modo Detalle se procesan aquí mismo, en PHP, vía GET.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$usuario = requerirLoginPagina();
$esSuperadmin = esSuperadmin();

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
asegurarTablasBaseDeDatos($pdo);
sincronizarPagosPlanillaComoGastos($pdo, date('Y-m'));

// --- Filtros del Modo Detalle (vienen por GET, se procesan en PHP) ---
$filtroCategoria = trim((string)($_GET['categoria'] ?? ''));
$filtroTipo      = trim((string)($_GET['tipo'] ?? ''));
$filtroDesde     = sanearFecha($_GET['desde'] ?? null);
$filtroHasta     = sanearFecha($_GET['hasta'] ?? null);

$hayFiltrosActivos = $filtroCategoria !== '' || $filtroTipo !== '' || $filtroDesde || $filtroHasta;
// Si el usuario ya filtró o pidió el modo detalle explícitamente, lo abrimos de entrada (sin parpadeo de JS)
$abrirDetalleDeEntrada = $hayFiltrosActivos || (($_GET['vista'] ?? '') === 'detalle');

// Mes actual por defecto (sin filtros = mes en curso)
$mesDesde = $filtroDesde ?: date('Y-m-01');
$mesHasta = $filtroHasta ?: date('Y-m-t');

$resumen = obtenerResumen($pdo, $mesDesde, $mesHasta);
$transacciones = obtenerTransacciones($pdo, [
    'categoria' => $filtroCategoria,
    'tipo'      => $filtroTipo,
    'desde'     => $mesDesde,
    'hasta'     => $mesHasta,
]);
$promedioGastos = obtenerPromedioGastos($pdo);
$umbralAtipico = $promedioGastos * 1.5; // gasto se marca atípico si supera 1.5x el promedio
$categoriasUsadas = obtenerCategoriasUsadas($pdo);
$categorias = categoriasDisponibles();

$s = $resumen['semaforo'];
$config = obtenerConfiguracion($pdo);
$paginaActiva = 'dashboard';

// Directorio de clientes, para el selector de "Cliente / proyecto" del registro rápido
$clientesDirectorio = obtenerContactos($pdo, 'cliente');
$totalesFacturados = totalesFacturadosPorContacto($pdo);
$conteosFacturas = facturasPorContacto($pdo);
$directorioJson = json_encode(
    array_map(static function (array $c) use ($totalesFacturados, $conteosFacturas): array {
        $cid = (int) $c['id'];
        return [
            'id' => $cid,
            'nombre' => (string) $c['nombre'],
            'telefono' => (string) ($c['telefono'] ?? ''),
            'email' => (string) ($c['email'] ?? ''),
            'total' => $totalesFacturados[$cid] ?? 0.0,
            'cantidad' => $conteosFacturas[$cid] ?? 0,
        ];
    }, $clientesDirectorio),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$idPorNombre = [];
foreach ($clientesDirectorio as $c) {
    $idPorNombre[mb_strtolower($c['nombre'])] = (int) $c['id'];
}
$mapaNombresJson = json_encode($idPorNombre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Etiqueta del segundo modo según el rol
$etiquetaSegundoModo = $esSuperadmin ? 'Detalle (auditoría)' : 'Movimientos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — <?= htmlspecialchars($config['nombre_negocio']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="min-h-screen <?= $config['tema'] === 'oscuro' ? 'tema-oscuro' : '' ?>">

<div class="app-shell" id="app-shell">
    <script>
        if (window.innerWidth >= 1024 && localStorage.getItem('sidebar_oculto_desktop') === 'true') {
            document.getElementById('app-shell').classList.add('sidebar-oculto');
        }
    </script>
    <!-- Anti-FOUC tema: aplica tema guardado antes de pintar -->
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
                <div class="flex items-center justify-between gap-2 mb-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <button type="button" class="btn-toggle-sidebar" onclick="toggleSidebar()" aria-label="Mostrar u ocultar menú lateral" title="Mostrar u ocultar menú lateral">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <line x1="9" y1="3" x2="9" y2="21"/>
                            </svg>
                        </button>
                        <h1 class="font-display text-lg font-semibold truncate">Dashboard</h1>
                    </div>
                    <button type="button" data-tema-toggle class="btn-tema-toggle flex-shrink-0" aria-label="Alternar tema" title="Alternar tema">
                        <svg class="icono-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        <svg class="icono-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                </div>

                <!-- Fila 2: Semáforo + Interruptor (derecha) -->
                <div class="flex items-center justify-end gap-2 flex-wrap">
                    <span id="badge-semaforo-header" class="sello-semaforo sello-<?= htmlspecialchars($s['color']) ?>">
                        <span><?= $s['emoji'] ?></span>
                        <span id="texto-semaforo-header"><?= htmlspecialchars($s['titulo']) ?></span>
                    </span>

                    <div class="interruptor">
                        <button type="button" id="btn-modo-resumen" class="activo" onclick="cambiarModo('resumen')">Resumen</button>
                        <button type="button" id="btn-modo-detalle" onclick="cambiarModo('detalle')"><?= htmlspecialchars($etiquetaSegundoModo) ?></button>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-6xl mx-auto px-5 py-8 animar-entrada">

    <!-- =====================================================
         MODO RESUMEN — Vista Dueño (lenguaje simple)
         ===================================================== -->
    <section id="vista-resumen" class="<?= $abrirDetalleDeEntrada ? 'oculto' : '' ?>">

        <div class="bento-resumen">

            <!-- Tarjeta ticket: estado del negocio -->
            <div class="area-estado tarjeta-ticket">
                <p class="etiqueta-mono font-mono text-xs uppercase tracking-wide" style="color:var(--color-ink-soft)">Estado de tu negocio hoy</p>
                <div class="flex items-center gap-3 mt-2">
                    <span id="emoji-semaforo" class="text-4xl"><?= $s['emoji'] ?></span>
                    <div>
                        <h2 id="titulo-semaforo" class="font-display text-2xl font-semibold"><?= htmlspecialchars($s['titulo']) ?></h2>
                        <p id="mensaje-semaforo" class="text-sm mt-1" style="color:var(--color-ink-soft)"><?= htmlspecialchars($s['mensaje']) ?></p>
                    </div>
                </div>
            </div>

            <!-- Tarjeta de acción -->
            <div class="area-accion tarjeta-accion">
                <div>
                    <p class="font-mono text-xs uppercase tracking-wide opacity-70">Registro rápido</p>
                    <p class="font-display text-lg font-semibold mt-1">¿Entró o salió dinero hoy?</p>
                </div>
                <button type="button" onclick="abrirModal()"
                    class="text-sm font-semibold rounded-full px-4 py-2.5 w-full"
                    style="background:var(--color-paper); color:var(--color-ink)">
                    + Registrar movimiento
                </button>
            </div>

            <!-- Ingresos -->
            <div class="area-ingresos tarjeta tarjeta-cifra ingresos">
                <p class="etiqueta">Lo que ha entrado</p>
                <p id="valor-ingresos" class="valor"><?= htmlspecialchars(formatearMonto($resumen['ingresos'])) ?></p>
            </div>

            <!-- Gastos -->
            <div class="area-gastos tarjeta tarjeta-cifra gastos">
                <p class="etiqueta">Lo que ha salido</p>
                <p id="valor-gastos" class="valor"><?= htmlspecialchars(formatearMonto($resumen['gastos'])) ?></p>
            </div>

            <!-- Utilidad -->
            <div class="area-utilidad tarjeta tarjeta-cifra utilidad">
                <p class="etiqueta">Lo que te queda</p>
                <p id="valor-utilidad" class="valor"><?= htmlspecialchars(formatearMonto($resumen['utilidad'])) ?></p>
            </div>

            <!-- Placeholder de imagen/ilustración -->
            <div class="area-imagen placeholder-imagen">
                [ Espacio reservado para imagen o ilustración del negocio ]
            </div>

        </div>
    </section>

    <!-- =====================================================
         SEGUNDO MODO
         - Dueño: lista básica de movimientos (sin análisis).
         - Superadmin: detalle de auditoría completo.
         ===================================================== -->
    <section id="vista-detalle" class="tema-libro <?= $abrirDetalleDeEntrada ? '' : 'oculto' ?>">
        <div class="panel-detalle">

            <div class="flex items-start justify-between flex-wrap gap-3 mb-6">
                <div>
                    <p class="etiqueta-mono"><?= $esSuperadmin ? 'Auditoría de movimientos' : 'Tus movimientos' ?></p>
                    <h2 class="font-display text-xl font-semibold mt-1"><?= $esSuperadmin ? 'Facturas y gastos registrados' : 'Todo lo que registraste' ?></h2>
                </div>
                <?php if ($esSuperadmin): ?>
                <div class="text-right">
                    <p class="etiqueta-mono">Margen actual</p>
                    <p class="font-mono text-lg" style="color:var(--color-gold)"><?= number_format($resumen['margen'], 1) ?>%</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filtros (procesados en PHP vía GET) -->
            <form method="GET" action="index.php" class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <input type="hidden" name="vista" value="detalle">

                <div class="col-span-2 md:col-span-1">
                    <label class="etiqueta-mono block mb-1">Tipo</label>
                    <select name="tipo" class="campo w-full">
                        <option value="">Todos</option>
                        <option value="ingreso" <?= $filtroTipo === 'ingreso' ? 'selected' : '' ?>>Ingresos</option>
                        <option value="gasto" <?= $filtroTipo === 'gasto' ? 'selected' : '' ?>>Gastos</option>
                    </select>
                </div>

                <div class="col-span-2 md:col-span-1">
                    <label class="etiqueta-mono block mb-1">Categoría</label>
                    <select name="categoria" class="campo w-full">
                        <option value="">Todas</option>
                        <?php foreach ($categoriasUsadas as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $filtroCategoria === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="etiqueta-mono block mb-1">Desde</label>
                    <input type="date" name="desde" value="<?= htmlspecialchars($filtroDesde ?? '') ?>" class="campo w-full">
                </div>

                <div>
                    <label class="etiqueta-mono block mb-1">Hasta</label>
                    <input type="date" name="hasta" value="<?= htmlspecialchars($filtroHasta ?? '') ?>" class="campo w-full">
                </div>

                <div class="flex items-end gap-2">
                    <button type="submit" class="campo w-full font-semibold" style="background:var(--color-gold); color:#1B2027; border-color:var(--color-gold)">
                        Filtrar
                    </button>
                </div>
            </form>

            <?php if ($hayFiltrosActivos): ?>
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs" style="color:var(--color-text-dark-soft)">
                        Mostrando <?= count($transacciones) ?> resultado(s) con los filtros aplicados.
                    </p>
                    <a href="index.php?vista=detalle" class="text-xs underline" style="color:var(--color-gold)">Limpiar filtros</a>
                </div>
            <?php endif; ?>

            <!-- Tabla -->
            <div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line-dark)">
                <table class="tabla-libro">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Categoría</th>
                            <th>Descripción</th>
                            <th class="text-right">Monto</th>
                            <?php if ($esSuperadmin): ?>
                                <th>Estado</th>
                            <?php endif; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transacciones)): ?>
                            <tr>
                                <td colspan="<?= $esSuperadmin ? 7 : 6 ?>" class="text-center py-8" style="color:var(--color-text-dark-soft)">
                                    No hay movimientos que coincidan con estos filtros.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transacciones as $t): ?>
                                <?php
                                    $esGastoAtipico = $esSuperadmin
                                        && $t['tipo'] === 'gasto'
                                        && (float) $t['monto'] > $umbralAtipico
                                        && $umbralAtipico > 0;

                                    $datosEdicion = [
                                        'id'          => (int) $t['id'],
                                        'tipo'        => $t['tipo'],
                                        'monto'       => (float) $t['monto'],
                                        'categoria'   => $t['categoria'],
                                        'descripcion' => (string) ($t['descripcion'] ?? ''),
                                        'fecha'       => $t['fecha'],
                                        'contacto_id' => $t['contacto_id'] ?? null,
                                    ];
                                ?>
                                <tr class="<?= $esGastoAtipico ? 'fila-atipica' : '' ?>">
                                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($t['fecha']))) ?></td>
                                    <td>
                                        <span class="badge-tipo badge-<?= $t['tipo'] ?>">
                                            <?= $t['tipo'] === 'ingreso' ? 'Ingreso' : 'Gasto' ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($t['categoria']) ?></td>
                                    <td style="color:var(--color-text-dark-soft)"><?= htmlspecialchars($t['descripcion'] ?? '—') ?></td>
                                    <td class="text-right"><?= htmlspecialchars(formatearMonto((float)$t['monto'])) ?></td>
                                    <?php if ($esSuperadmin): ?>
                                        <td>
                                            <?php if ($esGastoAtipico): ?>
                                                <span class="badge-atipico">⚠ Atípico</span>
                                            <?php else: ?>
                                                <span style="color:var(--color-text-dark-soft)">Normal</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="whitespace-nowrap text-right">
                                        <button type="button" class="btn-editar"
                                            onclick='editarMovimiento(<?= json_encode($datosEdicion, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
                                        <button type="button" class="btn-eliminar" title="Eliminar"
                                            onclick="eliminarMovimiento(<?= (int) $t['id'] ?>)">✕</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($esSuperadmin): ?>
            <p class="text-xs mt-4" style="color:var(--color-text-dark-soft)">
                * Un gasto se marca como <strong>atípico</strong> cuando supera 1.5 veces el gasto promedio
                (promedio actual: <?= htmlspecialchars(formatearMonto($promedioGastos)) ?>).
            </p>
            <?php endif; ?>
        </div>
    </section>

        </main>
    </div>
</div>

<!-- =========================================================
     MODAL — Registrar movimiento rápido (mobile-first)
     ===================================================== -->
<div id="fondo-modal" class="fondo-modal fixed inset-0 oculto z-50" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-movimiento">
    <div class="fondo-modal-backdrop"></div>
    <div class="modal-tarjeta w-full max-w-md">
        <!-- Header fijo en móvil -->
        <div class="modal-header flex items-center justify-between px-6 py-4 border-b" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <h3 id="titulo-modal-movimiento" class="font-display text-lg font-semibold">Registrar movimiento</h3>
            <button type="button" onclick="cerrarModal()" class="modal-close text-2xl leading-none px-3 py-1 -mr-2" style="color:var(--color-ink-soft); line-height:1;" aria-label="Cerrar">&times;</button>
        </div>
        <!-- Body scrolleable -->
        <div class="modal-body p-6">
            <form id="form-transaccion" data-ajax>
            <input type="hidden" name="accion" id="accion-movimiento" value="crear">
            <input type="hidden" name="id" id="id-movimiento" value="">

            <!-- Selector tipo -->
            <div class="flex gap-2 mb-4">
                <div class="chip-tipo activo-ingreso" id="chip-ingreso" onclick="seleccionarTipo('ingreso')">Entró dinero</div>
                <div class="chip-tipo" id="chip-gasto" onclick="seleccionarTipo('gasto')">Salió dinero</div>
            </div>
            <input type="hidden" name="tipo" id="input-tipo" value="ingreso">

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">¿Cuánto?</label>
                <input type="number" step="0.01" min="0.01" name="monto" id="input-monto" required
                       placeholder="0.00" class="campo-claro">
            </div>

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">¿En qué categoría?</label>
                <select name="categoria" id="input-categoria" required class="campo-claro"></select>
            </div>

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Cliente / proyecto (opcional)</label>
                <select name="selector_cliente" id="selector-cliente-gasto" class="campo-claro">
                    <option value="">— Sin cliente —</option>
                    <?php foreach ($clientesDirectorio as $clienteDir): ?>
                        <option value="<?= (int) $clienteDir['id'] ?>"
                            data-nombre="<?= htmlspecialchars($clienteDir['nombre'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($clienteDir['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__nuevo__">+ Nuevo cliente…</option>
                </select>
                <input type="text" name="cliente_nuevo" id="cliente-gasto" maxlength="120"
                       placeholder="Nombre del nuevo cliente" class="campo-claro mt-2 oculto"
                       autocomplete="off">
                <input type="hidden" name="contacto_id" id="contacto-id-gasto" value="">
                <div id="ficha-cliente-gasto" class="ficha-cliente oculto mt-2"></div>
                <p class="text-xs mt-1" style="color:var(--color-ink-soft)">
                    Vincula el movimiento a un cliente del directorio para verlo en los reportes por proyecto.
                </p>
            </div>

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Descripción (opcional)</label>
                <input type="text" name="descripcion" id="input-descripcion" maxlength="255"
                       placeholder="Ej: Venta del día, pago de luz..." class="campo-claro">
            </div>

            <div class="mb-5">
                <label class="text-sm font-medium block mb-1">Fecha</label>
                <input type="date" name="fecha" id="input-fecha" required class="campo-claro">
            </div>

            <p id="error-form" class="text-sm mb-3 oculto" style="color:var(--color-rojo)"></p>

            <button type="submit" id="btn-guardar"
                class="w-full rounded-full py-3 font-semibold text-sm"
                style="background:var(--color-ink); color:var(--color-paper)">
                Guardar movimiento
            </button>
            </form>
        </div>
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

<script>
    // Datos que PHP le pasa a JS (categorías válidas por tipo)
    const CATEGORIAS = <?= json_encode($categorias, JSON_UNESCAPED_UNICODE) ?>;
    const DETALLE_ABIERTO_DE_ENTRADA = <?= $abrirDetalleDeEntrada ? 'true' : 'false' ?>;
    // Directorio de clientes, para el selector de "Cliente / proyecto" (mismo patrón que Facturas/Gastos)
    const DIRECTORIO_CLIENTES = <?= $directorioJson ?>;
    const ID_CLIENTE_POR_NOMBRE = <?= $mapaNombresJson ?>;
</script>
<script src="assets/js/main.js"></script>
<script src="assets/js/modulos.js"></script>
</body>
</html>
