/* =========================================================
   main.js — JavaScript Vanilla (sin frameworks)
   ========================================================= */

const vistaResumen = document.getElementById('vista-resumen');
const vistaDetalle = document.getElementById('vista-detalle');
const btnResumen = document.getElementById('btn-modo-resumen');
const btnDetalle = document.getElementById('btn-modo-detalle');

/* -------------------------------------------------
   Menú lateral (cajón en móvil / adaptable y ocultable en escritorio)
   ------------------------------------------------- */
const sidebar = document.getElementById('sidebar');
const fondoSidebar = document.getElementById('fondo-sidebar');
const appShell = document.getElementById('app-shell') || document.querySelector('.app-shell');

function toggleSidebar() {
    const esEscritorio = window.innerWidth >= 1024;

    if (esEscritorio) {
        if (appShell) {
            const estaOculto = appShell.classList.toggle('sidebar-oculto');
            try {
                localStorage.setItem('sidebar_oculto_desktop', estaOculto ? 'true' : 'false');
            } catch (e) {}
        }
    } else {
        if (sidebar) {
            const estaAbierto = sidebar.classList.toggle('abierto');
            if (fondoSidebar) {
                fondoSidebar.classList.toggle('oculto', !estaAbierto);
            }
        }
    }
}

function abrirMenuLateral() {
    const esEscritorio = window.innerWidth >= 1024;
    if (esEscritorio) {
        if (appShell) {
            appShell.classList.remove('sidebar-oculto');
            try { localStorage.setItem('sidebar_oculto_desktop', 'false'); } catch (e) {}
        }
    } else {
        if (sidebar) sidebar.classList.add('abierto');
        if (fondoSidebar) fondoSidebar.classList.remove('oculto');
    }
}

function cerrarMenuLateral() {
    const esEscritorio = window.innerWidth >= 1024;
    if (esEscritorio) {
        if (appShell) {
            appShell.classList.add('sidebar-oculto');
            try { localStorage.setItem('sidebar_oculto_desktop', 'true'); } catch (e) {}
        }
    } else {
        if (sidebar) sidebar.classList.remove('abierto');
        if (fondoSidebar) fondoSidebar.classList.add('oculto');
    }
}

// Inicialización del estado guardado en escritorio
(function inicializarSidebar() {
    if (window.innerWidth >= 1024) {
        try {
            const ocultoGuardado = localStorage.getItem('sidebar_oculto_desktop') === 'true';
            if (appShell) {
                appShell.classList.toggle('sidebar-oculto', ocultoGuardado);
            }
        } catch (e) {}
    }
})();

// Cerrar menú con Escape en móvil
document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && sidebar && sidebar.classList.contains('abierto')) {
        cerrarMenuLateral();
    }
});

// Limpieza al cambiar de tamaño de pantalla
window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        if (sidebar) sidebar.classList.remove('abierto');
        if (fondoSidebar) fondoSidebar.classList.add('oculto');
        try {
            const ocultoGuardado = localStorage.getItem('sidebar_oculto_desktop') === 'true';
            if (appShell) {
                appShell.classList.toggle('sidebar-oculto', ocultoGuardado);
            }
        } catch (e) {}
    }
});

/* -------------------------------------------------
   Alternador Modo Resumen / Modo Detalle
   ------------------------------------------------- */
function cambiarModo(modo) {
    if (!vistaResumen || !vistaDetalle) return;
    const esDetalle = modo === 'detalle';

    vistaResumen.classList.toggle('oculto', esDetalle);
    vistaDetalle.classList.toggle('oculto', !esDetalle);

    btnResumen.classList.toggle('activo', !esDetalle);
    btnDetalle.classList.toggle('activo', esDetalle);

    // Recordamos la preferencia para que, si recarga, mantenga el modo
    try { localStorage.setItem('modoDashboard', modo); } catch (e) { /* almacenamiento no disponible: se ignora */ }
}

// Si PHP no forzó la apertura del detalle (por filtros en la URL),
// respetamos la última preferencia guardada por el usuario.
(function inicializarModo() {
    if (!vistaResumen || !vistaDetalle || typeof DETALLE_ABIERTO_DE_ENTRADA === 'undefined') {
        return; // Página sin dashboard (módulos): no hay nada que alternar
    }
    if (DETALLE_ABIERTO_DE_ENTRADA) {
        cambiarModo('detalle');
        return;
    }
    let modoGuardado = null;
    try { modoGuardado = localStorage.getItem('modoDashboard'); } catch (e) { /* ignorar */ }
    if (modoGuardado === 'detalle') {
        cambiarModo('detalle');
    }
})();

/* -------------------------------------------------
   Modal: abrir / cerrar
   ------------------------------------------------- */
const fondoModal = document.getElementById('fondo-modal');
const formTransaccion = document.getElementById('form-transaccion');
const inputFecha = document.getElementById('input-fecha');
const errorForm = document.getElementById('error-form');

function abrirModal() {
    if (!formTransaccion) return;
    formTransaccion.reset();
    document.getElementById('accion-movimiento').value = 'crear';
    document.getElementById('id-movimiento').value = '';
    document.getElementById('titulo-modal-movimiento').textContent = 'Registrar movimiento';
    document.getElementById('btn-guardar').textContent = 'Guardar movimiento';
    seleccionarTipo('ingreso');
    inputFecha.value = new Date().toISOString().slice(0, 10);
    errorForm.classList.add('oculto');

    // Resetear selector de cliente/proyecto (mismo patrón que Facturas/Gastos)
    const selCliente = document.getElementById('selector-cliente-gasto');
    const inputClienteNuevo = document.getElementById('cliente-gasto');
    const hiddenContacto = document.getElementById('contacto-id-gasto');
    const fichaCliente = document.getElementById('ficha-cliente-gasto');
    if (selCliente) selCliente.value = '';
    if (inputClienteNuevo) {
        inputClienteNuevo.value = '';
        inputClienteNuevo.classList.add('oculto');
        inputClienteNuevo.required = false;
    }
    if (hiddenContacto) hiddenContacto.value = '';
    if (fichaCliente) {
        fichaCliente.classList.add('oculto');
        fichaCliente.innerHTML = '';
    }

    fondoModal.classList.remove('oculto');
}

/** Abre el mismo modal en modo edición, precargando el movimiento. */
function editarMovimiento(t) {
    formTransaccion.reset();
    document.getElementById('accion-movimiento').value = 'editar';
    document.getElementById('id-movimiento').value = t.id;
    document.getElementById('titulo-modal-movimiento').textContent = 'Editar movimiento';
    document.getElementById('btn-guardar').textContent = 'Guardar cambios';

    seleccionarTipo(t.tipo);
    document.getElementById('input-monto').value = t.monto;
    document.getElementById('input-categoria').value = t.categoria;
    document.getElementById('input-descripcion').value = t.descripcion || '';
    document.getElementById('input-fecha').value = t.fecha;

    // Precargar cliente/proyecto vinculado (mismo patrón que Facturas/Gastos)
    const selCliente = document.getElementById('selector-cliente-gasto');
    if (selCliente) {
        selCliente.value = t.contacto_id ? String(t.contacto_id) : '';
        if (typeof sincronizarSelectorCliente === 'function') {
            sincronizarSelectorCliente('gasto');
        }
    }

    errorForm.classList.add('oculto');
    fondoModal.classList.remove('oculto');
}

/** Elimina un movimiento ( AJAX ) y recarga la lista. */
async function eliminarMovimiento(id) {
    if (!confirm('¿Eliminar este movimiento? Esta acción no se puede deshacer.')) return;

    try {
        const datos = new FormData();
        datos.append('accion', 'eliminar');
        datos.append('id', id);

        const respuesta = await fetch('api/add_transaction.php', { method: 'POST', body: datos });
        const resultado = await respuesta.json();

        if (!resultado.ok) {
            mostrarToast(resultado.mensaje || 'No se pudo eliminar el movimiento.', true);
            return;
        }
        mostrarToast('Movimiento eliminado ✓');
        setTimeout(() => window.location.reload(), 600);
    } catch (error) {
        mostrarToast('Hubo un problema de conexión. Intenta de nuevo.', true);
    }
}

function cerrarModal() {
    fondoModal?.classList.add('oculto');
}

// Cerrar si se hace click fuera de la tarjeta del modal (nueva estructura con .fondo-modal-backdrop)
if (fondoModal) {
    fondoModal.addEventListener('click', (evento) => {
        // Cerrar si click en el fondo mismo O en el backdrop (hijo .fondo-modal-backdrop)
        if (evento.target === fondoModal || evento.target.classList?.contains('fondo-modal-backdrop')) {
            cerrarModal();
        }
    });
}

// Cerrar con la tecla Escape
document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && fondoModal && !fondoModal.classList.contains('oculto')) {
        cerrarModal();
    }
});

/* -------------------------------------------------
   Selector de tipo (Entró / Salió dinero) + categorías dinámicas
   ------------------------------------------------- */
const chipIngreso = document.getElementById('chip-ingreso');
const chipGasto = document.getElementById('chip-gasto');
const inputTipo = document.getElementById('input-tipo');
const selectCategoria = document.getElementById('input-categoria');

function seleccionarTipo(tipo) {
    if (!inputTipo || !selectCategoria) return;
    inputTipo.value = tipo;

    chipIngreso.classList.toggle('activo-ingreso', tipo === 'ingreso');
    chipGasto.classList.toggle('activo-gasto', tipo === 'gasto');

    // Repoblar las categorías según el tipo elegido
    selectCategoria.innerHTML = '';
    const opciones = CATEGORIAS[tipo] || [];
    opciones.forEach((categoria) => {
        const opcion = document.createElement('option');
        opcion.value = categoria;
        opcion.textContent = categoria;
        selectCategoria.appendChild(opcion);
    });
}

chipIngreso?.addEventListener('click', () => seleccionarTipo('ingreso'));
chipGasto?.addEventListener('click', () => seleccionarTipo('gasto'));

/* -------------------------------------------------
   Envío del formulario (AJAX -> api/add_transaction.php)
   ------------------------------------------------- */
const btnGuardar = document.getElementById('btn-guardar');

formTransaccion?.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    errorForm.classList.add('oculto');
    btnGuardar.disabled = true;
    btnGuardar.textContent = 'Guardando...';

    const accion = document.getElementById('accion-movimiento').value || 'crear';

    try {
        const datos = new FormData(formTransaccion);
        const respuesta = await fetch('api/add_transaction.php', {
            method: 'POST',
            body: datos,
        });
        const resultado = await respuesta.json();

        if (!resultado.ok) {
            mostrarErrorForm(resultado.mensaje || 'No se pudo guardar el movimiento.');
            return;
        }

        cerrarModal();
        mostrarToast(accion === 'editar' ? 'Movimiento actualizado ✓' : 'Movimiento registrado ✓');
        await refrescarResumen();

        // La lista de movimientos se renderiza en PHP: al editar/eliminar
        // (o si el modo lista está visible) recargamos para ver los cambios.
        if (accion === 'editar' || !vistaDetalle.classList.contains('oculto')) {
            setTimeout(() => window.location.reload(), 700);
        }
    } catch (error) {
        mostrarErrorForm('Hubo un problema de conexión. Intenta de nuevo.');
    } finally {
        btnGuardar.disabled = false;
        btnGuardar.textContent = document.getElementById('accion-movimiento').value === 'editar'
            ? 'Guardar cambios'
            : 'Guardar movimiento';
    }
});

function mostrarErrorForm(mensaje) {
    errorForm.textContent = mensaje;
    errorForm.classList.remove('oculto');
}

/* -------------------------------------------------
   Refrescar las tarjetas del Modo Resumen sin recargar la página
   ------------------------------------------------- */
async function refrescarResumen() {
    try {
        const respuesta = await fetch('api/get_resumen.php');
        const datos = await respuesta.json();
        if (!datos.ok) return;

        document.getElementById('valor-ingresos').textContent = datos.ingresos_fmt;
        document.getElementById('valor-gastos').textContent = datos.gastos_fmt;
        document.getElementById('valor-utilidad').textContent = datos.utilidad_fmt;

        const s = datos.semaforo;
        document.getElementById('emoji-semaforo').textContent = s.emoji;
        document.getElementById('titulo-semaforo').textContent = s.titulo;
        document.getElementById('mensaje-semaforo').textContent = s.mensaje;

        const badgeHeader = document.getElementById('badge-semaforo-header');
        badgeHeader.className = 'sello-semaforo sello-' + s.color;
        document.getElementById('texto-semaforo-header').textContent = s.titulo;
    } catch (error) {
        // Si falla el refresco silencioso, no interrumpimos al usuario.
        console.warn('No se pudo refrescar el resumen:', error);
    }
}

/* -------------------------------------------------
   Toast de confirmación
   ------------------------------------------------- */
let toastTimeout = null;
function mostrarToast(mensaje, esError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = mensaje;
    toast.classList.toggle('error', esError);
    toast.classList.add('visible');

    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('visible'), 2500);
}

/* -------------------------------------------------
   Overlay de carga de página
   - Se muestra al abrir (logo + barra brillante) y se
     desvanece cuando el navegador termina de cargar.
   - También aparece de inmediato al enviar cualquier
     formulario que redirige, para una transición fina.
   ------------------------------------------------- */
const cargaPagina = document.getElementById('carga-pagina');

function ocultarCarga() {
    if (!cargaPagina) return;
    cargaPagina.classList.add('lista');
}

function mostrarCarga() {
    if (!cargaPagina) return;
    cargaPagina.classList.remove('lista');
}

// Exponer globalmente para recuperación tras validaciones o cierres de modal
window.ocultarCarga = ocultarCarga;
window.mostrarCarga = mostrarCarga;

// Respaldo por si el evento 'load' ya ocurrió o tarda demasiado.
const ocultarCargaUnaVez = (() => {
    let hecho = false;
    return () => {
        if (hecho) return;
        hecho = true;
        ocultarCarga();
    };
})();

if (document.readyState === 'complete') {
    ocultarCargaUnaVez();
} else {
    window.addEventListener('load', ocultarCargaUnaVez);
}
setTimeout(ocultarCargaUnaVez, 1800);

// Escucha en fase de burbujeo (false) para respetar validaciones y prevenciones de envío
document.addEventListener('submit', (evento) => {
    if (evento.defaultPrevented) return;
    const formulario = evento.target;
    if (formulario && formulario.tagName === 'FORM' && !formulario.hasAttribute('data-ajax')) {
        mostrarCarga();
    }
}, false);

// Escape cierra cualquier modal abierto (incluye los de los módulos)
document.addEventListener('keydown', (evento) => {
    if (evento.key !== 'Escape') return;
    document.querySelectorAll('.fondo-modal:not(.oculto)').forEach((fondo) => fondo.classList.add('oculto'));
});

/* -------------------------------------------------
   Tema claro/oscuro instantáneo
   - Corta sin esperar a recargar ni a guardar.
   - Guarda la preferencia en localStorage y la
     persiste en la BD (para el login y otras vistas).
   - Mantiene en sincronía los chips de Configuración.
   ------------------------------------------------- */
function temaActual() {
    return document.body.classList.contains('tema-oscuro') ? 'oscuro' : 'claro';
}

function aplicarTema(tema) {
    const oscuro = tema === 'oscuro';
    document.body.classList.toggle('tema-oscuro', oscuro);
    document.documentElement.classList.toggle('tema-oscuro', oscuro);
    document.body.setAttribute('data-tema', tema);
    try { localStorage.setItem('tema_dashboard', tema); } catch (e) {}
    sincronizarChipsTema(tema);
    window.dispatchEvent(new CustomEvent('temaCambiado', { detail: { tema, oscuro } }));
}

function sincronizarChipsTema(tema) {
    document.querySelectorAll('input[name="tema"]').forEach((chip) => {
        chip.checked = chip.value === tema;
        chip.parentElement.classList.toggle('activo', chip.value === tema);
    });
}

/** Persiste el tema elegido en el servidor (para el login y el resto de vistas). */
async function guardarTemaServidor(tema) {
    try {
        const datos = new FormData();
        datos.append('accion', 'cambiar_tema');
        datos.append('tema', tema);
        await fetch('api/configuracion.php', { method: 'POST', body: datos });
    } catch (e) { /* sin conexión: igualmente queda en localStorage */ }
}

function alternarTemaRapido(btn) {
    const nuevo = temaActual() === 'oscuro' ? 'claro' : 'oscuro';
    if (btn) {
        btn.classList.add('toggling');
        setTimeout(() => btn.classList.remove('toggling'), 400);
    }
    aplicarTema(nuevo);
    guardarTemaServidor(nuevo);
}

/* Botones rápidos de tema (encabezado, login y menú lateral) */
document.querySelectorAll('[data-tema-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => alternarTemaRapido(btn));
});

/* Los chips de la sección Configuración cambian el tema al instante */
document.querySelectorAll('input[name="tema"]').forEach((chip) => {
    chip.addEventListener('change', () => {
        aplicarTema(chip.value);
        guardarTemaServidor(chip.value);
    });
});
