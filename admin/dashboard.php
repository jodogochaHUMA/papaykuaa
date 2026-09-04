<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();

$errorMsg = '';
$rows = [];

try {
    $stmt = $pdo->query("
      SELECT 
        id, nombre, apellido, documento, email, telefono, fecha_registro, estado
      FROM preinscripciones
      ORDER BY fecha_registro DESC
      LIMIT 1000
    ");
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | Papapykuaa Admin</title>

  <!-- AdminLTE + dependencias -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="#" class="nav-link">Panel de Inscriptos</a>
      </li>
    </ul>
    <ul class="navbar-nav ml-auto">
      <li class="nav-item mr-2 mt-1">
        <span class="text-muted">👤 <?= htmlspecialchars($_SESSION['admin_username'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
      </li>
      <li class="nav-item">
        <a href="logout.php" class="btn btn-danger btn-sm">Cerrar sesión</a>
      </li>
    </ul>
  </nav>

  <!-- Sidebar -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="#" class="brand-link text-center">
      <img src="../img/logo_intro.png" alt="Logo" class="brand-image img-circle elevation-3" style="opacity:.9">
      <span class="brand-text font-weight-light">Papapykuaa</span><br>
    </a>

    <div class="sidebar">
      <nav class="mt-3">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
          <li class="nav-item">
            <a href="dashboard.php" class="nav-link active">
              <i class="nav-icon fas fa-users"></i>
              <p>Preinscripciones</p>
            </a>
          </li>
        </ul>
      </nav>
    </div>
  </aside>

  <!-- Content -->
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <h1>Listado de preinscripciones</h1>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">

        <?php if ($errorMsg): ?>
          <div class="alert alert-danger">
            <strong>Error SQL:</strong> <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <div class="row">
          <div class="col-md-3">
            <div class="small-box bg-info">
              <div class="inner">
                <h3><?= count($rows) ?></h3>
                <p>Registros cargados</p>
              </div>
              <div class="icon"><i class="fas fa-database"></i></div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Personas preinscritas</h3>
          </div>
          <div class="card-body">
            <table id="tablaInscriptos" class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nombre</th>
                  <th>Apellido</th>
                  <th>Documento</th>
                  <th>Email</th>
                  <th>Teléfono</th>
                  <th>Fecha registro</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['documento'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['telefono'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['fecha_registro'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <?php
                        $estado = $r['estado'];
                        $badge = 'badge-secondary';
                        if ($estado === 'confirmado') $badge = 'badge-success';
                        if ($estado === 'pendiente') $badge = 'badge-warning';
                        if ($estado === 'cancelado') $badge = 'badge-danger';
                      ?>
                      <span class="badge <?= $badge ?>"><?= htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </section>
  </div>

  <footer class="main-footer">
    <strong>&copy; <?= date('Y') ?> Papapykuaa</strong> - Panel administrativo.
  </footer>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>

<script>
$(function () {
  $("#tablaInscriptos").DataTable({
    "responsive": true,
    "autoWidth": false,
    "pageLength": 25,
    "language": {
      "url": "https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json"
    }
  });
});
</script>
</body>
</html>