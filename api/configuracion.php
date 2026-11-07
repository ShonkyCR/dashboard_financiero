<?php
/**
 * api/configuracion.php
 * Recibe los formularios del módulo de Configuración (POST normal)
 * y redirige de vuelta a modulo.php?p=configuracion con un mensaje.
 *
 * Acciones:
 *  - guardar             → datos del negocio + tema
 *  - guardar_logo        → sube/actualiza el logo (multipart)
 *  - eliminar_logo       → quita el logo guardado
 *  - guardar_perfil      → nombre/correo de la cuenta en sesión
 *  - cambiar_clave       → cambia la contraseña de la cuenta en sesión
 *  - crear_usuario       → superadmin: da de alta una cuenta
 *  - eliminar_usuario    → superadmin: borra una cuenta
 *  - cambiar_estado_usuario → superadmin: activa/desactiva una cuenta
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$usuario = requerirLoginApiForm();

$pdo = conectarDB();
asegurarEsquemaAuth($pdo);
asegurarTablasBaseDeDatos($pdo); // migración suave: columna 'logo' y tablas existentes

$accion = (string) ($_POST['accion'] ?? '');

function volverUsuario(string $ok = '', string $error = ''): never
{
    $params = ['p' => 'configuracion'];
    if ($ok) {
        $params['ok'] = $ok;
    }
    if ($error) {
        $params['error'] = $error;
    }
    header('Location: ../modulo.php?' . http_build_query($params));
    exit;
}

/** Actualiza la variable de sesión para reflejar cambios del propio usuario. */
function refrescarSesionUsuario(array $fila): void
{
    $_SESSION['usuario'] = [
        'id'     => (int) $fila['id'],
        'nombre' => $fila['nombre'],
        'email'  => $fila['email'],
        'rol'    => $fila['rol'],
    ];
}

try {
    /* ---------------- Datos del negocio + tema ---------------- */
    if ($accion === 'guardar') {
        guardarConfiguracion($pdo, [
            'nombre_negocio' => trim((string) ($_POST['nombre_negocio'] ?? '')),
            'nombre_admin' => trim((string) ($_POST['nombre_admin'] ?? '')),
            'email_admin' => trim((string) ($_POST['email_admin'] ?? '')),
            'telefono_admin' => trim((string) ($_POST['telefono_admin'] ?? '')),
            'tema' => (string) ($_POST['tema'] ?? 'claro'),
            'categorias_ingreso' => trim((string) ($_POST['categorias_ingreso'] ?? '')),
            'categorias_gasto' => trim((string) ($_POST['categorias_gasto'] ?? '')),
        ]);
        volverUsuario('Configuración guardada.');
    }

    /* ---------------- Logo del negocio ---------------- */
    if ($accion === 'guardar_logo') {
        $config = obtenerConfiguracion($pdo);
        $ruta = guardarLogoNegocio($_FILES['logo'] ?? []);

        if ($ruta === '') {
            volverUsuario('', 'Selecciona una imagen (PNG, JPG, WEBP o SVG, máx. 2 MB).');
        }
        if (!str_starts_with($ruta, 'uploads/')) {
            volverUsuario('', $ruta); // mensaje de error de guardarLogoNegocio
        }

        $pdo->prepare('UPDATE configuracion SET logo = :logo WHERE id = 1')
            ->execute([':logo' => $ruta]);

        // Si había un logo anterior con otra ruta, borramos el archivo viejo.
        $logoAnterior = (string) ($config['logo'] ?? '');
        if ($logoAnterior !== '' && $logoAnterior !== $ruta) {
            $absoluta = rutaCarpetaUploads() . '/' . basename($logoAnterior);
            if (is_file($absoluta)) {
                @unlink($absoluta);
            }
        }
        volverUsuario('Logo actualizado.');
    }

    /* ---------------- Quitar logo ---------------- */
    if ($accion === 'eliminar_logo') {
        $config = obtenerConfiguracion($pdo);
        eliminarLogoNegocio($pdo, (string) ($config['logo'] ?? ''));
        volverUsuario('Se quitó el logo.');
    }

    /* ---------------- Tema rápido (llamado por fetch desde main.js) ---------------- */
    if ($accion === 'cambiar_tema') {
        $tema = in_array((string) ($_POST['tema'] ?? ''), ['claro', 'oscuro'], true)
            ? (string) $_POST['tema']
            : 'claro';
        $pdo->prepare('UPDATE configuracion SET tema = :tema WHERE id = 1')
            ->execute([':tema' => $tema]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'tema' => $tema]);
        exit;
    }

    /* ---------------- Mi perfil (nombre y correo) ---------------- */
    if ($accion === 'guardar_perfil') {
        $resultado = actualizarPerfilUsuario($pdo, (int) $usuario['id'], [
            'nombre' => $_POST['nombre'] ?? '',
            'email' => $_POST['email'] ?? '',
        ]);
        if (is_string($resultado)) {
            volverUsuario('', $resultado);
        }
        refrescarSesionUsuario($resultado);
        volverUsuario('Tus datos se guardaron correctamente.');
    }

    /* ---------------- Cambiar contraseña ---------------- */
    if ($accion === 'cambiar_clave') {
        $resultado = cambiarContrasenaUsuario(
            $pdo,
            (int) $usuario['id'],
            (string) ($_POST['clave_actual'] ?? ''),
            (string) ($_POST['clave_nueva'] ?? '')
        );
        if (is_string($resultado)) {
            volverUsuario('', $resultado);
        }
        volverUsuario('Contraseña actualizada.');
    }

    /* ============ Las siguientes acciones son del superadmin ============ */
    if ($usuario['rol'] !== 'superadmin') {
        volverUsuario('', 'Solo el superadmin puede gestionar usuarios.');
    }

    /* ---------------- Crear usuario ---------------- */
    if ($accion === 'crear_usuario') {
        $resultado = crearUsuario($pdo, $_POST);
        if (is_string($resultado)) {
            volverUsuario('', $resultado);
        }
        volverUsuario('Usuario "' . htmlspecialchars($resultado['nombre']) . '" creado.');
    }

    /* ---------------- Eliminar usuario ---------------- */
    if ($accion === 'eliminar_usuario') {
        $resultado = eliminarUsuario($pdo, (int) ($_POST['id'] ?? 0), (int) $usuario['id']);
        if (is_string($resultado)) {
            volverUsuario('', $resultado);
        }
        volverUsuario('Usuario eliminado.');
    }

    /* ---------------- Activar / desactivar usuario ---------------- */
    if ($accion === 'cambiar_estado_usuario') {
        $activo = ($_POST['activo'] ?? '') === '1';
        $resultado = cambiarEstadoUsuario($pdo, (int) ($_POST['id'] ?? 0), (int) $usuario['id'], $activo);
        if (is_string($resultado)) {
            volverUsuario('', $resultado);
        }
        volverUsuario($activo ? 'Usuario activado.' : 'Usuario desactivado.');
    }

    volverUsuario('', 'Acción no reconocida.');
} catch (Throwable $e) {
    volverUsuario('', 'Ocurrió un error al guardar la configuración.');
}