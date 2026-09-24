<?php
/**
 * includes/modulos/facturas.php
 * Requiere $pdo (de modulo.php). Deja el HTML listo en $contenidoModulo.
 *
 * Mejoras: número autogenerado, selección de cliente desde el
 * directorio (con autocompletado), edición de facturas y validaciones.
 */

declare(strict_types=1);

actualizarFacturasVencidas($pdo);

// Ingresos del Registro rápido antiguos (sin factura enlazada) → los mostramos aquí también
$pendientes = $pdo->query(
    "SELECT t.id FROM transacciones t
     LEFT JOIN facturas f ON f.transaccion_id = t.id
     WHERE t.tipo = 'ingreso' AND f.id IS NULL"
)->fetchAll();
foreach ($pendientes as $pend) {
    sincronizarFacturaDesdeIngreso($pdo, (int) $pend['id']);
}

$mesConsultado = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesConsultado)) {
    $mesConsultado = date('Y-m');
}
$esMesActual = $mesConsultado === date('Y-m');
$nombreMes = ucfirst(strftime_es($mesConsultado));

$facturas = obtenerFacturasDelMes($pdo, $mesConsultado);
$resumen = obtenerResumenFacturas($facturas);

$siguienteNumero = siguienteNumeroFactura($pdo);
$clientesDirectorio = obtenerContactos($pdo, 'cliente');

// Datos para vincular el modal de factura con el directorio de clientes
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

ob_start();
?>
<div class="flex items-start justify-between flex-wrap gap-3 mb-6">
    <div class="min-w-0">
        <p class="etiqueta-mono">Pagos de clientes</p>
        <h2 class="font-display text-xl font-semibold mt-1"><?= htmlspecialchars($nombreMes) ?></h2>
        <p class="text-xs mt-1" style="color:var(--color-ink-soft)">
            Facturas y cobros. Los ingresos del Registro rápido del dashboard también aparecen aquí como pagadas.
            <?= $esMesActual ? '' : ' Estás viendo un mes anterior.' ?>
        </p>
    </div>
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
        <form method="get" class="flex items-center gap-2">
            <input type="hidden" name="p" value="facturas">
            <input type="month" name="mes" value="<?= htmlspecialchars($mesConsultado) ?>" class="campo-claro" onchange="this.form.submit()">
        </form>
        <div class="flex items-center gap-2">
            <button type="button" onclick="abrirModalFactura()" class="rounded-full px-4 py-2.5 text-sm font-semibold flex-1 sm:flex-none" style="background:var(--color-ink); color:var(--color-paper)">
                + Nueva factura
            </button>
        </div>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6">
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Facturas del mes</p>
        <p class="valor" style="font-size:1.6rem"><?= $resumen['cantidad'] ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra ingresos">
        <p class="etiqueta">Pagado</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($resumen['pagada'])) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Pendiente</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($resumen['pendiente'])) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra gastos">
        <p class="etiqueta">Vencido</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($resumen['vencida'])) ?></p>
    </div>
</div>

<div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
    <table class="tabla-modulo">
        <thead>
            <tr>
                <th>N.º</th>
                <th>Cliente</th>
                <th>Emisión</th>
                <th>Vence</th>
                <th class="text-right">Monto</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($facturas)): ?>
                <tr><td colspan="7" class="text-center py-8" style="color:var(--color-ink-soft)">No hay facturas registradas este mes.</td></tr>
            <?php else: foreach ($facturas as $f): ?>
                <tr>
                    <td><?= htmlspecialchars($f['numero']) ?></td>
                    <td>
                        <?php if (!empty($f['contacto_id'])): ?>
                            <span class="badge-cliente-vinculado" title="Vinculado al directorio de clientes">✓ ficha</span>
                        <?php endif; ?>
                        <?= htmlspecialchars($f['cliente']) ?>
                        <?php if ($f['descripcion']): ?>
                            <br><span class="text-xs" style="color:var(--color-ink-soft)"><?= htmlspecialchars($f['descripcion']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($f['fecha_emision']))) ?></td>
                    <td><?= $f['fecha_vencimiento'] ? htmlspecialchars(date('d/m/Y', strtotime($f['fecha_vencimiento']))) : '—' ?></td>
                    <td class="text-right"><?= htmlspecialchars(formatearMonto((float) $f['monto'])) ?></td>
                    <td>
                        <form method="post" action="api/facturas.php" class="inline-flex items-center gap-1">
                            <input type="hidden" name="accion" value="cambiar_estado">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <select name="estado" onchange="this.form.submit()" class="badge-select badge-<?= $f['estado'] ?>">
                                <option value="pendiente" <?= $f['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                <option value="pagada" <?= $f['estado'] === 'pagada' ? 'selected' : '' ?>>Pagada</option>
                                <option value="vencida" <?= $f['estado'] === 'vencida' ? 'selected' : '' ?>>Vencida</option>
                            </select>
                        </form>
                    </td>
                    <td class="whitespace-nowrap">
                        <button type="button" class="btn-editar"
                            onclick='abrirModalFactura(<?= json_encode($f, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
                        <form method="post" action="api/facturas.php" class="inline" onsubmit="return confirm('¿Eliminar esta factura? Si está pagada, también se elimina el ingreso asociado.');">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <button type="submit" class="btn-eliminar" title="Eliminar">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
    const DIRECTORIO_CLIENTES = <?= $directorioJson ?>;
    const ID_CLIENTE_POR_NOMBRE = <?= $mapaNombresJson ?>;
</script>

<!-- Modal factura (sirve para crear y editar) -->
<div id="fondo-modal-factura" class="fondo-modal fixed inset-0 oculto z-50" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-factura">
    <div class="fondo-modal-backdrop"></div>
    <div class="modal-tarjeta w-full max-w-md">
        <!-- Header fijo -->
        <div class="modal-header flex items-center justify-between px-6 py-4 border-b" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <h3 id="titulo-modal-factura" class="font-display text-lg font-semibold">Nueva factura</h3>
            <button type="button" onclick="cerrarModalFactura()" class="modal-close text-2xl leading-none px-3 py-1 -mr-2" style="color:var(--color-ink-soft); line-height:1;" aria-label="Cerrar">&times;</button>
        </div>
        <!-- Body scrolleable -->
        <div class="modal-body p-6">
            <form method="post" action="api/facturas.php" id="form-factura">
            <input type="hidden" name="accion" id="accion-factura" value="crear">
            <input type="hidden" name="id" id="id-factura" value="">

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Número de factura</label>
                <input type="text" name="numero" id="numero-factura" required maxlength="30"
                       value="<?= htmlspecialchars($siguienteNumero) ?>" class="campo-claro">
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Cliente</label>
                <select name="selector_cliente" id="selector-cliente-factura" required class="campo-claro">
                    <option value="">— Elige un cliente —</option>
                    <?php foreach ($clientesDirectorio as $clienteDir): ?>
                        <option value="<?= (int) $clienteDir['id'] ?>"
                            data-nombre="<?= htmlspecialchars($clienteDir['nombre'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($clienteDir['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__nuevo__">+ Nuevo cliente…</option>
                </select>
                <input type="text" name="cliente" id="cliente-factura" maxlength="120"
                       placeholder="Nombre completo del cliente" class="campo-claro mt-2 oculto"
                       autocomplete="off">
                <input type="hidden" name="contacto_id" id="contacto-id-factura" value="">
                <div id="ficha-cliente-factura" class="ficha-cliente oculto mt-2"></div>
                <p class="text-xs mt-1" style="color:var(--color-ink-soft)">
                    Elige un cliente del directorio para vincular la factura a su ficha
                    (evita duplicados por mayúsculas/minúsculas). Opcionalmente puedes crear uno nuevo.
                </p>
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Monto</label>
                <input type="number" step="0.01" min="0.01" name="monto" id="monto-factura" required class="campo-claro">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="text-sm font-medium block mb-1">Fecha de emisión</label>
                    <input type="date" name="fecha_emision" id="emision-factura" required value="<?= date('Y-m-d') ?>" class="campo-claro">
                </div>
                <div>
                    <label class="text-sm font-medium block mb-1">Vence (recomendado)</label>
                    <input type="date" name="fecha_vencimiento" id="vencimiento-factura" class="campo-claro">
                </div>
            </div>
            <div class="mb-3 oculto" id="bloque-estado-factura">
                <label class="text-sm font-medium block mb-1">Estado inicial</label>
                <select name="estado" id="estado-factura" class="campo-claro">
                    <option value="pendiente" selected>Pendiente</option>
                    <option value="pagada">Pagada</option>
                </select>
            </div>
            <div class="mb-5">
                <label class="text-sm font-medium block mb-1">Descripción del trabajo (opcional)</label>
                <input type="text" name="descripcion" id="descripcion-factura" maxlength="255" class="campo-claro"
                       placeholder="Ej: Remodelación de cocina - fase 1">
            </div>
            <button type="submit" id="btn-guardar-factura" class="w-full rounded-full py-3 font-semibold text-sm" style="background:var(--color-ink); color:var(--color-paper)">
                Guardar factura
            </button>
            </form>
        </div>
    </div>
</div>

<?php
$contenidoModulo = ob_get_clean();
