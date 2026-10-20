<?php
require_once 'config.php';
configurarCORS();
header("Content-Type: application/json; charset=UTF-8");

include_once 'conexion.php';
include_once 'jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responder(405, ["exito" => false, "mensaje" => "Método no permitido."]);
}
if (!verificarJWT()) {
    responder(401, ["exito" => false, "mensaje" => "No autorizado. Sesión inválida o expirada."]);
}

try {
    $stmt = $conexion->query("SELECT p.id as profesional_id, u.nombre, p.matricula, p.especialidad
                              FROM profesionales p JOIN usuarios u ON p.usuario_id = u.id");
    // Lista vacía no es un error: 200 con arreglo vacío
    responder(200, ["exito" => true, "profesionales" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    error_log("Error en obtener_profesionales.php: " . $e->getMessage());
    responder(500, ["exito" => false, "mensaje" => "Error interno del servidor al obtener la lista de profesionales."]);
}
