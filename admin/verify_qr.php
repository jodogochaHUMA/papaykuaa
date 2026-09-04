<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

/**
 * Endpoint para futura app de lectura QR.
 * Recibe por GET o POST:
 *   - code: contenido QR completo, por ejemplo:
 *     PPK|ID:15|DOC:1234567|COMP:ABC-999
 *
 * Respuesta JSON:
 *   { ok: true/false, message: "...", data: {...} }
 */

header('Content-Type: application/json; charset=utf-8');

$raw = trim((string)($_POST['code'] ?? $_GET['code'] ?? ''));

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
        echo json_encode(['ok' => false, 'message' => 'Inscripto no encontrado']);
        exit;
    }

    // Validaciones de seguridad
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

    echo json_encode([
        'ok' => true,
        'message' => 'QR válido',
        'data' => [
            'id' => (int)$r['id'],
            'nombre' => $r['nombre'],
            'apellido' => $r['apellido'],
            'documento' => $r['documento'],
            'estado' => $r['estado']
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Error interno: ' . $e->getMessage()]);
}