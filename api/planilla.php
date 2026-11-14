<?php
/**
 * api/planilla.php
 * Procesa los formularios y peticiones del módulo de Nómina Semanal.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$usuario = requerirLoginApiForm();

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
asegurarTablasBaseDeDatos($pdo);

$accion = (string) ($_POST['accion'] ?? $_GET['accion'] ?? '');

function volver(string $ok = '', string $error = '', string $tab = '', string $mes = ''): never
{
    $params = ['p' => 'planilla'];
    if ($ok !== '') {
        $params['ok'] = $ok;
    }
    if ($error !== '') {
        $params['error'] = $error;
    }
    if ($tab !== '') {
        $params['tab'] = $tab;
    }
    if ($mes !== '') {
        $params['mes'] = $mes;
    }
    header('Location: ../modulo.php?' . http_build_query($params));
    exit;
}

function responderJson(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

try {
    /* ----------------------------------------------------
       1. OBTENER DETALLE DE NÓMINA (AJAX)
       ---------------------------------------------------- */
    if ($accion === 'obtener_nomina_ajax') {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            responderJson(['ok' => false, 'error' => 'ID de nómina no válido.']);
        }
        $nomina = obtenerNominaSemanal($pdo, $id);
        if (!$nomina) {
            responderJson(['ok' => false, 'error' => 'Nómina no encontrada.']);
        }
        responderJson(['ok' => true, 'nomina' => $nomina]);
    }

    /* ----------------------------------------------------
       2. GUARDAR NÓMINA SEMANAL (Borrador o Confirmada)
       ---------------------------------------------------- */
    if ($accion === 'guardar_nomina') {
        $id = (int) ($_POST['id'] ?? 0);
        $periodoInicio = trim((string) ($_POST['periodo_inicio'] ?? ''));
        $periodoFin = trim((string) ($_POST['periodo_fin'] ?? ''));
        $fechaPago = trim((string) ($_POST['fecha_pago'] ?? ''));
        $estado = (string) ($_POST['estado'] ?? 'borrador');
        $notas = trim((string) ($_POST['notas'] ?? ''));

        if (!in_array($estado, ['borrador', 'confirmada'], true)) {
            $estado = 'borrador';
        }

        // Parsear detalles enviados desde el formulario
        $detallesRaw = $_POST['detalles'] ?? [];
        $detalles = [];

        if (is_array($detallesRaw)) {
            foreach ($detallesRaw as $det) {
                if (!is_array($det)) {
                    continue;
                }
                $detalles[] = [
                    'colaborador_id' => isset($det['colaborador_id']) && (int) $det['colaborador_id'] > 0 ? (int) $det['colaborador_id'] : null,
                    'nombre_colaborador' => trim((string) ($det['nombre_colaborador'] ?? '')),
                    'monto' => (float) ($det['monto'] ?? 0),
                    'sin_pago' => !empty($det['sin_pago']) ? 1 : 0,
                    'notas' => trim((string) ($det['notas'] ?? '')),
                ];
            }
        }

        $datosNomina = [
            'id' => $id,
            'empresa_id' => 1,
            'periodo_inicio' => $periodoInicio,
            'periodo_fin' => $periodoFin,
            'fecha_pago' => $fechaPago,
            'estado' => $estado,
            'notas' => $notas,
        ];

        $resultado = guardarNominaSemanal($pdo, $datosNomina, $detalles, (string) ($usuario['nombre'] ?? 'Administrador'));

        if (!$resultado['ok']) {
            volver('', $resultado['error'] ?? 'No se pudo guardar la nómina semanal.');
        }

        $mesPago = date('Y-m', strtotime($fechaPago));
        volver($resultado['mensaje'], '', '', $mesPago);
    }

    /* ----------------------------------------------------
       3. ELIMINAR NÓMINA SEMANAL
       ---------------------------------------------------- */
    if ($accion === 'eliminar_nomina') {
        $id = (int) ($_POST['id'] ?? 0);
        $mes = trim((string) ($_POST['mes'] ?? ''));
        if ($id <= 0) {
            volver('', 'ID de nómina no válido.');
        }

        if (eliminarNominaSemanal($pdo, $id)) {
            volver('Nómina semanal eliminada.', '', '', $mes);
        } else {
            volver('', 'No se pudo eliminar la nómina.', '', $mes);
        }
    }

    /* ----------------------------------------------------
       4. GESTIÓN DE COLABORADORES (Directorio de equipo)
       ---------------------------------------------------- */
    if ($accion === 'crear' || $accion === 'crear_colaborador') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $puesto = trim((string) ($_POST['puesto'] ?? ''));
        $tarifa = (float) ($_POST['tarifa'] ?? 0);
        $tipoPago = 'semana';

        if ($nombre === '' || $tarifa < 0) {
            volver('', 'Revisa el nombre y monto del colaborador.', 'colaboradores');
        }

        insertarColaborador($pdo, [
            'nombre' => $nombre,
            'puesto' => $puesto,
            'tipo_pago' => $tipoPago,
            'tarifa' => $tarifa,
        ]);

        volver('Colaborador agregado al directorio.', '', 'colaboradores');
    }

    if ($accion === 'editar' || $accion === 'editar_colaborador') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $puesto = trim((string) ($_POST['puesto'] ?? ''));
        $tarifa = (float) ($_POST['tarifa'] ?? 0);
        $tipoPago = 'semana';

        if ($id <= 0 || $nombre === '' || $tarifa < 0) {
            volver('', 'Revisa los datos del colaborador.', 'colaboradores');
        }

        actualizarColaborador($pdo, $id, [
            'nombre' => $nombre,
            'puesto' => $puesto,
            'tipo_pago' => $tipoPago,
            'tarifa' => $tarifa,
        ]);

        volver('Colaborador actualizado.', '', 'colaboradores');
    }

    if ($accion === 'cambiar_estado_colaborador') {
        $id = (int) ($_POST['id'] ?? 0);
        $activo = !empty($_POST['activo']);
        if ($id <= 0) {
            volver('', 'Colaborador no válido.', 'colaboradores');
        }
        cambiarEstadoColaborador($pdo, $id, $activo);
        volver($activo ? 'Colaborador activado.' : 'Colaborador desactivado.', '', 'colaboradores');
    }

    if ($accion === 'eliminar' || $accion === 'eliminar_colaborador') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            volver('', 'Colaborador no válido.', 'colaboradores');
        }
        eliminarColaborador($pdo, $id);
        volver('Colaborador eliminado.', '', 'colaboradores');
    }

    volver('', 'Acción no reconocida.');
} catch (Throwable $e) {
    volver('', 'Ocurrió un error al procesar la nómina: ' . $e->getMessage());
}
