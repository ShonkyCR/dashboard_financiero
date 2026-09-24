<?php
/**
 * api/clientes.php
 * Recibe los formularios del módulo de Clientes/Proveedores (POST normal)
 * y redirige de vuelta a modulo.php?p=clientes con un mensaje.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requerirLoginApiForm();

$pdo = conectarDB();
$accion = (string) ($_POST['accion'] ?? '');

function volver(string $ok = '', string $error = ''): never
{
    $params = ['p' => 'clientes'];
    if ($ok) {
        $params['ok'] = $ok;
    }
    if ($error) {
        $params['error'] = $error;
    }
    header('Location: ../modulo.php?' . http_build_query($params));
    exit;
}

/** Lee y valida los campos del formulario de contacto. Devuelve null si algo falta. */
function leerDatosContacto(): ?array
{
    $tipo = (string) ($_POST['tipo'] ?? '');
    $nombre = trim((string) ($_POST['nombre'] ?? ''));

    if (!in_array($tipo, ['cliente', 'proveedor'], true) || $nombre === '') {
        return null;
    }

    return [
        'tipo' => $tipo,
        'nombre' => $nombre,
        'telefono' => trim((string) ($_POST['telefono'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'gasto_periodico' => !empty($_POST['gasto_periodico']),
        'monto_periodico' => $_POST['monto_periodico'] ?? '',
        'frecuencia' => $_POST['frecuencia'] ?? '',
        'notas' => trim((string) ($_POST['notas'] ?? '')),
        'proyecto_finalizado' => !empty($_POST['proyecto_finalizado']),
    ];
}

try {
    if ($accion === 'crear') {
        $datos = leerDatosContacto();
        if ($datos === null) {
            volver('', 'Revisa los datos del contacto.');
        }
        insertarContacto($pdo, $datos);
        volver('Contacto guardado.');
    }

    if ($accion === 'editar') {
        $id = (int) ($_POST['id'] ?? 0);
        $datos = leerDatosContacto();
        if ($id <= 0 || $datos === null) {
            volver('', 'Revisa los datos del contacto.');
        }
        actualizarContacto($pdo, $id, $datos);
        volver('Contacto actualizado.');
    }

    if ($accion === 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        eliminarContacto($pdo, $id);
        volver('Contacto eliminado.');
    }

    if ($accion === 'alternar_proyecto') {
        $id = (int) ($_POST['id'] ?? 0);
        $finalizado = ($_POST['finalizado'] ?? '0') === '1';
        if ($id <= 0) {
            volver('', 'Cliente no encontrado.');
        }
        alternarProyectoCliente($pdo, $id, $finalizado);
        volver($finalizado ? 'Proyecto marcado como finalizado.' : 'Proyecto reabierto.');
    }

    volver('', 'Acción no reconocida.');
} catch (Throwable $e) {
    volver('', 'Ocurrió un error al procesar el contacto.');
}
