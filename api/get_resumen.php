<?php
/**
 * api/get_resumen.php
 * Devuelve ingresos/gastos/utilidad/margen/semáforo en JSON.
 * Usado por main.js para refrescar el "Modo Resumen" tras registrar un movimiento,
 * sin recargar toda la página.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requerirLoginApiJson();

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
asegurarTablasBaseDeDatos($pdo);
sincronizarPagosPlanillaComoGastos($pdo, date('Y-m'));
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-t');
// Si el rango es un mes concreto, materializa también ese mes de planilla
if (preg_match('/^(\d{4}-\d{2})-01$/', (string) $desde, $mDes)) {
    sincronizarPagosPlanillaComoGastos($pdo, $mDes[1]);
}
$resumen = obtenerResumen($pdo, $desde, $hasta);

echo json_encode([
    'ok' => true,
    'ingresos_fmt' => formatearMonto($resumen['ingresos']),
    'gastos_fmt'   => formatearMonto($resumen['gastos']),
    'utilidad_fmt' => formatearMonto($resumen['utilidad']),
    'margen'       => round($resumen['margen'], 1),
    'semaforo'     => $resumen['semaforo'],
]);
