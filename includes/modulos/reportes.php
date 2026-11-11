<?php
/**
 * includes/modulos/reportes.php
 * Requiere $pdo (de modulo.php). Deja el HTML listo en $contenidoModulo.
 *
 * - Filtro de mes (?mes=YYYY-MM), por defecto el mes actual.
 * - Filtro de cliente (?cliente=ID), solo proyectos ACTIVOS (no finalizados).
 * - KPIs: utilidad del mes seleccionado vs el anterior.
 * - Gráficas de los últimos 6 meses (filtradas si se elige cliente).
 * - Doughnuts de categorías SOLO del mes seleccionado.
 * - Ingresos vs gastos POR CLIENTE del mes seleccionado (proyecto = cliente).
 */

declare(strict_types=1);

$mesConsultado = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesConsultado)) {
    $mesConsultado = date('Y-m');
}
$nombreMes = ucfirst(strftime_es($mesConsultado));

$desdeMes = $mesConsultado . '-01';
$hastaMes = date('Y-m-t', strtotime($desdeMes));

/** Solo clientes con proyecto activo aparecen en el filtro (finalizados quedan fuera). */
$clientesActivos = clientesActivosParaReportes($pdo);
$idsActivos = array_map(static fn(array $c): int => (int) $c['id'], $clientesActivos);
$clienteSolicitado = (int) ($_GET['cliente'] ?? 0);
$clienteFiltradoId = ($clienteSolicitado > 0 && in_array($clienteSolicitado, $idsActivos, true))
    ? $clienteSolicitado
    : null;
$nombreClienteFiltrado = null;
if ($clienteFiltradoId !== null) {
    foreach ($clientesActivos as $c) {
        if ((int) $c['id'] === $clienteFiltradoId) {
            $nombreClienteFiltrado = (string) $c['nombre'];
            break;
        }
    }
}

$serieMensual = obtenerSerieMensual($pdo, 6, $clienteFiltradoId);

// La planilla es gasto general (sin cliente): se sincroniza solo cuando NO hay filtro por cliente
if ($clienteFiltradoId === null) {
    $mesesSync = [$mesConsultado, date('Y-m', strtotime($desdeMes . ' -1 month'))];
    for ($iMesSerie = 0; $iMesSerie < 6; $iMesSerie++) {
        $mesesSync[] = date('Y-m', strtotime('first day of this month -' . $iMesSerie . ' months'));
    }
    foreach (array_unique($mesesSync) as $mesSync) {
        sincronizarPagosPlanillaComoGastos($pdo, $mesSync);
    }
    $serieMensual = obtenerSerieMensual($pdo, 6, $clienteFiltradoId);
}
$gastosPorCategoria = obtenerTotalesPorCategoria($pdo, 'gasto', $desdeMes, $hastaMes, $clienteFiltradoId);
$ingresosPorCategoria = obtenerTotalesPorCategoria($pdo, 'ingreso', $desdeMes, $hastaMes, $clienteFiltradoId);
$comparativo = obtenerComparativoMensual($pdo, $mesConsultado, $clienteFiltradoId);
$porCliente = totalesPorClienteEnMes($pdo, $mesConsultado, $clienteFiltradoId);
$desglosePlanilla = $clienteFiltradoId === null
    ? desglosePlanillaEnMes($pdo, $mesConsultado)
    : null;

$planillaActualReporte = $desglosePlanilla !== null 
    ? obtenerGastoPlanillaAcumuladoMes($pdo, $mesConsultado) 
    : 0.0;

$semanasPlanilla = [
    1 => ['nombre' => 'Semana #1', 'total' => 0.0, 'pagos' => []],
    2 => ['nombre' => 'Semana #2', 'total' => 0.0, 'pagos' => []],
    3 => ['nombre' => 'Semana #3', 'total' => 0.0, 'pagos' => []],
    4 => ['nombre' => 'Semana #4', 'total' => 0.0, 'pagos' => []],
    5 => ['nombre' => 'Semana #5', 'total' => 0.0, 'pagos' => []],
];

if ($desglosePlanilla !== null) {
    foreach ($desglosePlanilla['filas'] as $filaP) {
        $diaP = (int) date('j', strtotime($filaP['fecha']));
        $sem = match (true) {
            $diaP <= 7 => 1,
            $diaP <= 14 => 2,
            $diaP <= 21 => 3,
            $diaP <= 28 => 4,
            default => 5,
        };
        $semanasPlanilla[$sem]['total'] += (float) $filaP['monto'];
        $semanasPlanilla[$sem]['pagos'][] = $filaP;
    }
    if ($semanasPlanilla[5]['total'] <= 0 && empty($semanasPlanilla[5]['pagos'])) {
        unset($semanasPlanilla[5]);
    }
}

$costoSemana1 = $semanasPlanilla[1]['total'] ?? 0.0;
$proyeccionMensualPlanilla = $costoSemana1 > 0 ? ($costoSemana1 * 4) : (float) ($desglosePlanilla['total'] ?? 0.0);

// Rellenar siempre los últimos 6 meses (aunque el cliente no tenga movimientos en algunos)
$seriePorMes = [];
foreach ($serieMensual as $fila) {
    $seriePorMes[(string) $fila['mes']] = $fila;
}
$etiquetasMeses = [];
$serieIngresos = [];
$serieGastos = [];
for ($i = 5; $i >= 0; $i--) {
    $claveMes = date('Y-m', strtotime('first day of this month -' . $i . ' months'));
    $filaMes = $seriePorMes[$claveMes] ?? null;
    $etiquetasMeses[] = ucfirst(strftime_es($claveMes));
    $serieIngresos[] = $filaMes !== null ? (float) $filaMes['ingresos'] : 0.0;
    $serieGastos[] = $filaMes !== null ? (float) $filaMes['gastos'] : 0.0;
}

$difUtilidad = $comparativo['actual']['utilidad'] - $comparativo['anterior']['utilidad'];

// Series por cliente para Chart.js (top 12 + resto agrupado si hay muchos)
$porClienteTop = array_slice($porCliente, 0, 12);
$nombresClientes = array_column($porClienteTop, 'cliente');
$ingresosClientes = array_map('floatval', array_column($porClienteTop, 'ingresos'));
$gastosClientes = array_map('floatval', array_column($porClienteTop, 'gastos'));
$utilidadClientes = array_map('floatval', array_column($porClienteTop, 'utilidad'));

$hayDatosMes = count($porCliente) > 0
    || !empty($gastosPorCategoria)
    || !empty($ingresosPorCategoria);
$haySerieCliente = array_sum($serieIngresos) > 0 || array_sum($serieGastos) > 0;

ob_start();
?>
<div class="flex items-start justify-between flex-wrap gap-3 mb-6">
    <div class="min-w-0">
        <p class="etiqueta-mono">Análisis</p>
        <h2 class="font-display text-xl font-semibold mt-1">Reportes del negocio</h2>
        <p class="text-xs mt-1" style="color:var(--color-ink-soft)">
            Datos de <strong><?= htmlspecialchars($nombreMes) ?></strong>
            <?php if ($nombreClienteFiltrado): ?>
                · cliente: <strong><?= htmlspecialchars($nombreClienteFiltrado) ?></strong>
            <?php else: ?>
                · todos los proyectos activos
            <?php endif; ?>
            · por cliente = por proyecto
        </p>
    </div>
    <form method="get" class="flex items-center gap-2 flex-wrap">
        <input type="hidden" name="p" value="reportes">
        <label class="text-sm font-medium" for="cliente-reporte">Cliente</label>
        <select name="cliente" id="cliente-reporte" class="campo-claro" onchange="this.form.submit()">
            <option value="">— Todos los activos —</option>
            <?php foreach ($clientesActivos as $cActivo): ?>
                <option value="<?= (int) $cActivo['id'] ?>"
                    <?= $clienteFiltradoId === (int) $cActivo['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cActivo['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label class="text-sm font-medium" for="mes-reporte">Mes</label>
        <input type="month" name="mes" id="mes-reporte" value="<?= htmlspecialchars($mesConsultado) ?>"
               class="campo-claro" onchange="this.form.submit()">
    </form>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Utilidad de <?= htmlspecialchars($nombreMes) ?><?= $nombreClienteFiltrado ? ' · ' . htmlspecialchars($nombreClienteFiltrado) : '' ?></p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($comparativo['actual']['utilidad'])) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra">
        <p class="etiqueta">Mes anterior<?= $nombreClienteFiltrado ? ' · mismo cliente' : '' ?></p>
        <p class="valor" style="font-size:1.4rem"><?= htmlspecialchars(formatearMonto($comparativo['anterior']['utilidad'])) ?></p>
    </div>
    <div class="tarjeta tarjeta-cifra <?= $difUtilidad >= 0 ? 'ingresos' : 'gastos' ?>">
        <p class="etiqueta">Variación</p>
        <p class="valor" style="font-size:1.4rem"><?= $difUtilidad >= 0 ? '+' : '' ?><?= htmlspecialchars(formatearMonto($difUtilidad)) ?></p>
    </div>
</div>

<!-- ====== PLANILLA COMO GASTOS · detalle por frecuencia y semanas ====== -->
<?php if ($desglosePlanilla !== null && $desglosePlanilla['pagos'] > 0): ?>
<div class="tarjeta mb-6" style="padding:1.25rem">
    <div class="flex items-start justify-between flex-wrap gap-2 mb-1">
        <div>
            <p class="etiqueta-mono font-mono text-xs uppercase" style="color:var(--color-gold)">Nómina de colaboradores</p>
            <h3 class="font-display text-lg font-semibold">Pagos de Nómina · <?= htmlspecialchars($nombreMes) ?></h3>
        </div>
    </div>
    <p class="text-xs mb-4" style="color:var(--color-ink-soft)">
        Los salarios de colaboradores entran como gastos generales de <strong>Nómina</strong>. 
        Monto actual pagado a la fecha y proyección mensual estimada.
    </p>

    <!-- Tarjetas de resumen de planilla solicitadas en Punto 3 -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="tarjeta tarjeta-cifra gastos">
            <p class="etiqueta">Planilla actual</p>
            <p class="valor" style="font-size:1.3rem"><?= htmlspecialchars(formatearMonto($planillaActualReporte)) ?></p>
            <p class="text-xs mt-1" style="color:var(--color-rojo)">Monto pagado al momento del reporte</p>
        </div>
        <div class="tarjeta tarjeta-cifra">
            <p class="etiqueta">Proyección mensual</p>
            <p class="valor" style="font-size:1.3rem"><?= htmlspecialchars(formatearMonto($proyeccionMensualPlanilla)) ?></p>
            <p class="text-xs mt-1" style="color:var(--color-ink-soft)">1.ª semana × 4 (estimado, no real)</p>
        </div>
        <div class="col-span-2 grid grid-cols-2 sm:grid-cols-<?= count($semanasPlanilla) ?> gap-2">
            <?php foreach ($semanasPlanilla as $semData): ?>
                <div class="tarjeta tarjeta-cifra" style="padding:0.75rem 1rem">
                    <p class="etiqueta" style="font-size:0.7rem"><?= htmlspecialchars($semData['nombre']) ?></p>
                    <p class="valor font-mono" style="font-size:1.05rem"><?= htmlspecialchars(formatearMonto($semData['total'])) ?></p>
                    <p class="text-[0.68rem] mt-0.5" style="color:var(--color-ink-soft)"><?= count($semData['pagos']) ?> pago(s)</p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tabla detallada semana a semana -->
    <div class="overflow-x-auto rounded-xl" style="border:1px solid var(--color-line)">
        <table class="tabla-modulo">
            <thead>
                <tr>
                    <th>Semana</th>
                    <th>Fecha</th>
                    <th>Colaborador</th>
                    <th>Frecuencia</th>
                    <th>Detalle</th>
                    <th class="text-right">Monto</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $hoyFecha = date('Y-m-d');
                $etiquetasFrec = ['mes' => 'Mensual', 'quincena' => 'Quincenal', 'semana' => 'Semanal', 'hora' => 'Por hora'];
                foreach ($desglosePlanilla['filas'] as $filaPlanilla):
                    $diaP = (int) date('j', strtotime($filaPlanilla['fecha']));
                    $numSem = match (true) {
                        $diaP <= 7 => 1,
                        $diaP <= 14 => 2,
                        $diaP <= 21 => 3,
                        $diaP <= 28 => 4,
                        default => 5,
                    };
                    $frecClave = (string) ($filaPlanilla['frecuencia_planilla'] ?? 'semana');
                    $pagada = ($filaPlanilla['fecha'] <= $hoyFecha);
                ?>
                <tr>
                    <td>
                        <span class="font-mono text-xs font-semibold px-2 py-0.5 rounded" style="background:rgba(201,162,39,0.12); color:var(--color-gold);">
                            Semana #<?= $numSem ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($filaPlanilla['fecha']))) ?></td>
                    <td><strong><?= htmlspecialchars($filaPlanilla['colaborador'] !== '' ? $filaPlanilla['colaborador'] : '—') ?></strong></td>
                    <td>
                        <span class="badge-tipo badge-gasto">
                            <?= htmlspecialchars($etiquetasFrec[$frecClave] ?? 'Semanal') ?>
                        </span>
                    </td>
                    <td style="color:var(--color-ink-soft)"><?= htmlspecialchars($filaPlanilla['descripcion'] ?? '—') ?></td>
                    <td class="text-right font-medium"><?= htmlspecialchars(formatearMonto((float) $filaPlanilla['monto'])) ?></td>
                    <td>
                        <?php if ($pagada): ?>
                            <span class="badge-tipo badge-ingreso">✓ Pagado (actual)</span>
                        <?php else: ?>
                            <span class="badge-tipo" style="background:rgba(124,135,148,0.18); color:var(--color-ink-soft)">Programado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ====== POR CLIENTE (PROYECTO) — mes seleccionado ====== -->
<div class="tarjeta mb-6" style="padding:1.25rem">
    <p class="etiqueta mb-1">
        Ingresos y gastos por cliente · <?= htmlspecialchars($nombreMes) ?>
        <?php if ($nombreClienteFiltrado): ?>
            · <?= htmlspecialchars($nombreClienteFiltrado) ?>
        <?php endif; ?>
    </p>
    <p class="text-xs mb-4" style="color:var(--color-ink-soft)">
        <?php if ($nombreClienteFiltrado): ?>
            Solo movimientos de este cliente. Los proyectos finalizados no aparecen en el filtro superior.
        <?php else: ?>
            Cada cliente cuenta como un proyecto/obra. Incluye facturas pagadas y gastos con cliente asignado.
        <?php endif; ?>
    </p>

    <?php if (!$hayDatosMes): ?>
        <p class="text-sm" style="color:var(--color-ink-soft)">
            No hay movimientos<?= $nombreClienteFiltrado ? ' de <strong>' . htmlspecialchars($nombreClienteFiltrado) . '</strong>' : '' ?> en <?= htmlspecialchars($nombreMes) ?>. Registra facturas o gastos con cliente para ver este reporte.
        </p>
    <?php else: ?>
        <div class="overflow-x-auto rounded-xl mb-4" style="border:1px solid var(--color-line)">
            <table class="tabla-modulo">
                <thead>
                    <tr>
                        <th>Cliente / proyecto</th>
                        <th class="text-right">Ingresos</th>
                        <th class="text-right">Gastos</th>
                        <th class="text-right">Utilidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($porCliente as $fila): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($fila['cliente']) ?>
                                <?php if ($fila['cliente_id'] === null): ?>
                                    <span class="badge-cliente-vinculado" style="background:rgba(124,135,148,0.18); color:var(--color-ink-soft)">sin ficha</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right" style="color:var(--color-verde-dark)"><?= htmlspecialchars(formatearMonto($fila['ingresos'])) ?></td>
                            <td class="text-right" style="color:var(--color-rojo-dark)"><?= htmlspecialchars(formatearMonto($fila['gastos'])) ?></td>
                            <td class="text-right"><strong><?= htmlspecialchars(formatearMonto($fila['utilidad'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php $altCliente = max(160, min(260, count($nombresClientes) * 32)); ?>
        <div style="position: relative; height: <?= $altCliente ?>px; max-width: 480px; margin: 1rem auto; width: 100%;">
            <canvas id="grafico-por-cliente"></canvas>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="tarjeta" style="padding:1.25rem">
        <p class="etiqueta mb-3">Ingresos vs. gastos (últimos 6 meses)<?= $nombreClienteFiltrado ? ' · ' . htmlspecialchars($nombreClienteFiltrado) : '' ?></p>
        <?php if ($nombreClienteFiltrado && !$haySerieCliente): ?>
            <p class="text-sm" style="color:var(--color-ink-soft)">
                Este cliente no tiene movimientos vinculados en los últimos 6 meses.
                Registra facturas o gastos con este cliente para ver la serie.
            </p>
        <?php else: ?>
            <div style="position: relative; height: 210px; width: 100%;">
                <canvas id="grafico-ingresos-gastos"></canvas>
            </div>
        <?php endif; ?>
    </div>
    <div class="tarjeta" style="padding:1.25rem">
        <p class="etiqueta mb-3">Gastos por categoría · <?= htmlspecialchars($nombreMes) ?><?= $nombreClienteFiltrado ? ' · ' . htmlspecialchars($nombreClienteFiltrado) : '' ?></p>
        <div style="position: relative; height: 200px; width: 100%;">
            <canvas id="grafico-gastos-categoria"></canvas>
        </div>
    </div>
    <div class="tarjeta" style="padding:1.25rem">
        <p class="etiqueta mb-3">Ingresos por categoría · <?= htmlspecialchars($nombreMes) ?><?= $nombreClienteFiltrado ? ' · ' . htmlspecialchars($nombreClienteFiltrado) : '' ?></p>
        <div style="position: relative; height: 200px; width: 100%;">
            <canvas id="grafico-ingresos-categoria"></canvas>
        </div>
    </div>
    <div class="tarjeta" style="padding:1.25rem">
        <p class="etiqueta mb-3">Utilidad mensual (6 meses)<?= $nombreClienteFiltrado ? ' · ' . htmlspecialchars($nombreClienteFiltrado) : '' ?></p>
        <?php if ($nombreClienteFiltrado && !$haySerieCliente): ?>
            <p class="text-sm" style="color:var(--color-ink-soft)">
                Sin datos de utilidad de este cliente en los últimos 6 meses.
            </p>
        <?php else: ?>
            <div style="position: relative; height: 210px; width: 100%;">
                <canvas id="grafico-utilidad"></canvas>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const colorTexto = getComputedStyle(document.body).getPropertyValue('--color-ink').trim() || '#1C2733';
    Chart.defaults.color = colorTexto;
    Chart.defaults.font.family = "Inter, sans-serif";

    const meses = <?= json_encode($etiquetasMeses, JSON_UNESCAPED_UNICODE) ?>;
    const ingresos = <?= json_encode($serieIngresos) ?>;
    const gastos = <?= json_encode($serieGastos) ?>;
    const utilidad = ingresos.map((v, i) => v - gastos[i]);

    // Evolución mensual (estilo moderno: Teal + Dark Navy con bordes redondeados)
    const elIngresosGastos = document.getElementById('grafico-ingresos-gastos');
    if (elIngresosGastos) {
        new Chart(elIngresosGastos, {
            type: 'bar',
            data: {
                labels: meses,
                datasets: [
                    {
                        label: 'Ingresos',
                        data: ingresos,
                        backgroundColor: '#0d9488',
                        hoverBackgroundColor: '#14b8a6',
                        borderRadius: 6,
                        barPercentage: 0.65,
                    },
                    {
                        label: 'Gastos',
                        data: gastos,
                        backgroundColor: '#1e293b',
                        hoverBackgroundColor: '#334155',
                        borderRadius: 6,
                        barPercentage: 0.65,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, boxWidth: 8, padding: 15 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': $' + Number(ctx.raw).toLocaleString('en-US', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false }
                    },
                    y: {
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        border: { display: false }
                    }
                }
            },
        });
    }

    const elUtilidad = document.getElementById('grafico-utilidad');
    if (elUtilidad) {
        new Chart(elUtilidad, {
            type: 'line',
            data: {
                labels: meses,
                datasets: [{
                    label: 'Utilidad',
                    data: utilidad,
                    borderColor: '#0d9488',
                    backgroundColor: 'rgba(13, 148, 136, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#0d9488',
                    pointRadius: 4,
                    borderWidth: 2.5
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, border: { display: false } },
                    y: { grid: { color: 'rgba(0,0,0,0.05)' }, border: { display: false } }
                }
            },
        });
    }

    const catGastos = <?= json_encode(array_column($gastosPorCategoria, 'categoria'), JSON_UNESCAPED_UNICODE) ?>;
    const totGastos = <?= json_encode(array_map('floatval', array_column($gastosPorCategoria, 'total'))) ?>;
    new Chart(document.getElementById('grafico-gastos-categoria'), {
        type: 'doughnut',
        data: {
            labels: catGastos.length ? catGastos : ['Sin datos'],
            datasets: [{
                data: catGastos.length ? totGastos : [1],
                backgroundColor: catGastos.length ? ['#0d9488', '#1e293b', '#f59e0b', '#ef4444', '#6366f1', '#64748b'] : ['#E5E7EB'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 12 } }
            }
        },
    });

    const catIngresos = <?= json_encode(array_column($ingresosPorCategoria, 'categoria'), JSON_UNESCAPED_UNICODE) ?>;
    const totIngresos = <?= json_encode(array_map('floatval', array_column($ingresosPorCategoria, 'total'))) ?>;
    new Chart(document.getElementById('grafico-ingresos-categoria'), {
        type: 'doughnut',
        data: {
            labels: catIngresos.length ? catIngresos : ['Sin datos'],
            datasets: [{
                data: catIngresos.length ? totIngresos : [1],
                backgroundColor: catIngresos.length ? ['#0d9488', '#3b82f6', '#f59e0b', '#10b981', '#64748b'] : ['#E5E7EB'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 12 } }
            }
        },
    });

    // Ingresos vs gastos por cliente (Barras horizontales modernas estilo Landing / Mobile)
    const elCliente = document.getElementById('grafico-por-cliente');
    if (elCliente) {
        new Chart(elCliente, {
            type: 'bar',
            data: {
                labels: <?= json_encode($nombresClientes, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    {
                        label: 'Ingresos',
                        data: <?= json_encode($ingresosClientes) ?>,
                        backgroundColor: '#0d9488',
                        hoverBackgroundColor: '#14b8a6',
                        borderRadius: 6,
                        barThickness: 14,
                    },
                    {
                        label: 'Gastos',
                        data: <?= json_encode($gastosClientes) ?>,
                        backgroundColor: '#1e293b',
                        hoverBackgroundColor: '#334155',
                        borderRadius: 6,
                        barThickness: 14,
                    },
                ],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, boxWidth: 8, padding: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': $' + Number(ctx.raw).toLocaleString('en-US', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        border: { display: false }
                    },
                    y: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { autoSkip: false }
                    }
                }
            },
        });
    }
})();
</script>
<?php
$contenidoModulo = ob_get_clean();
