<?php
/**
 * includes/modulos/planilla.php
 * Módulo de Nómina Semanal.
 *
 * Flujo:
 *  1. Crea una nómina semanal (período trabajado + fecha de pago).
 *  2. Ingresa los montos por colaborador o marca «Sin pago».
 *  3. Revisa y confirma (Borrador o Confirmada).
 *  4. Consulta el resumen mensual agrupado por semanas con total de nómina del mes.
 */

declare(strict_types=1);

$mesConsultado = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesConsultado)) {
    $mesConsultado = date('Y-m');
}

$tabActiva = (string) ($_GET['tab'] ?? 'nominas');
if (!in_array($tabActiva, ['nominas', 'colaboradores'], true)) {
    $tabActiva = 'nominas';
}

$colaboradores = obtenerColaboradores($pdo);
$colaboradoresActivos = array_filter($colaboradores, fn($c) => (bool) $c['activo']);
$resumenNomina = obtenerResumenNominaMes($pdo, $mesConsultado);
$nominasSemanales = $resumenNomina['nominas'];

$mesNum = (int) date('n', strtotime($mesConsultado . '-01'));
$anioNum = date('Y', strtotime($mesConsultado . '-01'));
$nombreMesTexto = ucfirst(nombreMesSolo($mesNum));

// Fechas sugeridas para la próxima nómina semanal (lunes a domingo, pago viernes)
$lunesEstaSemana = date('Y-m-d', strtotime('monday this week'));
$domingoEstaSemana = date('Y-m-d', strtotime('sunday this week'));
$viernesEstaSemana = date('Y-m-d', strtotime('friday this week'));

ob_start();
?>
<div class="flex items-start justify-between flex-wrap gap-4 mb-6">
    <div>
        <p class="etiqueta-mono">Nómina semanal</p>
        <h2 class="font-display text-xl font-semibold mt-1">Control de nómina semanal</h2>
        <p class="text-xs mt-1" style="color:var(--color-ink-soft)">
            Registra los pagos por colaborador, revisa el total de cada semana y consulta el acumulado de nómina del mes.
        </p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <button type="button" onclick="abrirModalNominaSemanal()"
                class="rounded-full px-5 py-2.5 text-sm font-semibold flex items-center gap-1.5 shadow-sm"
                style="background:var(--color-ink); color:var(--color-paper)">
            <span>+</span> Nueva nómina semanal
        </button>
        <button type="button" onclick="abrirModalColaborador()"
                class="rounded-full px-4 py-2.5 text-sm font-semibold border flex items-center gap-1.5"
                style="border-color:var(--color-line); background:var(--color-paper-card); color:var(--color-ink)">
            <span>+</span> Agregar colaborador
        </button>
    </div>
</div>

<!-- Selector de Pestañas (Nóminas Semanales vs Directorio de Colaboradores) -->
<div class="flex items-center gap-3 border-b mb-6 pb-1" style="border-color:var(--color-line)">
    <button type="button" onclick="cambiarTabNomina('nominas')" id="tab-btn-nominas"
            class="px-4 py-2 text-sm font-semibold border-b-2 transition-colors <?= $tabActiva === 'nominas' ? 'border-[var(--color-ink)] font-bold text-[var(--color-ink)]' : 'border-transparent text-[var(--color-ink-soft)]' ?>">
        📅 Nóminas semanales del mes
    </button>
    <button type="button" onclick="cambiarTabNomina('colaboradores')" id="tab-btn-colaboradores"
            class="px-4 py-2 text-sm font-semibold border-b-2 transition-colors <?= $tabActiva === 'colaboradores' ? 'border-[var(--color-ink)] font-bold text-[var(--color-ink)]' : 'border-transparent text-[var(--color-ink-soft)]' ?>">
        👥 Directorio de colaboradores (<?= count($colaboradores) ?>)
    </button>
</div>

<!-- =====================================================
     PESTAÑA 1: RESUMEN DE NÓMINAS SEMANALES (PDF ALONSO)
     ===================================================== -->
<div id="seccion-nominas" class="<?= $tabActiva === 'nominas' ? '' : 'oculto' ?>">

    <!-- Filtro de mes y Tarjetas KPI -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
        <div>
            <h3 class="font-display text-lg font-semibold">Resumen de nómina — <?= htmlspecialchars($nombreMesTexto) ?> <?= htmlspecialchars($anioNum) ?></h3>
            <p class="text-xs" style="color:var(--color-ink-soft)">Montos según fecha de pago en USD</p>
        </div>
        <form method="get" action="modulo.php" class="flex items-center gap-2">
            <input type="hidden" name="p" value="planilla">
            <input type="hidden" name="tab" value="nominas">
            <label class="text-xs font-medium" style="color:var(--color-ink-soft)">Mes:</label>
            <input type="month" name="mes" value="<?= htmlspecialchars($mesConsultado) ?>" onchange="this.form.submit()"
                   class="campo-claro text-xs font-mono py-1.5 px-3 rounded-lg">
        </form>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="tarjeta tarjeta-cifra gastos">
            <p class="etiqueta">Total nómina confirmada</p>
            <p class="valor" style="font-size:1.5rem; color:var(--color-ink)">
                <?= htmlspecialchars(formatearMonto($resumenNomina['total_confirmado'])) ?>
            </p>
            <p class="text-xs mt-1" style="color:var(--color-rojo)">
                <?= $resumenNomina['conteo_confirmadas'] ?> semana(s) confirmada(s)
            </p>
        </div>
        <div class="tarjeta tarjeta-cifra">
            <p class="etiqueta">Nóminas en borrador</p>
            <p class="valor font-mono" style="font-size:1.5rem">
                <?= htmlspecialchars(formatearMonto($resumenNomina['total_borrador'])) ?>
            </p>
            <p class="text-xs mt-1" style="color:var(--color-amarillo)">
                <?= $resumenNomina['conteo_borradores'] ?> semana(s) pendiente(s)
            </p>
        </div>
        <div class="tarjeta tarjeta-cifra">
            <p class="etiqueta">Total semanas registradas</p>
            <p class="valor" style="font-size:1.5rem"><?= $resumenNomina['conteo_total'] ?></p>
            <p class="text-xs mt-1" style="color:var(--color-ink-soft)">En <?= htmlspecialchars($nombreMesTexto) ?></p>
        </div>
        <div class="tarjeta tarjeta-cifra">
            <p class="etiqueta">Equipo activo</p>
            <p class="valor" style="font-size:1.5rem"><?= count($colaboradoresActivos) ?></p>
            <p class="text-xs mt-1" style="color:var(--color-ink-soft)"><?= count($colaboradores) ?> registrados en total</p>
        </div>
    </div>

    <!-- Tabla idéntica al Ejemplo del Documento de Alonso -->
    <div class="tarjeta mb-6" style="padding:0; overflow:hidden;">
        <div class="px-5 py-4 border-b flex items-center justify-between flex-wrap gap-2" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <div>
                <p class="etiqueta-mono font-mono text-[0.68rem] uppercase" style="color:var(--color-gold)">Cierre mensual según fecha de pago</p>
                <h4 class="font-display font-semibold text-base">Planilla semanal de <?= htmlspecialchars($nombreMesTexto) ?></h4>
            </div>
            <span class="text-xs font-mono" style="color:var(--color-ink-soft)">Moneda: USD ($)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="tabla-modulo w-full">
                <thead>
                    <tr>
                        <th style="width:18%;">Nómina</th>
                        <th style="width:24%;">Período trabajado</th>
                        <th style="width:22%;">Fecha de pago</th>
                        <th style="width:16%;">Estado</th>
                        <th class="text-right" style="width:15%;">Total</th>
                        <th class="text-center" style="width:5%;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($nominasSemanales)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-10" style="color:var(--color-ink-soft)">
                                <span style="font-size:2rem; display:block; margin-bottom:0.5rem">📅</span>
                                <p class="font-medium text-sm">No hay nóminas registradas para <?= htmlspecialchars($nombreMesTexto) ?> <?= htmlspecialchars($anioNum) ?>.</p>
                                <button type="button" onclick="abrirModalNominaSemanal()"
                                        class="mt-3 rounded-full px-4 py-2 text-xs font-semibold inline-block"
                                        style="background:var(--color-ink); color:var(--color-paper)">
                                    + Registrar primera nómina semanal
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($nominasSemanales as $nom): 
                            $diaP = (int) date('j', strtotime($nom['fecha_pago']));
                            $mP = (int) date('n', strtotime($nom['fecha_pago']));
                            $fechaPagoTexto = $diaP . ' de ' . nombreMesSolo($mP);
                            $pIni = date('d/m/Y', strtotime($nom['periodo_inicio']));
                            $pFin = date('d/m/Y', strtotime($nom['periodo_fin']));
                        ?>
                            <tr>
                                <td>
                                    <strong class="font-display" style="font-size:0.95rem; color:var(--color-ink)">
                                        <?= htmlspecialchars($nom['etiqueta_semana']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="font-mono text-xs" style="color:var(--color-ink-soft)">
                                        <?= $pIni ?> — <?= $pFin ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-sm font-medium">
                                        <?= htmlspecialchars($fechaPagoTexto) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($nom['estado'] === 'confirmada'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium"
                                              style="background:rgba(46, 125, 50, 0.12); color:#2e7d32; border:1px solid rgba(46, 125, 50, 0.25);">
                                            ✓ Confirmada
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium"
                                              style="background:rgba(230, 185, 69, 0.15); color:#926806; border:1px solid rgba(230, 185, 69, 0.3);">
                                            ⏳ Borrador
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <strong class="font-mono text-sm" style="color:var(--color-ink)">
                                        <?= htmlspecialchars(formatearMonto((float) $nom['total'])) ?>
                                    </strong>
                                </td>
                                <td class="acciones-tabla text-center">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" class="btn-editar text-xs px-2.5 py-1 rounded"
                                                onclick="editarNominaSemanal(<?= (int) $nom['id'] ?>)">
                                            Ver / Editar
                                        </button>
                                        <form method="post" action="api/planilla.php" class="inline" onsubmit="return confirm('¿Eliminar esta nómina semanal?');">
                                            <input type="hidden" name="accion" value="eliminar_nomina">
                                            <input type="hidden" name="id" value="<?= (int) $nom['id'] ?>">
                                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mesConsultado) ?>">
                                            <button type="submit" class="btn-eliminar" title="Eliminar nómina">✕</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Fila destacada de Total de Nómina (como en el PDF) -->
                        <tr style="background: rgba(47, 125, 90, 0.08); border-top: 2px solid var(--color-line);">
                            <td colspan="4" style="padding: 1.1rem 1.25rem; font-weight: 700; font-size: 0.95rem; color: var(--color-ink);">
                                Total de nómina de <?= htmlspecialchars(mb_strtolower($nombreMesTexto)) ?>
                            </td>
                            <td class="text-right" style="padding: 1.1rem 1.25rem; font-weight: 800; font-size: 1.15rem; color: var(--color-ink); font-family: monospace;">
                                <?= htmlspecialchars(formatearMonto($resumenNomina['total_confirmado'])) ?>
                            </td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Guía interactiva de Flujo de Registro (del documento de Alonso) -->
    <div class="tarjeta p-5 mb-6" style="border:1px solid var(--color-line); background:var(--color-paper-card);">
        <p class="etiqueta-mono font-mono text-[0.68rem] uppercase mb-1" style="color:var(--color-gold)">Guía de uso en EE. UU.</p>
        <h4 class="font-display font-semibold text-base mb-3">Flujo de registro de nómina semanal</h4>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs">
            <div class="p-3 rounded-xl border border-[var(--color-line)]" style="background:var(--color-paper)">
                <span class="inline-block w-6 h-6 rounded-full text-center leading-6 font-bold mb-1.5" style="background:rgba(201, 162, 39, 0.15); color:var(--color-gold)">1</span>
                <p class="font-bold text-[0.85rem] mb-1">Crea la nómina</p>
                <p style="color:var(--color-ink-soft)">Pulsa «+ Nueva nómina semanal» e indica período y fecha de pago. El sistema carga los colaboradores activos.</p>
            </div>
            <div class="p-3 rounded-xl border border-[var(--color-line)]" style="background:var(--color-paper)">
                <span class="inline-block w-6 h-6 rounded-full text-center leading-6 font-bold mb-1.5" style="background:rgba(201, 162, 39, 0.15); color:var(--color-gold)">2</span>
                <p class="font-bold text-[0.85rem] mb-1">Ingresa los montos</p>
                <p style="color:var(--color-ink-soft)">Registra cuánto recibió cada trabajador o marca «Sin pago». El total semanal se actualiza mientras escribes.</p>
            </div>
            <div class="p-3 rounded-xl border border-[var(--color-line)]" style="background:var(--color-paper)">
                <span class="inline-block w-6 h-6 rounded-full text-center leading-6 font-bold mb-1.5" style="background:rgba(201, 162, 39, 0.15); color:var(--color-gold)">3</span>
                <p class="font-bold text-[0.85rem] mb-1">Revisa y confirma</p>
                <p style="color:var(--color-ink-soft)">Guarda borrador si continúas después. Al pulsar «Confirmar nómina», el total se incorpora a gastos y reportes.</p>
            </div>
            <div class="p-3 rounded-xl border border-[var(--color-line)]" style="background:var(--color-paper)">
                <span class="inline-block w-6 h-6 rounded-full text-center leading-6 font-bold mb-1.5" style="background:rgba(201, 162, 39, 0.15); color:var(--color-gold)">4</span>
                <p class="font-bold text-[0.85rem] mb-1">Repite cada semana</p>
                <p style="color:var(--color-ink-soft)">La semana siguiente vuelves a cargar las personas. Puedes abrir semanas pasadas para consultar el histórico.</p>
            </div>
        </div>
    </div>

</div>

<!-- =====================================================
     PESTAÑA 2: DIRECTORIO DE COLABORADORES
     ===================================================== -->
<div id="seccion-colaboradores" class="<?= $tabActiva === 'colaboradores' ? '' : 'oculto' ?>">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <div>
            <h3 class="font-display text-lg font-semibold">Directorio de colaboradores</h3>
            <p class="text-xs" style="color:var(--color-ink-soft)">
                Colaboradores registrados con su tarifa base semanal. Estos colaboradores se pre-cargan al crear cada nómina.
            </p>
        </div>
        <button type="button" onclick="abrirModalColaborador()" class="rounded-full px-4 py-2 text-xs font-semibold" style="background:var(--color-ink); color:var(--color-paper)">
            + Agregar nuevo colaborador
        </button>
    </div>

    <div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
        <table class="tabla-modulo w-full">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Puesto</th>
                    <th class="text-right">Tarifa semanal base</th>
                    <th>Estado</th>
                    <th class="text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($colaboradores)): ?>
                    <tr><td colspan="5" class="text-center py-8" style="color:var(--color-ink-soft)">No hay colaboradores en el directorio.</td></tr>
                <?php else: foreach ($colaboradores as $c): ?>
                    <tr class="<?= $c['activo'] ? '' : 'fila-inactiva' ?>">
                        <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
                        <td><?= htmlspecialchars($c['puesto'] ?? '—') ?></td>
                        <td class="text-right font-mono"><?= htmlspecialchars(formatearMonto((float) $c['tarifa'])) ?></td>
                        <td>
                            <?php if ($c['activo']): ?>
                                <span class="badge-tipo badge-ingreso">Activo</span>
                            <?php else: ?>
                                <span class="badge-tipo badge-gasto">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="acciones-tabla text-right">
                            <button type="button" class="btn-editar text-xs px-2.5 py-1 rounded"
                                    onclick='abrirModalColaborador(<?= json_encode($c, JSON_UNESCAPED_UNICODE) ?>)'>
                                Editar
                            </button>
                            <form method="post" action="api/planilla.php" class="inline" onsubmit="return confirm('¿Eliminar del directorio?');">
                                <input type="hidden" name="accion" value="eliminar_colaborador">
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

<!-- =====================================================
     MODAL DE NÓMINA SEMANAL (CREACIÓN Y EDICIÓN)
     ===================================================== -->
<div id="fondo-modal-nomina-semanal" class="fondo-modal fixed inset-0 oculto z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-nomina-semanal">
    <div class="fondo-modal-backdrop" onclick="cerrarModalNominaSemanal()"></div>
    <div class="modal-tarjeta w-full max-w-3xl" style="max-height:92vh; display:flex; flex-direction:column; overflow:hidden;">
        
        <!-- Header fijo -->
        <div class="modal-header flex items-center justify-between px-6 py-4 border-b flex-shrink-0" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <div>
                <p class="etiqueta-mono font-mono text-[0.68rem] uppercase" style="color:var(--color-gold)">Nómina semanal</p>
                <h3 id="titulo-modal-nomina-semanal" class="font-display text-lg font-semibold">Nueva nómina semanal</h3>
            </div>
            <button type="button" onclick="cerrarModalNominaSemanal()" class="modal-close text-2xl leading-none px-2" style="color:var(--color-ink-soft)" aria-label="Cerrar">&times;</button>
        </div>

        <!-- Formulario y cuerpo scrolleable -->
        <form method="post" action="api/planilla.php" id="form-nomina-semanal" style="display:flex; flex-direction:column; flex:1; min-height:0;" onsubmit="return validarEnvioNomina(event)">
            <input type="hidden" name="accion" value="guardar_nomina">
            <input type="hidden" name="id" id="nomina-id" value="0">
            <input type="hidden" name="estado" id="nomina-estado" value="borrador">

            <div class="modal-body p-6 overflow-y-auto" style="flex:1;">

                <!-- Fila 1: Fechas del período y fecha de pago -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
                    <div>
                        <label class="text-xs font-semibold block mb-1">Período desde *</label>
                        <input type="date" name="periodo_inicio" id="nomina-periodo_inicio" required
                               value="<?= $lunesEstaSemana ?>" class="campo-claro text-xs font-mono">
                    </div>
                    <div>
                        <label class="text-xs font-semibold block mb-1">Período hasta *</label>
                        <input type="date" name="periodo_fin" id="nomina-periodo_fin" required
                               value="<?= $domingoEstaSemana ?>" class="campo-claro text-xs font-mono">
                    </div>
                    <div>
                        <label class="text-xs font-semibold block mb-1">Fecha de pago *</label>
                        <input type="date" name="fecha_pago" id="nomina-fecha_pago" required
                               value="<?= $viernesEstaSemana ?>" class="campo-claro text-xs font-mono">
                    </div>
                </div>

                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-semibold">Detalle por colaborador</label>
                    <span class="text-xs" style="color:var(--color-ink-soft)">Indica el monto recibido o marca «Sin pago»</span>
                </div>

                <!-- Tabla de Colaboradores en la Nómina -->
                <div class="overflow-x-auto rounded-xl border mb-4" style="border-color:var(--color-line); max-height:280px; overflow-y:auto;">
                    <table class="tabla-modulo w-full" id="tabla-detalles-nomina">
                        <thead>
                            <tr style="position:sticky; top:0; background:var(--color-paper-card); z-index:2;">
                                <th>Colaborador</th>
                                <th>Tarifa base</th>
                                <th style="width:140px;">Monto pagado ($)</th>
                                <th class="text-center" style="width:90px;">Sin pago</th>
                                <th class="text-center" style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-detalles-nomina">
                            <!-- Filas generadas dinámicamente con JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Selector para agregar colaborador adicional que no esté en la tabla -->
                <div class="flex items-center gap-2 mb-4">
                    <select id="selector-agregar-colaborador" class="campo-claro text-xs py-1.5 flex-1">
                        <option value="">+ Selecciona un colaborador para agregar a esta semana...</option>
                        <?php foreach ($colaboradores as $col): ?>
                            <option value="<?= (int) $col['id'] ?>" data-nombre="<?= htmlspecialchars($col['nombre']) ?>" data-tarifa="<?= (float) $col['tarifa'] ?>">
                                <?= htmlspecialchars($col['nombre']) ?> (Base: <?= htmlspecialchars(formatearMonto((float) $col['tarifa'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="agregarColaboradorFila()"
                            class="btn-modal-agregar-fila">
                        + Agregar
                    </button>
                </div>

                <!-- Observaciones -->
                <div class="mb-2">
                    <label class="text-xs font-medium block mb-1" style="color:var(--color-ink-soft)">Notas / Observaciones de la semana (opcional)</label>
                    <input type="text" name="notas" id="nomina-notas" maxlength="255"
                           placeholder="Ej: Semana con horas extra de remodelación o ajustes específicos"
                           class="campo-claro text-xs">
                </div>
            </div>

            <!-- Footer fijo con total calculado y botones de acción -->
            <div class="modal-footer p-4 border-t flex flex-col sm:flex-row items-center justify-between gap-3 flex-shrink-0"
                 style="border-color:var(--color-line); background:var(--color-paper-card);">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-mono uppercase tracking-wide" style="color:var(--color-ink-soft)">Total de esta nómina:</span>
                    <strong id="total-semanal-calculado" class="font-mono text-xl" style="color:var(--color-ink)">$0</strong>
                </div>
                <div class="flex items-center gap-2 w-full sm:w-auto justify-end flex-wrap">
                    <button type="button" onclick="cerrarModalNominaSemanal()"
                            class="btn-modal-cancelar" title="Cerrar sin guardar cambios">
                        Cancelar
                    </button>
                    <button type="button" onclick="guardarComoBorrador()"
                            class="btn-modal-borrador" title="Guardar en borrador sin impacto en gastos">
                        Guardar borrador
                    </button>
                    <button type="button" onclick="confirmarNominaDirecto()"
                            class="btn-modal-confirmar" title="Confirmar e integrar al mes y gastos">
                        ✓ Confirmar nómina
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Colaborador (Crear y editar colaboradores del directorio) -->
<div id="fondo-modal-colaborador" class="fondo-modal fixed inset-0 oculto z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-colaborador">
    <div class="fondo-modal-backdrop" onclick="cerrarModalColaborador()"></div>
    <div class="modal-tarjeta w-full max-w-md">
        <div class="modal-header flex items-center justify-between px-6 py-4 border-b" style="border-color:var(--color-line); background:var(--color-paper-card);">
            <h3 id="titulo-modal-colaborador" class="font-display text-lg font-semibold">Agregar colaborador</h3>
            <button type="button" onclick="cerrarModalColaborador()" class="modal-close text-2xl leading-none px-2" style="color:var(--color-ink-soft)" aria-label="Cerrar">&times;</button>
        </div>
        <div class="modal-body p-6">
            <form method="post" action="api/planilla.php" id="form-colaborador">
                <input type="hidden" name="accion" id="accion-colaborador" value="crear_colaborador">
                <input type="hidden" name="id" id="id-colaborador" value="">
                <div class="mb-3">
                    <label class="text-sm font-medium block mb-1">Nombre completo *</label>
                    <input type="text" name="nombre" id="nombre-colaborador" required maxlength="120" class="campo-claro" placeholder="Ej: John Doe">
                </div>
                <div class="mb-3">
                    <label class="text-sm font-medium block mb-1">Puesto o actividad (opcional)</label>
                    <input type="text" name="puesto" id="puesto-colaborador" maxlength="80" class="campo-claro" placeholder="Ej: Carpintero, Albañil, Capataz">
                </div>
                <div class="mb-5">
                    <label class="text-sm font-medium block mb-1">Monto semanal habitual (USD) *</label>
                    <input type="number" step="1" min="0" inputmode="numeric" name="tarifa" id="tarifa-colaborador" required class="campo-claro font-mono sin-spinners" placeholder="0">
                    <p class="text-[0.72rem] mt-1" style="color:var(--color-ink-soft)">Tarifa base por semana que se sugerirá al generar la nómina.</p>
                </div>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" onclick="cerrarModalColaborador()" class="btn-modal-cancelar">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-modal-confirmar">
                        Guardar colaborador
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Datos en JSON para la inicialización y manipulación en JS -->
<script>
window.colaboradoresDirectorio = <?= json_encode(array_values($colaboradoresActivos), JSON_UNESCAPED_UNICODE) ?>;
window.todasNominas = <?= json_encode($nominasSemanales, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
function cambiarTabNomina(tab) {
    const secNominas = document.getElementById('seccion-nominas');
    const secColabs = document.getElementById('seccion-colaboradores');
    const btnNominas = document.getElementById('tab-btn-nominas');
    const btnColabs = document.getElementById('tab-btn-colaboradores');

    if (tab === 'colaboradores') {
        secNominas.classList.add('oculto');
        secColabs.classList.remove('oculto');
        btnColabs.className = 'px-4 py-2 text-sm font-semibold border-b-2 transition-colors border-[var(--color-ink)] font-bold text-[var(--color-ink)]';
        btnNominas.className = 'px-4 py-2 text-sm font-semibold border-b-2 transition-colors border-transparent text-[var(--color-ink-soft)]';
    } else {
        secColabs.classList.add('oculto');
        secNominas.classList.remove('oculto');
        btnNominas.className = 'px-4 py-2 text-sm font-semibold border-b-2 transition-colors border-[var(--color-ink)] font-bold text-[var(--color-ink)]';
        btnColabs.className = 'px-4 py-2 text-sm font-semibold border-b-2 transition-colors border-transparent text-[var(--color-ink-soft)]';
    }
}

function abrirModalNominaSemanal(nomina = null) {
    const fondo = document.getElementById('fondo-modal-nomina-semanal');
    const titulo = document.getElementById('titulo-modal-nomina-semanal');
    const inputId = document.getElementById('nomina-id');
    const inputEstado = document.getElementById('nomina-estado');
    const inputInicio = document.getElementById('nomina-periodo_inicio');
    const inputFin = document.getElementById('nomina-periodo_fin');
    const inputPago = document.getElementById('nomina-fecha_pago');
    const inputNotas = document.getElementById('nomina-notas');
    const tbody = document.getElementById('tbody-detalles-nomina');

    tbody.innerHTML = '';

    if (nomina) {
        titulo.textContent = 'Editar ' + (nomina.etiqueta_semana || 'nómina semanal');
        inputId.value = nomina.id;
        inputEstado.value = nomina.estado || 'borrador';
        inputInicio.value = nomina.periodo_inicio;
        inputFin.value = nomina.periodo_fin;
        inputPago.value = nomina.fecha_pago;
        inputNotas.value = nomina.notas || '';

        // Si ya trae detalles del objeto o los cargamos
        const detalles = nomina.detalles || [];
        if (detalles.length > 0) {
            detalles.forEach((det, idx) => {
                agregarFilaColaboradorDetalle({
                    colaborador_id: det.colaborador_id,
                    nombre_colaborador: det.nombre_colaborador,
                    monto: parseFloat(det.monto) || 0,
                    sin_pago: parseInt(det.sin_pago) === 1
                }, idx);
            });
        } else {
            cargarColaboradoresPorDefecto();
        }
    } else {
        titulo.textContent = 'Nueva nómina semanal';
        inputId.value = '0';
        inputEstado.value = 'borrador';
        inputNotas.value = '';
        cargarColaboradoresPorDefecto();
    }

    recalcularTotalNominaModal();
    fondo.classList.remove('oculto');
}

function cerrarModalNominaSemanal() {
    const fondo = document.getElementById('fondo-modal-nomina-semanal');
    if (fondo) fondo.classList.add('oculto');
    if (typeof ocultarCarga === 'function') ocultarCarga();
}

function cerrarModalColaborador() {
    const fondo = document.getElementById('fondo-modal-colaborador');
    if (fondo) fondo.classList.add('oculto');
    if (typeof ocultarCarga === 'function') ocultarCarga();
}

function cargarColaboradoresPorDefecto() {
    const tbody = document.getElementById('tbody-detalles-nomina');
    tbody.innerHTML = '';
    const lista = window.colaboradoresDirectorio || [];

    if (lista.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4" style="color:var(--color-ink-soft)">No hay colaboradores activos en el directorio. Puedes agregarlos abajo.</td></tr>';
        return;
    }

    lista.forEach((col, idx) => {
        agregarFilaColaboradorDetalle({
            colaborador_id: col.id,
            nombre_colaborador: col.nombre,
            puesto: col.puesto || '',
            tarifa_base: parseFloat(col.tarifa) || 0,
            monto: parseFloat(col.tarifa) || 0,
            sin_pago: false
        }, idx);
    });
}

function agregarFilaColaboradorDetalle(datos, idx) {
    const tbody = document.getElementById('tbody-detalles-nomina');
    const rowId = 'fila-det-' + (idx !== undefined ? idx : Date.now());

    const tr = document.createElement('tr');
    tr.id = rowId;
    tr.className = 'border-b';
    tr.style.borderColor = 'var(--color-line)';

    const sinPagoChecked = datos.sin_pago ? 'checked' : '';
    const montoVal = datos.sin_pago ? '0' : Math.round(Number(datos.monto || 0));
    const montoDisabled = datos.sin_pago ? 'disabled style="opacity:0.45; background:var(--color-paper-card);"' : '';

    tr.innerHTML = `
        <td>
            <input type="hidden" name="detalles[${rowId}][colaborador_id]" value="${datos.colaborador_id || ''}">
            <input type="hidden" name="detalles[${rowId}][nombre_colaborador]" value="${htmlspecialcharsJS(datos.nombre_colaborador)}">
            <strong style="color:var(--color-ink)">${htmlspecialcharsJS(datos.nombre_colaborador)}</strong>
            ${datos.puesto ? '<span class="block text-[0.68rem]" style="color:var(--color-ink-soft)">' + htmlspecialcharsJS(datos.puesto) + '</span>' : ''}
        </td>
        <td class="font-mono text-xs" style="color:var(--color-ink-soft)">
            ${datos.tarifa_base !== undefined ? '$' + Math.round(Number(datos.tarifa_base || 0)).toLocaleString('en-US') : '—'}
        </td>
        <td>
            <input type="number" step="1" min="0" inputmode="numeric" name="detalles[${rowId}][monto]"
                   value="${montoVal}" placeholder="0" class="campo-claro campo-monto-nomina text-xs font-mono py-1 px-2 text-right sin-spinners"
                   oninput="recalcularTotalNominaModal()" ${montoDisabled} required>
        </td>
        <td class="text-center">
            <input type="checkbox" name="detalles[${rowId}][sin_pago]" value="1"
                   class="check-sin-pago w-4 h-4 cursor-pointer"
                   ${sinPagoChecked} onchange="toggleSinPagoFila('${rowId}', this)">
        </td>
        <td class="text-center">
            <button type="button" onclick="document.getElementById('${rowId}').remove(); recalcularTotalNominaModal();"
                    class="btn-fila-remover" title="Quitar de esta semana">
                ✕
            </button>
        </td>
    `;

    tbody.appendChild(tr);
}

function toggleSinPagoFila(rowId, checkbox) {
    const row = document.getElementById(rowId);
    if (!row) return;
    const inputMonto = row.querySelector('.campo-monto-nomina');
    if (!inputMonto) return;

    if (checkbox.checked) {
        inputMonto.dataset.prevValue = inputMonto.value;
        inputMonto.value = '0';
        inputMonto.disabled = true;
        inputMonto.style.opacity = '0.45';
        inputMonto.style.background = 'var(--color-paper-card)';
    } else {
        inputMonto.value = inputMonto.dataset.prevValue || '0';
        inputMonto.disabled = false;
        inputMonto.style.opacity = '1';
        inputMonto.style.background = '';
    }
    recalcularTotalNominaModal();
}

function recalcularTotalNominaModal() {
    let total = 0;
    document.querySelectorAll('#tbody-detalles-nomina tr').forEach(tr => {
        const check = tr.querySelector('.check-sin-pago');
        const input = tr.querySelector('.campo-monto-nomina');
        if (check && check.checked) {
            return;
        }
        if (input && !input.disabled) {
            const val = parseFloat(input.value) || 0;
            total += val;
        }
    });

    const labelTotal = document.getElementById('total-semanal-calculado');
    if (labelTotal) {
        labelTotal.textContent = '$' + Math.round(total).toLocaleString('en-US');
    }
}

function agregarColaboradorFila() {
    const sel = document.getElementById('selector-agregar-colaborador');
    if (!sel || !sel.value) return;

    const opt = sel.options[sel.selectedIndex];
    const colId = opt.value;
    const nombre = opt.dataset.nombre || '';
    const tarifa = parseFloat(opt.dataset.tarifa) || 0;

    agregarFilaColaboradorDetalle({
        colaborador_id: colId,
        nombre_colaborador: nombre,
        tarifa_base: tarifa,
        monto: tarifa,
        sin_pago: false
    }, Date.now());

    sel.value = '';
    recalcularTotalNominaModal();
}

function guardarComoBorrador() {
    document.getElementById('nomina-estado').value = 'borrador';
    const form = document.getElementById('form-nomina-semanal');
    if (form.requestSubmit) {
        form.requestSubmit();
    } else {
        form.submit();
    }
}

function confirmarNominaDirecto() {
    document.getElementById('nomina-estado').value = 'confirmada';
    const form = document.getElementById('form-nomina-semanal');
    if (form.requestSubmit) {
        form.requestSubmit();
    } else {
        form.submit();
    }
}

function validarEnvioNomina(e) {
    const estado = document.getElementById('nomina-estado').value;
    const filas = document.querySelectorAll('#tbody-detalles-nomina tr[id^="fila-det-"]');

    if (filas.length === 0) {
        alert('Debes incluir al menos un colaborador en la nómina semanal.');
        if (e && e.preventDefault) e.preventDefault();
        if (typeof ocultarCarga === 'function') ocultarCarga();
        return false;
    }

    if (estado === 'confirmada') {
        let hayError = false;
        filas.forEach(tr => {
            const check = tr.querySelector('.check-sin-pago');
            const input = tr.querySelector('.campo-monto-nomina');
            if (check && !check.checked) {
                if (!input || input.value === '' || parseFloat(input.value) < 0) {
                    hayError = true;
                }
            }
        });

        if (hayError) {
            alert('Antes de confirmar la nómina, verifica que cada colaborador tenga un monto asignado o esté marcado como «Sin pago».');
            if (e && e.preventDefault) e.preventDefault();
            if (typeof ocultarCarga === 'function') ocultarCarga();
            return false;
        }

        if (!confirm('¿Deseas confirmar esta nómina semanal? El monto se incorporará al resumen mensual, gastos y reportes.')) {
            if (e && e.preventDefault) e.preventDefault();
            if (typeof ocultarCarga === 'function') ocultarCarga();
            return false;
        }
    }
    return true;
}

async function editarNominaSemanal(id) {
    try {
        const resp = await fetch('api/planilla.php?accion=obtener_nomina_ajax&id=' + id);
        const data = await resp.json();
        if (data.ok && data.nomina) {
            abrirModalNominaSemanal(data.nomina);
        } else {
            alert(data.error || 'No se pudo cargar la información de la nómina.');
        }
    } catch (err) {
        alert('Error al consultar la nómina semanal.');
    }
}

function htmlspecialcharsJS(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
<?php
$contenidoModulo = ob_get_clean();
