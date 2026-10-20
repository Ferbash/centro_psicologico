// assets/js/main.js - Lógica frontend del Centro Psicológico

// Función auxiliar para escapar caracteres HTML y prevenir ataques XSS
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Nueva función para inyectar el token JWT en cada petición
function getAuthHeaders() {
    const token = localStorage.getItem('token');
    return {
        'Content-Type': 'application/json',
        'Authorization': token ? `Bearer ${token}` : ''
    };
}

// Manejo de cierres de sesión por token inválido
async function procesarRespuestaAPI(res) {
    const data = await res.json().catch(() => ({}));
    if (res.status === 401) {
        alert("Tu sesión expiró. Vuelve a iniciar sesión.");
        cerrarSesion();
        throw new Error("Sesión inválida");
    }
    if (!res.ok) throw new Error(data.mensaje || "Error del servidor.");
    return data;
}

// Muestra el error al usuario (la sesión inválida ya avisó)
function mostrarError(error) {
    if (error.message !== "Sesión inválida") alert(error.message);
}

function toggleRolFields() {
    const rol = document.getElementById('reg-rol').value;
    const campoTelefono = document.getElementById('campo-telefono');
    const camposProfesional = document.getElementById('campos-profesional');
    if (rol === 'profesional') {
        campoTelefono.classList.add('d-none');
        camposProfesional.classList.remove('d-none');
    } else {
        campoTelefono.classList.remove('d-none');
        camposProfesional.classList.add('d-none');
    }
}

document.getElementById('form-login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;

    try {
        const respuesta = await fetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
        });
        const data = await respuesta.json();
        if (respuesta.ok) {
            // Guardamos el usuario y ahora también el TOKEN JWT
            localStorage.setItem('usuario', JSON.stringify(data.usuario));
            localStorage.setItem('token', data.token); 
            cargarDashboard(data.usuario);
        } else {
            alert(data.mensaje);
        }
    } catch (error) {
        alert("Error de conexión. Es posible que el servidor se esté reactivando, por favor aguarde unos segundos e intente nuevamente.");
    }
});

document.getElementById('form-register').addEventListener('submit', async (e) => {
    e.preventDefault();
    const nombre = document.getElementById('reg-nombre').value;
    const email = document.getElementById('reg-email').value;
    const password = document.getElementById('reg-password').value;
    const ciudad = document.getElementById('reg-ciudad').value;
    const rol = document.getElementById('reg-rol').value;
    
    let bodyData = { nombre, email, password, ciudad, rol };
    if (rol === 'paciente') {
        bodyData.telefono = document.getElementById('reg-telefono').value;
    } else {
        bodyData.matricula = document.getElementById('reg-matricula').value;
        bodyData.especialidad = document.getElementById('reg-especialidad').value;
    }

    try {
        const respuesta = await fetch('api/registrar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(bodyData)
        });
        const data = await respuesta.json();
        alert(data.mensaje);
        if (respuesta.ok) {
            location.reload();
        }
    } catch (error) {
        alert("Error de conexión al registrar. Por favor, intente nuevamente en unos instantes.");
    }
});

async function cargarDashboard(usuario) {
    document.getElementById('auth-section').classList.add('d-none');
    document.getElementById('dashboard-section').classList.remove('d-none');
    document.getElementById('welcome-msg').innerText = `Panel de ${usuario.rol === 'paciente' ? 'Paciente' : 'Profesional'}: ${escapeHTML(usuario.nombre)}`;

    const contenido = document.getElementById('panel-contenido');
    
    if (usuario.rol === 'paciente') {
        contenido.innerHTML = `
            <h4 class="mb-3">Solicitar un Turno</h4>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Seleccionar Profesional</label>
                    <select class="form-select" id="select-profesional"></select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Fecha y Hora</label>
                    <input type="datetime-local" class="form-control" id="turno-fecha">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" onclick="solicitarTurno(${usuario.perfil_id})">Reservar Turno</button>
                </div>
            </div>
            <hr>
            <h4 class="mb-3">Mis Turnos Solicitados</h4>
            <div id="lista-turnos-paciente">Cargando turnos...</div>
        `;
        cargarSelectProfesionales();
        cargarTurnosPaciente(usuario.perfil_id);

    } else {
        contenido.innerHTML = `
            <h4 class="mb-3">Gestión de Turnos Asignados</h4>
            <div id="lista-turnos-profesional" class="mb-5">Cargando turnos...</div>
            <hr>
            <h4 class="mb-3">Registro de Historia Clínica</h4>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Seleccionar Paciente</label>
                    <select class="form-select" id="hc-paciente-id">
                        <option value="">Cargando pacientes...</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notas Clínicas / Evolución</label>
                    <textarea class="form-control" id="hc-notas" rows="3" placeholder="Escriba la evolución clínica..."></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-success" onclick="guardarHistoriaClinica()">Guardar Historia Clínica</button>
                </div>
            </div>
            <hr>
            <h4 class="mb-3">Listado de Historias Clínicas Registradas</h4>
            <div id="lista-historias-clinicas">Cargando historiales...</div>
        `;
        cargarTurnosProfesional(usuario.perfil_id);
        cargarHistoriasClinicasProfesional(usuario.perfil_id);
        cargarSelectPacientesHistoriaClinica(usuario.perfil_id);
    }
}

async function cargarSelectProfesionales() {
    try {
        const res = await fetch('api/obtener_profesionales.php', { headers: getAuthHeaders() });
        const data = await procesarRespuestaAPI(res);
        const select = document.getElementById('select-profesional');
        if (data.exito) {
            select.innerHTML = data.profesionales.map(p => `<option value="${escapeHTML(p.profesional_id)}">${escapeHTML(p.nombre)} - ${escapeHTML(p.especialidad)} (Mat: ${escapeHTML(p.matricula)})</option>`).join('');
        } else {
            select.innerHTML = '<option>No hay profesionales disponibles</option>';
        }
    } catch (error) {
        document.getElementById('select-profesional').innerHTML = '<option>Error al cargar profesionales</option>';
    }
}

async function cargarSelectPacientesHistoriaClinica(profesionalId) {
    try {
        const res = await fetch(`api/gestionar_turnos.php?profesional_id=${profesionalId}`, { headers: getAuthHeaders() });
        const data = await procesarRespuestaAPI(res);
        const select = document.getElementById('hc-paciente-id');
        
        if (data.exito && data.turnos.length > 0) {
            const pacientesUnicos = Array.from(new Set(data.turnos.map(t => t.paciente_id)))
                .map(id => data.turnos.find(t => t.paciente_id === id));
                
            select.innerHTML = pacientesUnicos.map(p => 
                `<option value="${escapeHTML(p.paciente_id)}">${escapeHTML(p.nombre_paciente)} (ID: ${escapeHTML(p.paciente_id)})</option>`
            ).join('');
        } else {
            select.innerHTML = '<option value="">No hay pacientes con turnos asignados</option>';
        }
    } catch (error) {
        document.getElementById('hc-paciente-id').innerHTML = '<option value="">Error al cargar pacientes</option>';
    }
}

async function solicitarTurno(pacienteId) {
    const profesional_id = document.getElementById('select-profesional').value;
    const fecha_hora_solicitada = document.getElementById('turno-fecha').value;

    if (!profesional_id || !fecha_hora_solicitada) {
        alert('Por favor selecciona un profesional y una fecha y hora.');
        return;
    }

    try {
        const res = await fetch('api/gestionar_turnos.php', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify({ paciente_id: pacienteId, profesional_id, fecha_hora_solicitada })
        });
        const data = await procesarRespuestaAPI(res);
        alert(data.mensaje);
        if (data.exito) location.reload();
    } catch (error) {
        mostrarError(error);
    }
}

async function cargarTurnosPaciente(pacienteId) {
    try {
        const res = await fetch(`api/gestionar_turnos.php?paciente_id=${pacienteId}`, { headers: getAuthHeaders() });
        const data = await procesarRespuestaAPI(res);
        const contenedor = document.getElementById('lista-turnos-paciente');
        
        if (data.exito && data.turnos.length > 0) {
            contenedor.innerHTML = `<table class="table table-striped"><thead><tr><th>Profesional</th><th>Especialidad</th><th>Origen (Ciudad)</th><th>Fecha Solicitada</th><th>Estado</th><th>Alternativa Propuesta</th><th>Acciones</th></tr></thead><tbody>` +
                data.turnos.map(t => {
                    const btnCancelar = (t.estado !== 'cancelado') 
                        ? `<button class="btn btn-sm btn-danger" onclick="actualizarTurno(${escapeHTML(t.id)}, 'cancelado')">Cancelar</button>`
                        : `<button class="btn btn-sm btn-outline-danger" onclick="eliminarTurno(${escapeHTML(t.id)})">Eliminar</button>`;
                    
                    return `<tr>
                        <td>${escapeHTML(t.nombre_profesional)}</td>
                        <td>${escapeHTML(t.especialidad)}</td>
                        <td>${escapeHTML(t.ciudad_paciente) || 'No especificada'}</td>
                        <td>${escapeHTML(t.fecha_hora_solicitada)}</td>
                        <td><span class="badge bg-secondary">${escapeHTML(t.estado)}</span></td>
                        <td>${escapeHTML(t.opcion_horario_alternativo) || 'Ninguna'}</td>
                        <td>${btnCancelar}</td>
                    </tr>`;
                }).join('') +
                `</tbody></table>`;
        } else {
            contenedor.innerHTML = '<p class="text-muted">No tienes turnos registrados.</p>';
        }
    } catch (error) {
        document.getElementById('lista-turnos-paciente').innerHTML = '<p class="text-danger">Error al cargar los turnos.</p>';
    }
}

async function cargarTurnosProfesional(profesionalId) {
    try {
        const res = await fetch(`api/gestionar_turnos.php?profesional_id=${profesionalId}`, { headers: getAuthHeaders() });
        const data = await procesarRespuestaAPI(res);
        const contenedor = document.getElementById('lista-turnos-profesional');
        
        if (data.exito && data.turnos.length > 0) {
            contenedor.innerHTML = `<table class="table table-bordered"><thead><tr><th>Paciente / Origen</th><th>Fecha Solicitada</th><th>Estado</th><th>Acciones / Propuesta Alternativa</th></tr></thead><tbody>` +
                data.turnos.map(t => `
                    <tr>
                        <td>
                            <strong>${escapeHTML(t.nombre_paciente)}</strong> (ID: ${escapeHTML(t.paciente_id)})<br>
                            <small class="text-muted">📍 Origen: ${escapeHTML(t.ciudad_paciente) || 'No especificada'}</small>
                        </td>
                        <td>${escapeHTML(t.fecha_hora_solicitada)}</td>
                        <td><span class="badge bg-info">${escapeHTML(t.estado)}</span></td>
                        <td>
                            <button class="btn btn-sm btn-success mb-1" onclick="actualizarTurno(${escapeHTML(t.id)}, 'aceptado')">Aceptar</button>
                            <div class="input-group input-group-sm mt-1">
                                <input type="datetime-local" class="form-control" id="alt-${escapeHTML(t.id)}">
                                <button class="btn btn-warning" onclick="proponerAlternativa(${escapeHTML(t.id)})">Proponer Alternativa</button>
                            </div>
                        </td>
                    </tr>
                `).join('') +
                `</tbody></table>`;
        } else {
            contenedor.innerHTML = '<p class="text-muted">No hay turnos pendientes para gestionar.</p>';
        }
    } catch (error) {
        document.getElementById('lista-turnos-profesional').innerHTML = '<p class="text-danger">Error al cargar los turnos.</p>';
    }
}

async function actualizarTurno(turnoId, estado, alternativa = null) {
    const bodyData = { turno_id: turnoId, estado };
    if (alternativa) bodyData.opcion_horario_alternativo = alternativa;

    try {
        const res = await fetch('api/gestionar_turnos.php', {
            method: 'PUT',
            headers: getAuthHeaders(),
            body: JSON.stringify(bodyData)
        });
        const data = await procesarRespuestaAPI(res);
        alert(data.mensaje);
        location.reload();
    } catch (error) {
        mostrarError(error);
    }
}

async function eliminarTurno(turnoId) {
    if (!confirm('¿Eliminar este turno definitivamente?')) return;
    try {
        const res = await fetch(`api/gestionar_turnos.php?id=${turnoId}`, {
            method: 'DELETE',
            headers: getAuthHeaders()
        });
        const data = await procesarRespuestaAPI(res);
        alert(data.mensaje);
        location.reload();
    } catch (error) {
        mostrarError(error);
    }
}

function proponerAlternativa(turnoId) {
    const fechaAlt = document.getElementById(`alt-${turnoId}`).value;
    if (!fechaAlt) {
        alert('Selecciona una fecha y hora alternativa.');
        return;
    }
    actualizarTurno(turnoId, 'reprogramado', fechaAlt);
}

async function guardarHistoriaClinica() {
    const usuario = JSON.parse(localStorage.getItem('usuario'));
    const profesional_id = usuario ? usuario.perfil_id : null;
    const paciente_id = document.getElementById('hc-paciente-id').value;
    const notas = document.getElementById('hc-notas').value;

    if (!paciente_id || !notas) {
        alert('Completa el paciente y las notas clínicas.');
        return;
    }

    try {
        const res = await fetch('api/historias_clinicas.php', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify({ paciente_id, profesional_id, notas })
        });
        const data = await procesarRespuestaAPI(res);
        alert(data.mensaje);
        if (data.exito) {
            document.getElementById('hc-notas').value = '';
            cargarHistoriasClinicasProfesional(profesional_id);
        }
    } catch (error) {
        mostrarError(error);
    }
}

async function cargarHistoriasClinicasProfesional(profesionalId) {
    try {
        const res = await fetch(`api/historias_clinicas.php?profesional_id=${profesionalId}`, { headers: getAuthHeaders() });
        const data = await procesarRespuestaAPI(res);
        const contenedor = document.getElementById('lista-historias-clinicas');
        
        if (data.exito && data.historias.length > 0) {
            contenedor.innerHTML = `<table class="table table-striped"><thead><tr><th>Fecha y Hora</th><th>Paciente (ID)</th><th>Notas Clínicas</th></tr></thead><tbody>` +
                data.historias.map(h => `
                    <tr>
                        <td><span class="badge bg-dark">${escapeHTML(h.fecha_hora)}</span></td>
                        <td><strong>${escapeHTML(h.nombre_paciente)}</strong> (ID: ${escapeHTML(h.paciente_id)})</td>
                        <td>${escapeHTML(h.notas)}</td>
                    </tr>
                `).join('') +
                `</tbody></table>`;
        } else {
            contenedor.innerHTML = '<p class="text-muted">No hay historias clínicas registradas.</p>';
        }
    } catch (error) {
        document.getElementById('lista-historias-clinicas').innerHTML = '<p class="text-danger">Error al cargar el historial.</p>';
    }
}

function cerrarSesion() {
    localStorage.removeItem('usuario');
    localStorage.removeItem('token'); // Eliminamos el token al salir
    location.reload();
}

window.onload = () => {
    const usuarioGuardado = localStorage.getItem('usuario');
    const tokenGuardado = localStorage.getItem('token');
    
    if (usuarioGuardado && tokenGuardado) {
        cargarDashboard(JSON.parse(usuarioGuardado));
    } else {
        // Por las dudas, limpiamos si quedó algo inconsistente
        localStorage.removeItem('usuario');
        localStorage.removeItem('token');
    }
}