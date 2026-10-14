<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="lista_asistencia_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'ID',
    'Nombre',
    'Apellido',
    'Documento',
    'Email',
    'Telefono',
    'Numero comprobante',
    'Estado',
    'Asistio',
    'Fecha asistencia'
]);

$sql = "
    SELECT
        p.id,
        p.nombre,
        p.apellido,
        p.documento,
        p.email,
        p.telefono,
        COALESCE(cp.numero_comprobante, '') AS numero_comprobante,
        p.estado,
        CASE WHEN p.asistio = 1 THEN 'SI' ELSE 'NO' END AS asistio,
        p.asistio_at
    FROM preinscripciones p
    LEFT JOIN comprobantes_pago cp ON cp.preinscripcion_id = p.id
    ORDER BY p.asistio DESC, p.asistio_at DESC, p.apellido ASC
";

$stmt = $pdo->query($sql);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['nombre'],
        $row['apellido'],
        $row['documento'],
        $row['email'],
        $row['telefono'],
        $row['numero_comprobante'],
        $row['estado'],
        $row['asistio'],
        $row['asistio_at'] ?? ''
    ]);
}

fclose($output);
exit;