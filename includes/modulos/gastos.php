<?php
/**
 * includes/modulos/gastos.php
 * Requiere $pdo (de modulo.php). Deja el HTML listo en $contenidoModulo.
 *
 * Módulo dedicado a TODO lo que sale del negocio: materiales, nómina,
 * contratistas, mantenimiento de maquinaria, transporte, etc.
 * (Antes vivía mezclado dentro de "Facturas"; ahora "Facturas" pasó
 * a ser "Pagos de clientes" y solo maneja dinero que entra.)
 */

declare(strict_types=1);

$mesConsultado = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesConsultado)) {
    $mesConsultado = date('Y-m');
}
$esMesActual = $mesConsultado === date('Y-m');
$nombreMes = ucfirst(strftime_es($mesConsultado));
$desdeMes = $mesConsultado . '-01';
$hastaMes = date('Y-m-t', strtotime($desdeMes));

sincronizarPagosPlanillaComoGastos($pdo, $mesConsultado);

$gastos = obtenerTransacciones($pdo, [
    'tipo'  => 'gasto',
    'desde' => $desdeMes,
    'hasta' => $hastaMes,
]);
$totalGastosMes = array_sum(array_map(static fn($g) => (float) $g['monto'], $gastos));

$categoriasGasto = categoriasDisponibles()['gasto'];
$clientesDirectorio = obtenerContactos($pdo, 'cliente');
$totalesFacturados = totalesFacturadosPorContacto($pdo);
$conteosFacturas = facturasPorContacto($pdo);

// Nombre del proyecto/cliente vinculado, para mostrarlo en la tabla sin hacer JOIN
$nombrePorContacto = [];
foreach ($clientesDirectorio as $c) {
    $nombrePorContacto[(int) $c['id']] = $c['nombre'];
}

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
        <p class="etiqueta-mono">Salidas de dinero</p>
        <h2 class="font-display text-xl font-semibold mt-1"><?= htmlspecialchars($nombreMes) ?></h2>
        <p class="text-xs mt-1" style="color:var(--color-ink-soft)">
            Materiales, nómina, contratistas, mantenimiento de maquinaria y cualquier otra salida de dinero.
            Los gastos del Registro rápido del dashboard también se listan aquí.
            Los pagos de planilla (Nómina) se generan automáticamente desde colaboradores activos.
            <?= $esMesActual ? '' : ' Estás viendo un mes anterior.' ?>
        </p>
    </div>
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
        <form method="get" class="flex items-center gap-2">
            <input type="hidden" name="p" value="gastos">
            <input type="month" name="mes" value="<?= htmlspecialchars($mesConsultado) ?>" class="campo-claro" onchange="this.form.submit()">
        </form>
        <button type="button" onclick="abrirModalGasto()" class="rounded-full px-4 py-2.5 text-sm font-semibold flex-1 sm:flex-none" style="background:var(--color-ink); color:var(--color-paper)">
            + Nuevo gasto
        </button>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-3 gap-3 md:gap-4 mb-6">
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Movimientos del mes</p>
        <p class="valor" style="font-size:1.6rem"><?= count($gastos) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra gastos">
        <p class="etiqueta">Total gastado</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($totalGastosMes)) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Promedio por movimiento</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto(count($gastos) > 0 ? $totalGastosMes / count($gastos) : 0)) ?></p>
    </div>
</div>

<div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
    <table class="tabla-modulo">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Categoría</th>
                <th>Cliente / proyecto</th>
                <th>Descripción</th>
                <th class="text-right">Monto</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($gastos)): ?>
                <tr><td colspan="6" class="text-center py-8" style="color:var(--color-ink-soft)">No hay gastos registrados este mes.</td></tr>
            <?php else: foreach ($gastos as $g): ?>
                <?php
                    $esPlanilla = ($g['origen'] ?? 'manual') === 'planilla';
                    $datosEdicion = [
                        'id'          => (int) $g['id'],
                        'monto'       => (float) $g['monto'],
                        'categoria'   => $g['categoria'],
                        'descripcion' => (string) ($g['descripcion'] ?? ''),
                        'fecha'       => $g['fecha'],
                        'contacto_id' => $g['contacto_id'] ?? null,
                    ];
                ?>
                <tr>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($g['fecha']))) ?></td>
                    <td>
                        <?= htmlspecialchars($g['categoria']) ?>
                        <?php if ($esPlanilla): ?>
                            <span class="badge-tipo badge-gasto" title="Generado desde Planilla">Planilla</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--color-ink-soft)">
                        <?= !empty($g['contacto_id']) && isset($nombrePorContacto[(int) $g['contacto_id']])
                            ? htmlspecialchars($nombrePorContacto[(int) $g['contacto_id']])
                            : '—' ?>
                    </td>
                    <td style="color:var(--color-ink-soft)"><?= htmlspecialchars($g['descripcion'] ?? '—') ?></td>
                    <td class="text-right"><?= htmlspecialchars(formatearMonto((float) $g['monto'])) ?></td>
                    <td class="whitespace-nowrap text-right">
                        <?php if ($esPlanilla): ?>
                            <span class="text-xs" style="color:var(--color-ink-soft)" title="Edita la tarifa o el tipo de pago en Planilla">Solo lectura</span>
                        <?php else: ?>
                            <button type="button" class="btn-editar"
                                onclick='abrirModalGasto(<?= json_encode($datosEdicion, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
                            <button type="button" class="btn-eliminar" title="Eliminar"
                                onclick="eliminarMovimiento(<?= (int) $g['id'] ?>)">✕</button>
                        <?php endif; ?>
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

<!-- Modal gasto (sirve para crear y editar) -->
<div id="fondo-modal-gasto" class="fondo-modal fixed inset-0 oculto z-50" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-gasto">
    <div class="fondo-modal-backdrop"></div>
    <div class="modal-tarjeta w-full max-w-md">
        <div class="modal-header flex items-center justify-between px-6 py-4 border-b" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <h3 id="titulo-modal-gasto" class="font-display text-lg font-semibold">Registrar gasto</h3>
            <button type="button" onclick="cerrarModalGasto()" class="modal-close text-2xl leading-none px-3 py-1 -mr-2" style="color:var(--color-ink-soft); line-height:1;" aria-label="Cerrar">&times;</button>
        </div>
        <div class="modal-body p-6">
            <form id="form-gasto" data-ajax>
            <input type="hidden" name="accion" id="accion-gasto" value="crear">
            <input type="hidden" name="id" id="id-gasto" value="">
            <input type="hidden" name="tipo" value="gasto">

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Monto</label>
                <input type="number" step="0.01" min="0.01" name="monto" id="monto-gasto" required placeholder="0.00" class="campo-claro">
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
                    Vincula el gasto a un cliente del directorio para verlo en los reportes por proyecto.
                </p>
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Categoría</label>
                <select name="categoria" id="categoria-gasto" required class="campo-claro">
                    <?php foreach ($categoriasGasto as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Descripción (opcional)</label>
                <input type="text" name="descripcion" id="descripcion-gasto" maxlength="255"
                       placeholder="Ej: Materiales de la obra, pago a contratista..." class="campo-claro">
            </div>
            <div class="mb-5">
                <label class="text-sm font-medium block mb-1">Fecha</label>
                <input type="date" name="fecha" id="fecha-gasto" required value="<?= date('Y-m-d') ?>" class="campo-claro">
            </div>

            <p id="error-gasto" class="text-sm mb-3 oculto" style="color:var(--color-rojo)"></p>

            <button type="submit" id="btn-guardar-gasto" class="w-full rounded-full py-3 font-semibold text-sm" style="background:var(--color-ink); color:var(--color-paper)">
                Guardar gasto
            </button>
            </form>
        </div>
    </div>
</div>
<?php
$contenidoModulo = ob_get_clean();
