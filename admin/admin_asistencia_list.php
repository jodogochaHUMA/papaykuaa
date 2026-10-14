<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Si tu admin usa sesión, activa y valida la sesión aquí:
session_start();
  if (!isset($_SESSION['admin_logged']) || !$_SESSION['admin_logged']) {
    header('Location: login.php');
  exit;
}

// Query: trae primer scan (si existe) y resumen
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
  p.asistio,
  p.asistio_at,
  (SELECT MIN(a.scan_at) FROM asistencias a WHERE a.preinscripcion_id = p.id) AS first_scan,
  (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(a.scan_at, '%Y-%m-%d %H:%i:%s'),' (',COALESCE(a.scanner,''),')') ORDER BY a.scan_at SEPARATOR ' | ') FROM asistencias a WHERE a.preinscripcion_id = p.id) AS scans
FROM preinscripciones p
LEFT JOIN comprobantes_pago cp ON cp.preinscripcion_id = p.id
ORDER BY p.asistio DESC, COALESCE(p.asistio_at, (SELECT MIN(a.scan_at) FROM asistencias a WHERE a.preinscripcion_id = p.id)) ASC, p.apellido ASC
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Lista de Asistencia — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="../lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../css/style.css" rel="stylesheet">
  <style>
    .table-wrap { padding: 20px; }
    .search { max-width: 360px; }
    .badge-yes { background:#28a745; color:#fff; }
    .badge-no  { background:#6c757d; color:#fff; }
    .small-note { font-size:0.9rem; color:#666; }
  </style>
</head>
<body>
  <div class="container-fluid">
    <div class="row bg-light align-items-center" style="padding:14px 20px;">
      <div class="col">
        <h4 class="mb-0">Panel — Lista de Asistencia</h4>
        <div class="small-note">Verifica lecturas QR y exporta en CSV</div>
      </div>
      <div class="col-auto">
        <a class="btn btn-success" href="export_asistencia.php">Exportar CSV</a>
      </div>
    </div>

    <div class="table-wrap">
      <div class="row mb-3">
        <div class="col-md-6">
          <input id="searchInput" class="form-control search" placeholder="Buscar por nombre, documento o comprobante">
        </div>
      </div>

      <div class="table-responsive">
        <table id="asistTable" class="table table-striped table-bordered">
          <thead class="thead-dark">
            <tr>
              <th>ID</th>
              <th>Nombre</th>
              <th>Documento</th>
              <th>Comprobante</th>
              <th>Estado</th>
              <th>Asistió</th>
              <th>Fecha asistencia</th>
              <th>Scans (historial)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['id']) ?></td>
                <td><?= htmlspecialchars($r['nombre'] . ' ' . $r['apellido']) ?></td>
                <td><?= htmlspecialchars($r['documento']) ?></td>
                <td><?= htmlspecialchars($r['numero_comprobante']) ?></td>
                <td><?= htmlspecialchars($r['estado']) ?></td>
                <td>
                  <?php if ((int)$r['asistio'] === 1): ?>
                    <span class="badge badge-yes">SI</span>
                  <?php else: ?>
                    <span class="badge badge-no">NO</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['asistio_at'] ?? $r['first_scan'] ?? '') ?></td>
                <td style="max-width:320px; word-break:break-word;"><?= htmlspecialchars($r['scans'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <footer class="text-center small text-muted" style="padding:20px;">
      Generado: <?= date('Y-m-d H:i:s') ?> — Protege esta página dentro del área admin.
    </footer>
  </div>

  <script src="../lib/jquery/jquery.min.js"></script>
  <script src="../lib/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Búsqueda cliente simple
    document.getElementById('searchInput').addEventListener('input', function (e) {
      const q = e.target.value.toLowerCase();
      const rows = document.querySelectorAll('#asistTable tbody tr');
      rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = text.indexOf(q) === -1 ? 'none' : '';
      });
    });
  </script>
</body>
</html>