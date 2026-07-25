<?php
// ============================================================
//  EJECUTAR UNA SOLA VEZ Y LUEGO ELIMINAR ESTE ARCHIVO
// ============================================================
require_once 'db_config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = "CREATE TABLE IF NOT EXISTS preinscripciones (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token         CHAR(64) NOT NULL UNIQUE,
        nombre        VARCHAR(100) NOT NULL,
        apellido      VARCHAR(100) NOT NULL,
        documento     VARCHAR(30)  NOT NULL,
        email         VARCHAR(150) NOT NULL,
        telefono      VARCHAR(30)  NOT NULL,
        fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_origen     VARCHAR(45),
        estado        ENUM('pendiente','confirmado','cancelado') NOT NULL DEFAULT 'pendiente'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo '<p style="font-family:sans-serif;color:green;font-size:1.2rem;">
            ✅ Tabla <strong>preinscripciones</strong> creada correctamente.<br>
            <strong>¡Eliminá este archivo del servidor ahora mismo!</strong>
          </p>';
} catch (PDOException $e) {
    echo '<p style="font-family:sans-serif;color:red;">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}