<?php
require_once 'config.php';
configurarCORS();
header("Content-Type: application/json; charset=UTF-8");

include_once 'conexion.php';
include_once 'jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ["exito" => false, "mensaje" => "Método no permitido."]);
}

try {
    $datos = leerJSON();
    $email = trim((string)($datos->email ?? ''));
    $password = (string)($datos->password ?? '');

    if ($email === '' || $password === '') {
        responder(400, ["exito" => false, "mensaje" => "Por favor, complete todos los campos."]);
    }

    $stmt = $conexion->prepare("SELECT id, nombre, email, password, rol FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Mensaje genérico: no revela si el email existe
    if (!$usuario || !password_verify($password, $usuario['password'])) {
        responder(401, ["exito" => false, "mensaje" => "Credenciales inválidas."]);
    }

    // ID del perfil según el rol
    $tabla = $usuario['rol'] === 'profesional' ? 'profesionales' : 'pacientes';
    $stmt = $conexion->prepare("SELECT id FROM $tabla WHERE usuario_id = ?");
    $stmt->execute([$usuario['id']]);
    $perfil_id = $stmt->fetchColumn() ?: null;

    $payloadUsuario = [
        "id"        => $usuario['id'],
        "perfil_id" => $perfil_id,
        "nombre"    => $usuario['nombre'],
        "email"     => $usuario['email'],
        "rol"       => $usuario['rol']
    ];

    responder(200, [
        "exito"   => true,
        "mensaje" => "Inicio de sesión exitoso.",
        "token"   => generarJWT($payloadUsuario),
        "usuario" => $payloadUsuario
    ]);
} catch (Exception $e) {
    error_log("Error en login.php: " . $e->getMessage());
    responder(500, ["exito" => false, "mensaje" => "Error interno del servidor al procesar el inicio de sesión."]);
}
