<?php
// api/jwt.php - Generación y verificación de JSON Web Tokens (HS256)

require_once 'config.php';

function base64UrlEncode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function generarJWT($payload) {
    if (JWT_SECRET === "") throw new Exception("JWT_SECRET no configurado.");

    $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['exp'] = time() + (12 * 60 * 60); // Expira en 12 horas
    $cuerpo = base64UrlEncode(json_encode($payload));
    $firma = base64UrlEncode(hash_hmac('sha256', "$header.$cuerpo", JWT_SECRET, true));

    return "$header.$cuerpo.$firma";
}

// Lee el header Authorization (funciona con o sin apache_request_headers)
function obtenerAuthorization() {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (function_exists('apache_request_headers')) {
        $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
        return $headers['authorization'] ?? '';
    }
    return '';
}

// Devuelve el payload si el token es válido, o false
function verificarJWT() {
    if (JWT_SECRET === "") return false;
    if (!preg_match('/Bearer\s(\S+)/', obtenerAuthorization(), $m)) return false;

    $partes = explode('.', $m[1]);
    if (count($partes) !== 3) return false;

    $firma = base64UrlEncode(hash_hmac('sha256', "$partes[0].$partes[1]", JWT_SECRET, true));
    if (!hash_equals($firma, $partes[2])) return false;

    $payload = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);
    if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) return false;

    return $payload;
}
