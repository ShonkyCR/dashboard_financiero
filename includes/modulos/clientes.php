<?php
/**
 * includes/modulos/clientes.php
 * Requiere $pdo (de modulo.php). Deja el HTML listo en $contenidoModulo.
 *
 * Mejoras: edición de contactos, total facturado por cliente y
 * teléfono + correo visibles en la tabla.
 */

declare(strict_types=1);

$contactos = obtenerContactos($pdo);
$clientes = array_filter($contactos, fn($c) => $c['tipo'] === 'cliente');
$proveedores = array_filter($contactos, fn($c) => $c['tipo'] === 'proveedor');
$gastosPeriodicos = obtenerGastosPeriodicos($pdo);
$totalGastosPeriodicos = array_sum(array_column($gastosPeriodicos, 'monto_periodico'));
$totalesFacturados = totalesFacturadosPorContacto($pdo);
$conteosFacturas = facturasPorContacto($pdo);

$etiquetasFrecuencia = ['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'];

ob_start();
?>
<div class="flex items-start justify-between flex-wrap gap-3 mb-6">
    <div>
        <p class="etiqueta-mono">Directorio</p>
        <h2 class="font-display text-xl font-semibold mt-1">Clientes y proveedores</h2>
    </div>
    <button type="button" onclick="abrirModalContacto()" class="rounded-full px-4 py-2.5 text-sm font-semibold" style="background:var(--color-ink); color:var(--color-paper)">
        + Agregar contacto
    </button>
</div>

<?php if (!empty($gastosPeriodicos)): ?>
<div class="tarjeta mb-6" style="padding:1rem 1.25rem">
    <p class="etiqueta mb-2">Gastos periódicos declarados con proveedores</p>
    <div class="flex flex-wrap gap-2 mb-2">
        <?php foreach ($gastosPeriodicos as $g): ?>
            <span class="badge-tipo badge-gasto">
                <?= htmlspecialchars($g['nombre']) ?> · <?= htmlspecialchars(formatearMonto((float) $g['monto_periodico'])) ?> / <?= htmlspecialchars($etiquetasFrecuencia[$g['frecuencia']] ?? $g['frecuencia']) ?>
            </span>
        <?php endforeach; ?>
    </div>
    <p class="text-xs" style="color:var(--color-ink-soft)">Suma mensual equivalente (semanal ×4.33, quincenal ×2):
        <?= htmlspecialchars(formatearMonto(
            array_sum(array_map(fn($g) => (float) $g['monto_periodico'] * match ($g['frecuencia']) {
                'semanal' => 4.33,
                'quincenal' => 2,
                default => 1,
            }, $gastosPeriodicos))
        )) ?>
    </p>
</div>
<?php endif; ?>

<div class="grid md:grid-cols-2 gap-6 w-full min-w-0">
    <div class="min-w-0 w-full">
        <h3 class="font-display text-lg font-semibold mb-3">Clientes (<?= count($clientes) ?>)</h3>
        <div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
            <table class="tabla-modulo">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Contacto</th>
                        <th class="text-right">Facturas</th>
                        <th class="text-right">Facturado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes)): ?>
                        <tr><td colspan="5" class="text-center py-6" style="color:var(--color-ink-soft)">Sin clientes registrados.</td></tr>
                    <?php else: foreach ($clientes as $c): ?>
                        <?php $proyectoFin = !empty($c['proyecto_finalizado']); ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($c['nombre']) ?>
                                <?php if ($proyectoFin): ?>
                                    <span class="badge-cliente-vinculado" style="background:rgba(124,135,148,0.2); color:var(--color-ink-soft)" title="Ya no aparece en el filtro de reportes">proyecto finalizado</span>
                                <?php else: ?>
                                    <span class="badge-cliente-vinculado" title="Activo en el filtro de reportes">● activo</span>
                                <?php endif; ?>
                                <?php if ($c['notas']): ?>
                                    <br><span class="text-xs" style="color:var(--color-ink-soft)"><?= htmlspecialchars($c['notas']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--color-ink-soft)">
                                <?= htmlspecialchars($c['telefono'] ?: '—') ?><br>
                                <?= htmlspecialchars($c['email'] ?: '—') ?>
                            </td>
                            <td class="text-right">
                                <?= $conteosFacturas[(int) $c['id']] ?? 0 ?>
                            </td>
                            <td class="text-right">
                                <?= isset($totalesFacturados[(int) $c['id']])
                                    ? htmlspecialchars(formatearMonto($totalesFacturados[(int) $c['id']]))
                                    : '—' ?>
                            </td>
                            <td class="whitespace-nowrap">
                                <?php if (!$proyectoFin): ?>
                                <form method="post" action="api/clientes.php" class="inline"
                                      onsubmit="return confirm('¿Marcar este proyecto como finalizado? El cliente dejará de aparecer en el filtro de reportes.');">
                                    <input type="hidden" name="accion" value="alternar_proyecto">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="finalizado" value="1">
                                    <button type="submit" class="btn-editar"
                                        title="Marcar proyecto finalizado (sale del filtro de reportes)">Finalizar</button>
                                </form>
                                <?php else: ?>
                                <form method="post" action="api/clientes.php" class="inline">
                                    <input type="hidden" name="accion" value="alternar_proyecto">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="finalizado" value="0">
                                    <button type="submit" class="btn-editar"
                                        title="Reabrir proyecto (vuelve al filtro de reportes)">Reabrir</button>
                                </form>
                                <?php endif; ?>
                                <button type="button" class="btn-editar"
                                    onclick='abrirModalContacto(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
                                <form method="post" action="api/clientes.php" class="inline" onsubmit="return confirm('¿Eliminar este contacto?');">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="btn-eliminar" title="Eliminar">✕</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="min-w-0 w-full">
        <h3 class="font-display text-lg font-semibold mb-3">Proveedores (<?= count($proveedores) ?>)</h3>
        <div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
            <table class="tabla-modulo">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Contacto</th>
                        <th>Gasto periódico</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($proveedores)): ?>
                        <tr><td colspan="4" class="text-center py-6" style="color:var(--color-ink-soft)">Sin proveedores registrados.</td></tr>
                    <?php else: foreach ($proveedores as $p): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($p['nombre']) ?>
                                <?php if ($p['notas']): ?>
                                    <br><span class="text-xs" style="color:var(--color-ink-soft)"><?= htmlspecialchars($p['notas']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--color-ink-soft)">
                                <?= htmlspecialchars($p['telefono'] ?: '—') ?><br>
                                <?= htmlspecialchars($p['email'] ?: '—') ?>
                            </td>
                            <td style="color:var(--color-ink-soft)">
                                <?= $p['gasto_periodico']
                                    ? htmlspecialchars(formatearMonto((float) $p['monto_periodico']) . ' / ' . ($etiquetasFrecuencia[$p['frecuencia']] ?? $p['frecuencia']))
                                    : '—' ?>
                            </td>
                            <td class="whitespace-nowrap">
                                <button type="button" class="btn-editar"
                                    onclick='abrirModalContacto(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
                                <form method="post" action="api/clientes.php" class="inline" onsubmit="return confirm('¿Eliminar este contacto?');">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn-eliminar" title="Eliminar">✕</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal contacto (sirve para crear y editar) -->
<div id="fondo-modal-contacto" class="fondo-modal fixed inset-0 oculto z-50" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-contacto">
    <div class="fondo-modal-backdrop"></div>
    <div class="modal-tarjeta w-full max-w-md">
        <!-- Header fijo -->
        <div class="modal-header flex items-center justify-between px-6 py-4 border-b" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <h3 id="titulo-modal-contacto" class="font-display text-lg font-semibold">Agregar contacto</h3>
            <button type="button" onclick="cerrarModalContacto()" class="modal-close text-2xl leading-none px-3 py-1 -mr-2" style="color:var(--color-ink-soft); line-height:1;" aria-label="Cerrar">&times;</button>
        </div>
        <!-- Body scrolleable -->
        <div class="modal-body p-6">
            <form method="post" action="api/clientes.php" id="form-contacto">
            <input type="hidden" name="accion" id="accion-contacto" value="crear">
            <input type="hidden" name="id" id="id-contacto" value="">

            <div class="flex gap-2 mb-4">
                <div class="chip-tipo activo-ingreso" id="chip-cliente" onclick="seleccionarTipoContacto('cliente')">Cliente</div>
                <div class="chip-tipo" id="chip-proveedor" onclick="seleccionarTipoContacto('proveedor')">Proveedor</div>
            </div>
            <input type="hidden" name="tipo" id="input-tipo-contacto" value="cliente">

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Nombre</label>
                <input type="text" name="nombre" id="nombre-contacto" required maxlength="120" class="campo-claro">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="text-sm font-medium block mb-1">Teléfono</label>
                    <input type="text" name="telefono" id="telefono-contacto" maxlength="40" class="campo-claro" placeholder="(555) 123-4567">
                </div>
                <div>
                    <label class="text-sm font-medium block mb-1">Correo</label>
                    <input type="email" name="email" id="email-contacto" maxlength="120" class="campo-claro">
                </div>
            </div>

            <div id="bloque-proyecto-cliente" class="oculto mb-3">
                <label class="flex items-center gap-2 text-sm font-medium mb-1">
                    <input type="checkbox" id="checkbox-proyecto-finalizado" name="proyecto_finalizado" value="1">
                    Proyecto finalizado
                </label>
                <p class="text-xs" style="color:var(--color-ink-soft)">
                    Si está marcado, este cliente deja de aparecer en el filtro de reportes.
                </p>
            </div>

            <div id="bloque-gasto-periodico" class="oculto mb-3">
                <label class="flex items-center gap-2 text-sm font-medium mb-2">
                    <input type="checkbox" id="checkbox-periodico" name="gasto_periodico" value="1"
                           onchange="document.getElementById('campos-periodico').classList.toggle('oculto', !this.checked)">
                    Es un gasto periódico
                </label>
                <div id="campos-periodico" class="oculto grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <input type="number" step="0.01" min="0" name="monto_periodico" id="monto-periodico-contacto" placeholder="Monto" class="campo-claro">
                    <select name="frecuencia" id="frecuencia-contacto" class="campo-claro">
                        <option value="mensual">Mensual</option>
                        <option value="quincenal">Quincenal</option>
                        <option value="semanal">Semanal</option>
                    </select>
                </div>
            </div>

            <div class="mb-5">
                <label class="text-sm font-medium block mb-1">Notas (opcional)</label>
                <input type="text" name="notas" id="notas-contacto" maxlength="255" class="campo-claro">
            </div>

            <button type="submit" id="btn-guardar-contacto" class="w-full rounded-full py-3 font-semibold text-sm" style="background:var(--color-ink); color:var(--color-paper)">
                Guardar contacto
            </button>
        </form>
        </div>
    </div>
</div>
<?php
$contenidoModulo = ob_get_clean();
