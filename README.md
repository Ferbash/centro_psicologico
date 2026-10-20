# Centro Psicológico Integral

Aplicación web para gestionar turnos y notas clínicas entre pacientes y profesionales de la salud mental.

## 1. Presentación

- **Nombre de la app:** Centro Psicológico Integral
- **Tema:** gestión de turnos e historias clínicas con roles de Paciente y Profesional.
- **Integrantes:** Cintia Funes, Ayelen Arance, Thomas Novelli y Fernando Bassini
- **URL pública (frontend):** https://centro-psicologico-ldrf.onrender.com
- **URL de la API:** https://centro-psicologico-ldrf.onrender.com/api/ (por ejemplo, `/api/gestionar_turnos.php`)

### Qué permite hacer

| Rol | Acciones |
|---|---|
| Paciente | Registrarse, ver profesionales, solicitar turnos, ver el estado de sus turnos y las propuestas de horario alternativo, cancelar y eliminar turnos |
| Profesional | Registrarse, ver sus turnos, aceptarlos o proponer un horario alternativo, registrar notas en la historia clínica |

### Tecnologías

- **Backend:** PHP con PDO y consultas preparadas; sesiones con JWT (HS256).
- **Base de datos:** MySQL (Aiven).
- **Frontend:** HTML5, CSS y JavaScript nativo (Fetch API).
- **Servicios externos:** Resend (correos), Open-Meteo (geocodificación) y JSONBin (resguardo de historias clínicas).
- **Infraestructura:** Docker (Apache + PHP) desplegado en Render.

## 2. Cómo correrla en local (XAMPP)

1. Clonar el repositorio en `C:\xampp\htdocs\centro_psicologico\`.
2. Iniciar **Apache** y **MySQL** desde el panel de XAMPP.
3. En phpMyAdmin crear la base `centro_psicologia` (utf8mb4) e importar `sql/database.sql`.
4. Crear `api/config.local.php` (está en `.gitignore`) con los secretos locales:
   ```php
   <?php
   putenv("JWT_SECRET=un_valor_largo_y_aleatorio");
   putenv("CIFRADO_CLAVE=otro_valor_largo_y_aleatorio");
   ```
   La conexión a la base usa por defecto `localhost`, usuario `root` y contraseña vacía.
5. Abrir http://localhost/centro_psicologico/index.html.

> Sin `RESEND_API_KEY` el envío de correos funciona en **modo simulación**: el aviso se registra en un log y el turno se crea igual.

## 3. Endpoints de la API

Todas las respuestas son JSON con la forma `{ "exito": true|false, "mensaje": "...", ... }`.

La columna **Auth** indica si el endpoint requiere el header `Authorization: Bearer <token>`. El token se obtiene en `POST /api/login.php`. Para probar un endpoint protegido: hacer login, copiar el campo `token` de la respuesta y enviarlo en ese header; sin él la API responde 401.

Un cuerpo que no sea JSON válido devuelve 400, y un método no soportado devuelve 405.

| Método | Ruta | Auth | Descripción | Parámetros / Body | Códigos |
|---|---|---|---|---|---|
| POST | `/api/login.php` | No | Inicia sesión y devuelve el token | `{email, password}` | 200, 400, 401, 405, 500 |
| POST | `/api/registrar.php` | No | Registra paciente o profesional | `{nombre, email, password, rol, ciudad, telefono \| matricula, especialidad}` | 201, 400, 405, 409, 422, 500 |
| GET | `/api/obtener_profesionales.php` | Sí | Lista profesionales | — | 200, 401, 405, 500 |
| GET | `/api/gestionar_turnos.php` | Sí | Lista los turnos propios (por rol) o uno con `?id=` | `?id=X` (opcional) | 200, 401, 403, 404, 500 |
| POST | `/api/gestionar_turnos.php` | Sí | Solicita un turno (paciente) | `{profesional_id, fecha_hora_solicitada}` | 201, 400, 401, 403, 404, 409, 422, 500 |
| PUT | `/api/gestionar_turnos.php` | Sí | Cambia el estado de un turno | `{turno_id, estado, opcion_horario_alternativo}` | 200, 400, 401, 403, 404, 422, 500 |
| DELETE | `/api/gestionar_turnos.php?id=X` | Sí | Elimina un turno propio | `?id=X` | 200, 400, 401, 403, 404, 500 |
| GET | `/api/historias_clinicas.php` | Sí | Lista las historias propias (por rol) | — | 200, 401, 403, 500 |
| POST | `/api/historias_clinicas.php` | Sí | Guarda una nota clínica (profesional) | `{paciente_id, notas}` | 201, 400, 401, 403, 404, 422, 500 |

- **Estados del turno:** `pendiente`, `aceptado`, `reprogramado`, `cancelado`. El paciente solo puede cancelar; el profesional puede aceptar, reprogramar (con horario alternativo futuro) o cancelar.
- Los turnos y las historias se filtran por el usuario del token, no por parámetros de la URL.
- **409:** email ya registrado, o el profesional ya tiene un turno en ese horario. **422:** datos inválidos (fecha pasada, email mal formado, estado no permitido).

## 4. Variables de entorno

Se configuran en el panel de Render. Ninguna clave ni secreto se guarda en el código.

| Variable | Descripción |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Conexión a MySQL (por defecto, valores de XAMPP local) |
| `JWT_SECRET` | Firma de los tokens de sesión (obligatoria) |
| `CIFRADO_CLAVE` | Clave para cifrar las notas clínicas antes del resguardo (obligatoria para resguardar) |
| `RESEND_API_KEY` | Clave de Resend. Sin ella los correos se simulan en un log |
| `JSONBIN_API_KEY` | Clave de JSONBin. Sin ella el resguardo cifrado va a un log local |
| `EMAIL_AVISOS` | Casilla de contacto de la aplicación |
| `ENTORNO` | `local` o `produccion`. En Render debe valer `produccion` (el CORS de localhost solo se habilita en `local`) |
| `ORIGEN_PRODUCCION` | Origen permitido por CORS (por defecto, la URL de Render) |

## 5. Servicios externos

### Resend (envío de correos)
- **Quién lo llama:** el backend (`api/servicios.php`).
- **Por qué:** para no exponer la clave privada en el navegador.
- **Datos que viajan:** email del destinatario, nombre del profesional y fecha del turno.
- **Si falla:** se registra el error y el turno se crea o actualiza igual.

### Open-Meteo Geocoding (ubicación de la ciudad)
- **Quién lo llama:** el backend (`api/servicios.php`) al registrar un usuario.
- **Por qué:** para obtener latitud y longitud a partir de la ciudad, sin clave.
- **Datos que viajan:** únicamente el nombre de la ciudad.
- **Si falla o no encuentra la ciudad:** el usuario se registra igual, sin coordenadas, y la respuesta lo avisa.

### JSONBin (resguardo de historias clínicas)
- **Quién lo llama:** el backend (`api/servicios.php`) cuando un profesional guarda una nota.
- **Por qué:** mantener una copia de respaldo fuera de la base de datos; la clave debe quedar en el servidor.
- **Datos que viajan:** el ID del paciente y la nota **cifrada con AES-256-CBC** (IV aleatorio). Sin `CIFRADO_CLAVE` no se envía nada.
- **Si falla:** la nota ya está guardada en la base de datos; el guardado no se interrumpe. Sin `JSONBIN_API_KEY`, el respaldo cifrado se escribe en un log local.

## 6. Despliegue

- **Proveedor:** Render (**PaaS**), con la base de datos MySQL en Aiven.
- **Por qué:** Render despliega desde GitHub usando el `Dockerfile` y maneja HTTPS y variables de entorno sin administrar el servidor. Como no ofrece MySQL gestionado en el mismo plan, la base está en Aiven.

**Cómo redesplegar:**

1. Conectar el repositorio de GitHub al servicio de Render.
2. Cargar las variables de la sección 4 en el panel de Render.
3. Hacer `git push`: Render detecta el `Dockerfile` y reconstruye la instancia.
4. La primera vez, importar `sql/database.sql` en la base de Aiven.

## 7. Limitaciones conocidas

- **Correos (Resend gratuito):** solo se pueden enviar mails a la cuenta del titular o a un dominio verificado, por lo que los demás usuarios no reciben notificaciones reales (quedan en modo simulación).
- **Archivos locales efímeros:** en Render los logs de simulación y de respaldo viven en el directorio temporal y se pierden al reiniciar o redesplegar.
- **Historias clínicas:** las notas se cifran antes de salir, pero se envían a un servicio de propósito general; falta revisar la normativa vigente sobre historias clínicas y protección de datos personales.
- **Sesión:** el token JWT se guarda en `localStorage` y no hay límite de intentos de login ni recuperación de contraseña.
- **Latencia y suspensión:** la base (Aiven) y la app (Render) están en servidores distintos, y el plan gratuito de Render se suspende por inactividad, por lo que la primera petición puede demorar.

## 8. Estructura del proyecto

```
centro_psicologico/
├── api/
│   ├── conexion.php            # Conexión PDO
│   ├── config.php              # Variables de entorno, CORS y helpers
│   ├── jwt.php                 # Generación y verificación de tokens
│   ├── login.php
│   ├── registrar.php
│   ├── obtener_profesionales.php
│   ├── gestionar_turnos.php
│   ├── historias_clinicas.php
│   └── servicios.php           # Open-Meteo, JSONBin y Resend
├── assets/
│   ├── css/style.css
│   └── js/main.js
├── sql/database.sql
├── .dockerignore
├── .gitignore
├── Dockerfile
├── index.html
├── index.php                   # Redirige a index.html
└── README.md
```

## 9. División de tareas del equipo

| Integrante | Área | Responsabilidades |
|---|---|---|
| **Fernando Bassini** | Backend y base de datos | Migración a PDO, conexion.php, config.php, login.php, registrar.php, database.sql, validación de entradas y manejo de excepciones |
| **Cintia Funes** | Servicios externos y seguridad | servicios.php (Resend, Open-Meteo y JSONBin), modo simulación, sesiones y autorización por rol, revocación de claves, .gitignore y bloqueo de *.log |
| **Ayelen Arance** | Frontend | index.html, main.js, flujos de paciente y profesional, escape de HTML, manejo de errores del fetch y estados de la interfaz |
| **Thomas Novelli** | Despliegue y documentación | Dockerfile, Render y variables de entorno, gestionar_turnos.php, historias_clinicas.php, obtener_profesionales.php, style.css, README e informe técnico |

**Trabajo conjunto:** pruebas cruzadas de cada flujo (registro, turno, aceptación, nota clínica) y revisión de código por pares antes de cada merge.
