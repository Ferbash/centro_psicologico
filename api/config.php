<?php

// api/config.php - Configuración por entorno, CORS y helpers comunes
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Variables locales opcionales
if (file_exists(__DIR__ . '/config.local.php')) require_once __DIR__ . '/config.local.php';

function entorno($nombre, $porDefecto = "") {
    $valor = getenv($nombre);
    return $valor !== false ? $valor : $porDefecto;
}

define("DB_HOST", entorno("DB_HOST", "localhost"));
define("DB_PORT", entorno("DB_PORT", "3306"));
define("DB_NAME", entorno("DB_NAME", "centro_psicologia"));
define("DB_USER", entorno("DB_USER", "root"));
define("DB_PASS", entorno("DB_PASS"));

// Claves y secretos: solo por variable de entorno
define("RESEND_API_KEY", entorno("RESEND_API_KEY"));
define("JSONBIN_API_KEY", entorno("JSONBIN_API_KEY"));
define("JWT_SECRET", entorno("JWT_SECRET"));
define("CIFRADO_CLAVE", entorno("CIFRADO_CLAVE"));
define("EMAIL_AVISOS", entorno("EMAIL_AVISOS"));
define("ENTORNO", entorno("ENTORNO", "local")); // "produccion" en la nube
define("ORIGEN_PRODUCCION", entorno("ORIGEN_PRODUCCION", "https://centro-psicologico-ldrf.onrender.com"));

date_default_timezone_set("America/Argentina/Buenos_Aires");

// CORS estricto: solo el origen de producción (y localhost en entorno local)
function configurarCORS() {
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    $esLocal = ENTORNO === 'local' && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origen);
    header("Access-Control-Allow-Origin: " . ($esLocal ? $origen : ORIGEN_PRODUCCION));
    header("Vary: Origin");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

// Envía una respuesta JSON y corta la ejecución
function responder($codigo, $datos) {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

// Lee el cuerpo JSON 400 si no es un objeto válido
function leerJSON() {
    $datos = json_decode(file_get_contents("php://input"));
    if (!is_object($datos)) {
        responder(400, ["exito" => false, "mensaje" => "El cuerpo debe ser un JSON válido."]);
    }
    return $datos;
}

// Convierte una fecha a 'Y-m-d H:i:s'; devuelve null si es inválida
function normalizarFecha($valor) {
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $formato) {
        $fecha = DateTime::createFromFormat($formato, (string)$valor);
        $errores = DateTime::getLastErrors();
        if ($fecha && !($errores && ($errores['warning_count'] || $errores['error_count']))) {
            return $fecha->format('Y-m-d H:i:s');
        }
    }
    return null;
}
