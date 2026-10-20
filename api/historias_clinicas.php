<?php
require_once 'config.php';
configurarCORS();
header("Content-Type: application/json; charset=UTF-8");

include_once 'conexion.php';
include_once 'servicios.php';
include_once 'jwt.php';

$usuarioActual = verificarJWT();
if (!$usuarioActual) {
    responder(401, ["exito" => false, "mensaje" => "No autorizado. Sesión inválida o expirada."]);
}
$rol = $usuarioActual['rol'];
$perfilId = $usuarioActual['perfil_id'];
if (!in_array($rol, ['paciente', 'profesional'], true) || !$perfilId) {
    responder(403, ["exito" => false, "mensaje" => "Acceso denegado."]);
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // El filtro sale del token, no del query string
            $campo = $rol === 'paciente' ? 'hc.paciente_id' : 'hc.profesional_id';
            $stmt = $conexion->prepare("SELECT hc.id, hc.paciente_id, hc.profesional_id, hc.notas, hc.fecha as fecha_hora,
                                               up.nombre as nombre_paciente, uprof.nombre as nombre_profesional
                                        FROM historias_clinicas hc
                                        JOIN pacientes p ON hc.paciente_id = p.id
                                        JOIN usuarios up ON p.usuario_id = up.id
                                        JOIN profesionales pr ON hc.profesional_id = pr.id
                                        JOIN usuarios uprof ON pr.usuario_id = uprof.id
                                        WHERE $campo = ? ORDER BY hc.fecha DESC");
            $stmt->execute([$perfilId]);
            responder(200, ["exito" => true, "historias" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'POST':
            if ($rol !== 'profesional') {
                responder(403, ["exito" => false, "mensaje" => "Solo los profesionales pueden registrar notas."]);
            }
            $datos = leerJSON();
            $paciente_id = $datos->paciente_id ?? null;
            $notas = trim((string)($datos->notas ?? ''));
            if (empty($paciente_id) || $notas === '') {
                responder(400, ["exito" => false, "mensaje" => "Faltan datos obligatorios."]);
            }
            if (mb_strlen($notas) > 5000) {
                responder(422, ["exito" => false, "mensaje" => "Las notas no pueden superar los 5000 caracteres."]);
            }

            $stmt = $conexion->prepare("SELECT id FROM pacientes WHERE id = ?");
            $stmt->execute([$paciente_id]);
            if ($stmt->rowCount() === 0) {
                responder(404, ["exito" => false, "mensaje" => "El paciente seleccionado no existe."]);
            }

            // Solo puede escribir sobre pacientes con los que tiene turnos
            $stmt = $conexion->prepare("SELECT id FROM turnos WHERE paciente_id = ? AND profesional_id = ? LIMIT 1");
            $stmt->execute([$paciente_id, $perfilId]);
            if ($stmt->rowCount() === 0) {
                responder(403, ["exito" => false, "mensaje" => "No tienes turnos con este paciente."]);
            }

            // El profesional sale del token, no del cuerpo
            $stmt = $conexion->prepare("INSERT INTO historias_clinicas (paciente_id, profesional_id, notas) VALUES (?, ?, ?)");
            $stmt->execute([$paciente_id, $perfilId, $notas]);

            // Resguardo externo secundario: si falla, la nota ya está guardada
            @subirResguardoRemotoHistoriaClinica($notas, $paciente_id);

            responder(201, ["exito" => true, "mensaje" => "Historia clínica guardada correctamente."]);
            break;

        default:
            responder(405, ["exito" => false, "mensaje" => "Método no permitido."]);
    }
} catch (Exception $e) {
    error_log("Error en historias_clinicas.php: " . $e->getMessage());
    responder(500, ["exito" => false, "mensaje" => "Error interno del servidor al procesar las historias clínicas."]);
}
