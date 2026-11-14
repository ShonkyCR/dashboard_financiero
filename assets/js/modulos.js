/* =========================================================
   modulos.js — Interacciones de Facturas, Planilla y Clientes/Proveedores
   (Reportes se maneja aparte, con Chart.js, dentro de su propia vista)
   ========================================================= */

/* ---------------- Facturas ---------------- */
function formatearMontoJS(valor) {
    return '$' + Math.round(Number(valor) || 0).toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
}

function buscarClienteDirectorio(nombre) {
    if (typeof DIRECTORIO_CLIENTES === 'undefined' || !Array.isArray(DIRECTORIO_CLIENTES)) return null;
    const clave = (nombre || '').trim().toLowerCase();
    if (!clave) return null;
    return DIRECTORIO_CLIENTES.find(c => (c.nombre || '').toLowerCase() === clave) || null;
}

function clientePorId(id) {
    if (!id || typeof DIRECTORIO_CLIENTES === 'undefined' || !Array.isArray(DIRECTORIO_CLIENTES)) return null;
    return DIRECTORIO_CLIENTES.find(c => String(c.id) === String(id)) || null;
}

function escapeHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto;
    return div.innerHTML;
}

function pintarFichaCliente(cliente, contexto) {
    const fichaId = contexto === 'gasto' ? 'ficha-cliente-gasto' : 'ficha-cliente-factura';
    const ficha = document.getElementById(fichaId);
    if (!ficha) return;
    if (!cliente) {
        ficha.classList.add('oculto');
        ficha.innerHTML = '';
        return;
    }
    ficha.innerHTML =
        '<div class="ficha-cliente-card">' +
            '<strong>' + escapeHtml(cliente.nombre) + '</strong>' +
            '<span class="ficha-cliente-meta">' +
                (cliente.telefono ? '📞 ' + escapeHtml(cliente.telefono) + ' · ' : '') +
                (cliente.email ? '✉ ' + escapeHtml(cliente.email) + ' · ' : '') +
                (cliente.cantidad != null ? cliente.cantidad + ' factura' + (cliente.cantidad === 1 ? '' : 's') + ' · ' : '') +
                formatearMontoJS(cliente.total || 0) +
            '</span>' +
        '</div>';
    ficha.classList.remove('oculto');
}

/** Alias de compatibilidad (usado en abrirModalFactura / prepararClienteNuevoFactura). */
function sincronizarSelectorClienteFactura() {
    sincronizarSelectorCliente('factura');
}

function prepararClienteNuevoFactura() {
    const selector = document.getElementById('selector-cliente-factura');
    const input = document.getElementById('cliente-factura');
    if (!selector || !input) return;
    if (selector.value !== '__nuevo__') return;
    const nombre = input.value.trim();
    if (!nombre) return;
    // Al escribir un nombre que ya existe, volver a elegirlo del directorio
    const existente = buscarClienteDirectorio(nombre);
    if (existente) {
        selector.value = String(existente.id);
        sincronizarSelectorClienteFactura();
    }
}

function abrirModalFactura(factura) {
    const fondo = document.getElementById('fondo-modal-factura');
    const accion = document.getElementById('accion-factura');
    const idInput = document.getElementById('id-factura');
    const titulo = document.getElementById('titulo-modal-factura');
    const bloqueEstado = document.getElementById('bloque-estado-factura');
    const btnGuardar = document.getElementById('btn-guardar-factura');
    const selector = document.getElementById('selector-cliente-factura');
    const inputCliente = document.getElementById('cliente-factura');
    const hidden = document.getElementById('contacto-id-factura');

    if (factura) {
        titulo.textContent = 'Editar factura';
        accion.value = 'editar';
        idInput.value = factura.id;
        document.getElementById('numero-factura').value = factura.numero;
        document.getElementById('monto-factura').value = factura.monto;
        document.getElementById('emision-factura').value = factura.fecha_emision;
        document.getElementById('vencimiento-factura').value = factura.fecha_vencimiento || '';
        document.getElementById('descripcion-factura').value = factura.descripcion || '';

        // Preferir el id del directorio; si no, casar por nombre (case-insensitive)
        let idElegido = '';
        if (factura.contacto_id && clientePorId(factura.contacto_id)) {
            idElegido = String(factura.contacto_id);
        } else {
            const porNombre = buscarClienteDirectorio(factura.cliente || '');
            if (porNombre) idElegido = String(porNombre.id);
        }

        if (selector) {
            if (idElegido) {
                selector.value = idElegido;
                if (hidden) hidden.value = idElegido;
                if (inputCliente) {
                    const c = clientePorId(idElegido);
                    inputCliente.value = c ? c.nombre : (factura.cliente || '');
                }
            } else {
                // Cliente fuera del directorio (datos antiguos) → tratar como nuevo
                selector.value = '__nuevo__';
                if (hidden) hidden.value = '';
                if (inputCliente) inputCliente.value = factura.cliente || '';
            }
            sincronizarSelectorClienteFactura();
        } else if (inputCliente) {
            inputCliente.value = factura.cliente;
            if (hidden) hidden.value = factura.contacto_id || '';
        }

        if (bloqueEstado) bloqueEstado.classList.add('oculto');
        if (btnGuardar) btnGuardar.textContent = 'Guardar cambios';
    } else {
        titulo.textContent = 'Nueva factura';
        accion.value = 'crear';
        idInput.value = '';
        document.getElementById('form-factura').reset();
        // El número autogenerado viene como value por defecto del input;
        // tras reset() el navegador conserva el value original del HTML.
        if (selector) selector.value = '';
        if (inputCliente) {
            inputCliente.value = '';
            inputCliente.classList.add('oculto');
            inputCliente.required = false;
        }
        if (hidden) hidden.value = '';
        if (bloqueEstado) bloqueEstado.classList.remove('oculto');
        if (btnGuardar) btnGuardar.textContent = 'Guardar factura';
        sincronizarSelectorClienteFactura();
    }

    if (fondo) fondo.classList.remove('oculto');
}

function cerrarModalFactura() {
    const fondo = document.getElementById('fondo-modal-factura');
    if (fondo) fondo.classList.add('oculto');
}

/* ---------------- Planilla ---------------- */
const etiquetasTarifaPorTipo = {
    semana: 'Monto semanal',
    quincena: 'Monto por quincena',
};

function abrirModalColaborador(colaborador) {
    const fondo = document.getElementById('fondo-modal-colaborador');
    const titulo = document.getElementById('titulo-modal-colaborador');
    const accion = document.getElementById('accion-colaborador');
    const idInput = document.getElementById('id-colaborador');
    const nombre = document.getElementById('nombre-colaborador');
    const puesto = document.getElementById('puesto-colaborador');
    const tipoPago = document.getElementById('tipo_pago-colaborador');
    const tarifa = document.getElementById('tarifa-colaborador');

    if (colaborador) {
        titulo.textContent = 'Editar colaborador';
        accion.value = 'editar';
        idInput.value = colaborador.id;
        nombre.value = colaborador.nombre;
        puesto.value = colaborador.puesto || '';
        tipoPago.value = colaborador.tipo_pago || 'semana';
        tarifa.value = colaborador.tarifa;
    } else {
        titulo.textContent = 'Agregar colaborador';
        accion.value = 'crear';
        idInput.value = '';
        document.getElementById('form-colaborador').reset();
        if (tipoPago) tipoPago.value = 'semana';
    }

    actualizarEtiquetaTarifa();
    if (fondo) fondo.classList.remove('oculto');
}

function cerrarModalColaborador() {
    const fondo = document.getElementById('fondo-modal-colaborador');
    if (fondo) fondo.classList.add('oculto');
}

function actualizarEtiquetaTarifa() {
    const tipoPago = document.getElementById('tipo_pago-colaborador');
    const etiqueta = document.getElementById('etiqueta-tarifa');
    if (tipoPago && etiqueta) {
        etiqueta.textContent = etiquetasTarifaPorTipo[tipoPago.value] || 'Tarifa';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const tipoPago = document.getElementById('tipo_pago-colaborador');
    if (tipoPago) tipoPago.addEventListener('change', actualizarEtiquetaTarifa);
});

/* ---------------- Clientes / Proveedores ---------------- */
function abrirModalContacto(contacto) {
    const fondo = document.getElementById('fondo-modal-contacto');
    const accion = document.getElementById('accion-contacto');
    const idInput = document.getElementById('id-contacto');
    const titulo = document.getElementById('titulo-modal-contacto');
    const btnGuardar = document.getElementById('btn-guardar-contacto');

    if (contacto) {
        titulo.textContent = 'Editar contacto';
        accion.value = 'editar';
        idInput.value = contacto.id;
        document.getElementById('nombre-contacto').value = contacto.nombre;
        document.getElementById('telefono-contacto').value = contacto.telefono || '';
        document.getElementById('email-contacto').value = contacto.email || '';
        document.getElementById('notas-contacto').value = contacto.notas || '';

        seleccionarTipoContacto(contacto.tipo);

        const checkbox = document.getElementById('checkbox-periodico');
        const camposPeriodico = document.getElementById('campos-periodico');
        const esPeriodico = contacto.gasto_periodico == 1 || contacto.gasto_periodico === '1';
        if (checkbox) checkbox.checked = esPeriodico;
        if (camposPeriodico) camposPeriodico.classList.toggle('oculto', !esPeriodico);
        document.getElementById('monto-periodico-contacto').value = contacto.monto_periodico || '';
        if (contacto.frecuencia) {
            document.getElementById('frecuencia-contacto').value = contacto.frecuencia;
        }

        const chkProyecto = document.getElementById('checkbox-proyecto-finalizado');
        if (chkProyecto) chkProyecto.checked = contacto.proyecto_finalizado == 1 || contacto.proyecto_finalizado === '1';

        if (btnGuardar) btnGuardar.textContent = 'Guardar cambios';
    } else {
        titulo.textContent = 'Agregar contacto';
        accion.value = 'crear';
        idInput.value = '';
        document.getElementById('form-contacto').reset();
        seleccionarTipoContacto('cliente');
        const camposPeriodico = document.getElementById('campos-periodico');
        if (camposPeriodico) camposPeriodico.classList.add('oculto');
        const chkProy = document.getElementById('checkbox-proyecto-finalizado');
        if (chkProy) chkProy.checked = false;
        if (btnGuardar) btnGuardar.textContent = 'Guardar contacto';
    }

    if (fondo) fondo.classList.remove('oculto');
}

function cerrarModalContacto() {
    const fondo = document.getElementById('fondo-modal-contacto');
    if (fondo) fondo.classList.add('oculto');
}

function seleccionarTipoContacto(tipo) {
    const input = document.getElementById('input-tipo-contacto');
    const chipCliente = document.getElementById('chip-cliente');
    const chipProveedor = document.getElementById('chip-proveedor');
    const bloquePeriodico = document.getElementById('bloque-gasto-periodico');
    const bloqueProyecto = document.getElementById('bloque-proyecto-cliente');
    const chkProyecto = document.getElementById('checkbox-proyecto-finalizado');

    if (input) input.value = tipo;
    if (chipCliente) chipCliente.classList.toggle('activo-ingreso', tipo === 'cliente');
    if (chipProveedor) chipProveedor.classList.toggle('activo-gasto', tipo === 'proveedor');

    // El "gasto periódico" solo tiene sentido para proveedores
    if (bloquePeriodico) bloquePeriodico.classList.toggle('oculto', tipo !== 'proveedor');
    // El estado del proyecto solo aplica a clientes
    if (bloqueProyecto) bloqueProyecto.classList.toggle('oculto', tipo !== 'cliente');
    if (chkProyecto && tipo !== 'cliente') chkProyecto.checked = false;
}

/* ---------------- Gastos (módulo Gastos) ---------------- */
/**
 * Abre el modal de gasto. Sin argumento: modo "crear".
 * Con un objeto {id, monto, categoria, descripcion, fecha, contacto_id}: modo "editar".
 */
function abrirModalGasto(gasto) {
    const fondo = document.getElementById('fondo-modal-gasto');
    const form = document.getElementById('form-gasto');
    if (!fondo || !form) return;
    form.reset();

    const accion = document.getElementById('accion-gasto');
    const idInput = document.getElementById('id-gasto');
    const titulo = document.getElementById('titulo-modal-gasto');
    const btn = document.getElementById('btn-guardar-gasto');
    const error = document.getElementById('error-gasto');
    const sel = document.getElementById('selector-cliente-gasto');
    const inputNuevo = document.getElementById('cliente-gasto');
    const hidden = document.getElementById('contacto-id-gasto');
    const ficha = document.getElementById('ficha-cliente-gasto');

    if (error) error.classList.add('oculto');

    if (gasto) {
        // ---- Modo editar ----
        if (titulo) titulo.textContent = 'Editar gasto';
        if (accion) accion.value = 'editar';
        if (idInput) idInput.value = gasto.id;
        if (btn) btn.textContent = 'Guardar cambios';

        document.getElementById('monto-gasto').value = gasto.monto;
        document.getElementById('categoria-gasto').value = gasto.categoria;
        document.getElementById('descripcion-gasto').value = gasto.descripcion || '';
        document.getElementById('fecha-gasto').value = gasto.fecha;

        if (sel) sel.value = gasto.contacto_id ? String(gasto.contacto_id) : '';
        sincronizarSelectorCliente('gasto');

        fondo.classList.remove('oculto');
        return;
    }

    // ---- Modo crear ----
    if (titulo) titulo.textContent = 'Registrar gasto';
    if (accion) accion.value = 'crear';
    if (idInput) idInput.value = '';
    if (btn) btn.textContent = 'Guardar gasto';

    const fecha = document.getElementById('fecha-gasto');
    if (fecha) fecha.value = new Date().toISOString().slice(0, 10);

    // Resetear selector de cliente del gasto
    if (sel) sel.value = '';
    if (inputNuevo) {
        inputNuevo.value = '';
        inputNuevo.classList.add('oculto');
        inputNuevo.required = false;
    }
    if (hidden) hidden.value = '';
    if (ficha) {
        ficha.classList.add('oculto');
        ficha.innerHTML = '';
    }
    fondo.classList.remove('oculto');
}

/**
 * Sincroniza el desplegable de cliente de un modal (factura o gasto).
 * @param {'factura'|'gasto'} contexto
 */
function sincronizarSelectorCliente(contexto) {
    const esFactura = contexto === 'factura';
    const selector = document.getElementById(esFactura ? 'selector-cliente-factura' : 'selector-cliente-gasto');
    const hidden = document.getElementById(esFactura ? 'contacto-id-factura' : 'contacto-id-gasto');
    const ficha = document.getElementById(esFactura ? 'ficha-cliente-factura' : 'ficha-cliente-gasto');
    const input = document.getElementById(esFactura ? 'cliente-factura' : 'cliente-gasto');
    if (!selector || !hidden) return;

    const valor = selector.value;

    if (valor === '__nuevo__') {
        hidden.value = '';
        if (input) {
            input.classList.remove('oculto');
            input.required = esFactura;
            input.focus();
            const nombre = input.value.trim();
            if (nombre && ficha) {
                const existente = buscarClienteDirectorio(nombre);
                if (existente) {
                    pintarFichaCliente(existente, contexto);
                } else {
                    ficha.innerHTML =
                        '<div class="ficha-cliente-card ficha-cliente-nuevo">' +
                            '<strong>Cliente nuevo</strong>' +
                            '<span class="ficha-cliente-meta">' +
                                'Se creará “' + escapeHtml(nombre) + '” al guardar.' +
                            '</span>' +
                        '</div>';
                    ficha.classList.remove('oculto');
                }
            } else if (ficha) {
                ficha.classList.add('oculto');
            }
        }
        return;
    }

    if (valor && valor !== '') {
        const cliente = clientePorId(valor);
        if (cliente) {
            hidden.value = cliente.id;
            if (input) {
                if (esFactura) {
                    input.value = cliente.nombre;
                } else {
                    input.value = '';
                }
                input.classList.add('oculto');
                input.required = false;
            }
            pintarFichaCliente(cliente, contexto);
            return;
        }
    }

    // Vacío / sin cliente
    hidden.value = '';
    if (input) {
        input.value = '';
        input.classList.add('oculto');
        input.required = false;
    }
    if (ficha) {
        ficha.classList.add('oculto');
        ficha.innerHTML = '';
    }
}

function cerrarModalGasto() {
    const fondo = document.getElementById('fondo-modal-gasto');
    if (fondo) fondo.classList.add('oculto');
}

document.addEventListener('DOMContentLoaded', () => {
    // Helper: cerrar modal al click en backdrop (nueva estructura con .fondo-modal-backdrop)
    function setupModalBackdropClose(modalId, closeFn) {
        const fondo = document.getElementById(modalId);
        if (!fondo) return;
        fondo.addEventListener('click', (evento) => {
            // Cerrar si click en el fondo mismo O en el backdrop (hijo .fondo-modal-backdrop)
            if (evento.target === fondo || evento.target.classList?.contains('fondo-modal-backdrop')) {
                closeFn();
            }
        });
    }

    setupModalBackdropClose('fondo-modal-factura', () => {
        const fondo = document.getElementById('fondo-modal-factura');
        if (fondo) fondo.classList.add('oculto');
    });
    setupModalBackdropClose('fondo-modal-colaborador', () => {
        const fondo = document.getElementById('fondo-modal-colaborador');
        if (fondo) fondo.classList.add('oculto');
    });
    setupModalBackdropClose('fondo-modal-contacto', () => {
        const fondo = document.getElementById('fondo-modal-contacto');
        if (fondo) fondo.classList.add('oculto');
    });
    setupModalBackdropClose('fondo-modal-gasto', cerrarModalGasto);

    // Selector de cliente en modal de facturas
    const selectorCliente = document.getElementById('selector-cliente-factura');
    if (selectorCliente) {
        selectorCliente.addEventListener('change', () => sincronizarSelectorCliente('factura'));
    }
    const inputCliente = document.getElementById('cliente-factura');
    if (inputCliente) {
        inputCliente.addEventListener('input', () => {
            if (selectorCliente && selectorCliente.value === '__nuevo__') {
                sincronizarSelectorCliente('factura');
            }
        });
        inputCliente.addEventListener('change', prepararClienteNuevoFactura);
    }
    const formFactura = document.getElementById('form-factura');
    if (formFactura) {
        formFactura.addEventListener('submit', () => {
            if (!selectorCliente || !inputCliente) return;
            if (selectorCliente.value && selectorCliente.value !== '__nuevo__') {
                const c = clientePorId(selectorCliente.value);
                if (c) inputCliente.value = c.nombre;
            }
        });
    }

    // Selector de cliente en modal de gastos (mismo directorio)
    const selectorClienteGasto = document.getElementById('selector-cliente-gasto');
    if (selectorClienteGasto) {
        selectorClienteGasto.addEventListener('change', () => sincronizarSelectorCliente('gasto'));
    }
    const inputClienteGasto = document.getElementById('cliente-gasto');
    if (inputClienteGasto) {
        inputClienteGasto.addEventListener('input', () => {
            if (selectorClienteGasto && selectorClienteGasto.value === '__nuevo__') {
                // Aviso de "se creará" vía ficha
                const ficha = document.getElementById('ficha-cliente-gasto');
                const nombre = inputClienteGasto.value.trim();
                if (ficha) {
                    if (nombre) {
                        ficha.innerHTML =
                            '<div class="ficha-cliente-card ficha-cliente-nuevo">' +
                                '<strong>Cliente nuevo</strong>' +
                                '<span class="ficha-cliente-meta">Se creará “' + escapeHtml(nombre) + '” al guardar el gasto.</span>' +
                            '</div>';
                        ficha.classList.remove('oculto');
                    } else {
                        ficha.classList.add('oculto');
                    }
                }
            }
        });
    }

    const form = document.getElementById('form-gasto');
    if (form) {
        form.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            const error = document.getElementById('error-gasto');
            const btn = document.getElementById('btn-guardar-gasto');
            if (error) error.classList.add('oculto');
            if (btn) btn.classList.add('cargando');

            try {
                const datos = new FormData(form);
                const respuesta = await fetch('api/add_transaction.php', { method: 'POST', body: datos });
                const resultado = await respuesta.json();

                if (!resultado.ok) {
                    if (error) {
                        error.textContent = resultado.mensaje || 'No se pudo guardar el gasto.';
                        error.classList.remove('oculto');
                    }
                    return;
                }
                cerrarModalGasto();
                const accionGasto = document.getElementById('accion-gasto');
                mostrarToast(accionGasto && accionGasto.value === 'editar' ? 'Gasto actualizado ✓' : 'Gasto registrado ✓');
                setTimeout(() => window.location.reload(), 600);
            } catch (err) {
                if (error) {
                    error.textContent = 'Hubo un problema de conexión. Intenta de nuevo.';
                    error.classList.remove('oculto');
                }
            } finally {
                if (btn) btn.classList.remove('cargando');
            }
        });
    }
});
