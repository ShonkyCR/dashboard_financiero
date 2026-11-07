<?php
/**
 * includes/modulos/planilla.php
 * Requiere $pdo (de modulo.php). Deja el HTML listo en $contenidoModulo.
 */

declare(strict_types=1);

$colaboradores = obtenerColaboradores($pdo);
sincronizarPagosPlanillaComoGastos($pdo, date('Y-m'));
$costoMensualEstimado = calcularCostoMensualPlanilla($colaboradores);
$activos = array_filter($colaboradores, fn($c) => (bool) $c['activo']);

$etiquetasTipoPago = ['hora' => 'Por hora', 'semana' => 'Semanal', 'quincena' => 'Por quincena', 'mes' => 'Salario mensual fijo'];

ob_start();
?>
<div class="flex items-start justify-between flex-wrap gap-3 mb-6">
    <div>
        <p class="etiqueta-mono">Planilla</p>
        <h2 class="font-display text-xl font-semibold mt-1">Colaboradores</h2>
    </div>
    <button type="button" onclick="abrirModalColaborador()" class="rounded-full px-4 py-2.5 text-sm font-semibold" style="background:var(--color-ink); color:var(--color-paper)">
        + Agregar colaborador
    </button>
</div>

<div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Colaboradores activos</p>
        <p class="valor" style="font-size:1.6rem"><?= count($activos) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra gastos">
        <p class="etiqueta">Costo mensual estimado</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($costoMensualEstimado)) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Referencia por hora</p>
        <p class="valor" style="font-size:0.95rem; font-weight:500">176 horas/mes (8h × 22 días)</p>
    </div>
</div>
<p class="text-xs mb-5" style="color:var(--color-ink-soft)">
    * El costo de quienes ganan por hora es un estimado a jornada completa; el monto real depende de las horas que reporte cada persona.
    Los pagos del mes actual se registran solos como gastos <strong>Nómina</strong> y aparecen en Reportes y Gastos
    (desglose mensual · quincenal · semanal · por hora).
</p>

<div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
    <table class="tabla-modulo">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Puesto</th>
                <th>Tipo de pago</th>
                <th class="text-right">Tarifa</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($colaboradores)): ?>
                <tr><td colspan="6" class="text-center py-8" style="color:var(--color-ink-soft)">No hay colaboradores registrados.</td></tr>
            <?php else: foreach ($colaboradores as $c): ?>
                <tr class="<?= $c['activo'] ? '' : 'fila-inactiva' ?>">
                    <td><?= htmlspecialchars($c['nombre']) ?></td>
                    <td><?= htmlspecialchars($c['puesto'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($etiquetasTipoPago[$c['tipo_pago']]) ?></td>
                    <td class="text-right"><?= htmlspecialchars(formatearMonto((float) $c['tarifa'])) ?></td>
                    <td><?= $c['activo'] ? '<span class="badge-tipo badge-ingreso">Activo</span>' : '<span class="badge-tipo badge-gasto">Inactivo</span>' ?></td>
                    <td class="acciones-tabla">
                        <button type="button" class="btn-editar"
                            onclick='abrirModalColaborador(<?= json_encode($c, JSON_UNESCAPED_UNICODE) ?>)'>Editar</button>
                        <form method="post" action="api/planilla.php" class="inline" onsubmit="return confirm('¿Eliminar este colaborador?');">
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

<!-- Modal colaborador (sirve para crear y editar) -->
<div id="fondo-modal-colaborador" class="fondo-modal fixed inset-0 oculto z-50" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-colaborador">
    <div class="fondo-modal-backdrop"></div>
    <div class="modal-tarjeta w-full max-w-md">
        <!-- Header fijo -->
        <div class="modal-header flex items-center justify-between px-6 py-4 border-b" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <h3 id="titulo-modal-colaborador" class="font-display text-lg font-semibold">Agregar colaborador</h3>
            <button type="button" onclick="cerrarModalColaborador()" class="modal-close text-2xl leading-none px-3 py-1 -mr-2" style="color:var(--color-ink-soft); line-height:1;" aria-label="Cerrar">&times;</button>
        </div>
        <!-- Body scrolleable -->
        <div class="modal-body p-6">
            <form method="post" action="api/planilla.php" id="form-colaborador">
            <input type="hidden" name="accion" id="accion-colaborador" value="crear">
            <input type="hidden" name="id" id="id-colaborador" value="">
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Nombre</label>
                <input type="text" name="nombre" id="nombre-colaborador" required maxlength="120" class="campo-claro">
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Puesto (opcional)</label>
                <input type="text" name="puesto" id="puesto-colaborador" maxlength="80" class="campo-claro">
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Tipo de pago</label>
                <select name="tipo_pago" id="tipo_pago-colaborador" required class="campo-claro">
                    <option value="mes">Salario mensual fijo</option>
                    <option value="quincena">Por quincena</option>
                    <option value="semana">Semanal</option>
                    <option value="hora">Por hora</option>
                </select>
            </div>
            <div class="mb-5">
                <label class="text-sm font-medium block mb-1" id="etiqueta-tarifa">Monto mensual</label>
                <input type="number" step="0.01" min="0" name="tarifa" id="tarifa-colaborador" required class="campo-claro">
            </div>
            <button type="submit" class="w-full rounded-full py-3 font-semibold text-sm" style="background:var(--color-ink); color:var(--color-paper)">
                Guardar colaborador
            </button>
        </form>
        </div>
    </div>
</div>
<?php
$contenidoModulo = ob_get_clean();
