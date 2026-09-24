<?php
/**
 * api/add_transaction.php
 * Recibe el modal de movimientos (fetch POST) y crea, edita o
 * elimina una transacción. Responde siempre en JSON.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

function responder(bool $ok, string $mensaje, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $mensaje], $extra));
    exit;
}

requerirLoginApiJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, 'Método no permitido.');
}

$accion = (string) ($_POST['accion'] ?? 'crear');
$tipo        = trim((string)($_POST['tipo'] ?? ''));
$montoCrudo  = trim((string)($_POST['monto'] ?? ''));
$categoria   = trim((string)($_POST['categoria'] ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));
$fecha       = sanearFecha($_POST['fecha'] ?? null);
$id          = (int) ($_POST['id'] ?? 0);
$contactoId  = (int) ($_POST['contacto_id'] ?? 0) ?: null;
$clienteNuevo = trim((string) ($_POST['cliente_nuevo'] ?? ''));

try {
    $pdo = conectarDB();
    asegurarEsquemaAuth($pdo);
    asegurarTablasBaseDeDatos($pdo);

    /* ------------------------------------------------
       ELIMINAR
       ------------------------------------------------ */
    if ($accion === 'eliminar') {
        if ($id <= 0) {
            responder(false, 'Movimiento no encontrado.');
        }
        $stmtOrigen = $pdo->prepare(
            "SELECT origen FROM transacciones WHERE id = :id"
        );
        $stmtOrigen->execute([':id' => $id]);
        $filaOrigen = $stmtOrigen->fetch();
        if ($filaOrigen && ($filaOrigen['origen'] ?? 'manual') === 'planilla') {
            responder(false, 'Los pagos de planilla se editan o eliminan desde el módulo Planilla.');
        }
        // Quitar antes la factura de "Pagos de clientes" si este ingreso estaba enlazado
        $pdo->prepare('DELETE FROM facturas WHERE transaccion_id = :tid')
            ->execute([':tid' => $id]);
        $stmt = $pdo->prepare('DELETE FROM transacciones WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            responder(false, 'Ese movimiento ya no existe.');
        }
        responder(true, 'Movimiento eliminado.');
    }

    /* ------------------------------------------------
       VALIDACIONES (crear y editar)
       ------------------------------------------------ */
    if (!in_array($tipo, ['ingreso', 'gasto'], true)) {
        responder(false, 'Selecciona si fue una entrada o una salida de dinero.');
    }

    if (!is_numeric($montoCrudo) || (float)$montoCrudo <= 0) {
        responder(false, 'El monto debe ser un número mayor a cero.');
    }
    $monto = round((float)$montoCrudo, 2);

    $categoriasValidas = categoriasDisponibles()[$tipo] ?? [];
    if (!in_array($categoria, $categoriasValidas, true)) {
        responder(false, 'Elige una categoría válida para ese tipo de movimiento.');
    }

    if (!$fecha) {
        $fecha = date('Y-m-d'); // si no mandan fecha, usamos hoy
    }

    if (mb_strlen($descripcion) > 255) {
        $descripcion = mb_substr($descripcion, 0, 255);
    }
    $descripcionGuardada = $descripcion !== '' ? $descripcion : null;

    // Cliente del gasto: id del desplegable o nombre nuevo (se crea al vuelo)
    if ($clienteNuevo !== '') {
        $contactoId = asegurarClienteDeFactura($pdo, $clienteNuevo, $contactoId);
    } elseif ($contactoId) {
        $stmtChk = $pdo->prepare("SELECT id FROM contactos WHERE id = :id AND tipo = 'cliente'");
        $stmtChk->execute([':id' => $contactoId]);
        if (!$stmtChk->fetch()) {
            $contactoId = null;
        }
    }

    /* ------------------------------------------------
       EDITAR
       ------------------------------------------------ */
    if ($accion === 'editar') {
        if ($id <= 0) {
            responder(false, 'Movimiento no encontrado.');
        }
        $stmtOrigen = $pdo->prepare(
            "SELECT origen FROM transacciones WHERE id = :id"
        );
        $stmtOrigen->execute([':id' => $id]);
        $filaOrigen = $stmtOrigen->fetch();
        if ($filaOrigen && ($filaOrigen['origen'] ?? 'manual') === 'planilla') {
            responder(false, 'Los pagos de planilla se editan desde el módulo Planilla.');
        }
        $stmt = $pdo->prepare(
            'UPDATE transacciones
             SET tipo = :tipo, monto = :monto, categoria = :categoria,
                 descripcion = :descripcion, fecha = :fecha, contacto_id = :contacto_id
             WHERE id = :id'
        );
        $stmt->execute([
            ':tipo'        => $tipo,
            ':monto'       => $monto,
            ':categoria'   => $categoria,
            ':descripcion' => $descripcionGuardada,
            ':fecha'       => $fecha,
            ':contacto_id' => $contactoId,
            ':id'          => $id,
        ]);
        if ($stmt->rowCount() === 0) {
            // Sin cambios reales en la fila: igualmente confirmamos
            sincronizarFacturaDesdeIngreso($pdo, $id);
            responder(true, 'Movimiento sin cambios.', ['id' => $id]);
        }
        sincronizarFacturaDesdeIngreso($pdo, $id);
        responder(true, 'Movimiento actualizado.', ['id' => $id]);
    }

    /* ------------------------------------------------
        CREAR
       ------------------------------------------------ */
    if ($accion !== 'crear') {
        responder(false, 'Acción no reconocida.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO transacciones (tipo, monto, categoria, descripcion, fecha, contacto_id, origen)
         VALUES (:tipo, :monto, :categoria, :descripcion, :fecha, :contacto_id, \'manual\')'
    );
    $stmt->execute([
        ':tipo'        => $tipo,
        ':monto'       => $monto,
        ':categoria'   => $categoria,
        ':descripcion' => $descripcionGuardada,
        ':fecha'       => $fecha,
        ':contacto_id' => $contactoId,
    ]);

    $nuevoId = (int) $pdo->lastInsertId();
    // Ingreso del Registro rápido → aparece también en "Pagos de clientes"
    sincronizarFacturaDesdeIngreso($pdo, $nuevoId);

    responder(true, 'Movimiento registrado con éxito.', ['id' => $nuevoId]);
} catch (PDOException $e) {
    http_response_code(500);
    responder(false, 'Ocurrió un error al guardar. Intenta de nuevo.');
}
