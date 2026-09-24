<?php
/**
 * includes/modulos/configuracion.php
 * Requiere $pdo (de modulo.php). Deja el HTML listo en $contenidoModulo.
 *
 * Tres apartados:
 *  1. Datos del negocio + apariencia + logo (para todos).
 *  2. Mi cuenta: nombre, correo y cambio de contraseña (para todos).
 *  3. Usuarios y accesos (solo superadmin): alta, activación y baja de cuentas.
 */

declare(strict_types=1);

$usuarioConfig = usuarioActual();
$esSuperadminConfig = ($usuarioConfig['rol'] ?? '') === 'superadmin';
$listaUsuarios = listarUsuarios($pdo);

// Vuelve a cargar la configuración por si acaba de cambiarla el superadmin.
$config = obtenerConfiguracion($pdo);

ob_start();
?>
<div class="mb-6">
    <p class="etiqueta-mono">Cuenta</p>
    <h2 class="font-display text-xl font-semibold mt-1">Configuración</h2>
    <p class="text-sm mt-1" style="color:var(--color-ink-soft)">
        Datos del negocio, tu cuenta y los accesos del panel.
    </p>
</div>

<div class="flex flex-col gap-6 w-full min-w-0">

    <!-- =====================================================
         APARTADO 1 — Datos del negocio + apariencia + logo
         ===================================================== -->
    <div class="tarjeta max-w-3xl w-full min-w-0">
        <h3 class="font-display text-lg font-semibold mb-1">Datos del negocio</h3>
        <p class="text-sm mb-5" style="color:var(--color-ink-soft)">Estos datos se muestran en el menú lateral y en el inicio de sesión.</p>

        <form method="post" action="api/configuracion.php">
            <input type="hidden" name="accion" value="guardar">

            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Nombre del negocio</label>
                <input type="text" name="nombre_negocio" maxlength="120" value="<?= htmlspecialchars($config['nombre_negocio'] ?? '') ?>" class="campo-claro">
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Nombre del administrador</label>
                <input type="text" name="nombre_admin" maxlength="120" value="<?= htmlspecialchars($config['nombre_admin'] ?? '') ?>" class="campo-claro">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="text-sm font-medium block mb-1">Correo</label>
                    <input type="email" name="email_admin" maxlength="120" value="<?= htmlspecialchars($config['email_admin'] ?? '') ?>" class="campo-claro">
                </div>
                <div>
                    <label class="text-sm font-medium block mb-1">Teléfono</label>
                    <input type="text" name="telefono_admin" maxlength="40" value="<?= htmlspecialchars($config['telefono_admin'] ?? '') ?>" class="campo-claro">
                </div>
            </div>

            <h4 class="etiqueta-mono font-mono text-[0.68rem] uppercase tracking-wide mb-3 mt-5">Apariencia</h4>
            <div class="flex flex-wrap gap-3 mb-5">
                <label class="chip-tema <?= $config['tema'] === 'claro' ? 'activo' : '' ?>">
                    <input type="radio" name="tema" value="claro" <?= $config['tema'] === 'claro' ? 'checked' : '' ?> class="oculto-visual">
                    ☀️ Modo claro
                </label>
                <label class="chip-tema <?= $config['tema'] === 'oscuro' ? 'activo' : '' ?>">
                    <input type="radio" name="tema" value="oscuro" <?= $config['tema'] === 'oscuro' ? 'checked' : '' ?> class="oculto-visual">
                    🌙 Modo oscuro
                </label>
            </div>

            <button type="submit" class="w-full rounded-full py-3 font-semibold text-sm" style="background:var(--color-ink); color:var(--color-paper)">
                Guardar cambios
            </button>
        </form>
    </div>

    <!-- =====================================================
         APARTADO 1b — Logo del negocio (tarjeta hermana, forms
         INDEPENDIENTES). Antes estaban ANIDADOS dentro del
         form "guardar" → HTML inválido → el navegador rompía
         la subida del archivo. Ahora cada form es raíz.
         ===================================================== -->
    <div class="tarjeta max-w-3xl w-full min-w-0">
        <h3 class="font-display text-lg font-semibold mb-1">Logo del negocio</h3>
        <p class="text-sm mb-5" style="color:var(--color-ink-soft)">Aparece en el menú lateral y en el inicio de sesión.</p>

        <div class="flex items-center gap-4 flex-wrap mb-5">
            <?php if (!empty($config['logo'])): ?>
                <img src="<?= htmlspecialchars($config['logo']) ?>" alt="Logo del negocio" class="logo-visor">
            <?php else: ?>
                <div class="logo-visor logo-visor-vacio">Sin logo</div>
            <?php endif; ?>
        </div>

        <form method="post" action="api/configuracion.php" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="guardar_logo">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" class="campo-claro campo-archivo">
                <button type="submit" class="rounded-full px-4 py-2 text-sm font-semibold whitespace-nowrap" style="background:var(--color-ink); color:var(--color-paper)">
                    Subir logo
                </button>
            </div>
            <p class="text-xs mt-2" style="color:var(--color-ink-soft)">PNG, JPG, WEBP o SVG · máximo 2 MB · fondo transparente recomendado.</p>
        </form>

        <?php if (!empty($config['logo'])): ?>
        <form method="post" action="api/configuracion.php" onsubmit="return confirm('¿Quitar el logo actual?');" class="mt-3">
            <input type="hidden" name="accion" value="eliminar_logo">
            <button type="submit" class="btn-secundario">Quitar logo</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- =====================================================
         APARTADO 2 — Mi cuenta (datos personales y contraseña)
         ===================================================== -->
    <div class="tarjeta max-w-3xl w-full min-w-0">
        <h3 class="font-display text-lg font-semibold mb-1">Mi cuenta</h3>
        <p class="text-sm mb-5" style="color:var(--color-ink-soft)">
            Cambias tus datos personales o tu contraseña. Nadie más puede verla.
        </p>

        <form method="post" action="api/configuracion.php" class="mb-6">
            <input type="hidden" name="accion" value="guardar_perfil">
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Tu nombre</label>
                <input type="text" name="nombre" maxlength="120" required
                       value="<?= htmlspecialchars($usuarioConfig['nombre'] ?? '') ?>" class="campo-claro">
            </div>
            <div class="mb-3">
                <label class="text-sm font-medium block mb-1">Tu correo (con el que entras)</label>
                <input type="email" name="email" maxlength="160" required
                       value="<?= htmlspecialchars($usuarioConfig['email'] ?? '') ?>" class="campo-claro">
            </div>
            <button type="submit" class="rounded-full px-5 py-2.5 text-sm font-semibold" style="background:var(--color-ink); color:var(--color-paper)">
                Guardar mis datos
            </button>
        </form>

        <hr style="border-color:var(--color-line)">
        <form method="post" action="api/configuracion.php" class="mt-6">
            <input type="hidden" name="accion" value="cambiar_clave">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                <div>
                    <label class="text-sm font-medium block mb-1">Contraseña actual</label>
                    <input type="password" name="clave_actual" required autocomplete="current-password" class="campo-claro">
                </div>
                <div>
                    <label class="text-sm font-medium block mb-1">Contraseña nueva</label>
                    <input type="password" name="clave_nueva" required minlength="6" autocomplete="new-password" class="campo-claro">
                </div>
                <div>
                    <label class="text-sm font-medium block mb-1">Repite la nueva</label>
                    <input type="password" name="clave_nueva2" required minlength="6" autocomplete="new-password" class="campo-claro">
                </div>
            </div>
            <button type="submit" class="rounded-full px-5 py-2.5 text-sm font-semibold" style="background:var(--color-ink); color:var(--color-paper)">
                Cambiar contraseña
            </button>
        </form>
    </div>

    <?php if ($esSuperadminConfig): ?>
    <!-- =====================================================
         APARTADO 3 — Usuarios y accesos (solo superadmin)
         ===================================================== -->
    <div class="tarjeta max-w-4xl w-full min-w-0">
        <div class="flex items-start justify-between flex-wrap gap-3 mb-1">
            <div>
                <h3 class="font-display text-lg font-semibold">Usuarios y accesos</h3>
                <p class="text-sm" style="color:var(--color-ink-soft)">
                    Crea cuentas para el dueño o el equipo de administración. El rol <strong>superadmin</strong> también ve el módulo de Auditoría.
                </p>
            </div>
            <span class="badge-select" style="background:var(--color-gris-bg); color:var(--color-gris)">Solo superadmin</span>
        </div>

        <!-- Tabla de usuarios -->
        <div class="overflow-x-auto rounded-xl mt-5" style="border:1px solid var(--color-line)">
            <table class="tabla-modulo">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Creado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listaUsuarios as $u): ?>
                        <?php $esYo = (int) $u['id'] === (int) ($usuarioConfig['id'] ?? 0); ?>
                        <tr class="<?= !$u['activo'] ? 'fila-inactiva' : '' ?>">
                            <td>
                                <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                                <br><span class="text-xs" style="color:var(--color-ink-soft)"><?= htmlspecialchars($u['email']) ?></span>
                                <?php if ($esYo): ?>
                                    <span class="badge-select ml-1" style="background:var(--color-teal-bg); color:var(--color-teal)">Tú</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-select <?= $u['rol'] === 'superadmin' ? 'badge-pagada' : 'badge-pendiente' ?>">
                                    <?= $u['rol'] === 'superadmin' ? 'Superadmin' : 'Dueño' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-select <?= $u['activo'] ? 'badge-pagada' : 'badge-vencida' ?>">
                                    <?= $u['activo'] ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td class="whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime($u['creado_en']))) ?></td>
                            <td class="acciones-tabla">
                                <?php if (!$esYo): ?>
                                <form method="post" action="api/configuracion.php" class="inline">
                                    <input type="hidden" name="accion" value="cambiar_estado_usuario">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="activo" value="<?= $u['activo'] ? '0' : '1' ?>">
                                    <button type="submit" class="btn-editar">
                                        <?= $u['activo'] ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </form>
                                <form method="post" action="api/configuracion.php" class="inline" onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>? Ya no podrá entrar.');">
                                    <input type="hidden" name="accion" value="eliminar_usuario">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="btn-eliminar" title="Eliminar usuario">✕</button>
                                </form>
                                <?php else: ?>
                                    <span class="text-xs" style="color:var(--color-ink-soft)">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Alta de nuevo usuario -->
        <form method="post" action="api/configuracion.php" class="formulario-alta-usuario grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-5 items-end">
            <input type="hidden" name="accion" value="crear_usuario">
            <div>
                <label class="text-sm font-medium block mb-1">Nombre</label>
                <input type="text" name="nombre" required maxlength="120" placeholder="Nombre completo" class="campo-claro">
            </div>
            <div>
                <label class="text-sm font-medium block mb-1">Correo</label>
                <input type="email" name="email" required maxlength="160" placeholder="correo@ejemplo.com" class="campo-claro">
            </div>
            <div>
                <label class="text-sm font-medium block mb-1">Rol</label>
                <select name="rol" class="campo-claro">
                    <option value="dueno">Dueño</option>
                    <option value="superadmin">Superadmin</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium block mb-1">Contraseña</label>
                <input type="password" name="password" required minlength="6" autocomplete="new-password" placeholder="mín. 6 caracteres" class="campo-claro">
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <button type="submit" class="rounded-full px-5 py-2.5 text-sm font-semibold w-full sm:w-auto" style="background:var(--color-ink); color:var(--color-paper)">
                    + Crear usuario
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>
<?php
$contenidoModulo = ob_get_clean();