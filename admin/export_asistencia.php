<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
session_start();
require_login();

$asistioFilter = isset($_GET['asistio']) ? $_GET['asistio'] : '';

// Construir consulta
$sql = "
  SELECT p.id, p.nombre, p.apellido, p.documento, p.email, p.telefono,
         p.estado, p.asistio, p.asistio_at,
         (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(a.scan_at, '%Y-%m-%d %H:%i:%s'),'|',COALESCE(a.scanner,'')) SEPARATOR '||') FROM asistencias a WHERE a.preinscripcion_id = p.id) AS scans
  FROM preinscripciones p
  LEFT JOIN comprobantes_pago cp ON cp.preinscripcion_id = p.id
";

$params = [];
if ($asistioFilter === '1') {
    $sql .= " WHERE p.asistio = 1";
} elseif ($asistioFilter === '0') {
    $sql .= " WHERE p.asistio = 0";
}
$sql .= " ORDER BY p.asistio DESC, p.apellido ASC, p.nombre ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSV headers
$filename = 'asistencia_' . ($asistioFilter === '1' ? 'asistieron' : ($asistioFilter === '0' ? 'no_asistieron' : 'todos')) . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID','Nombre','Apellido','Documento','Email','Telefono','Estado','Asistio','Asistio_at','Scans']);

foreach ($rows as $r) {
    // formatear scans: 'date|scanner||date2|scanner2' => legible
    $scans = $r['scans'] ?? '';
    if ($scans !== '') {
        $parts = explode('||', $scans);
        $scans_out = implode(' | ', $parts);
    } else {
        $scans_out = '';
    }
    fputcsv($out, [
        $r['id'],
        $r['nombre'],
        $r['apellido'],
        $r['documento'],
        $r['email'],
        $r['telefono'],
        $r['estado'],
        $r['asistio'] ? '1' : '0',
        $r['asistio_at'] ?? '',
        $scans_out
    ]);
}
fclose($out);
exit;