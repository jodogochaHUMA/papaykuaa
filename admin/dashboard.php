<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();

$errorMsg = '';
$okMsg = '';
$estadosValidos = ['pendiente','pagado'];

/* =========================
   POST: Agregar comprobante
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar_comprobante') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errorMsg = 'Token CSRF inválido.';
    } else {
        $id = (int)($_POST['preinscripcion_id'] ?? 0);
        $numero = trim((string)($_POST['numero_comprobante'] ?? ''));
        $tipo = trim((string)($_POST['tipo'] ?? ''));

        if ($id <= 0 || $numero === '' || !in_array($tipo, ['participante','disertante'], true)) {
            $errorMsg = 'Datos inválidos para registrar comprobante.';
        } else {
            try {
                $pdo->beginTransaction();

                // Verificar estado actual
                $q = $pdo->prepare("SELECT estado FROM preinscripciones WHERE id = :id FOR UPDATE");
                $q->execute([':id' => $id]);
                $ins = $q->fetch();

                if (!$ins) {
                    throw new RuntimeException('Inscripción no encontrada.');
                }
                if ($ins['estado'] !== 'pendiente') {
                    throw new RuntimeException('Solo se puede cargar comprobante si el estado está pendiente.');
                }

                // Insertar comprobante (1 por preinscripción)
                $insComp = $pdo->prepare("
                    INSERT INTO comprobantes_pago (preinscripcion_id, numero_comprobante, tipo)
                    VALUES (:pid, :num, :tipo)
                ");
                $insComp->execute([
                    ':pid' => $id,
                    ':num' => $numero,
                    ':tipo' => $tipo
                ]);

                // Cambiar estado a pagado
                $up = $pdo->prepare("UPDATE preinscripciones SET estado = 'pagado' WHERE id = :id");
                $up->execute([':id' => $id]);

                $pdo->commit();
                $okMsg = 'Comprobante registrado y estado actualizado a pagado.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = 'No se pudo registrar comprobante: ' . $e->getMessage();
            }
        }
    }
}

/* =========================
   POST: Generar QR (simulado)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar_qr') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errorMsg = 'Token CSRF inválido.';
    } else {
        $id = (int)($_POST['preinscripcion_id'] ?? 0);
        if ($id <= 0) {
            $errorMsg = 'ID inválido para generar QR.';
        } else {
            try {
                // Solo permite generar si está pagado y qr_generado = 0
                $up = $pdo->prepare("
                    UPDATE preinscripciones
                    SET qr_generado = 1
                    WHERE id = :id AND estado = 'pagado' AND qr_generado = 0
                ");
                $up->execute([':id' => $id]);

                if ($up->rowCount() > 0) {
                    $okMsg = 'QR generado correctamente (marcado).';
                } else {
                    $errorMsg = 'No se pudo generar QR: verificá estado pagado o si ya fue generado.';
                }
            } catch (Throwable $e) {
                $errorMsg = 'Error al generar QR: ' . $e->getMessage();
            }
        }
    }
}

/* =========================
   Exportar CSV
   ========================= */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $filtroEstado = trim((string)($_GET['estado'] ?? ''));
    $where = '';
    $params = [];

    if ($filtroEstado !== '' && in_array($filtroEstado, $estadosValidos, true)) {
        $where = " WHERE p.estado = :estado ";
        $params[':estado'] = $filtroEstado;
    }

    $sqlExport = "
      SELECT 
        p.id, p.nombre, p.apellido, p.documento, p.email, p.telefono, p.fecha_registro, p.estado, p.qr_generado,
        c.numero_comprobante, c.tipo
      FROM preinscripciones p
      LEFT JOIN comprobantes_pago c ON c.preinscripcion_id = p.id
      $where
      ORDER BY p.fecha_registro DESC
    ";
    $st = $pdo->prepare($sqlExport);
    $st->execute($params);
    $data = $st->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=preinscripciones_' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Nombre','Apellido','Documento','Email','Telefono','Fecha registro','Estado','QR generado','Nro comprobante','Tipo pago'], ';');
    foreach ($data as $r) {
        fputcsv($out, [
            $r['id'], $r['nombre'], $r['apellido'], $r['documento'], $r['email'], $r['telefono'],
            $r['fecha_registro'], $r['estado'], $r['qr_generado'], $r['numero_comprobante'], $r['tipo']
        ], ';');
    }
    fclose($out);
    exit;
}

/* =========================
   GET: filtro estado
   ========================= */
$filtroEstado = trim((string)($_GET['estado'] ?? ''));
$where = '';
$params = [];
if ($filtroEstado !== '' && in_array($filtroEstado, $estadosValidos, true)) {
    $where = " WHERE p.estado = :estado ";
    $params[':estado'] = $filtroEstado;
}

/* =========================
   Query principal
   ========================= */
$rows = [];
try {
    $stmt = $pdo->prepare("
      SELECT
        p.id, p.nombre, p.apellido, p.documento, p.email, p.telefono, p.fecha_registro, p.estado, p.qr_generado,
        c.numero_comprobante, c.tipo
      FROM preinscripciones p
      LEFT JOIN comprobantes_pago c ON c.preinscripcion_id = p.id
      $where
      ORDER BY p.fecha_registro DESC
      LIMIT 2000
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}

/* =========================
   Conteos tabs
   ========================= */
$counts = ['todos' => 0, 'pendiente' => 0, 'pagado' => 0];
try {
    $c = $pdo->query("
      SELECT estado, COUNT(*) total
      FROM preinscripciones
      GROUP BY estado
    ")->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    foreach ($c as $x) {
        $estado = $x['estado'];
        $n = (int)$x['total'];
        $total += $n;
        if (isset($counts[$estado])) $counts[$estado] = $n;
    }
    $counts['todos'] = $total;
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | Papapykuaa</title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
      <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
      <li class="nav-item d-none d-sm-inline-block"><span class="nav-link">Panel de preinscripciones</span></li>
    </ul>
    <ul class="navbar-nav ml-auto">
      <li class="nav-item mr-2 mt-1">
        <span class="text-muted">👤 <?= htmlspecialchars($_SESSION['admin_username'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
      </li>
      <li class="nav-item"><a href="logout.php" class="btn btn-danger btn-sm">Cerrar sesión</a></li>
    </ul>
  </nav>

  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="#" class="brand-link text-center">
      <img src="../img/logo_intro.png" alt="Logo" class="brand-image img-circle elevation-3" style="opacity:.9">
      <span class="brand-text font-weight-light">Papapykuaa</span>
    </a>
    <div class="sidebar">
      <nav class="mt-3">
        <ul class="nav nav-pills nav-sidebar flex-column">
          <li class="nav-item">
            <a href="dashboard.php" class="nav-link active">
              <i class="nav-icon fas fa-users"></i><p>Inscripciones</p>
            </a>
          </li>
        </ul>
      </nav>
    </div>
  </aside>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <h1>Lista de Inscriptos</h1>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">

        <?php if ($errorMsg): ?><div class="alert alert-danger"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($okMsg): ?><div class="alert alert-success"><?= htmlspecialchars($okMsg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="card">
          <div class="card-header p-2">
            <ul class="nav nav-pills">
              <?php
                $tabs = [
                  '' => ['Todos', $counts['todos']],
                  'pendiente' => ['Pendiente', $counts['pendiente']],
                  'pagado' => ['Pagado', $counts['pagado']],
                ];
                foreach ($tabs as $k => $info):
                  $active = ($filtroEstado === $k) ? 'active' : '';
                  $url = 'dashboard.php' . ($k !== '' ? '?estado=' . urlencode($k) : '');
              ?>
                <li class="nav-item">
                  <a class="nav-link <?= $active ?>" href="<?= $url ?>">
                    <?= $info[0] ?> <span class="badge badge-light ml-1"><?= (int)$info[1] ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <div class="card-body">
            <a href="dashboard.php?export=1<?= $filtroEstado !== '' ? '&estado=' . urlencode($filtroEstado) : '' ?>"
               class="btn btn-success mb-3">
              <i class="fas fa-file-excel"></i> Exportar a Excel (CSV)
            </a>

            <button class="btn btn-secondary mb-3 ml-2" disabled title="Función aún no habilitada">
              <i class="fas fa-paper-plane"></i> Enviar QR
            </button>

            <div class="table-responsive">
              <table id="tablaInscriptos" class="table table-bordered table-striped">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Apellido</th>
                    <th>Documento</th>
                    <th>Email</th>
                    <th>Teléfono</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Comprobante</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <?php
                      $estado = $r['estado'];
                      $badge = $estado === 'pagado' ? 'badge-success' : 'badge-warning';
                      $qrGenerado = (int)$r['qr_generado'] === 1;
                    ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($r['apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($r['documento'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($r['telefono'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($r['fecha_registro'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') ?></span></td>
                      <td>
                        <?php if ($qrGenerado): ?>
                          <span class="badge badge-info">Generado</span>
                        <?php else: ?>
                          <span class="badge badge-secondary">Pendiente</span>
                        <?php endif; ?>
                      </td>
                      <td style="white-space:nowrap;">
                        <!-- Botón Agregar comprobante: SOLO pendiente -->
                        <button
                          type="button"
                          class="btn btn-primary btn-sm btn-comprobante"
                          data-id="<?= (int)$r['id'] ?>"
                          data-nombre="<?= htmlspecialchars($r['nombre'].' '.$r['apellido'], ENT_QUOTES, 'UTF-8') ?>"
                          <?= $estado === 'pendiente' ? '' : 'disabled' ?>
                        >
                          <i class="fas fa-receipt"></i> Agregar comprobante
                        </button>

                        <!-- Botón Generar QR: SOLO pagado y no generado -->
                        <form method="post" class="d-inline">
                          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                          <input type="hidden" name="accion" value="generar_qr">
                          <input type="hidden" name="preinscripcion_id" value="<?= (int)$r['id'] ?>">
                          <button
                            type="submit"
                            class="btn btn-info btn-sm"
                            <?= ($estado === 'pagado' && !$qrGenerado) ? '' : 'disabled' ?>
                          >
                            <i class="fas fa-qrcode"></i> Generar QR
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </section>
  </div>

  <footer class="main-footer">
    <strong>&copy; <?= date('Y') ?> Papapykuaa</strong> - Panel administrativo.
  </footer>
</div>

<!-- Modal comprobante -->
<div class="modal fade" id="modalComprobante" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Agregar comprobante</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="accion" value="agregar_comprobante">
        <input type="hidden" name="preinscripcion_id" id="mc_preinscripcion_id">

        <p class="mb-2"><strong>Inscripto:</strong> <span id="mc_nombre"></span></p>

        <div class="form-group">
          <label>Número de comprobante</label>
          <input type="text" name="numero_comprobante" class="form-control" required maxlength="100">
        </div>

        <div class="form-group">
          <label>Tipo de pago</label>
          <select name="tipo" class="form-control" required>
            <option value="">Seleccione...</option>
            <option value="participante">Participante</option>
            <option value="disertante">Disertante</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar comprobante</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>
<script>
$(function () {
  $("#tablaInscriptos").DataTable({
    responsive: true,
    autoWidth: false,
    pageLength: 25,
    order: [[0, 'desc']],
    language: { url: "https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json" }
  });

  $('.btn-comprobante').on('click', function() {
    const id = $(this).data('id');
    const nombre = $(this).data('nombre');
    $('#mc_preinscripcion_id').val(id);
    $('#mc_nombre').text(nombre);
    $('#modalComprobante').modal('show');
  });
});
</script>
</body>
</html>