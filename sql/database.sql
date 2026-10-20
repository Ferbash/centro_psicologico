-- sql/database.sql - Esquema de centro_psicologia
-- Local: CREATE DATABASE centro_psicologia CHARACTER SET utf8mb4; USE centro_psicologia;
-- En la nube, ejecutar sobre la base ya creada por el proveedor.

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('paciente','profesional') NOT NULL
);

CREATE TABLE pacientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL UNIQUE,
    telefono VARCHAR(30),
    ciudad VARCHAR(100),
    latitud DECIMAL(9,6),
    longitud DECIMAL(9,6),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE profesionales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL UNIQUE,
    matricula VARCHAR(50) NOT NULL,
    especialidad VARCHAR(100) NOT NULL,
    ciudad VARCHAR(100),
    latitud DECIMAL(9,6),
    longitud DECIMAL(9,6),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE turnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    profesional_id INT NOT NULL,
    fecha_hora_solicitada DATETIME NOT NULL,
    estado ENUM('pendiente','aceptado','reprogramado','cancelado') NOT NULL DEFAULT 'pendiente',
    opcion_horario_alternativo DATETIME NULL,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE
);

CREATE TABLE historias_clinicas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    profesional_id INT NOT NULL,
    notas TEXT NOT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE
);
