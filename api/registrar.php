<?php
require_once 'config.php';
configurarCORS();
header("Content-Type: application/json; charset=UTF-8");

include_once 'conexion.php';
include_once 'servicios.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ["exito" => false, "mensaje" => "Método no permitido."]);
}

$datos = leerJSON();
$nombre = trim((string)($datos->nombre ?? ''));
$email = trim((string)($datos->email ?? ''));
$passwordPlano = (string)($datos->password ?? '');
$rol = (string)($datos->rol ?? '');
$ciudad = trim((string)($datos->ciudad ?? ''));
$telefono = trim((string)($datos->telefono ?? ''));
$matricula = trim((string)($datos->matricula ?? ''));
$especialidad = trim((string)($datos->especialidad ?? ''));

// Validaciones (400 = faltan datos, 422 = datos inválidos)
if ($nombre === '' || $email === '' || $passwordPlano === '' || $rol === '') {
    responder(400, ["exito" => false, "mensaje" => "Faltan datos obligatorios."]);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(422, ["exito" => false, "mensaje" => "El correo electrónico no es válido."]);
}
if (!in_array($rol, ['paciente', 'profesional'], true)) {
    responder(422, ["exito" => false, "mensaje" => "El rol debe ser paciente o profesional."]);
}
if (strlen($passwordPlano) < 8) {
    responder(422, ["exito" => false, "mensaje" => "La contraseña debe tener al menos 8 caracteres."]);
}
if ($rol === 'profesional' && ($matricula === '' || $especialidad === '')) {
    responder(422, ["exito" => false, "mensaje" => "Matrícula y especialidad son obligatorias para profesionales."]);
}

// Geocodificación (si falla, el registro continúa sin coordenadas)
$latitud = null;
$longitud = null;
if ($ciudad !== '') {
    $geo = geocodificarCiudad($ciudad);
    if ($geo["estado"] === "ok") {
        $latitud = $geo["latitud"];
        $longitud = $geo["longitud"];
    }
}
$geolocalizado = $latitud !== null;

try {
    $verificar = $conexion->prepare("SELECT id FROM usuarios WHERE email = ?");
    $verificar->execute([$email]);
    if ($verificar->rowCount() > 0) {
        responder(409, ["exito" => false, "mensaje" => "El correo electrónico ya está registrado."]);
    }

    $conexion->beginTransaction();

    $stmt = $conexion->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nombre, $email, password_hash($passwordPlano, PASSWORD_BCRYPT), $rol]);
    $usuario_id = $conexion->lastInsertId();

    if ($rol === 'profesional') {
        $stmt = $conexion->prepare("INSERT INTO profesionales (usuario_id, matricula, especialidad, ciudad, latitud, longitud) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$usuario_id, $matricula, $especialidad, $ciudad, $latitud, $longitud]);
    } else {
        $stmt = $conexion->prepare("INSERT INTO pacientes (usuario_id, telefono, ciudad, latitud, longitud) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$usuario_id, $telefono, $ciudad, $latitud, $longitud]);
    }

    $conexion->commit();

    $mensaje = $geolocalizado || $ciudad === ''
        ? "Usuario registrado correctamente."
        : "Usuario registrado, pero no se pudo ubicar la ciudad indicada.";
    responder(201, ["exito" => true, "mensaje" => $mensaje, "geolocalizado" => $geolocalizado]);

} catch (Exception $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log("Error en registrar.php: " . $e->getMessage());
    responder(500, ["exito" => false, "mensaje" => "Error interno del servidor. No se pudo completar el registro."]);
}
