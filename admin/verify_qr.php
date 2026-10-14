<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$raw = trim((string)($_POST['code'] ?? $_GET['code'] ?? ''));
$scanner = trim((string)($_POST['scanner'] ?? $_GET['scanner'] ?? '')); // opcional: nombre del escáner
if ($raw === '') {
    echo json_encode(['ok' => false, 'message' => 'Parámetro code requerido']);
    exit;
}

// Parse simple: PPK|ID:X|DOC:Y|COMP:Z
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
    $pdo->beginTransaction();

    $st = $pdo->prepare("
      SELECT p.id, p.nombre, p.apellido, p.documento, p.estado, p.qr_generado, p.qr_data,
             c.numero_comprobante
      FROM preinscripciones p
      LEFT JOIN comprobantes_pago c ON c.preinscripcion_id = p.id
      WHERE p.id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => 'Inscripto no encontrado']);
        exit;
    }

    // Validaciones de seguridad
    if ($r['estado'] !== 'pagado') {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => 'Inscripto no pagado']);
        exit;
    }

    if ((int)$r['qr_generado'] !== 1) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => 'QR no generado']);
        exit;
    }

    $matches =
        hash_equals((string)$r['documento'], (string)$doc) &&
        hash_equals((string)($r['numero_comprobante'] ?? ''), (string)$comp) &&
        hash_equals((string)($r['qr_data'] ?? ''), (string)$raw);

    if (!$matches) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => 'QR no coincide con registro']);
        exit;
    }

    // ¿ya existe un registro de escaneo idéntico?
    $chk = $pdo->prepare("SELECT id, scan_at, scanner FROM asistencias WHERE preinscripcion_id = :pid AND qr_raw = :qr LIMIT 1");
    $chk->execute([':pid' => $id, ':qr' => $raw]);
    $exists = $chk->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        // Ya escaneado: devolvemos info pero NO insertamos otro registro
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'message' => 'QR válido — ya escaneado',
            'already_scanned' => true,
            'data' => [
                'id' => (int)$r['id'],
                'nombre' => $r['nombre'],
                'apellido' => $r['apellido'],
                'documento' => $r['documento'],
                'scan_at' => $exists['scan_at'],
                'scanner' => $exists['scanner'] ?? ''
            ]
        ]);
        exit;
    }

    // Insert asistencia
    $ins = $pdo->prepare("INSERT INTO asistencias (preinscripcion_id, scanner, qr_raw, scan_at) VALUES (:pid, :scanner, :qr, NOW())");
    $ins->execute([':pid' => $id, ':scanner' => $scanner ?: null, ':qr' => $raw]);
    // Actualizar preinscripciones
    $upd = $pdo->prepare("UPDATE preinscripciones SET asistio = 1, asistio_at = NOW() WHERE id = :id");
    $upd->execute([':id' => $id]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'QR válido y asistencia registrada',
        'already_scanned' => false,
        'data' => [
            'id' => (int)$r['id'],
            'nombre' => $r['nombre'],
            'apellido' => $r['apellido'],
            'documento' => $r['documento'],
            'scan_at' => date('Y-m-d H:i:s'),
            'scanner' => $scanner
        ]
    ]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Error interno: ' . $e->getMessage()]);
    exit;
}