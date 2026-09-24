<?php
/**
 * api/planilla.php
 * Recibe los formularios del módulo de Planilla (POST normal, sin AJAX)
 * y redirige de vuelta a modulo.php?p=planilla con un mensaje.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requerirLoginApiForm();

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
asegurarTablasBaseDeDatos($pdo);
$accion = (string) ($_POST['accion'] ?? '');

function volver(string $ok = '', string $error = ''): never
{
    $params = ['p' => 'planilla'];
    if ($ok) {
        $params['ok'] = $ok;
    }
    if ($error) {
        $params['error'] = $error;
    }
    header('Location: ../modulo.php?' . http_build_query($params));
    exit;
}

try {
    $datos = [
        'nombre' => trim((string) ($_POST['nombre'] ?? '')),
        'puesto' => trim((string) ($_POST['puesto'] ?? '')),
        'tipo_pago' => (string) ($_POST['tipo_pago'] ?? 'mes'),
        'tarifa' => (float) ($_POST['tarifa'] ?? 0),
    ];

    if (!in_array($datos['tipo_pago'], ['hora', 'quincena', 'mes'], true)) {
        $datos['tipo_pago'] = 'mes';
    }

    if ($accion === 'crear') {
        if ($datos['nombre'] === '' || $datos['tarifa'] < 0) {
            volver('', 'Revisa los datos del colaborador.');
        }
        insertarColaborador($pdo, $datos);
        sincronizarPagosPlanillaComoGastos($pdo, date('Y-m'));
        volver('Colaborador agregado.');
    }

    if ($accion === 'editar') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || $datos['nombre'] === '') {
            volver('', 'Revisa los datos del colaborador.');
        }
        actualizarColaborador($pdo, $id, $datos);
        sincronizarPagosPlanillaComoGastos($pdo, date('Y-m'));
        volver('Colaborador actualizado.');
    }

    if ($accion === 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare(
            "DELETE FROM transacciones
             WHERE origen = 'planilla' AND colaborador_id = :id
               AND fecha >= :desde"
        )->execute([':id' => $id, ':desde' => date('Y-m-01')]);
        eliminarColaborador($pdo, $id);
        volver('Colaborador eliminado.');
    }

    volver('', 'Acción no reconocida.');
} catch (Throwable $e) {
    volver('', 'Ocurrió un error al procesar el colaborador.');
}
