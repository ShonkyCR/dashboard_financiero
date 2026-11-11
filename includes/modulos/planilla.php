<?php
/**
 * includes/modulos/planilla.php
 * Requiere $pdo (de modulo.php). Deja el HTML listo en $contenidoModulo.
 */

declare(strict_types=1);

$colaboradores = obtenerColaboradores($pdo);
sincronizarPagosPlanillaComoGastos($pdo, date('Y-m'));
$costoMensualEstimado = calcularCostoMensualPlanilla($colaboradores);
$costoSemanalEstimado = calcularCostoSemanalPlanilla($colaboradores);
$gastoAcumuladoMes = obtenerGastoPlanillaAcumuladoMes($pdo, date('Y-m'));
$activos = array_filter($colaboradores, fn($c) => (bool) $c['activo']);
$desglosePlanilla = desglosePlanillaEnMes($pdo, date('Y-m'));

$etiquetasTipoPago = ['semana' => 'Semanal', 'quincena' => 'Por quincena', 'mes' => 'Mensual', 'hora' => 'Por hora'];

ob_start();
?>
<div class="flex items-start justify-between flex-wrap gap-3 mb-6">
    <div>
        <p class="etiqueta-mono">Nómina</p>
        <h2 class="font-display text-xl font-semibold mt-1">Colaboradores</h2>
    </div>
    <button type="button" onclick="abrirModalColaborador()" class="rounded-full px-4 py-2.5 text-sm font-semibold" style="background:var(--color-ink); color:var(--color-paper)">
        + Agregar colaborador
    </button>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Colaboradores activos</p>
        <p class="valor" style="font-size:1.6rem"><?= count($activos) ?></p>
        <p class="text-xs mt-1" style="color:var(--color-ink-soft)"><?= count($colaboradores) ?> registrados</p>
    </div>
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Gasto semanal</p>
        <p class="valor" style="font-size:1.4rem; color:var(--color-ink)"><?= htmlspecialchars(formatearMonto($costoSemanalEstimado)) ?></p>
        <p class="text-xs mt-1" style="color:var(--color-ink-soft)">Tarifa por semana</p>
    </div>
    <div class="tarjeta tarjeta-cifra gastos">
        <p class="etiqueta">Acumulado este mes</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($gastoAcumuladoMes)) ?></p>
        <p class="text-xs mt-1" style="color:var(--color-rojo)">Gastos a la fecha (actual)</p>
    </div>
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Costo mensual estimado</p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($costoMensualEstimado)) ?></p>
        <p class="text-xs mt-1" style="color:var(--color-ink-soft)">Proyección 4 semanas</p>
    </div>
</div>
<p class="text-xs mb-5" style="color:var(--color-ink-soft)">
    * En «Acumulado este mes» ves los pagos de nómina transcurridos a la fecha actual. A medida que avanza cada semana, el siguiente pago semanal se suma automáticamente a ese total.
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

<!-- Calendario y pagos de nómina de este mes -->
<div class="mt-8">
    <div class="mb-3">
        <h3 class="font-display text-lg font-semibold">Calendario y pagos de nómina — <?= htmlspecialchars(ucfirst(strftime_es(date('Y-m')))) ?></h3>
        <p class="text-xs" style="color:var(--color-ink-soft)">
            Fechas de corte de cada semana y quincena. Los pagos marcados como «Aplicado» ya están sumados en «Acumulado este mes».
        </p>
    </div>
    <div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
        <table class="tabla-modulo">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Colaborador</th>
                    <th>Frecuencia</th>
                    <th>Detalle</th>
                    <th class="text-right">Monto</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($desglosePlanilla['filas'])): ?>
                    <tr><td colspan="6" class="text-center py-6" style="color:var(--color-ink-soft)">No hay pagos de nómina programados este mes.</td></tr>
                <?php else: ?>
                    <?php 
                    $hoy = date('Y-m-d');
                    foreach ($desglosePlanilla['filas'] as $f): 
                        $yaPaso = ($f['fecha'] <= $hoy);
                    ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($f['fecha']))) ?></td>
                            <td><strong><?= htmlspecialchars($f['colaborador'] !== '' ? $f['colaborador'] : '—') ?></strong></td>
                            <td>
                                <span class="badge-tipo badge-gasto">
                                    <?= ($f['frecuencia_planilla'] === 'semana') ? 'Semanal' : (($f['frecuencia_planilla'] === 'quincena') ? 'Quincenal' : htmlspecialchars($f['frecuencia_planilla'] ?? 'Semanal')) ?>
                                </span>
                            </td>
                            <td style="color:var(--color-ink-soft)"><?= htmlspecialchars($f['descripcion'] ?? '—') ?></td>
                            <td class="text-right font-medium"><?= htmlspecialchars(formatearMonto((float) $f['monto'])) ?></td>
                            <td>
                                <?php if ($yaPaso): ?>
                                    <span class="badge-tipo badge-ingreso">✓ Aplicado (actual)</span>
                                <?php else: ?>
                                    <span class="badge-tipo" style="background:rgba(124,135,148,0.18); color:var(--color-ink-soft)">Próxima semana</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
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
                    <option value="semana" selected>Semanal</option>
                    <option value="quincena">Por quincena</option>
                </select>
            </div>
            <div class="mb-5">
                <label class="text-sm font-medium block mb-1" id="etiqueta-tarifa">Monto semanal</label>
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
