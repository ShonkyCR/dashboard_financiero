<?php
/**
 * api/facturas.php
 * Recibe los formularios del módulo de Facturas (POST normal, sin AJAX)
 * y redirige de vuelta a modulo.php?p=facturas con un mensaje.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requerirLoginApiForm();

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
$accion = (string) ($_POST['accion'] ?? '');

function volver(string $ok = '', string $error = ''): never
{
    $params = ['p' => 'facturas'];
    if ($ok) {
        $params['ok'] = $ok;
    }
    if ($error) {
        $params['error'] = $error;
    }
    header('Location: ../modulo.php?' . http_build_query($params));
    exit;
}

/** Lee y valida los campos comunes del formulario de factura.
 *  Devuelve los datos (array), un mensaje de error (string) o null si falta algo. */
function leerDatosFactura(PDO $pdo, int $excluirId = 0): array|string|null
{
    $numero = trim((string) ($_POST['numero'] ?? ''));
    $cliente = trim((string) ($_POST['cliente'] ?? ''));
    $monto = (float) ($_POST['monto'] ?? 0);
    $fechaEmision = sanearFecha($_POST['fecha_emision'] ?? null);
    $fechaVencimiento = sanearFecha($_POST['fecha_vencimiento'] ?? null);
    $contactoId = (int) ($_POST['contacto_id'] ?? 0) ?: null;

    // Si el nombre viene vacío pero se eligió un cliente del desplegable, resolvemos por id.
    $selector = (string) ($_POST['selector_cliente'] ?? '');
    if ($cliente === '' && $selector !== '' && $selector !== '__nuevo__') {
        $idSel = (int) $selector;
        if ($idSel > 0) {
            $stmt = $pdo->prepare("SELECT nombre FROM contactos WHERE id = :id AND tipo = 'cliente'");
            $stmt->execute([':id' => $idSel]);
            $fila = $stmt->fetch();
            if ($fila) {
                $cliente = (string) $fila['nombre'];
                $contactoId = $idSel;
            }
        }
    }
    // Alinear contacto_id con el desplegable si no vino el hidden
    if (!$contactoId && $selector !== '' && $selector !== '__nuevo__' && (int) $selector > 0) {
        $contactoId = (int) $selector;
    }

    if ($numero === '' || $cliente === '' || $monto <= 0 || !$fechaEmision) {
        return null;
    }
    if (numeroFacturaDuplicado($pdo, $numero, $excluirId)) {
        return 'Ya existe una factura con el número "'
            . $numero . '". Usa otro número o edita la factura existente.';
    }
    if ($fechaVencimiento && $fechaVencimiento < $fechaEmision) {
        return 'La fecha de vencimiento no puede ser anterior a la fecha de emisión.';
    }

    return [
        'numero' => $numero,
        'cliente' => $cliente,
        'contacto_id' => $contactoId,
        'monto' => $monto,
        'estado' => in_array($_POST['estado'] ?? '', ['pendiente', 'pagada'], true) ? $_POST['estado'] : 'pendiente',
        'fecha_emision' => $fechaEmision,
        'fecha_vencimiento' => $fechaVencimiento,
        'descripcion' => trim((string) ($_POST['descripcion'] ?? '')),
    ];
}

try {
    if ($accion === 'crear') {
        $datos = leerDatosFactura($pdo);
        if ($datos === null) {
            volver('', 'Revisa los datos de la factura: faltan campos obligatorios.');
        }
        if (is_string($datos)) {
            volver('', $datos);
        }
        insertarFactura($pdo, $datos);
        volver('Factura guardada correctamente.');
    }

    if ($accion === 'editar') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || !facturaPorId($pdo, $id)) {
            volver('', 'La factura ya no existe.');
        }
        $datos = leerDatosFactura($pdo, $id);
        if ($datos === null) {
            volver('', 'Revisa los datos de la factura: faltan campos obligatorios.');
        }
        if (is_string($datos)) {
            volver('', $datos);
        }
        actualizarFactura($pdo, $id, $datos);
        volver('Factura actualizada.');
    }

    if ($accion === 'cambiar_estado') {
        $id = (int) ($_POST['id'] ?? 0);
        $estado = (string) ($_POST['estado'] ?? '');
        if ($id <= 0 || !actualizarEstadoFactura($pdo, $id, $estado)) {
            volver('', 'No se pudo actualizar el estado de la factura.');
        }
        volver('Estado actualizado.');
    }

    if ($accion === 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        eliminarFactura($pdo, $id);
        volver('Factura eliminada.');
    }

    volver('', 'Acción no reconocida.');
} catch (Throwable $e) {
    volver('', 'Ocurrió un error al procesar la factura.');
}
