<?php
/**
 * includes/functions.php
 * Toda la lógica de negocio "de lectura" vive aquí para mantener
 * index.php enfocado en presentación.
 */

declare(strict_types=1);

/**
 * Categorías permitidas, separadas por tipo de movimiento.
 * Ajustadas al rubro objetivo: negocios de remodelación,
 * construcción y limpieza en EE. UU.
 */
function categoriasDisponibles(?PDO $pdo = null): array
{
    static $cacheCategorias = null;
    if ($cacheCategorias !== null && $pdo === null) {
        return $cacheCategorias;
    }

    $defecto = [
        'ingreso' => ['Remodelación', 'Construcción', 'Limpieza', 'Otros ingresos'],
        'gasto'   => ['Materiales', 'Nómina', 'Contratistas', 'Mantenimiento de maquinaria', 'Transporte', 'Seguros y permisos', 'Servicios públicos', 'Marketing', 'Otros gastos'],
    ];

    if ($pdo === null && function_exists('conectarDB')) {
        try {
            $pdo = conectarDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo instanceof PDO) {
        try {
            $config = obtenerConfiguracion($pdo);
            $catIngreso = trim((string)($config['categorias_ingreso'] ?? ''));
            $catGasto = trim((string)($config['categorias_gasto'] ?? ''));

            $listaIngreso = $catIngreso !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $catIngreso))))
                : $defecto['ingreso'];
            $listaGasto = $catGasto !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $catGasto))))
                : $defecto['gasto'];

            $cacheCategorias = [
                'ingreso' => !empty($listaIngreso) ? $listaIngreso : $defecto['ingreso'],
                'gasto'   => !empty($listaGasto) ? $listaGasto : $defecto['gasto'],
            ];
            return $cacheCategorias;
        } catch (Throwable $e) {
            // fallback al valor por defecto
        }
    }

    $cacheCategorias = $defecto;
    return $cacheCategorias;
}

/** Categorías de gasto que representan costo de personal. */
function categoriasDePersonal(): array
{
    return ['Nómina', 'Contratistas'];
}

/** Formatea un monto numérico como moneda legible. Ej: 125000 -> "₡125,000.00" */
function formatearMonto(float $monto): string
{
    return MONEDA . number_format($monto, 2);
}

/**
 * Calcula ingresos, gastos, utilidad y margen dentro de un rango de fechas opcional.
 * Devuelve también el estado del "semáforo" del negocio.
 */
function obtenerResumen(PDO $pdo, ?string $desde = null, ?string $hasta = null, ?int $contactoId = null): array
{
    $condiciones = [];
    $params = [];

    if ($desde) {
        $condiciones[] = 'fecha >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta) {
        $condiciones[] = 'fecha <= :hasta';
        $params[':hasta'] = $hasta;
    }
    if ($contactoId !== null && $contactoId > 0) {
        $condiciones[] = 'contacto_id = :contacto_id';
        $params[':contacto_id'] = $contactoId;
    }

    $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    $sql = "SELECT
                COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) AS ingresos,
                COALESCE(SUM(CASE WHEN tipo = 'gasto'   THEN monto ELSE 0 END), 0) AS gastos
            FROM transacciones
            $where";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fila = $stmt->fetch();

    $ingresos = (float) $fila['ingresos'];
    $gastos   = (float) $fila['gastos'];
    $utilidad = $ingresos - $gastos;
    $margen   = $ingresos > 0 ? ($utilidad / $ingresos) * 100 : 0.0;

    return [
        'ingresos' => $ingresos,
        'gastos'   => $gastos,
        'utilidad' => $utilidad,
        'margen'   => $margen,
        'semaforo' => calcularSemaforo($margen, $ingresos),
    ];
}

/**
 * Traduce el margen de utilidad a un semáforo con lenguaje simple,
 * pensado para alguien sin formación financiera.
 */
function calcularSemaforo(float $margen, float $ingresos): array
{
    if ($ingresos <= 0) {
        return [
            'color'   => 'gris',
            'emoji'   => '⚪',
            'titulo'  => 'Sin movimientos todavía',
            'mensaje' => 'Registra tus primeras ventas y gastos para ver cómo va tu negocio.',
        ];
    }

    if ($margen >= 20) {
        return [
            'color'   => 'verde',
            'emoji'   => '🟢',
            'titulo'  => 'Vas muy bien',
            'mensaje' => 'Por cada ' . MONEDA . '100 que entran, te está quedando una buena parte. Sigue así.',
        ];
    }

    if ($margen >= 5) {
        return [
            'color'   => 'amarillo',
            'emoji'   => '🟡',
            'titulo'  => 'Vas bien, con cuidado',
            'mensaje' => 'Te queda algo de ganancia, pero es ajustado. Revisa si hay gastos que puedas bajar.',
        ];
    }

    return [
        'color'   => 'rojo',
        'emoji'   => '🔴',
        'titulo'  => 'Estás gastando más de lo que entra',
        'mensaje' => 'Tus gastos se están comiendo (o superando) tus ventas. Es buen momento para revisar en qué se va el dinero.',
    ];
}

/** Promedio de los gastos (para marcar gastos atípicos en el modo detalle). */
function obtenerPromedioGastos(PDO $pdo): float
{
    $stmt = $pdo->query("SELECT COALESCE(AVG(monto), 0) AS promedio FROM transacciones WHERE tipo = 'gasto'");
    return (float) $stmt->fetch()['promedio'];
}

/**
 * Devuelve las transacciones aplicando filtros opcionales de categoría y rango de fechas.
 * Todo el filtrado ocurre en PHP/SQL con consultas preparadas (sin construir SQL por concatenación).
 */
function obtenerTransacciones(PDO $pdo, array $filtros): array
{
    $condiciones = [];
    $params = [];

    if (!empty($filtros['categoria'])) {
        $condiciones[] = 'categoria = :categoria';
        $params[':categoria'] = $filtros['categoria'];
    }
    if (!empty($filtros['tipo']) && in_array($filtros['tipo'], ['ingreso', 'gasto'], true)) {
        $condiciones[] = 'tipo = :tipo';
        $params[':tipo'] = $filtros['tipo'];
    }
    if (!empty($filtros['desde'])) {
        $condiciones[] = 'fecha >= :desde';
        $params[':desde'] = $filtros['desde'];
    }
    if (!empty($filtros['hasta'])) {
        $condiciones[] = 'fecha <= :hasta';
        $params[':hasta'] = $filtros['hasta'];
    }

    $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    $sql = "SELECT id, tipo, monto, categoria, descripcion, fecha, contacto_id,
                   origen, colaborador_id, frecuencia_planilla
            FROM transacciones
            $where
            ORDER BY fecha DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Lista de categorías distintas ya usadas, para poblar el filtro del modo detalle. */
function obtenerCategoriasUsadas(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT categoria FROM transacciones ORDER BY categoria ASC');
    return array_column($stmt->fetchAll(), 'categoria');
}

/** Sanea una fecha en formato Y-m-d proveniente de $_GET/$_POST. Devuelve null si no es válida. */
function sanearFecha(?string $valor): ?string
{
    if (!$valor) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $valor);
    return ($d && $d->format('Y-m-d') === $valor) ? $valor : null;
}

/** Nombre de mes en español a partir de "YYYY-MM" (evita depender de setlocale, poco confiable entre servidores). */
function strftime_es(string $mes): string
{
    $nombres = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
                7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
    [$anio, $mesNum] = explode('-', $mes);
    return $nombres[(int) $mesNum] . ' ' . $anio;
}

/* =========================================================
   FACTURAS
   ========================================================= */

/**
 * Marca como "vencida" cualquier factura pendiente cuya fecha de
 * vencimiento ya pasó. Se llama antes de listar para que el estado
 * siempre esté al día sin necesidad de un cron.
 */
function actualizarFacturasVencidas(PDO $pdo): void
{
    $pdo->exec("UPDATE facturas
                SET estado = 'vencida'
                WHERE estado = 'pendiente'
                  AND fecha_vencimiento IS NOT NULL
                  AND fecha_vencimiento < CURDATE()");
}

/**
 * Siguiente número de factura sugerido (F-0001, F-0002, ...).
 * Respeta la numeración existente: toma el sufijo numérico más alto
 * y lo incrementa con el mismo ancho de dígitos.
 */
function siguienteNumeroFactura(PDO $pdo): string
{
    $maximo = 0;
    $ancho = 4;

    foreach ($pdo->query('SELECT numero FROM facturas')->fetchAll() as $fila) {
        if (preg_match('/(\d+)$/', (string) $fila['numero'], $coincidencia)) {
            $valor = (int) $coincidencia[1];
            if ($valor > $maximo) {
                $maximo = $valor;
                $ancho = strlen($coincidencia[1]);
            }
        }
    }

    return 'F-' . str_pad((string) ($maximo + 1), $ancho, '0', STR_PAD_LEFT);
}

/** Devuelve el id de un contacto existente por su nombre (insensible a mayúsculas) o null. */
function contactoIdPorNombre(PDO $pdo, string $nombre, string $tipo): ?int
{
    if ($nombre === '' || !in_array($tipo, ['cliente', 'proveedor'], true)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM contactos WHERE tipo = :tipo AND LOWER(nombre) = :nombre LIMIT 1');
    $stmt->execute([':tipo' => $tipo, ':nombre' => mb_strtolower($nombre)]);
    $fila = $stmt->fetch();
    return $fila ? (int) $fila['id'] : null;
}

/**
 * Asegura que la factura quede vinculada a un cliente del directorio.
 * - Si viene un contacto_id válido, lo usa.
 * - Si no, busca por nombre.
 * - Si tampoco existe, crea el cliente (para que todo factura tenga ficha).
 */
function asegurarClienteDeFactura(PDO $pdo, string $nombre, ?int $contactoId = null): ?int
{
    $nombre = trim($nombre);
    if ($nombre === '') {
        return null;
    }
    if ($contactoId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM contactos WHERE id = :id AND tipo = 'cliente'");
        $stmt->execute([':id' => $contactoId]);
        if ($stmt->fetch()) {
            return $contactoId;
        }
    }
    $existente = contactoIdPorNombre($pdo, $nombre, 'cliente');
    if ($existente) {
        return $existente;
    }
    return insertarContacto($pdo, [
        'tipo' => 'cliente',
        'nombre' => $nombre,
        'telefono' => '',
        'email' => '',
        'gasto_periodico' => 0,
        'monto_periodico' => '',
        'frecuencia' => '',
        'notas' => 'Creado automáticamente desde Facturas.',
    ]);
}

/** Número de factura ya usado por otra factura (true = duplicado). */
function numeroFacturaDuplicado(PDO $pdo, string $numero, int $excluirId = 0): bool
{
    $stmt = $pdo->prepare('SELECT id FROM facturas WHERE LOWER(numero) = :numero AND id <> :id LIMIT 1');
    $stmt->execute([':numero' => mb_strtolower($numero), ':id' => $excluirId]);
    return (bool) $stmt->fetch();
}

/**
 * Facturas del mes indicado (por defecto el mes en curso). Al llegar
 * un mes nuevo, esta misma función automáticamente deja de traer las
 * del mes anterior porque compara contra CURDATE().
 */
function obtenerFacturasDelMes(PDO $pdo, ?string $mes = null): array
{
    if ($mes && preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $anio = (int) substr($mes, 0, 4);
        $mesNum = (int) substr($mes, 5, 2);
    } else {
        $anio = (int) date('Y');
        $mesNum = (int) date('n');
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM facturas
         WHERE YEAR(fecha_emision) = :anio AND MONTH(fecha_emision) = :mes
         ORDER BY fecha_emision DESC, id DESC"
    );
    $stmt->execute([':anio' => $anio, ':mes' => $mesNum]);
    return $stmt->fetchAll();
}

/** Totales rápidos del mes: cuánto está pendiente, pagado y vencido. */
function obtenerResumenFacturas(array $facturas): array
{
    $resumen = ['pendiente' => 0.0, 'pagada' => 0.0, 'vencida' => 0.0, 'cantidad' => count($facturas)];
    foreach ($facturas as $f) {
        $resumen[$f['estado']] += (float) $f['monto'];
    }
    return $resumen;
}

function insertarFactura(PDO $pdo, array $datos): int
{
    $contactoId = asegurarClienteDeFactura(
        $pdo,
        (string) ($datos['cliente'] ?? ''),
        isset($datos['contacto_id']) ? (int) $datos['contacto_id'] : null
    );

    $stmt = $pdo->prepare(
        "INSERT INTO facturas (numero, cliente, contacto_id, monto, estado, fecha_emision, fecha_vencimiento, descripcion)
         VALUES (:numero, :cliente, :contacto_id, :monto, :estado, :fecha_emision, :fecha_vencimiento, :descripcion)"
    );
    $stmt->execute([
        ':numero' => $datos['numero'],
        ':cliente' => $datos['cliente'],
        ':contacto_id' => $contactoId,
        ':monto' => $datos['monto'],
        ':estado' => $datos['estado'] ?? 'pendiente',
        ':fecha_emision' => $datos['fecha_emision'],
        ':fecha_vencimiento' => $datos['fecha_vencimiento'] ?: null,
        ':descripcion' => $datos['descripcion'] ?: null,
    ]);

    $id = (int) $pdo->lastInsertId();

    // Si nace ya pagada, registra el ingreso y guarda el vínculo
    if (($datos['estado'] ?? 'pendiente') === 'pagada') {
        vincularIngresoDeFactura($pdo, $id);
    }

    return $id;
}

/** Edita los datos de una factura (el estado se cambia desde la tabla). */
function actualizarFactura(PDO $pdo, int $id, array $datos): bool
{
    $contactoId = asegurarClienteDeFactura(
        $pdo,
        (string) ($datos['cliente'] ?? ''),
        isset($datos['contacto_id']) ? (int) $datos['contacto_id'] : null
    );

    return $pdo->prepare(
        "UPDATE facturas
         SET numero = :numero,
             cliente = :cliente,
             contacto_id = :contacto_id,
             monto = :monto,
             fecha_emision = :fecha_emision,
             fecha_vencimiento = :fecha_vencimiento,
             descripcion = :descripcion
         WHERE id = :id"
    )->execute([
        ':numero' => $datos['numero'],
        ':cliente' => $datos['cliente'],
        ':contacto_id' => $contactoId,
        ':monto' => $datos['monto'],
        ':fecha_emision' => $datos['fecha_emision'],
        ':fecha_vencimiento' => $datos['fecha_vencimiento'] ?: null,
        ':descripcion' => $datos['descripcion'] ?: null,
        ':id' => $id,
    ]);
}

function facturaPorId(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM facturas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $factura = $stmt->fetch();
    return $factura ?: null;
}

/**
 * Crea el ingreso en transacciones de una factura pagada y guarda el
 * id en facturas.transaccion_id, para poder deshacerlo si cambia de estado.
 */
function vincularIngresoDeFactura(PDO $pdo, int $facturaId): void
{
    $factura = facturaPorId($pdo, $facturaId);
    if (!$factura || $factura['estado'] !== 'pagada' || $factura['transaccion_id']) {
        return;
    }

    $pdo->prepare(
        "INSERT INTO transacciones (tipo, monto, categoria, descripcion, fecha, contacto_id)
         VALUES ('ingreso', :monto, 'Otros ingresos', :descripcion, :fecha, :contacto_id)"
    )->execute([
        ':monto' => $factura['monto'],
        ':descripcion' => 'Factura ' . $factura['numero'] . ' - ' . $factura['cliente'],
        ':fecha' => $factura['fecha_emision'],
        ':contacto_id' => $factura['contacto_id'] ?: null,
    ]);

    $pdo->prepare('UPDATE facturas SET transaccion_id = :tid WHERE id = :id')
        ->execute([':tid' => (int) $pdo->lastInsertId(), ':id' => $facturaId]);
}

/** Elimina el ingreso vinculado (si existe) y limpia el campo. */
function desvincularIngresoDeFactura(PDO $pdo, int $facturaId): void
{
    $factura = facturaPorId($pdo, $facturaId);
    if (!$factura) {
        return;
    }
    if ($factura['transaccion_id']) {
        $pdo->prepare('DELETE FROM transacciones WHERE id = :tid')
            ->execute([':tid' => $factura['transaccion_id']]);
    }
    $pdo->prepare('UPDATE facturas SET transaccion_id = NULL WHERE id = :id')
        ->execute([':id' => $facturaId]);
}

/**
 * Sincroniza un ingreso del Registro rápido con la tabla `facturas`
 * para que aparezca en "Pagos de clientes" sin duplicar el dinero:
 * la factura nace "pagada" y apunta a la misma transacción (transaccion_id).
 * Si el movimiento ya no es ingreso, elimina la factura enlazada.
 */
function sincronizarFacturaDesdeIngreso(PDO $pdo, int $transaccionId): void
{
    if ($transaccionId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM transacciones WHERE id = :id');
    $stmt->execute([':id' => $transaccionId]);
    $tx = $stmt->fetch();

    $facturaStmt = $pdo->prepare('SELECT id FROM facturas WHERE transaccion_id = :tid LIMIT 1');
    $facturaStmt->execute([':tid' => $transaccionId]);
    $facturaVinculada = $facturaStmt->fetch();

    if (!$tx || $tx['tipo'] !== 'ingreso') {
        if ($facturaVinculada) {
            $pdo->prepare('DELETE FROM facturas WHERE id = :id')
                ->execute([':id' => (int) $facturaVinculada['id']]);
        }
        return;
    }

    $contactoId = $tx['contacto_id'] !== null ? (int) $tx['contacto_id'] : null;
    $cliente = 'Ingreso rápido';
    if ($contactoId) {
        $cStmt = $pdo->prepare("SELECT nombre FROM contactos WHERE id = :id AND tipo = 'cliente'");
        $cStmt->execute([':id' => $contactoId]);
        $cRow = $cStmt->fetch();
        if ($cRow) {
            $cliente = (string) $cRow['nombre'];
        }
    } elseif (!empty($tx['descripcion'])) {
        $cliente = mb_substr((string) $tx['descripcion'], 0, 120);
    }

    $monto = (float) $tx['monto'];
    $fecha = (string) $tx['fecha'];
    $descripcion = $tx['descripcion'] !== null ? (string) $tx['descripcion'] : ('Registro rápido · ' . $tx['categoria']);

    if ($facturaVinculada) {
        $pdo->prepare(
            "UPDATE facturas
             SET cliente = :cliente,
                 contacto_id = :contacto_id,
                 monto = :monto,
                 estado = 'pagada',
                 fecha_emision = :fecha_emision,
                 fecha_vencimiento = NULL,
                 descripcion = :descripcion
             WHERE id = :id"
        )->execute([
            ':cliente' => $cliente,
            ':contacto_id' => $contactoId,
            ':monto' => $monto,
            ':fecha_emision' => $fecha,
            ':descripcion' => $descripcion,
            ':id' => (int) $facturaVinculada['id'],
        ]);
        return;
    }

    // Insertar directamente con transaccion_id para NO crear otro ingreso (evita doble conteo)
    $pdo->prepare(
        "INSERT INTO facturas (numero, cliente, contacto_id, transaccion_id, monto, estado, fecha_emision, fecha_vencimiento, descripcion)
         VALUES (:numero, :cliente, :contacto_id, :transaccion_id, :monto, 'pagada', :fecha_emision, NULL, :descripcion)"
    )->execute([
        ':numero' => siguienteNumeroFactura($pdo),
        ':cliente' => $cliente,
        ':contacto_id' => $contactoId,
        ':transaccion_id' => $transaccionId,
        ':monto' => $monto,
        ':fecha_emision' => $fecha,
        ':descripcion' => $descripcion,
    ]);
}

/**
 * Cambia el estado de una factura. Si pasa a "pagada", registra el
 * ingreso en transacciones (y guarda el vínculo); si deja de estar
 * pagada, elimina ese ingreso para no dejar cifras huérfanas.
 */
function actualizarEstadoFactura(PDO $pdo, int $id, string $estado): bool
{
    if (!in_array($estado, ['pendiente', 'pagada', 'vencida'], true)) {
        return false;
    }

    $factura = facturaPorId($pdo, $id);
    if (!$factura) {
        return false;
    }

    $pdo->prepare('UPDATE facturas SET estado = :estado WHERE id = :id')
        ->execute([':estado' => $estado, ':id' => $id]);

    if ($estado === 'pagada' && $factura['estado'] !== 'pagada') {
        vincularIngresoDeFactura($pdo, $id);
    } elseif ($estado !== 'pagada' && $factura['estado'] === 'pagada') {
        desvincularIngresoDeFactura($pdo, $id);
    }

    return true;
}

function eliminarFactura(PDO $pdo, int $id): bool
{
    $factura = facturaPorId($pdo, $id);
    if ($factura && $factura['transaccion_id']) {
        $pdo->prepare('DELETE FROM transacciones WHERE id = :tid')
            ->execute([':tid' => $factura['transaccion_id']]);
    }
    return $pdo->prepare('DELETE FROM facturas WHERE id = :id')->execute([':id' => $id]);
}

/* =========================================================
   PLANILLA (colaboradores)
   ========================================================= */

function obtenerColaboradores(PDO $pdo, bool $soloActivos = false): array
{
    $sql = 'SELECT * FROM colaboradores';
    if ($soloActivos) {
        $sql .= ' WHERE activo = 1';
    }
    $sql .= ' ORDER BY activo DESC, nombre ASC';
    return $pdo->query($sql)->fetchAll();
}

function insertarColaborador(PDO $pdo, array $datos): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO colaboradores (nombre, puesto, tipo_pago, tarifa, activo)
         VALUES (:nombre, :puesto, :tipo_pago, :tarifa, 1)"
    );
    $stmt->execute([
        ':nombre' => $datos['nombre'],
        ':puesto' => $datos['puesto'] ?: null,
        ':tipo_pago' => $datos['tipo_pago'],
        ':tarifa' => $datos['tarifa'],
    ]);
    return (int) $pdo->lastInsertId();
}

/** Permite al dueño modificar el tipo de pago y la tarifa de un colaborador. */
function actualizarColaborador(PDO $pdo, int $id, array $datos): bool
{
    return $pdo->prepare(
        "UPDATE colaboradores
         SET nombre = :nombre, puesto = :puesto, tipo_pago = :tipo_pago, tarifa = :tarifa
         WHERE id = :id"
    )->execute([
        ':nombre' => $datos['nombre'],
        ':puesto' => $datos['puesto'] ?: null,
        ':tipo_pago' => $datos['tipo_pago'],
        ':tarifa' => $datos['tarifa'],
        ':id' => $id,
    ]);
}

function cambiarEstadoColaborador(PDO $pdo, int $id, bool $activo): bool
{
    return $pdo->prepare('UPDATE colaboradores SET activo = :activo WHERE id = :id')
        ->execute([':activo' => $activo ? 1 : 0, ':id' => $id]);
}

function eliminarColaborador(PDO $pdo, int $id): bool
{
    return $pdo->prepare('DELETE FROM colaboradores WHERE id = :id')->execute([':id' => $id]);
}

/**
 * Estimado de costo mensual de planilla. Para quienes ganan por hora
 * se asume una jornada de tiempo completo (8h x 22 días ≈ 176h/mes)
 * solo como referencia; el monto real depende de las horas que
 * reporte cada quien.
 */
function calcularCostoMensualPlanilla(array $colaboradores): float
{
    $horasMesReferencia = 176;
    $total = 0.0;
    foreach ($colaboradores as $c) {
        if (!$c['activo']) {
            continue;
        }
        $tarifa = (float) $c['tarifa'];
        $total += match ($c['tipo_pago']) {
            'hora' => $tarifa * $horasMesReferencia,
            'semana' => $tarifa * 4,
            'quincena' => $tarifa * 2,
            default => $tarifa, // 'mes'
        };
    }
    return $total;
}

/**
 * Pagos esperados de un colaborador en un mes (YYYY-MM).
 * Devuelve filas listas para transacciones tipo=gasto:
 * [{fecha, monto, descripcion, frecuencia}]
 */
function pagosPlanillaEnMes(array $colaborador, string $mes): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    $dia1 = $mes . '-01';
    $tarifa = round((float) $colaborador['tarifa'], 2);
    $nombre = (string) $colaborador['nombre'];
    $frecuencia = (string) $colaborador['tipo_pago'];

    return match ($frecuencia) {
        'semana' => [
            [
                'fecha' => $mes . '-07',
                'monto' => $tarifa,
                'descripcion' => 'Pago semanal 1 — ' . $nombre,
                'frecuencia' => 'semana',
            ],
            [
                'fecha' => $mes . '-14',
                'monto' => $tarifa,
                'descripcion' => 'Pago semanal 2 — ' . $nombre,
                'frecuencia' => 'semana',
            ],
            [
                'fecha' => $mes . '-21',
                'monto' => $tarifa,
                'descripcion' => 'Pago semanal 3 — ' . $nombre,
                'frecuencia' => 'semana',
            ],
            [
                'fecha' => $mes . '-28',
                'monto' => $tarifa,
                'descripcion' => 'Pago semanal 4 — ' . $nombre,
                'frecuencia' => 'semana',
            ],
        ],
        'quincena' => [
            [
                'fecha' => $mes . '-01',
                'monto' => $tarifa,
                'descripcion' => 'Pago quincenal 1 — ' . $nombre,
                'frecuencia' => 'quincena',
            ],
            [
                'fecha' => $mes . '-15',
                'monto' => $tarifa,
                'descripcion' => 'Pago quincenal 2 — ' . $nombre,
                'frecuencia' => 'quincena',
            ],
        ],
        'hora' => [
            [
                'fecha' => $dia1,
                'monto' => round($tarifa * 176, 2),
                'descripcion' => 'Pago por hora (est. 176h) — ' . $nombre,
                'frecuencia' => 'hora',
            ],
        ],
        default => [
            [
                'fecha' => $dia1,
                'monto' => $tarifa,
                'descripcion' => 'Salario mensual — ' . $nombre,
                'frecuencia' => 'mes',
            ],
        ],
    };
}

/**
 * Materializa los pagos de colaboradores activos como gastos de categoría
 * "Nómina" (origen=planilla) para el mes indicado, para que Reportes,
 * el dashboard y Gastos los incluyan automáticamente.
 *
 * Idempotente: crea/actualiza pagos de activos; no borra pagos históricos
 * de colaboradores hoy inactivos; al eliminar un colaborador se limpian
 * sus pagos del mes actual.
 */
function sincronizarPagosPlanillaComoGastos(PDO $pdo, ?string $mes = null): void
{
    if ($mes === null || !preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }

    foreach ([
        "ALTER TABLE transacciones ADD COLUMN origen ENUM('manual', 'planilla') NOT NULL DEFAULT 'manual'",
        'ALTER TABLE transacciones ADD COLUMN colaborador_id INT DEFAULT NULL',
        "ALTER TABLE transacciones ADD COLUMN frecuencia_planilla ENUM('mes', 'quincena', 'hora') DEFAULT NULL",
    ] as $sqlAlter) {
        try {
            $pdo->exec($sqlAlter);
        } catch (Throwable $e) {
            // Columna ya existe.
        }
    }

    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $mesActual = date('Y-m');

    $colaboradores = obtenerColaboradores($pdo);
    $porId = [];
    foreach ($colaboradores as $c) {
        $porId[(int) $c['id']] = $c;
    }

    // Pagos esperados solo para activos
    $esperados = []; // clave colaborador_id|fecha
    foreach ($colaboradores as $c) {
        if (!(int) $c['activo']) {
            continue;
        }
        foreach (pagosPlanillaEnMes($c, $mes) as $pago) {
            $clave = (int) $c['id'] . '|' . $pago['fecha'];
            $esperados[$clave] = [
                'colaborador_id' => (int) $c['id'],
                'fecha' => $pago['fecha'],
                'monto' => (float) $pago['monto'],
                'descripcion' => $pago['descripcion'],
                'frecuencia' => $pago['frecuencia'],
            ];
        }
    }

    $stmtExistentes = $pdo->prepare(
        "SELECT id, colaborador_id, fecha, monto, descripcion, frecuencia_planilla
         FROM transacciones
         WHERE origen = 'planilla' AND tipo = 'gasto'
           AND fecha >= :desde AND fecha <= :hasta"
    );
    $stmtExistentes->execute([':desde' => $desde, ':hasta' => $hasta]);
    $existentes = $stmtExistentes->fetchAll();

    $existePorClave = [];
    foreach ($existentes as $e) {
        $colabId = $e['colaborador_id'] !== null ? (int) $e['colaborador_id'] : 0;
        $existePorClave[$colabId . '|' . $e['fecha']] = $e;
    }

    $ins = $pdo->prepare(
        "INSERT INTO transacciones
            (tipo, monto, categoria, descripcion, fecha, contacto_id, origen, colaborador_id, frecuencia_planilla)
         VALUES ('gasto', :monto, 'Nómina', :descripcion, :fecha, NULL, 'planilla', :colaborador_id, :frecuencia)"
    );
    $upd = $pdo->prepare(
        "UPDATE transacciones
         SET monto = :monto, categoria = 'Nómina', descripcion = :descripcion,
             frecuencia_planilla = :frecuencia, origen = 'planilla'
         WHERE id = :id"
    );

    foreach ($esperados as $clave => $esp) {
        if (isset($existePorClave[$clave])) {
            $e = $existePorClave[$clave];
            $cambioMonto = abs((float) $e['monto'] - $esp['monto']) > 0.009;
            $cambioDesc = (string) ($e['descripcion'] ?? '') !== $esp['descripcion'];
            $cambioFreq = (string) ($e['frecuencia_planilla'] ?? '') !== $esp['frecuencia'];
            if ($cambioMonto || $cambioDesc || $cambioFreq) {
                $upd->execute([
                    ':monto' => $esp['monto'],
                    ':descripcion' => $esp['descripcion'],
                    ':frecuencia' => $esp['frecuencia'],
                    ':id' => (int) $e['id'],
                ]);
            }
            unset($existePorClave[$clave]);
            continue;
        }
        $ins->execute([
            ':monto' => $esp['monto'],
            ':descripcion' => $esp['descripcion'],
            ':fecha' => $esp['fecha'],
            ':colaborador_id' => $esp['colaborador_id'],
            ':frecuencia' => $esp['frecuencia'],
        ]);
    }

    // Colaborador eliminado: en el mes actual, retira sus pagos de planilla.
    // En meses pasados se conserva el histórico.
    if ($mes === $mesActual) {
        $del = $pdo->prepare(
            "DELETE FROM transacciones
             WHERE origen = 'planilla' AND colaborador_id = :cid
               AND fecha >= :desde AND fecha <= :hasta"
        );
        foreach ($existePorClave as $claveRestante => $e) {
            $colabId = (int) ($e['colaborador_id'] ?? 0);
            if ($colabId > 0 && !isset($porId[$colabId])) {
                $del->execute([':cid' => $colabId, ':desde' => $desde, ':hasta' => $hasta]);
            }
        }
    }
}

/**
 * Detalle de pagos de planilla del mes agrupado por frecuencia
 * (mes / quincena / hora) + filas individuales. Para Reportes.
 *
 * @return array{total: float, pagos: int, por_frecuencia: array, filas: array}
 */
function desglosePlanillaEnMes(PDO $pdo, string $mes): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));

    $stmt = $pdo->prepare(
        "SELECT t.id, t.fecha, t.monto, t.descripcion, t.frecuencia_planilla,
                COALESCE(c.nombre, '') AS colaborador
         FROM transacciones t
         LEFT JOIN colaboradores c ON c.id = t.colaborador_id
         WHERE t.origen = 'planilla'
           AND t.tipo = 'gasto'
           AND t.fecha >= :desde
           AND t.fecha <= :hasta
         ORDER BY t.frecuencia_planilla, c.nombre, t.fecha"
    );
    $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
    $filas = $stmt->fetchAll();

    $etiquetas = [
        'mes' => 'Mensual',
        'quincena' => 'Quincenal',
        'semana' => 'Semanal',
        'hora' => 'Por hora',
    ];
    $porFrecuencia = [];
    foreach ($etiquetas as $clave => $etiqueta) {
        $porFrecuencia[$clave] = [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'pagos' => 0,
            'total' => 0.0,
        ];
    }

    foreach ($filas as $f) {
        $clave = (string) ($f['frecuencia_planilla'] ?? 'mes');
        if (!isset($porFrecuencia[$clave])) {
            $clave = 'mes';
        }
        $porFrecuencia[$clave]['pagos']++;
        $porFrecuencia[$clave]['total'] += (float) $f['monto'];
    }

    $total = array_sum(array_column($porFrecuencia, 'total'));
    $nPagos = count($filas);

    return [
        'total' => $total,
        'pagos' => $nPagos,
        'por_frecuencia' => array_values(array_filter(
            $porFrecuencia,
            static fn(array $x): bool => $x['pagos'] > 0
        )),
        'filas' => $filas,
    ];
}

/* =========================================================
   CLIENTES Y PROVEEDORES (contactos)
   ========================================================= */

function obtenerContactos(PDO $pdo, ?string $tipo = null): array
{
    if ($tipo && in_array($tipo, ['cliente', 'proveedor'], true)) {
        $stmt = $pdo->prepare('SELECT * FROM contactos WHERE tipo = :tipo ORDER BY nombre ASC');
        $stmt->execute([':tipo' => $tipo]);
        return $stmt->fetchAll();
    }
    return $pdo->query('SELECT * FROM contactos ORDER BY tipo ASC, nombre ASC')->fetchAll();
}

function insertarContacto(PDO $pdo, array $datos): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO contactos (tipo, nombre, telefono, email, gasto_periodico, monto_periodico, frecuencia, notas, proyecto_finalizado)
         VALUES (:tipo, :nombre, :telefono, :email, :gasto_periodico, :monto_periodico, :frecuencia, :notas, :proyecto_finalizado)"
    );
    $stmt->execute([
        ':tipo' => $datos['tipo'],
        ':nombre' => $datos['nombre'],
        ':telefono' => $datos['telefono'] ?: null,
        ':email' => $datos['email'] ?: null,
        ':gasto_periodico' => !empty($datos['gasto_periodico']) ? 1 : 0,
        ':monto_periodico' => $datos['monto_periodico'] !== '' ? $datos['monto_periodico'] : null,
        ':frecuencia' => $datos['frecuencia'] ?: null,
        ':notas' => $datos['notas'] ?: null,
        ':proyecto_finalizado' => !empty($datos['proyecto_finalizado']) ? 1 : 0,
    ]);
    return (int) $pdo->lastInsertId();
}

function eliminarContacto(PDO $pdo, int $id): bool
{
    return $pdo->prepare('DELETE FROM contactos WHERE id = :id')->execute([':id' => $id]);
}

/** Edita los datos de un contacto (cliente o proveedor). */
function actualizarContacto(PDO $pdo, int $id, array $datos): bool
{
    return $pdo->prepare(
        "UPDATE contactos
         SET tipo = :tipo,
             nombre = :nombre,
             telefono = :telefono,
             email = :email,
             gasto_periodico = :gasto_periodico,
             monto_periodico = :monto_periodico,
             frecuencia = :frecuencia,
             notas = :notas,
             proyecto_finalizado = :proyecto_finalizado
         WHERE id = :id"
    )->execute([
        ':tipo' => $datos['tipo'],
        ':nombre' => $datos['nombre'],
        ':telefono' => $datos['telefono'] ?: null,
        ':email' => $datos['email'] ?: null,
        ':gasto_periodico' => !empty($datos['gasto_periodico']) ? 1 : 0,
        ':monto_periodico' => ($datos['monto_periodico'] ?? '') !== '' ? $datos['monto_periodico'] : null,
        ':frecuencia' => $datos['frecuencia'] ?: null,
        ':notas' => $datos['notas'] ?: null,
        ':proyecto_finalizado' => !empty($datos['proyecto_finalizado']) ? 1 : 0,
        ':id' => $id,
    ]);
}

/** Marca/desmarca el proyecto de un cliente como finalizado (0 = activo, 1 = finalizado). */
function alternarProyectoCliente(PDO $pdo, int $id, bool $finalizado): bool
{
    return $pdo->prepare(
        "UPDATE contactos SET proyecto_finalizado = :f WHERE id = :id AND tipo = 'cliente'"
    )->execute([':f' => $finalizado ? 1 : 0, ':id' => $id]);
}

/** Clientes con proyecto ACTIVO (aparecen en el filtro de reportes). */
function clientesActivosParaReportes(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, nombre FROM contactos
         WHERE tipo = 'cliente' AND proyecto_finalizado = 0
         ORDER BY nombre ASC"
    )->fetchAll();
}

/** Total facturado por cliente (según facturas vinculadas). Devuelve [contacto_id => monto]. */
function totalesFacturadosPorContacto(PDO $pdo): array
{
    $filas = $pdo->query(
        'SELECT contacto_id, SUM(monto) AS total
         FROM facturas
         WHERE contacto_id IS NOT NULL
         GROUP BY contacto_id'
    )->fetchAll();

    $totales = [];
    foreach ($filas as $fila) {
        $totales[(int) $fila['contacto_id']] = (float) $fila['total'];
    }
    return $totales;
}

/** Cantidad de facturas por cliente. Devuelve [contacto_id => cantidad]. */
function facturasPorContacto(PDO $pdo): array
{
    $filas = $pdo->query(
        'SELECT contacto_id, COUNT(*) AS cantidad
         FROM facturas
         WHERE contacto_id IS NOT NULL
         GROUP BY contacto_id'
    )->fetchAll();

    $conteos = [];
    foreach ($filas as $fila) {
        $conteos[(int) $fila['contacto_id']] = (int) $fila['cantidad'];
    }
    return $conteos;
}

/** Gastos periódicos declarados (para recordarle al dueño lo que se repite cada mes). */
function obtenerGastosPeriodicos(PDO $pdo): array
{
    return $pdo->query(
        "SELECT * FROM contactos WHERE tipo = 'proveedor' AND gasto_periodico = 1 ORDER BY monto_periodico DESC"
    )->fetchAll();
}

/* =========================================================
   REPORTES
   ========================================================= */

/** Ingresos y gastos agrupados por mes, para una gráfica de línea/barras (por defecto últimos 6 meses). */
function obtenerSerieMensual(PDO $pdo, int $meses = 6, ?int $contactoId = null): array
{
    $condiciones = ['fecha >= DATE_SUB(CURDATE(), INTERVAL :meses MONTH)'];
    $params = [':meses' => $meses];
    if ($contactoId !== null && $contactoId > 0) {
        $condiciones[] = 'contacto_id = :contacto_id';
        $params[':contacto_id'] = $contactoId;
    }
    $where = implode(' AND ', $condiciones);

    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes,
                COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) AS ingresos,
                COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END), 0) AS gastos
         FROM transacciones
         WHERE $where
         GROUP BY mes
         ORDER BY mes ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Total por categoría, para gráficas de pastel/barras (tipo = 'ingreso' o 'gasto'). */
function obtenerTotalesPorCategoria(PDO $pdo, string $tipo, ?string $desde = null, ?string $hasta = null, ?int $contactoId = null): array
{
    $condiciones = ['tipo = :tipo'];
    $params = [':tipo' => $tipo];
    if ($desde) {
        $condiciones[] = 'fecha >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta) {
        $condiciones[] = 'fecha <= :hasta';
        $params[':hasta'] = $hasta;
    }
    if ($contactoId !== null && $contactoId > 0) {
        $condiciones[] = 'contacto_id = :contacto_id';
        $params[':contacto_id'] = $contactoId;
    }
    $where = implode(' AND ', $condiciones);

    $stmt = $pdo->prepare(
        "SELECT categoria, SUM(monto) AS total
         FROM transacciones
         WHERE $where
         GROUP BY categoria
         ORDER BY total DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Ingresos y gastos del mes agrupados por cliente (contacto_id).
 * Los movimientos sin cliente van a la fila "Sin cliente".
 * Devuelve: [{cliente_id, cliente, ingresos, gastos, utilidad}, ...]
 */
function totalesPorClienteEnMes(PDO $pdo, string $mes, ?int $soloContactoId = null): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));

    $condiciones = ['t.fecha >= :desde', 't.fecha <= :hasta'];
    $params = [':desde' => $desde, ':hasta' => $hasta];
    if ($soloContactoId !== null && $soloContactoId > 0) {
        $condiciones[] = 't.contacto_id = :contacto_id';
        $params[':contacto_id'] = $soloContactoId;
    }
    $where = implode(' AND ', $condiciones);

    $stmt = $pdo->prepare(
        "SELECT t.contacto_id,
                COALESCE(c.nombre, 'Sin cliente') AS cliente,
                COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0) AS ingresos,
                COALESCE(SUM(CASE WHEN t.tipo = 'gasto'  THEN t.monto ELSE 0 END), 0) AS gastos
         FROM transacciones t
         LEFT JOIN contactos c ON c.id = t.contacto_id AND c.tipo = 'cliente'
         WHERE $where
         GROUP BY t.contacto_id, c.nombre
         ORDER BY (COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0)
                 - COALESCE(SUM(CASE WHEN t.tipo = 'gasto'  THEN t.monto ELSE 0 END), 0)) DESC,
                  COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0) DESC"
    );
    $stmt->execute($params);
    $filas = $stmt->fetchAll();

    return array_map(static function (array $f): array {
        $ingresos = (float) $f['ingresos'];
        $gastos = (float) $f['gastos'];
        return [
            'cliente_id' => $f['contacto_id'] !== null ? (int) $f['contacto_id'] : null,
            'cliente' => (string) $f['cliente'],
            'ingresos' => $ingresos,
            'gastos' => $gastos,
            'utilidad' => $ingresos - $gastos,
        ];
    }, $filas);
}

/** Compara un mes contra el anterior (ingresos, gastos y utilidad). $mes = 'YYYY-MM'. */
function obtenerComparativoMensual(PDO $pdo, ?string $mes = null, ?int $contactoId = null): array
{
    if (!$mes || !preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $anteriorMes = date('Y-m', strtotime($desde . ' -1 month'));
    $desdeAnterior = $anteriorMes . '-01';
    $hastaAnterior = date('Y-m-t', strtotime($desdeAnterior));

    return [
        'actual' => obtenerResumen($pdo, $desde, $hasta, $contactoId),
        'anterior' => obtenerResumen($pdo, $desdeAnterior, $hastaAnterior, $contactoId),
        'mes' => $mes,
        'mes_anterior' => $anteriorMes,
    ];
}

/* =========================================================
   CONFIGURACIÓN (datos del admin + tema visual)
   ========================================================= */

/** Crea las tablas faltantes si la base de datos aún no ha sido migrada por completo. */
function asegurarTablasBaseDeDatos(PDO $pdo, bool $forzar = false): void
{
    static $hecho = false;
    if ($hecho && !$forzar) {
        return;
    }
    if (!$forzar && !empty($_SESSION['tablas_esquema_listo'])) {
        $hecho = true;
        return;
    }
    $hecho = true;

    $sqls = [
        "CREATE TABLE IF NOT EXISTS transacciones (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            tipo               ENUM('ingreso', 'gasto') NOT NULL,
            monto              DECIMAL(12,2) NOT NULL,
            categoria          VARCHAR(60) NOT NULL,
            descripcion        VARCHAR(255) DEFAULT NULL,
            fecha              DATE NOT NULL,
            contacto_id        INT          DEFAULT NULL,
            origen             ENUM('manual', 'planilla') NOT NULL DEFAULT 'manual',
            colaborador_id     INT          DEFAULT NULL,
            frecuencia_planilla ENUM('mes', 'quincena', 'semana', 'hora') DEFAULT NULL,
            creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS facturas (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            numero             VARCHAR(30)  NOT NULL,
            cliente            VARCHAR(120) NOT NULL,
            contacto_id        INT          DEFAULT NULL,
            transaccion_id     INT          DEFAULT NULL,
            monto              DECIMAL(12,2) NOT NULL,
            estado             ENUM('pendiente', 'pagada', 'vencida') NOT NULL DEFAULT 'pendiente',
            fecha_emision      DATE NOT NULL,
            fecha_vencimiento  DATE DEFAULT NULL,
            descripcion        VARCHAR(255) DEFAULT NULL,
            creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS colaboradores (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            nombre      VARCHAR(120) NOT NULL,
            puesto      VARCHAR(80)  DEFAULT NULL,
            tipo_pago   ENUM('hora', 'semana', 'quincena', 'mes') NOT NULL DEFAULT 'mes',
            tarifa      DECIMAL(12,2) NOT NULL DEFAULT 0,
            activo      TINYINT(1) NOT NULL DEFAULT 1,
            creado_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS contactos (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            tipo               ENUM('cliente', 'proveedor') NOT NULL,
            nombre             VARCHAR(120) NOT NULL,
            telefono           VARCHAR(40)  DEFAULT NULL,
            email              VARCHAR(120) DEFAULT NULL,
            gasto_periodico    TINYINT(1) NOT NULL DEFAULT 0,
            monto_periodico    DECIMAL(12,2) DEFAULT NULL,
            frecuencia         ENUM('semanal', 'quincenal', 'mensual') DEFAULT NULL,
            notas              VARCHAR(255) DEFAULT NULL,
            proyecto_finalizado TINYINT(1) NOT NULL DEFAULT 0,
            creado_en          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS configuracion (
            id                 INT PRIMARY KEY DEFAULT 1,
            nombre_negocio     VARCHAR(120) DEFAULT 'Mi Negocio',
            nombre_admin       VARCHAR(120) DEFAULT NULL,
            email_admin        VARCHAR(120) DEFAULT NULL,
            telefono_admin     VARCHAR(40)  DEFAULT NULL,
            tema               ENUM('claro', 'oscuro') NOT NULL DEFAULT 'claro',
            logo               VARCHAR(255) DEFAULT NULL,
            categorias_ingreso TEXT DEFAULT NULL,
            categorias_gasto   TEXT DEFAULT NULL
        ) ENGINE=InnoDB",

        "INSERT IGNORE INTO configuracion (id, nombre_negocio) VALUES (1, 'Mi Negocio')"
    ];

    foreach ($sqls as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Ignorar excepciones secundarias si las tablas ya fueron creadas
        }
    }

    // Migración suave: agrega columnas nuevas y actualiza enums si es necesario
    foreach ([
        'ALTER TABLE configuracion ADD COLUMN logo VARCHAR(255) DEFAULT NULL',
        'ALTER TABLE configuracion ADD COLUMN categorias_ingreso TEXT DEFAULT NULL',
        'ALTER TABLE configuracion ADD COLUMN categorias_gasto TEXT DEFAULT NULL',
        'ALTER TABLE transacciones ADD COLUMN contacto_id INT DEFAULT NULL',
        'ALTER TABLE transacciones ADD COLUMN origen ENUM(\'manual\', \'planilla\') NOT NULL DEFAULT \'manual\'',
        'ALTER TABLE transacciones ADD COLUMN colaborador_id INT DEFAULT NULL',
        'ALTER TABLE transacciones MODIFY COLUMN frecuencia_planilla ENUM(\'mes\', \'quincena\', \'semana\', \'hora\') DEFAULT NULL',
        'ALTER TABLE facturas ADD COLUMN contacto_id INT DEFAULT NULL',
        'ALTER TABLE facturas ADD COLUMN transaccion_id INT DEFAULT NULL',
        'ALTER TABLE contactos ADD COLUMN proyecto_finalizado TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE colaboradores MODIFY COLUMN tipo_pago ENUM(\'hora\', \'semana\', \'quincena\', \'mes\') NOT NULL DEFAULT \'mes\'',
    ] as $sqlAlter) {
        try {
            $pdo->exec($sqlAlter);
        } catch (Throwable $e) {
            // La columna ya existe o ya está actualizada: se ignora.
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['tablas_esquema_listo'] = true;
    }
}

/** Trae la fila única de configuración; si no existe (BD recién migrada o tabla faltante) la crea automáticamente. */
function obtenerConfiguracion(PDO $pdo): array
{
    $fallback = [
        'id' => 1,
        'nombre_negocio' => 'Mi Negocio',
        'nombre_admin' => null,
        'email_admin' => null,
        'telefono_admin' => null,
        'tema' => 'claro',
        'logo' => null,
    ];

    try {
        $stmt = $pdo->query('SELECT * FROM configuracion WHERE id = 1');
        $config = $stmt->fetch();

        if (!$config) {
            $pdo->exec("INSERT INTO configuracion (id, nombre_negocio) VALUES (1, 'Mi Negocio')");
            $stmt = $pdo->query('SELECT * FROM configuracion WHERE id = 1');
            $config = $stmt->fetch();
        }

        return $config ?: $fallback;
    } catch (PDOException $e) {
        // Si la tabla no existe en MySQL (error 1146/42S02), intentamos auto-crear las tablas requeridas
        asegurarTablasBaseDeDatos($pdo);
        try {
            $stmt = $pdo->query('SELECT * FROM configuracion WHERE id = 1');
            $config = $stmt->fetch();
            return $config ?: $fallback;
        } catch (Throwable $ex) {
            return $fallback;
        }
    }
}

function guardarConfiguracion(PDO $pdo, array $datos): bool
{
    $campos = [
        'nombre_negocio = :nombre_negocio',
        'nombre_admin = :nombre_admin',
        'email_admin = :email_admin',
        'telefono_admin = :telefono_admin',
        'tema = :tema',
    ];
    $params = [
        ':nombre_negocio' => $datos['nombre_negocio'] ?: 'Mi Negocio',
        ':nombre_admin' => $datos['nombre_admin'] ?: null,
        ':email_admin' => $datos['email_admin'] ?: null,
        ':telefono_admin' => $datos['telefono_admin'] ?: null,
        ':tema' => in_array($datos['tema'] ?? '', ['claro', 'oscuro'], true) ? $datos['tema'] : 'claro',
    ];

    if (array_key_exists('categorias_ingreso', $datos)) {
        $campos[] = 'categorias_ingreso = :categorias_ingreso';
        $params[':categorias_ingreso'] = $datos['categorias_ingreso'] !== '' ? $datos['categorias_ingreso'] : null;
    }
    if (array_key_exists('categorias_gasto', $datos)) {
        $campos[] = 'categorias_gasto = :categorias_gasto';
        $params[':categorias_gasto'] = $datos['categorias_gasto'] !== '' ? $datos['categorias_gasto'] : null;
    }

    $sql = 'UPDATE configuracion SET ' . implode(', ', $campos) . ' WHERE id = 1';

    try {
        return $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        asegurarTablasBaseDeDatos($pdo, true);
        return $pdo->prepare($sql)->execute($params);
    }
}

/* =========================================================
   USUARIOS Y ACCESOS (Configuración)
   Gestión de cuentas: el superadmin crea/elimina/desactiva
   usuarios, y cada usuario puede cambiar su propio perfil
   y contraseña. Las funciones devuelven true o array en
   caso de éxito, o un string con el motivo del error.
   ========================================================= */

/** Lista de usuarios para la sección "Usuarios y accesos". */
function listarUsuarios(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM usuarios ORDER BY id ASC')->fetchAll();
}

/** Busca un usuario por correo (devuelve la fila o false). */
function usuarioPorEmail(PDO $pdo, string $email): array|false
{
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    $fila = $stmt->fetch();
    return $fila ?: false;
}

/** Cuántos superadmins activos hay (para no dejar la app sin administrador). */
function contarSuperadminsActivos(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) AS c FROM usuarios WHERE rol = 'superadmin' AND activo = 1")->fetch()['c'];
}

/** Crea un usuario. Devuelve la fila nueva o un string con el error. */
function crearUsuario(PDO $pdo, array $d): array|string
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $email = strtolower(trim((string) ($d['email'] ?? '')));
    $clave = (string) ($d['password'] ?? '');
    $rol = (string) ($d['rol'] ?? 'dueno');

    if ($nombre === '') {
        return 'El nombre es obligatorio.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'El correo no es válido.';
    }
    if (mb_strlen($clave) < 6) {
        return 'La contraseña debe tener al menos 6 caracteres.';
    }
    if (!in_array($rol, ['dueno', 'superadmin'], true)) {
        return 'Rol no reconocido.';
    }
    if (usuarioPorEmail($pdo, $email)) {
        return 'Ya existe un usuario con ese correo.';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nombre, email, password_hash, rol)
         VALUES (:n, :e, :p, :r)'
    );
    $stmt->execute([
        ':n' => $nombre,
        ':e' => $email,
        ':p' => password_hash($clave, PASSWORD_DEFAULT),
        ':r' => $rol,
    ]);

    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
}

/** Cambia la contraseña de un usuario. Devuelve true o string de error. */
function cambiarContrasenaUsuario(PDO $pdo, int $id, string $claveActual, string $claveNueva): true|string
{
    if (mb_strlen($claveNueva) < 6) {
        return 'La nueva contraseña debe tener al menos 6 caracteres.';
    }

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        return 'El usuario ya no existe.';
    }
    if (!password_verify($claveActual, $usuario['password_hash'])) {
        return 'La contraseña actual no es correcta.';
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET password_hash = :p WHERE id = :id');
    $stmt->execute([':p' => password_hash($claveNueva, PASSWORD_DEFAULT), ':id' => $id]);
    return true;
}

/** Actualiza nombre y/o correo de un usuario (su propio perfil). Devuelve la fila o string de error. */
function actualizarPerfilUsuario(PDO $pdo, int $id, array $d): array|string
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $email = strtolower(trim((string) ($d['email'] ?? '')));

    if ($nombre === '') {
        return 'Tu nombre no puede quedar vacío.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'El correo no es válido.';
    }

    $existente = usuarioPorEmail($pdo, $email);
    if ($existente && (int) $existente['id'] !== $id) {
        return 'Ese correo ya está en uso por otra cuenta.';
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET nombre = :n, email = :e WHERE id = :id');
    $stmt->execute([':n' => $nombre, ':e' => $email, ':id' => $id]);
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
}

/**
 * Elimina un usuario. Protecciones: no se permite borrar la propia
 * cuenta ni dejar la app sin un superadmin activo.
 */
function eliminarUsuario(PDO $pdo, int $id, int $idSesion): true|string
{
    if ($id === $idSesion) {
        return 'No puedes eliminar tu propia cuenta.';
    }

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $objetivo = $stmt->fetch();

    if (!$objetivo) {
        return 'El usuario ya no existe.';
    }
    if ($objetivo['rol'] === 'superadmin' && (int) $objetivo['activo'] === 1 && contarSuperadminsActivos($pdo) <= 1) {
        return 'No se puede eliminar el único superadmin activo.';
    }

    $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return true;
}

/**
 * Activa o desactiva un usuario. Protecciones: no a la propia cuenta
 * y no si se quedaría la app sin superadmin activo.
 */
function cambiarEstadoUsuario(PDO $pdo, int $id, int $idSesion, bool $activo): true|string
{
    if ($id === $idSesion) {
        return 'No puedes desactivar tu propia cuenta.';
    }

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $objetivo = $stmt->fetch();

    if (!$objetivo) {
        return 'El usuario ya no existe.';
    }
    if (!$activo && $objetivo['rol'] === 'superadmin' && (int) $objetivo['activo'] === 1 && contarSuperadminsActivos($pdo) <= 1) {
        return 'No se puede desactivar el único superadmin activo.';
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET activo = :a WHERE id = :id');
    $stmt->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    return true;
}

/** Trae la ruta relativa de la carpeta donde se guardan los logos. */
function rutaCarpetaUploads(): string
{
    return __DIR__ . '/../uploads';
}

/** Guarda la imagen recibida en $archivo ($_FILES['logo']) como el logo del negocio. Devuelve la ruta relativa o string de error. */
function guardarLogoNegocio(array $archivo): string
{
    if (!isset($archivo['error']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return 'No se pudo subir el archivo. Intenta de nuevo.';
    }
    if ((int) $archivo['size'] > 2 * 1024 * 1024) {
        return 'La imagen es demasiado grande (máximo 2 MB).';
    }

    $ext = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
        return 'Formato no permitido. Usa PNG, JPG, WEBP o SVG.';
    }

    $carpeta = rutaCarpetaUploads();
    if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
        return 'No se pudo crear la carpeta de subidas.';
    }

    $nombreFinal = 'logo-negocio.' . $ext;
    $destino = $carpeta . '/' . $nombreFinal;

    if (!@move_uploaded_file((string) $archivo['tmp_name'], $destino)) {
        return 'No se pudo guardar el archivo.';
    }

    return 'uploads/' . $nombreFinal;
}

/** Elimina el logo guardado y limpia la configuración. */
function eliminarLogoNegocio(PDO $pdo, string $ruta): void
{
    if ($ruta !== '' && str_starts_with($ruta, 'uploads/') && !str_contains($ruta, '..')) {
        $absoluta = rutaCarpetaUploads() . '/' . basename($ruta);
        if (is_file($absoluta)) {
            @unlink($absoluta);
        }
    }
    $pdo->prepare('UPDATE configuracion SET logo = NULL WHERE id = 1')->execute();
}

/* =========================================================
   AUDITORÍA (solo superadmin)
   Análisis para que el superadmin pueda exponerle al dueño
   sus falencias y buenas prácticas de administración.
   ========================================================= */

/**
 * Devuelve los KPIs y los hallazgos del negocio:
 *  - kpis:      etiqueta, valor, clase (para colorear la tarjeta)
 *  - hallazgos: severidad (peligro|alerta|bien), titulo, detalle, recomendacion
 *  - conteo:    cuántos peligros, alertas y buenas prácticas hay
 */
function analizarNegocio(PDO $pdo): array
{
    actualizarFacturasVencidas($pdo);

    $mesDesde = date('Y-m-01');
    $mesHasta = date('Y-m-t');

    $resumen = obtenerResumen($pdo, $mesDesde, $mesHasta);
    $comparativo = obtenerComparativoMensual($pdo);
    $promedioGastos = obtenerPromedioGastos($pdo);
    $umbralAtipico = $promedioGastos * 1.5;

    /* ---- Facturas ---- */
    $facturas = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN estado = 'pendiente' THEN monto ELSE 0 END), 0) AS pendiente,
            COALESCE(SUM(CASE WHEN estado = 'vencida'   THEN monto ELSE 0 END), 0) AS vencida,
            COALESCE(SUM(CASE WHEN estado = 'vencida'   THEN 1 ELSE 0 END), 0)     AS vencida_n,
            COALESCE(SUM(CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END), 0) AS sin_vencimiento,
            COUNT(*) AS total,
            COALESCE(AVG(monto), 0) AS ticket
         FROM facturas"
    )->fetch();

    $ingresosMes = (float) $comparativo['actual']['ingresos'];
    $gastosMes = (float) $comparativo['actual']['gastos'];

    /* ---- Días sin registrar movimientos ---- */
    $ultimaFecha = $pdo->query('SELECT MAX(fecha) AS f FROM transacciones')->fetch()['f'];
    $diasSinRegistro = $ultimaFecha
        ? (int) floor((time() - strtotime($ultimaFecha)) / 86400)
        : null;

    /* ---- Gastos atípicos (últimos 60 días) ---- */
    $stmt = $pdo->prepare(
        "SELECT categoria, descripcion, monto, fecha
         FROM transacciones
         WHERE tipo = 'gasto'
           AND fecha >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
           AND monto > :umbral
         ORDER BY monto DESC"
    );
    $stmt->execute([':umbral' => $umbralAtipico]);
    $gastosAtipicos = $stmt->fetchAll();

    /* ---- Contactos incompletos ---- */
    $contactosIncompletos = (int) $pdo->query(
        "SELECT COUNT(*) AS c FROM contactos
         WHERE (telefono IS NULL OR telefono = '')
           AND (email IS NULL OR email = '')"
    )->fetch()['c'];

    /* ---- Concentración en un solo cliente ---- */
    $topCliente = null;
    $totalFacturado = 0.0;
    foreach ($pdo->query('SELECT cliente, SUM(monto) AS total FROM facturas GROUP BY cliente ORDER BY total DESC')->fetchAll() as $fila) {
        $totalFacturado += (float) $fila['total'];
        if ($topCliente === null) {
            $topCliente = $fila;
        }
    }
    $participacionTop = $totalFacturado > 0 ? ((float) $topCliente['total'] / $totalFacturado) * 100 : 0.0;

    /* ---- Costo de personal sobre ingresos del mes ---- */
    $catsPersonal = categoriasDePersonal();
    $marcasPersonal = implode(',', array_fill(0, count($catsPersonal), '?'));
    $stmtPersonal = $pdo->prepare(
        "SELECT COALESCE(SUM(monto), 0) AS total
         FROM transacciones
         WHERE tipo = 'gasto'
           AND categoria IN ($marcasPersonal)
           AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    );
    $stmtPersonal->execute($catsPersonal);
    $personalMes = (float) $stmtPersonal->fetch()['total'];
    $proporcionPersonal = $ingresosMes > 0 ? ($personalMes / $ingresosMes) * 100 : 0.0;

    /* ---- Gastos periódicos declarados, normalizados a mensual ---- */
    $mensualEquivalente = 0.0;
    foreach (obtenerGastosPeriodicos($pdo) as $gasto) {
        $monto = (float) ($gasto['monto_periodico'] ?? 0);
        $mensualEquivalente += match ($gasto['frecuencia']) {
            'semanal' => $monto * 4.33,
            'quincenal' => $monto * 2,
            default => $monto,
        };
    }

    /* ---- Comparativo del mes ---- */
    $difUtilidad = (float) $comparativo['actual']['utilidad'] - (float) $comparativo['anterior']['utilidad'];

    /* ---- NUEVOS: métricas numéricas del mes con guardas de ceros ---- */
    // Categoría de gasto dominante del mes
    $catGastoDominante = $pdo->query(
        "SELECT categoria, SUM(monto) AS total
         FROM transacciones
         WHERE tipo = 'gasto'
           AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
         GROUP BY categoria
         ORDER BY total DESC
         LIMIT 1"
    )->fetch();
    $gastoMesTotal = (float) $pdo->query(
        "SELECT COALESCE(SUM(monto), 0) FROM transacciones
         WHERE tipo = 'gasto' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn();
    $catGastoDomPct = $gastoMesTotal > 0 && $catGastoDominante
        ? ((float) $catGastoDominante['total'] / $gastoMesTotal) * 100
        : 0.0;

    // Categoría de ingreso dominante del mes
    $catIngresoDominante = $pdo->query(
        "SELECT categoria, SUM(monto) AS total
         FROM transacciones
         WHERE tipo = 'ingreso'
           AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
         GROUP BY categoria
         ORDER BY total DESC
         LIMIT 1"
    )->fetch();
    $ingresoMesTotal = (float) $pdo->query(
        "SELECT COALESCE(SUM(monto), 0) FROM transacciones
         WHERE tipo = 'ingreso' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn();
    $catIngresoDomPct = $ingresoMesTotal > 0 && $catIngresoDominante
        ? ((float) $catIngresoDominante['total'] / $ingresoMesTotal) * 100
        : 0.0;

    // Ticket promedio facturas del mes actual vs anterior
    $facturasMes = $pdo->query(
        "SELECT COUNT(*) AS n, COALESCE(AVG(monto), 0) AS ticket
         FROM facturas
         WHERE fecha_emision >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetch();
    $facturasMesAnt = $pdo->query(
        "SELECT COUNT(*) AS n, COALESCE(AVG(monto), 0) AS ticket
         FROM facturas
         WHERE fecha_emision >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
           AND fecha_emision < DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetch();
    $ticketMes = (float) $facturasMes['ticket'];
    $ticketMesAnt = (float) $facturasMesAnt['ticket'];
    $ticketVarPct = $ticketMesAnt > 0 ? (($ticketMes - $ticketMesAnt) / $ticketMesAnt) * 100 : 0.0;

    // Constancia: días con al menos un movimiento en el mes
    $diasConMov = (int) $pdo->query(
        "SELECT COUNT(DISTINCT fecha) FROM transacciones
         WHERE fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn();
    $diasDelMes = (int) date('t');

    // Cobranza del mes: facturas pagadas vs emitidas
    $cobranzaMes = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN estado = 'pagada' THEN monto ELSE 0 END), 0) AS pagado,
            COALESCE(SUM(monto), 0) AS emitido
         FROM facturas
         WHERE fecha_emision >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetch();
    $cobradoMes = (float) $cobranzaMes['pagado'];
    $emitidoMes = (float) $cobranzaMes['emitido'];
    $cobranzaPct = $emitidoMes > 0 ? ($cobradoMes / $emitidoMes) * 100 : 0.0;

    // Evolución de ingresos vs mes anterior (ya existe $ingresosMes y $comparativo['anterior']['ingresos'])
    $ingresosMesAnt = (float) $comparativo['anterior']['ingresos'];
    $ingresosVarPct = $ingresosMesAnt > 0 ? (($ingresosMes - $ingresosMesAnt) / $ingresosMesAnt) * 100 : 0.0;

    /* =========================================================
       HALLAZGOS
       ========================================================= */
    $hallazgos = [];
    $agregar = function (string $severidad, string $titulo, string $detalle, string $recomendacion) use (&$hallazgos): void {
        $hallazgos[] = [
            'severidad'     => $severidad,
            'titulo'        => $titulo,
            'detalle'       => $detalle,
            'recomendacion' => $recomendacion,
        ];
    };

    // 1. Margen de utilidad
    if ($resumen['ingresos'] <= 0) {
        $agregar('alerta', 'Aún no hay ingresos registrados',
            'Sin ingresos no se puede evaluar la salud financiera del negocio.',
            'Empieza por registrar los trabajos cobrados del mes, aunque sea de forma semanal.');
    } elseif ($resumen['margen'] < 5) {
        $agregar('peligro', 'Margen de utilidad crítico',
            'El margen actual es de ' . number_format($resumen['margen'], 1) . '%: casi todo lo que entra se va en gastos.',
            'Revisa precio de venta por trabajo y compara contra costo real de materiales + mano de obra antes de cotizar el próximo proyecto.');
    } elseif ($resumen['margen'] < 20) {
        $agregar('alerta', 'Margen de utilidad ajustado',
            'El margen actual es de ' . number_format($resumen['margen'], 1) . '% (lo recomendado en el rubro es 20% o más).',
            'Identifica los tres gastos más grandes del mes y define cuáles puedes reducir o renegociar.');
    } else {
        $agregar('bien', 'Buen margen de utilidad',
            'El margen actual es de ' . number_format($resumen['margen'], 1) . '%, por encima del 20% recomendado.',
            'Mantén el hábito de cotizar con margen y revisa el semáforo cada semana.');
    }

    // 2. Facturas vencidas (cobranza)
    if ((float) $facturas['vencida'] > 0) {
        $agregar('peligro', 'Hay facturas vencidas por cobrar',
            $facturas['vencida_n'] . ' factura(s) vencida(s) suman ' . formatearMonto((float) $facturas['vencida']) . '.',
            'Agenda llamadas de cobro esta semana; en remodelación conviene cobrar anticipos de 50% al firmar el trabajo.');
    } else {
        $agregar('bien', 'Sin facturas vencidas',
            'Ninguna factura está vencida en este momento.',
            'Mantén la regla: vencimiento claro en cada factura y seguimiento automático.');
    }

    // 3. Facturas sin fecha de vencimiento
    if ((int) $facturas['sin_vencimiento'] > 0) {
        $agregar('alerta', 'Facturas sin fecha de vencimiento',
            (int) $facturas['sin_vencimiento'] . ' factura(s) no tienen fecha de vencimiento, así que no hay forma de saber cuándo cobrar.',
            'Define términos de pago estándar (por ejemplo, Net 15 o Net 30) y agrégalos al editar cada factura.');
    } else {
        $agregar('bien', 'Todas las facturas tienen vencimiento',
            'Cada factura registrada tiene fecha de vencimiento definida.',
            'Ese es el hábito correcto para no perder dinero en cuentas por cobrar.');
    }

    // 4. Cartera pendiente frente a los ingresos del mes
    $cartera = (float) $facturas['pendiente'] + (float) $facturas['vencida'];
    if ($ingresosMes > 0 && $cartera > $ingresosMes * 0.5) {
        $agregar('alerta', 'Mucha plata por cobrar frente a lo cobrado',
            'Hay ' . formatearMonto($cartera) . ' pendientes, equivalente a más de la mitad de los ingresos del mes (' . formatearMonto($ingresosMes) . ').',
            'Cobra lo vencido antes de asumir nuevos compromisos de compra de materiales.');
    } elseif ($facturas['total'] > 0) {
        $agregar('bien', 'Cartera de cobro bajo control',
            'Lo pendiente por cobrar (' . formatearMonto($cartera) . ') es proporcional a lo que ya cobraste este mes.',
            'Sigue emitiendo factura con vencimiento en cada trabajo terminado.');
    }

    // 5. Gastos atípicos
    if (!empty($gastosAtipicos)) {
        $ejemplos = array_map(
            fn($g) => formatearMonto((float) $g['monto']) . ' — ' . ($g['descripcion'] ?: $g['categoria']),
            array_slice($gastosAtipicos, 0, 3)
        );
        $agregar('alerta', 'Gastos inusualmente grandes (últimos 60 días)',
            count($gastosAtipicos) . ' gasto(s) superan 1.5 veces el promedio. Ejemplos: ' . implode(' · ', $ejemplos) . '.',
            'Verifica que sean gastos justificados del negocio y no compras personales mezcladas; si se repiten, actualiza tu presupuesto.');
    } else {
        $agregar('bien', 'Sin gastos inusuales recientes',
            'Ningún gasto de los últimos 60 días supera 1.5 veces el promedio.',
            'Los gastos se mantienen consistentes con el historial.');
    }

    // 6. Constancia en el registro
    if ($diasSinRegistro === null || $diasSinRegistro > 14) {
        $agregar('peligro', 'Llevas tiempo sin registrar movimientos',
            $diasSinRegistro === null
                ? 'Todavía no hay movimientos registrados.'
                : 'El último movimiento tiene ' . $diasSinRegistro . ' días.',
            'Dedica 5 minutos cada viernes a registrar entradas y salidas; los datos viejos no se recuperan.');
    } elseif ($diasSinRegistro > 7) {
        $agregar('alerta', 'El registro está dejando de ser constante',
            'El último movimiento tiene ' . $diasSinRegistro . ' días.',
            'Vuelve al hábito semanal: sin datos al día el semáforo no refleja la realidad.');
    } else {
        $agregar('bien', 'Registro constante',
            'El último movimiento tiene ' . $diasSinRegistro . ' día(s).',
            'Ese es el nivel de constancia que hace útil el panel.');
    }

    // 7. Contactos incompletos
    if ($contactosIncompletos > 0) {
        $agregar('alerta', 'Contactos sin forma de comunicarse',
            $contactosIncompletos . ' contacto(s) no tienen ni teléfono ni correo registrados.',
            'Completa al menos un medio de contacto: es tu lista para recordatorios de pago y trabajo de referencia.');
    } else {
        $agregar('bien', 'Directorio de contactos completo',
            'Todos los contactos tienen al menos teléfono o correo.',
            'Aprovecha esa lista para pedir referidos después de cada trabajo bien hecho.');
    }

    // 8. Concentración en un solo cliente
    if ($totalFacturado > 0 && $participacionTop >= 50) {
        $agregar('alerta', 'Dependes de un solo cliente',
            'El cliente "' . $topCliente['cliente'] . '" concentra el ' . number_format($participacionTop, 0) . '% de lo facturado.',
            'Si ese trabajo se termina, los ingresos caen de golpe: busca cerrar 2 o 3 trabajos más este mes.');
    } elseif ($totalFacturado > 0) {
        $agregar('bien', 'Cartera de clientes diversificada',
            'Ningún cliente concentra más del ' . number_format($participacionTop, 0) . '% de lo facturado.',
            'Buen equilibrio: un cliente que no paga no paraliza el negocio.');
    }

    // 9. Costo de personal
    if ($ingresosMes > 0 && $proporcionPersonal > 60) {
        $agregar('peligro', 'El costo de personal se está comiendo los ingresos',
            'Nómina y contratistas suman ' . number_format($proporcionPersonal, 0) . '% de los ingresos del mes (' . formatearMonto($personalMes) . ').',
            'Revisa precios por trabajo y horas reales por obra: en remodelación el costo de mano de obra debe rondar el 30-40% de lo cobrado.');
    } elseif ($ingresosMes > 0 && $proporcionPersonal > 40) {
        $agregar('alerta', 'Costo de personal por encima de lo recomendado',
            'Nómina y contratistas suman ' . number_format($proporcionPersonal, 0) . '% de los ingresos del mes (ideal: hasta 40%).',
            'Compara lo que cobras por trabajo contra las horas que consume la cuadrilla.');
    } elseif ($ingresosMes > 0) {
        $agregar('bien', 'Costo de personal controlado',
            'Nómina y contratistas suman ' . number_format($proporcionPersonal, 0) . '% de los ingresos del mes.',
            'Dentro del rango sano para el rubro.');
    }

    // 10. Gastos periódicos no reflejados en el registro
    if ($mensualEquivalente > 0 && $gastosMes > 0 && $mensualEquivalente > $gastosMes) {
        $agregar('peligro', 'Gastos fijos que no estás registrando',
            'Los proveedores con gasto periódico declarado equivalen a ' . formatearMonto($mensualEquivalente)
                . ' al mes, pero solo registraste ' . formatearMonto($gastosMes) . ' en gastos este mes.',
            'Registra cada gasto fijo cuando ocurre: si no entra en el panel, el margen y el semáforo mienten.');
    } elseif ($mensualEquivalente > 0) {
        $agregar('bien', 'Gastos fijos reflejados en el registro',
            'Los gastos periódicos declarados (' . formatearMonto($mensualEquivalente) . '/mes) están dentro de lo registrado.',
            'Los números del panel reflejan tus compromisos fijos.');
    }

    // 11. Documentación con facturas
    if ((int) $facturas['total'] === 0) {
        $agregar('alerta', 'No estás emitiendo facturas',
            'No hay ninguna factura registrada en el sistema.',
            'Factura cada trabajo: además de cobrar mejor, te deja ver cuánto factura cada mes y quién debe.');
    } else {
        $agregar('bien', 'Trabajos documentados con factura',
            (int) $facturas['total'] . ' factura(s) registradas, con ticket promedio de ' . formatearMonto((float) $facturas['ticket']) . '.',
            'Sigue facturando cada trabajo; ese historial sirve para pedir crédito y para estimar ingresos.');
    }

    // 12. Comparativo del mes
    if ($comparativo['anterior']['ingresos'] > 0) {
        $variacion = ($difUtilidad < 0) ? 'bajó' : 'subió';
        if ($difUtilidad < 0) {
            $agregar('alerta', 'La utilidad bajó frente al mes anterior',
                'La utilidad ' . $variacion . ' ' . formatearMonto(abs($difUtilidad))
                    . ' respecto al mes pasado (pasó de ' . formatearMonto((float) $comparativo['anterior']['utilidad'])
                    . ' a ' . formatearMonto((float) $comparativo['actual']['utilidad']) . ').',
                'Revisa si hubo gastos extraordinarios o si faltaron trabajos entrantes.');
        } else {
            $agregar('bien', 'La utilidad creció frente al mes anterior',
                'La utilidad ' . $variacion . ' ' . formatearMonto(abs($difUtilidad)) . ' respecto al mes pasado.',
                'Repite lo que hiciste este mes: captación, precios y control de gastos.');
        }
    }

    /* =========================================================
       NUEVOS HALLAZGOS NUMÉRICOS DEL MES (con guardas de ceros)
       ========================================================= */

    // 13. Categoría de gasto dominante del mes
    if ($gastoMesTotal > 0 && $catGastoDominante && $catGastoDomPct >= 40) {
        $agregar('alerta', 'Gastos concentrados en una categoría',
            'La categoría "' . $catGastoDominante['categoria'] . '" representa el ' . number_format($catGastoDomPct, 0) . '% de los gastos del mes (' . formatearMonto((float) $catGastoDominante['total']) . ').',
            'Revisa si puedes renegociar proveedores o buscar alternativas en esa partida.');
    } elseif ($gastoMesTotal > 0 && $catGastoDominante) {
        $agregar('bien', 'Gastos diversificados por categoría',
            'La mayor categoría de gasto es "' . $catGastoDominante['categoria'] . '" con el ' . number_format($catGastoDomPct, 0) . '% del total.',
            'Los gastos están bien distribuidos entre categorías.');
    }

    // 14. Categoría de ingreso dominante del mes
    if ($ingresoMesTotal > 0 && $catIngresoDominante && $catIngresoDomPct >= 50) {
        $agregar('alerta', 'Ingresos concentrados en un tipo de trabajo',
            'La categoría "' . $catIngresoDominante['categoria'] . '" genera el ' . number_format($catIngresoDomPct, 0) . '% de los ingresos del mes.',
            'Diversifica: promociona los otros servicios para no depender de un solo tipo de trabajo.');
    } elseif ($ingresoMesTotal > 0 && $catIngresoDominante) {
        $agregar('bien', 'Ingresos diversificados por categoría',
            'La principal categoría de ingreso es "' . $catIngresoDominante['categoria'] . '" con el ' . number_format($catIngresoDomPct, 0) . '%.',
            'Buena mezcla de tipos de trabajo este mes.');
    }

    // 15. Ticket promedio facturas mes actual vs anterior
    if ($facturasMes['n'] > 0 && $facturasMesAnt['n'] > 0 && abs($ticketVarPct) >= 20) {
        $dir = $ticketVarPct > 0 ? 'subió' : 'bajó';
        $agregar('alerta', 'Ticket promedio ' . $dir . ' ' . number_format(abs($ticketVarPct), 0) . '% vs mes anterior',
            'Ticket actual: ' . formatearMonto($ticketMes) . ' (' . (int) $facturasMes['n'] . ' facturas) vs ' . formatearMonto($ticketMesAnt) . ' mes anterior (' . (int) $facturasMesAnt['n'] . ' facturas).',
            $ticketVarPct > 0
                ? 'Buen momento para subir precios o cerrar trabajos de mayor valor.'
                : 'Revisa si estás cotizando bajo o si los trabajos son de menor alcance.');
    } elseif ($facturasMes['n'] > 0) {
        $agregar('bien', 'Ticket promedio estable',
            'Ticket actual: ' . formatearMonto($ticketMes) . ' (' . (int) $facturasMes['n'] . ' facturas).',
            'El valor promedio por factura se mantiene consistente.');
    }

    // 16. Constancia: días con movimientos en el mes
    if ($diasConMov >= $diasDelMes * 0.7) {
        $agregar('bien', 'Registro diario consistente',
            'Registraste movimientos en ' . $diasConMov . ' de ' . $diasDelMes . ' días del mes (' . number_format(($diasConMov / $diasDelMes) * 100, 0) . '%).',
            'Esa constancia hace que el panel refleje la realidad del negocio.');
    } elseif ($diasConMov >= $diasDelMes * 0.3) {
        $agregar('alerta', 'Registro intermitente este mes',
            'Solo ' . $diasConMov . ' de ' . $diasDelMes . ' días tienen movimientos registrados.',
            'Intenta registrar al menos cada 2-3 días; los huecos ciegan el semáforo.');
    } elseif ($diasConMov > 0) {
        $agregar('peligro', 'Muy pocos días con registro este mes',
            'Solo ' . $diasConMov . ' día(s) con movimientos en ' . $diasDelMes . ' días.',
            'Sin datos diarios el semáforo y los KPIs no son fiables.');
    }

    // 17. Cobranza del mes (pagadas vs emitidas)
    if ($emitidoMes > 0 && $cobranzaPct < 50) {
        $agregar('peligro', 'Cobranza del mes muy baja',
            'Solo has cobrado el ' . number_format($cobranzaPct, 0) . '% de lo facturado este mes (' . formatearMonto($cobradoMes) . ' de ' . formatearMonto($emitidoMes) . ').',
            'Prioriza cobrar lo vencido antes de comprar materiales para nuevos trabajos.');
    } elseif ($emitidoMes > 0 && $cobranzaPct < 80) {
        $agregar('alerta', 'Cobranza del mes mejorable',
            'Has cobrado el ' . number_format($cobranzaPct, 0) . '% de lo facturado este mes.',
            'Da seguimiento a facturas pendientes esta semana; cobra antes de asumir nuevos gastos.');
    } elseif ($emitidoMes > 0) {
        $agregar('bien', 'Buena cobranza del mes',
            'Has cobrado el ' . number_format($cobranzaPct, 0) . '% de lo facturado este mes (' . formatearMonto($cobradoMes) . ' de ' . formatearMonto($emitidoMes) . ').',
            'El flujo de caja está sano: cobras lo que facturas.');
    }

    // 18. Evolución de ingresos vs mes anterior
    if ($ingresosMesAnt > 0 && abs($ingresosVarPct) >= 15) {
        $dir = $ingresosVarPct > 0 ? 'subieron' : 'bajaron';
        $agregar($ingresosVarPct > 0 ? 'bien' : 'alerta', 'Ingresos ' . $dir . ' ' . number_format(abs($ingresosVarPct), 0) . '% vs mes anterior',
            'Ingresos mes actual: ' . formatearMonto($ingresosMes) . ' vs ' . formatearMonto($ingresosMesAnt) . ' mes anterior.',
            $ingresosVarPct > 0
                ? 'Buena tendencia: replicar lo que funcionó (captación, precios, cierre).'
                : 'Analiza causa: menos trabajos, menor ticket, o trabajos perdidos.');
    } elseif ($ingresosMes > 0) {
        $agregar('bien', 'Ingresos estables mes a mes',
            'Ingresos mes actual: ' . formatearMonto($ingresosMes) . '.',
            'Mantén el ritmo de captación y cierre de trabajos.');
    }

    /* =========================================================
       HALLAZGOS CONSULTIVOS — Para asesoría del admin al dueño
       Detectan anomalías, tendencias y oportunidades con números.
       ========================================================= */

    // 19. Desviación de estructura de costos (gastos vs ingresos por categoría)
    if ($ingresosMes > 0 && $gastoMesTotal > 0) {
        // Top 3 categorías de gasto con % sobre ingresos
        $topGastos = $pdo->query(
            "SELECT categoria, SUM(monto) AS total
             FROM transacciones
             WHERE tipo = 'gasto' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
             GROUP BY categoria
             ORDER BY total DESC
             LIMIT 3"
        )->fetchAll();
        foreach ($topGastos as $tg) {
            $pctIng = ((float) $tg['total'] / $ingresosMes) * 100;
            if ($pctIng > 25) {
                $agregar('alerta', 'Categoría de gasto "' . $tg['categoria'] . '" consume ' . number_format($pctIng, 0) . '% de los ingresos',
                    'Este mes gastaste ' . formatearMonto((float) $tg['total']) . ' en ' . $tg['categoria'] . ', equivaliendo al ' . number_format($pctIng, 1) . '% de lo que facturaste.',
                    'Negocia con proveedores, busca alternativas o sube precios en trabajos que usan intensivamente esta partida.');
            }
        }
    }

    // 20. Tendencia de margen (últimos 3 meses) — detecta erosión silenciosa
    $margenes3m = $pdo->query(
        "SELECT
            DATE_FORMAT(fecha, '%Y-%m') AS mes,
            SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END) AS ing,
            SUM(CASE WHEN tipo = 'gasto'  THEN monto ELSE 0 END) AS gas
         FROM transacciones
         WHERE fecha >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 2 MONTH), '%Y-%m-01')
         GROUP BY mes
         ORDER BY mes"
    )->fetchAll();
    if (count($margenes3m) >= 2) {
        $margenes = [];
        foreach ($margenes3m as $m) {
            $ing = (float) $m['ing'];
            $gas = (float) $m['gas'];
            $margenes[] = $ing > 0 ? (($ing - $gas) / $ing) * 100 : 0;
        }
        $pendiente = end($margenes) - reset($margenes);
        if ($pendiente < -5) {
            $agregar('peligro', 'Margen en caída sostenida (' . number_format(abs($pendiente), 1) . ' pp en 3 meses)',
                'El margen pasó de ' . number_format(reset($margenes), 1) . '% a ' . number_format(end($margenes), 1) . '% en los últimos ' . count($margenes) . ' meses.',
                'Revisa si los costos subieron más que los precios; ajusta cotizaciones y renegocia proveedores ya.');
        } elseif ($pendiente < -2) {
            $agregar('alerta', 'Margen descendiendo (' . number_format(abs($pendiente), 1) . ' pp en 3 meses)',
                'Erosión gradual: ' . number_format(reset($margenes), 1) . '% → ' . number_format(end($margenes), 1) . '%.',
                'Actúa antes de que sea crítico: 1-2% de ajuste en precios o costos frena la sangría.');
        }
    }

    // 21. Efectividad de cobranza por cliente (top deudores)
    $topDeudores = $pdo->query(
        "SELECT cliente, SUM(CASE WHEN estado IN ('pendiente','vencida') THEN monto ELSE 0 END) AS adeuda,
                SUM(CASE WHEN estado = 'pagada' THEN 1 ELSE 0 END) AS pagadas,
                SUM(CASE WHEN estado IN ('pendiente','vencida') THEN 1 ELSE 0 END) AS pendientes
         FROM facturas
         WHERE fecha_emision >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 3 MONTH), '%Y-%m-01')
         GROUP BY cliente
         HAVING adeuda > 0
         ORDER BY adeuda DESC
         LIMIT 3"
    )->fetchAll();
    if (!empty($topDeudores)) {
        foreach ($topDeudores as $td) {
            if ((float) $td['adeuda'] > $ingresosMes * 0.2) {
                $agregar('alerta', 'Cliente "' . $td['cliente'] . '" concentra deuda de ' . formatearMonto((float) $td['adeuda']),
                    'Representa el ' . number_format(((float) $td['adeuda'] / max($ingresosMes, 1)) * 100, 0) . '% de los ingresos del mes. Pagadas: ' . (int) $td['pagadas'] . ', Pendientes: ' . (int) $td['pendientes'] . '.',
                    'Prioriza cobro a este cliente; evalúa exigir anticipo en próximos trabajos o reducir exposición.');
            }
        }
    }

    // 22. Anomalía: gastos en categorías "no típicas" del negocio
    $catsTipicas = ['Materiales', 'Nómina', 'Transporte', 'Contratistas', 'Mantenimiento de maquinaria', 'Seguros y permisos', 'Servicios públicos', 'Marketing', 'Otros gastos', 'Alquiler'];
    $catsNoTipicas = $pdo->query(
        "SELECT categoria, SUM(monto) AS total
         FROM transacciones
         WHERE tipo = 'gasto' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
         GROUP BY categoria"
    )->fetchAll();
    foreach ($catsNoTipicas as $c) {
        if (!in_array($c['categoria'], $catsTipicas, true) && (float) $c['total'] > $gastoMesTotal * 0.05) {
            $agregar('alerta', 'Gasto significativo en categoría atípica: "' . $c['categoria'] . '"',
                'Gastaste ' . formatearMonto((float) $c['total']) . ' (' . number_format(((float) $c['total'] / $gastoMesTotal) * 100, 1) . '% de gastos) en una partida fuera del rubro habitual.',
                'Verifica si es un gasto legítimo del negocio o una fuga; si es recurrente, créala como categoría oficial y presupuéstala.');
        }
    }

    // 23. Proyección de caja simple: facturas pendientes vs gastos fijos próximos 30 días
    if ($emitidoMes > 0 || $mensualEquivalente > 0) {
        $flujoProyectado = $cobradoMes + (float) $facturas['pendiente'] - $mensualEquivalente;
        if ($flujoProyectado < 0) {
            $agregar('peligro', 'Proyección de caja negativa a 30 días',
                'Cobros esperados (' . formatearMonto($cobradoMes + (float) $facturas['pendiente']) . ') no cubren gastos fijos (' . formatearMonto($mensualEquivalente) . '). Déficit: ' . formatearMonto(abs($flujoProyectado)) . '.',
                'Acelera cobranzas, posterga compras no críticas o negocia plazos con proveedores.');
        } elseif ($flujoProyectado < $mensualEquivalente * 0.3) {
            $agregar('alerta', 'Caja justa a 30 días (margen < 30%)',
                'Queda ' . formatearMonto($flujoProyectado) . ' de holgura tras cubrir fijos (' . formatearMonto($mensualEquivalente) . ').',
                'Mantén reserva de al menos 1x gastos fijos; evita compromisos nuevos hasta mejorar cobranza.');
        }
    }

    // 24. Rentabilidad por categoría de ingreso (ingresos - gastos directos estimados)
    if ($ingresosMes > 0 && $gastoMesTotal > 0) {
        $rentabCat = $pdo->query(
            "SELECT t1.categoria,
                    SUM(t1.monto) AS ingresos,
                    COALESCE(SUM(t2.monto), 0) AS gastos_asociados
             FROM transacciones t1
             LEFT JOIN transacciones t2
               ON t2.tipo = 'gasto'
              AND t2.categoria IN ('Materiales', 'Contratistas')
              AND t2.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              AND t2.fecha <= LAST_DAY(CURDATE())
             WHERE t1.tipo = 'ingreso'
               AND t1.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
             GROUP BY t1.categoria
             HAVING ingresos > 0"
        )->fetchAll();
        foreach ($rentabCat as $rc) {
            $ing = (float) $rc['ingresos'];
            $gas = (float) $rc['gastos_asociados'];
            if ($ing > 0) {
                $marg = (($ing - $gas) / $ing) * 100;
                if ($marg < 15) {
                    $agregar('alerta', 'Baja rentabilidad en "' . $rc['categoria'] . '" (' . number_format($marg, 0) . '%)',
                        'Ingresos: ' . formatearMonto($ing) . ', Costos directos estimados: ' . formatearMonto($gas) . '.',
                        'Sube precios en esta categoría o reduce costos directos (materiales/contratistas) antes del próximo proyecto.');
                }
            }
        }
    }

    // 25. Detección de pagos duplicados o errores (mismo monto, misma categoría, días consecutivos)
    $duplicados = $pdo->query(
        "SELECT monto, categoria, fecha, COUNT(*) AS veces
         FROM transacciones
         WHERE fecha >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 30 DAY), '%Y-%m-01')
         GROUP BY monto, categoria, fecha
         HAVING veces > 1"
    )->fetchAll();
    if (!empty($duplicados)) {
        foreach ($duplicados as $d) {
            $agregar('alerta', 'Posible duplicado: ' . (int) $d['veces'] . ' movimientos de ' . formatearMonto((float) $d['monto']) . ' en ' . $d['categoria'] . ' el ' . date('d/m', strtotime($d['fecha'])),
                'Mismo importe, misma categoría y mismo día registrados ' . (int) $d['veces'] . ' veces.',
                'Revisa si son entradas repetidas por error; de ser así, elimina las duplicadas desde el módulo de Movimientos.');
        }
    }

    // Orden: peligros primero, luego alertas, luego buenas prácticas
    $orden = ['peligro' => 0, 'alerta' => 1, 'bien' => 2];
    usort($hallazgos, fn($a, $b) => $orden[$a['severidad']] <=> $orden[$b['severidad']]);

    $conteo = ['peligro' => 0, 'alerta' => 0, 'bien' => 0];
    foreach ($hallazgos as $h) {
        $conteo[$h['severidad']]++;
    }

    $ultimaFechaTexto = $ultimaFecha ? date('d/m/Y', strtotime($ultimaFecha)) : '—';

    $kpis = [
        ['etiqueta' => 'Margen del negocio',      'valor' => number_format($resumen['margen'], 1) . '%', 'clase' => $resumen['margen'] >= 20 ? 'ingresos' : ($resumen['margen'] >= 5 ? '' : 'gastos')],
        ['etiqueta' => 'Utilidad mes actual',     'valor' => formatearMonto((float) $comparativo['actual']['utilidad']), 'clase' => $difUtilidad >= 0 ? 'utilidad' : 'gastos'],
        ['etiqueta' => 'Por cobrar (pendiente)',  'valor' => formatearMonto($cartera), 'clase' => $cartera > 0 ? '' : 'ingresos'],
        ['etiqueta' => 'Facturas vencidas',       'valor' => formatearMonto((float) $facturas['vencida']), 'clase' => (float) $facturas['vencida'] > 0 ? 'gastos' : 'ingresos'],
        ['etiqueta' => 'Ticket promedio factura', 'valor' => formatearMonto((float) $facturas['ticket']), 'clase' => ''],
        ['etiqueta' => 'Personal / ingresos mes', 'valor' => $ingresosMes > 0 ? number_format($proporcionPersonal, 0) . '%' : '—', 'clase' => $proporcionPersonal > 40 ? 'gastos' : 'ingresos'],
        ['etiqueta' => 'Último registro',         'valor' => $diasSinRegistro === null ? '—' : $diasSinRegistro . ' día(s)', 'clase' => ($diasSinRegistro === null || $diasSinRegistro > 7) ? 'gastos' : 'ingresos'],
        ['etiqueta' => 'Fecha del último dato',   'valor' => $ultimaFechaTexto, 'clase' => ''],
    ];

    return [
        'kpis'     => $kpis,
        'hallazgos' => $hallazgos,
        'conteo'   => $conteo,
        'resumen'  => $resumen,
    ];
}
