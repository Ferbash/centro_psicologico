<?php
require_once 'config.php';
configurarCORS();
header("Content-Type: application/json; charset=UTF-8");

include_once 'conexion.php';
include_once 'servicios.php';
include_once 'jwt.php';

// Estados que puede asignar cada rol
const ESTADOS_POR_ROL = [
    'paciente'    => ['cancelado'],
    'profesional' => ['aceptado', 'reprogramado', 'cancelado'],
];

$usuarioActual = verificarJWT();
if (!$usuarioActual) {
    responder(401, ["exito" => false, "mensaje" => "No autorizado. Sesión inválida o expirada."]);
}
$rol = $usuarioActual['rol'];
$perfilId = $usuarioActual['perfil_id'];
if (!isset(ESTADOS_POR_ROL[$rol]) || !$perfilId) {
    responder(403, ["exito" => false, "mensaje" => "Acceso denegado."]);
}
$campoPropio = $rol === 'paciente' ? 'paciente_id' : 'profesional_id';

// Devuelve el turno si existe (404) y pertenece al usuario (403)
function obtenerTurnoPropio($conexion, $turnoId, $campoPropio, $perfilId) {
    $stmt = $conexion->prepare("SELECT id, paciente_id, profesional_id FROM turnos WHERE id = ?");
    $stmt->execute([$turnoId]);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$turno) {
        responder(404, ["exito" => false, "mensaje" => "El turno no existe."]);
    }
    if ($turno[$campoPropio] != $perfilId) {
        responder(403, ["exito" => false, "mensaje" => "No tienes permiso sobre este turno."]);
    }
    return $turno;
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // El filtro sale del token: se ignoran paciente_id / profesional_id del query string
            $sql = "SELECT t.id, t.fecha_hora_solicitada, t.estado, t.opcion_horario_alternativo,
                           p.id as paciente_id, p.ciudad as ciudad_paciente, up.nombre as nombre_paciente,
                           pr.id as profesional_id, uprof.nombre as nombre_profesional, pr.especialidad
                    FROM turnos t
                    JOIN pacientes p ON t.paciente_id = p.id
                    JOIN usuarios up ON p.usuario_id = up.id
                    JOIN profesionales pr ON t.profesional_id = pr.id
                    JOIN usuarios uprof ON pr.usuario_id = uprof.id
                    WHERE t.$campoPropio = ?";
            $parametros = [$perfilId];

            $id = $_GET['id'] ?? null;
            if ($id !== null) {
                $sql .= " AND t.id = ?";
                $parametros[] = $id;
            }
            $stmt = $conexion->prepare($sql . " ORDER BY t.fecha_hora_solicitada DESC");
            $stmt->execute($parametros);
            $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($id !== null) {
                if (!$turnos) responder(404, ["exito" => false, "mensaje" => "El turno no existe."]);
                responder(200, ["exito" => true, "turno" => $turnos[0]]);
            }
            responder(200, ["exito" => true, "turnos" => $turnos]);
            break;

        case 'POST':
            if ($rol !== 'paciente') {
                responder(403, ["exito" => false, "mensaje" => "Solo los pacientes pueden solicitar turnos."]);
            }
            $datos = leerJSON();
            $profesional_id = $datos->profesional_id ?? null;
            if (empty($profesional_id) || empty($datos->fecha_hora_solicitada)) {
                responder(400, ["exito" => false, "mensaje" => "Faltan datos obligatorios para el turno."]);
            }

            $fecha = normalizarFecha($datos->fecha_hora_solicitada);
            if (!$fecha) {
                responder(422, ["exito" => false, "mensaje" => "El formato de fecha no es válido."]);
            }
            if (new DateTime($fecha) <= new DateTime()) {
                responder(422, ["exito" => false, "mensaje" => "La fecha del turno debe ser futura."]);
            }

            $stmt = $conexion->prepare("SELECT id FROM profesionales WHERE id = ?");
            $stmt->execute([$profesional_id]);
            if ($stmt->rowCount() === 0) {
                responder(404, ["exito" => false, "mensaje" => "El profesional seleccionado no existe."]);
            }

            // Evita dos turnos activos del mismo profesional en el mismo horario
            $stmt = $conexion->prepare("SELECT id FROM turnos WHERE profesional_id = ? AND fecha_hora_solicitada = ? AND estado IN ('pendiente','aceptado')");
            $stmt->execute([$profesional_id, $fecha]);
            if ($stmt->rowCount() > 0) {
                responder(409, ["exito" => false, "mensaje" => "El profesional ya tiene un turno en ese horario."]);
            }

            // El paciente sale del token, no del cuerpo
            $stmt = $conexion->prepare("INSERT INTO turnos (paciente_id, profesional_id, fecha_hora_solicitada, estado) VALUES (?, ?, ?, 'pendiente')");
            $stmt->execute([$perfilId, $profesional_id, $fecha]);
            $nuevoId = $conexion->lastInsertId();

            // El aviso por correo es secundario: si falla, el turno igual se crea
            $stmt = $conexion->prepare("SELECT u.email FROM pacientes p JOIN usuarios u ON p.usuario_id = u.id WHERE p.id = ?");
            $stmt->execute([$perfilId]);
            if ($email = $stmt->fetchColumn()) {
                enviarCorreoResend($email, "Turno Solicitado - Centro Psicológico",
                    "<h3>Hola</h3><p>Tu turno fue solicitado para: <b>" . htmlspecialchars($fecha) . "</b>. Queda pendiente de confirmación.</p>");
            }

            responder(201, ["exito" => true, "mensaje" => "Turno solicitado con éxito.", "id" => $nuevoId]);
            break;

        case 'PUT':
            $datos = leerJSON();
            $turno_id = $datos->turno_id ?? null;
            $estado = $datos->estado ?? null;
            if (empty($turno_id) || empty($estado)) {
                responder(400, ["exito" => false, "mensaje" => "Faltan datos para actualizar el turno."]);
            }
            if (!in_array($estado, ESTADOS_POR_ROL[$rol], true)) {
                responder(422, ["exito" => false, "mensaje" => "Estado no permitido para tu rol."]);
            }
            obtenerTurnoPropio($conexion, $turno_id, $campoPropio, $perfilId);

            $alternativa = null;
            if ($estado === 'reprogramado') {
                $alternativa = normalizarFecha($datos->opcion_horario_alternativo ?? '');
                if (!$alternativa || new DateTime($alternativa) <= new DateTime()) {
                    responder(422, ["exito" => false, "mensaje" => "Indica un horario alternativo válido y futuro."]);
                }
            }

            $stmt = $conexion->prepare("UPDATE turnos SET estado = ?, opcion_horario_alternativo = ? WHERE id = ?");
            $stmt->execute([$estado, $alternativa, $turno_id]);

            // Aviso por correo al paciente
            $stmt = $conexion->prepare("SELECT u.email, t.fecha_hora_solicitada, uprof.nombre as nombre_profesional
                                        FROM turnos t
                                        JOIN pacientes p ON t.paciente_id = p.id
                                        JOIN usuarios u ON p.usuario_id = u.id
                                        JOIN profesionales pr ON t.profesional_id = pr.id
                                        JOIN usuarios uprof ON pr.usuario_id = uprof.id
                                        WHERE t.id = ?");
            $stmt->execute([$turno_id]);
            if ($info = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $prof = htmlspecialchars($info['nombre_profesional']);
                $fechaTurno = htmlspecialchars($info['fecha_hora_solicitada']);

                if ($estado === 'aceptado') {
                    $asunto = "¡Turno Confirmado! - Centro Psicológico";
                    $html = "<h3>¡Buenas noticias!</h3><p>Tu turno con <b>$prof</b> del <b>$fechaTurno</b> fue <b>confirmado</b>.</p>";
                } elseif ($estado === 'reprogramado') {
                    $asunto = "Propuesta de Reprogramación - Centro Psicológico";
                    $html = "<h3>Actualización de Turno</h3><p><b>$prof</b> propuso un nuevo horario: <b>" . htmlspecialchars($alternativa) . "</b>.</p>";
                } else {
                    $asunto = "Actualización de Turno - Centro Psicológico";
                    $html = "<h3>Actualización</h3><p>Tu turno con <b>$prof</b> pasó al estado: <b>" . htmlspecialchars($estado) . "</b>.</p>";
                }
                enviarCorreoResend($info['email'], $asunto, $html);
            }

            responder(200, ["exito" => true, "mensaje" => "Turno actualizado correctamente."]);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (empty($id)) {
                responder(400, ["exito" => false, "mensaje" => "Falta el id del turno."]);
            }
            obtenerTurnoPropio($conexion, $id, $campoPropio, $perfilId);

            $stmt = $conexion->prepare("DELETE FROM turnos WHERE id = ?");
            $stmt->execute([$id]);
            responder(200, ["exito" => true, "mensaje" => "Turno eliminado correctamente."]);
            break;

        default:
            responder(405, ["exito" => false, "mensaje" => "Método no permitido."]);
    }
} catch (Exception $e) {
    error_log("Error en gestionar_turnos.php: " . $e->getMessage());
    responder(500, ["exito" => false, "mensaje" => "Error interno del servidor al procesar el turno."]);
}
