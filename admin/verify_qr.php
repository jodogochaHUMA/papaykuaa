<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$raw = trim((string)($_POST['code'] ?? $_GET['code'] ?? ''));

if ($raw === '') {
    echo json_encode(['ok' => false, 'message' => 'Parámetro code requerido']);
    exit;
}

// Formato esperado: PPK|ID:X|DOC:Y|COMP:Z
$parts = explode('|', $raw);
if (count($parts) < 4 || $parts[0] !== 'PPK') {
    echo json_encode(['ok' => false, 'message' => 'Formato QR inválido']);
    exit;
}

$id = null;
$doc = null;
$comp = null;

foreach ($parts as $p) {
    if (strpos($p, 'ID:') === 0) $id = (int)substr($p, 3);
    if (strpos($p, 'DOC:') === 0) $doc = trim(substr($p, 4));
    if (strpos($p, 'COMP:') === 0) $comp = trim(substr($p, 5));
}

if (!$id || $doc === null || $comp === null) {
    echo json_encode(['ok' => false, 'message' => 'Datos QR incompletos']);
    exit;
}

try {
    $st = $pdo->prepare("
        SELECT
            p.id,
            p.nombre,
            p.apellido,
            p.documento,
            p.email,
            p.telefono,
            p.estado,
            p.qr_generado,
            p.qr_data,
            c.numero_comprobante,
            p.asistio,
            p.asistio_at
        FROM preinscripciones p
        LEFT JOIN comprobantes_pago c ON c.preinscripcion_id = p.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        echo json_encode(['ok' => false, 'message' => 'Inscripto no encontrado']);
        exit;
    }

    if ($r['estado'] !== 'pagado') {
        echo json_encode(['ok' => false, 'message' => 'Inscripto no pagado']);
        exit;
    }

    if ((int)$r['qr_generado'] !== 1) {
        echo json_encode(['ok' => false, 'message' => 'QR no generado']);
        exit;
    }

    $matches =
        hash_equals((string)$r['documento'], (string)$doc) &&
        hash_equals((string)($r['numero_comprobante'] ?? ''), (string)$comp) &&
        hash_equals((string)($r['qr_data'] ?? ''), (string)$raw);

    if (!$matches) {
        echo json_encode(['ok' => false, 'message' => 'QR no coincide con registro']);
        exit;
    }

    $pdo->beginTransaction();

    $lockSt = $pdo->prepare("
        SELECT asistio, asistio_at
        FROM preinscripciones
        WHERE id = :id
        FOR UPDATE
    ");
    $lockSt->execute([':id' => $id]);
    $lockRow = $lockSt->fetch(PDO::FETCH_ASSOC);

    if ($lockRow && (int)$lockRow['asistio'] === 1) {
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'message' => 'QR válido — ya registrado como asistente',
            'already_scanned' => true,
            'data' => [
                'id' => (int)$r['id'],
                'nombre' => $r['nombre'],
                'apellido' => $r['apellido'],
                'documento' => $r['documento'],
                'asistio_at' => $lockRow['asistio_at'] ?? null
            ]
        ]);
        exit;
    }

    $scanner = (string)($_POST['scanner'] ?? $_GET['scanner'] ?? '');
    $insert = $pdo->prepare("
        INSERT INTO asistencias (preinscripcion_id, scanner, qr_raw, scan_at)
        VALUES (:preinscripcion_id, :scanner, :qr_raw, NOW())
    ");
    $insert->execute([
        ':preinscripcion_id' => $id,
        ':scanner' => $scanner !== '' ? $scanner : null,
        ':qr_raw' => $raw
    ]);

    $update = $pdo->prepare("
        UPDATE preinscripciones
        SET asistio = 1,
            asistio_at = NOW()
        WHERE id = :id
    ");
    $update->execute([':id' => $id]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Asistencia registrada correctamente',
        'already_scanned' => false,
        'data' => [
            'id' => (int)$r['id'],
            'nombre' => $r['nombre'],
            'apellido' => $r['apellido'],
            'documento' => $r['documento'],
            'asistio_at' => date('Y-m-d H:i:s')
        ]
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
    exit;
}