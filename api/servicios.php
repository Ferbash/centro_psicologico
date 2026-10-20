<?php
// api/servicios.php - Servicios cloud externos (Open-Meteo, JSONBin y Resend)

require_once "config.php";

// Cliente HTTP genérico con cURL
function pedirJSON($url, $metodo = "GET", $cuerpo = null, $cabeceras = []) {
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 5);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $metodo);
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }

    if ($cuerpo !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($cuerpo));
        $cabeceras[] = "Content-Type: application/json";
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $cabeceras);

    $respuesta = curl_exec($curl);
    $estado = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($respuesta === false) return [0, null];
    return [$estado, json_decode($respuesta, true)];
}

// 1. Geocodificación con Open-Meteo (sin clave)
function geocodificarCiudad($ciudad) {
    $url = "https://geocoding-api.open-meteo.com/v1/search?" . http_build_query([
        "name" => $ciudad, "count" => 1, "language" => "es", "format" => "json",
    ]);

    [$estado, $datos] = pedirJSON($url);
    if ($estado !== 200 || empty($datos["results"])) {
        return ["estado" => "no_encontrada"];
    }

    $lugar = $datos["results"][0];
    return ["estado" => "ok", "latitud" => $lugar["latitude"], "longitud" => $lugar["longitude"]];
}

// Cifra con AES-256-CBC y IV aleatorio (se antepone al resultado)
function cifrarNota($texto) {
    $clave = hash('sha256', CIFRADO_CLAVE, true);
    $iv = random_bytes(16);
    $cifrado = openssl_encrypt($texto, 'aes-256-cbc', $clave, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cifrado);
}

// 2. Resguardo de notas clínicas cifradas (JSONBin, o log local si no hay clave)
function subirResguardoRemotoHistoriaClinica($contenidoTexto, $idPaciente) {
    if (CIFRADO_CLAVE === "") {
        return ["exito" => false, "modo" => "sin_clave_cifrado"]; // Nunca se resguarda sin cifrar
    }
    $notaCifrada = cifrarNota($contenidoTexto);

    if (JSONBIN_API_KEY === "") {
        $linea = date("Y-m-d H:i:s") . " | Paciente ID: $idPaciente | Nota: $notaCifrada" . PHP_EOL;
        file_put_contents(sys_get_temp_dir() . "/historias_respaldo.log", $linea, FILE_APPEND);
        return ["exito" => true, "modo" => "respaldo_local_seguro"];
    }

    $payload = [
        "paciente_id" => $idPaciente,
        "notas"       => $notaCifrada,
        "timestamp"   => date('Y-m-d H:i:s'),
        "origen"      => "Centro Psicológico - Resguardo Cloud JSONBin"
    ];
    $cabeceras = ["X-Master-Key: " . JSONBIN_API_KEY, "X-Bin-Private: true"];

    [$estado] = pedirJSON("https://api.jsonbin.io/v3/b", "POST", $payload, $cabeceras);
    $ok = ($estado === 200 || $estado === 201);
    return ["exito" => $ok, "modo" => $ok ? "remoto_jsonbin" : "error_remoto"];
}

// 3. Correos transaccionales con Resend (simulación en log si no hay clave)
function enviarCorreoResend($destinatario, $asunto, $mensajeHtml) {
    if (RESEND_API_KEY === "") {
        file_put_contents(sys_get_temp_dir() . "/avisos.log",
            date("Y-m-d H:i:s") . " | SIMULACIÓN MAIL a: $destinatario - Asunto: $asunto" . PHP_EOL,
            FILE_APPEND
        );
        return ["exito" => true, "modo" => "simulacion"];
    }

    $payload = [
        'from'    => 'Centro Psicológico <onboarding@resend.dev>',
        'to'      => [$destinatario],
        'subject' => $asunto,
        'html'    => $mensajeHtml
    ];
    $cabeceras = ['Authorization: Bearer ' . RESEND_API_KEY];

    [$estado, $respuesta] = pedirJSON('https://api.resend.com/emails', "POST", $payload, $cabeceras);

    if ($estado !== 200 && $estado !== 201) {
        error_log("Error al enviar email via Resend ($estado): " . json_encode($respuesta));
        return ["exito" => false, "modo" => "error_envio"];
    }
    return ["exito" => true, "modo" => "remoto_resend"];
}
