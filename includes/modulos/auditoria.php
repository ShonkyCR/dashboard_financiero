<?php
/**
 * includes/modulos/auditoria.php
 * Módulo EXCLUSIVO del superadmin (se comprueba en modulo.php).
 * Requiere $pdo. Deja el HTML listo en $contenidoModulo.
 *
 * Su propósito es darle al superadmin (perfil de administración y
 * finanzas) el material para sentarse con el dueño y exponerle sus
 * falencias y buenas prácticas, con lenguaje concreto y accionable.
 */

declare(strict_types=1);

$analisis = analizarNegocio($pdo);
$kpis = $analisis['kpis'];
$hallazgos = $analisis['hallazgos'];
$conteo = $analisis['conteo'];

$etiquetasSeveridad = [
    'peligro' => ['texto' => 'Peligro', 'emoji' => '🔴'],
    'alerta'  => ['texto' => 'Alerta',  'emoji' => '🟡'],
    'bien'    => ['texto' => 'Bien',    'emoji' => '🟢'],
];

ob_start();
?>
<div class="flex items-start justify-between flex-wrap gap-3 mb-6">
    <div>
        <p class="etiqueta-mono">Solo superadmin</p>
        <h2 class="font-display text-xl font-semibold mt-1">Auditoría y capacitación</h2>
        <p class="text-sm mt-1" style="color:var(--color-ink-soft)">
            Análisis para exponerle al dueño sus falencias y buenas prácticas de administración.
        </p>
    </div>
    <div class="flex gap-2 flex-wrap">
        <span class="sello-semaforo sello-rojo"><?= $conteo['peligro'] ?> peligro(s)</span>
        <span class="sello-semaforo sello-amarillo"><?= $conteo['alerta'] ?> alerta(s)</span>
        <span class="sello-semaforo sello-verde"><?= $conteo['bien'] ?> práctica(s) buena(s)</span>
    </div>
</div>

<!-- KPIs -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php foreach ($kpis as $kpi): ?>
        <div class="tarjeta tarjeta-cifra <?= htmlspecialchars($kpi['clase']) ?>">
            <p class="etiqueta"><?= htmlspecialchars($kpi['etiqueta']) ?></p>
            <p class="valor" style="font-size:1.35rem"><?= htmlspecialchars($kpi['valor']) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<!-- Hallazgos -->
<h3 class="font-display text-lg font-semibold mb-3">Hallazgos para trabajar con el dueño</h3>

<div class="grid gap-3 mb-6">
    <?php if (empty($hallazgos)): ?>
        <div class="tarjeta" style="padding:1.25rem">
            <p style="color:var(--color-ink-soft)">Todavía no hay datos suficientes para emitir hallazgos.</p>
        </div>
    <?php else: foreach ($hallazgos as $hallazgo): ?>
        <div class="hallazgo hallazgo-<?= htmlspecialchars($hallazgo['severidad']) ?>">
            <div class="hallazgo-cabecera">
                <span class="hallazgo-sello"><?= $etiquetasSeveridad[$hallazgo['severidad']]['emoji'] ?>
                    <?= $etiquetasSeveridad[$hallazgo['severidad']]['texto'] ?></span>
                <h4 class="font-display font-semibold"><?= htmlspecialchars($hallazgo['titulo']) ?></h4>
            </div>
            <p class="hallazgo-detalle"><?= htmlspecialchars($hallazgo['detalle']) ?></p>
            <p class="hallazgo-recomendacion"><strong>Recomendación:</strong> <?= htmlspecialchars($hallazgo['recomendacion']) ?></p>
        </div>
    <?php endforeach; endif; ?>
</div>

<div class="tarjeta" style="padding:1.25rem">
    <p class="etiqueta mb-2">Cómo usar este panel en una capacitación</p>
    <ol class="lista-capacitacion">
        <li>Empieza por los <strong>peligros</strong>: son los puntos que hoy ponen en riesgo la caja del negocio.</li>
        <li>Luego revisa las <strong>alertas</strong>: no queman, pero si se acumulan se convierten en peligros.</li>
        <li>Cierra con las <strong>buenas prácticas</strong>: reconoce lo que el dueño ya hace bien para que lo sostenga.</li>
        <li>Termina con un acuerdo concreto: qué va a corregir esta semana y qué fecha de repaso se fija.</li>
    </ol>
</div>
<?php
$contenidoModulo = ob_get_clean();
