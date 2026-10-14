<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();

$autoloadAdmin = __DIR__ . '/vendor/autoload.php';
$autoloadRoot  = dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists($autoloadAdmin)) {
    require $autoloadAdmin;
} elseif (file_exists($autoloadRoot)) {
    require $autoloadRoot;
} else {
    die('No se encontró vendor/autoload.php. Ejecutá composer install.');
}
require __DIR__ . '/qr_helper.php';

use PHPMailer\PHPMailer\PHPMailer;

$errorMsg = '';
$okMsg = '';
$estadosValidos = ['pendiente', 'pagado'];

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

        if ($id <= 0 || $numero === '' || !in_array($tipo, ['participante', 'disertante'], true)) {
            $errorMsg = 'Datos inválidos para registrar comprobante.';
        } else {
            try {
                $pdo->beginTransaction();

                $q = $pdo->prepare("SELECT estado FROM preinscripciones WHERE id = :id FOR UPDATE");
                $q->execute([':id' => $id]);
                $ins = $q->fetch(PDO::FETCH_ASSOC);

                if (!$ins) {
                    throw new RuntimeException('Inscripción no encontrada.');
                }
                if ($ins['estado'] !== 'pendiente') {
                    throw new RuntimeException('Solo se puede cargar comprobante si el estado está pendiente.');
                }

                $insComp = $pdo->prepare("
                    INSERT INTO comprobantes_pago (preinscripcion_id, numero_comprobante, tipo)
                    VALUES (:pid, :num, :tipo)
                ");
                $insComp->execute([
                    ':pid' => $id,
                    ':num' => $numero,
                    ':tipo' => $tipo
                ]);

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
   POST: Generar QR real
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar_qr') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errorMsg = 'Token CSRF inválido.';
    } else {
        $id = (int)($_POST['preinscripcion_id'] ?? 0);

        try {
            $q = $pdo->prepare("
              SELECT p.id, p.nombre, p.apellido, p.documento, p.email, p.estado, p.qr_generado,
                     c.numero_comprobante
              FROM preinscripciones p
              INNER JOIN comprobantes_pago c ON c.preinscripcion_id = p.id
              WHERE p.id = :id
              LIMIT 1
            ");
            $q->execute([':id' => $id]);
            $u = $q->fetch(PDO::FETCH_ASSOC);

            if (!$u) {
                throw new RuntimeException('No existe inscripción o no tiene comprobante.');
            }
            if ($u['estado'] !== 'pagado') {
                throw new RuntimeException('Solo se puede generar QR en estado pagado.');
            }
            if ((int)$u['qr_generado'] === 1) {
                throw new RuntimeException('El QR ya fue generado.');
            }

            $qrData = "PPK|ID:{$u['id']}|DOC:{$u['documento']}|COMP:{$u['numero_comprobante']}";

            $dir = __DIR__ . '/qrs';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filename = 'qr_' . $u['id'] . '_' . time() . '.png';
            $fullPath = $dir . '/' . $filename;
            $relPath = 'admin/qrs/' . $filename;

            $logoPath = dirname(__DIR__) . '/img/logo_intro.png';
            if (!file_exists($logoPath)) {
                throw new RuntimeException('No se encontró el logo en img/logo_intro.png');
            }

            // función definida en qr_helper.php
            generarQrConLogo($qrData, $fullPath, $logoPath);

            $up = $pdo->prepare("
              UPDATE preinscripciones
              SET qr_generado = 1, qr_data = :data, qr_path = :path
              WHERE id = :id
            ");
            $up->execute([
                ':data' => $qrData,
                ':path' => $relPath,
                ':id' => $u['id']
            ]);

            $okMsg = 'QR generado correctamente.';
        } catch (Throwable $e) {
            $errorMsg = 'Error al generar QR: ' . $e->getMessage();
        }
    }
}

/* =========================
   POST: Enviar QR por correo
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar_qr_individual') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errorMsg = 'Token CSRF inválido.';
    } else {
        $id = (int)($_POST['preinscripcion_id'] ?? 0);

        try {
            $q = $pdo->prepare("
              SELECT id, nombre, apellido, email, estado, qr_generado, qr_path, qr_enviado
              FROM preinscripciones
              WHERE id = :id
              LIMIT 1
            ");
            $q->execute([':id' => $id]);
            $u = $q->fetch(PDO::FETCH_ASSOC);

            if (!$u) throw new RuntimeException('Inscripción no encontrada.');
            if ($u['estado'] !== 'pagado') throw new RuntimeException('Solo se envía QR si está pagado.');
            if ((int)$u['qr_generado'] !== 1) throw new RuntimeException('Primero debés generar el QR.');
            if ((int)$u['qr_enviado'] === 1) throw new RuntimeException('Este QR ya fue enviado.');
            if (empty($u['qr_path'])) throw new RuntimeException('No existe ruta del QR.');

            $qrFile = dirname(__DIR__) . '/' . $u['qr_path'];
            if (!file_exists($qrFile)) throw new RuntimeException('Archivo QR no encontrado en servidor.');

            $mailCfg = require __DIR__ . '/mail_config.php';

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $mailCfg['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $mailCfg['username'];
            $mail->Password = $mailCfg['password'];
            $mail->SMTPSecure = $mailCfg['secure'];
            $mail->Port = (int)$mailCfg['port'];
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
            $mail->addAddress($u['email'], trim($u['nombre'] . ' ' . $u['apellido']));
            $mail->Subject = 'Tu código QR - Congreso Papapykuaa';
            $mail->Body = "Hola {$u['nombre']},\n\nAdjuntamos tu código QR para el ingreso al Congreso Papapykuaa.\n\nSaludos.";
            $mail->addAttachment($qrFile, 'qr_papapykuaa.png');
            $mail->send();

            $up = $pdo->prepare("
              UPDATE preinscripciones
              SET qr_enviado = 1, qr_enviado_at = NOW()
              WHERE id = :id
            ");
            $up->execute([':id' => $u['id']]);

            $okMsg = 'QR enviado por correo correctamente.';
        } catch (Throwable $e) {
            $errorMsg = 'Error al enviar QR: ' . $e->getMessage();
        }
    }
}

/* =========================
   POST: Agregar inscripto (AJAX or form)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar_inscripto') {
    // allow AJAX calls
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $error = 'Token CSRF inválido.';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $error]);
            exit;
        } else {
            $errorMsg = $error;
        }
    } else {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $apellido = trim((string)($_POST['apellido'] ?? ''));
        $documento = trim((string)($_POST['documento'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $estado = in_array(trim((string)($_POST['estado'] ?? 'pendiente')), $estadosValidos, true) ? trim((string)$_POST['estado']) : 'pendiente';

        if ($nombre === '' || $apellido === '' || $documento === '') {
            $err = 'Nombre, apellido y documento son obligatorios.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $err]);
                exit;
            } else {
                $errorMsg = $err;
            }
        } else {
            try {
                $pdo->beginTransaction();

                $token = bin2hex(random_bytes(32));
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;

                $ins = $pdo->prepare("
                    INSERT INTO preinscripciones
                      (token, nombre, apellido, documento, email, telefono, fecha_registro, ip_origen, estado, qr_generado, qr_enviado)
                    VALUES
                      (:token, :nombre, :apellido, :documento, :email, :telefono, NOW(), :ip, :estado, 0, 0)
                ");
                $ins->execute([
                    ':token' => $token,
                    ':nombre' => $nombre,
                    ':apellido' => $apellido,
                    ':documento' => $documento,
                    ':email' => $email,
                    ':telefono' => $telefono,
                    ':ip' => $ip,
                    ':estado' => $estado,
                ]);

                $newId = (int)$pdo->lastInsertId();

                $st = $pdo->prepare("
                  SELECT p.id, p.nombre, p.apellido, p.documento, p.email, p.telefono, p.fecha_registro, p.estado,
                         p.qr_generado, p.qr_path, p.qr_enviado, p.asistio, p.asistio_at
                  FROM preinscripciones p
                  WHERE p.id = :id
                  LIMIT 1
                ");
                $st->execute([':id' => $newId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);

                $pdo->commit();

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'message' => 'Inscripto creado', 'record' => $row]);
                    exit;
                } else {
                    $okMsg = 'Inscripto creado correctamente.';
                    header('Location: dashboard.php');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = 'No se pudo crear inscripto: ' . $e->getMessage();
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'message' => $err]);
                    exit;
                } else {
                    $errorMsg = $err;
                }
            }
        }
    }
}

/* =========================
   POST: Marcar / desmarcar asistencia (AJAX)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'marcar_asistio') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $err = 'Token CSRF inválido.';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $err]);
        exit;
    }

    $id = (int)($_POST['preinscripcion_id'] ?? 0);
    $valor = ($_POST['valor'] ?? '1') === '1' ? 1 : 0;

    if ($id <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'ID inválido']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // obtener qr_data si existe
        $q = $pdo->prepare("SELECT qr_data FROM preinscripciones WHERE id = :id FOR UPDATE");
        $q->execute([':id' => $id]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('Inscripción no encontrada.');

        if ($valor === 1) {
            $up = $pdo->prepare("UPDATE preinscripciones SET asistio = 1, asistio_at = NOW() WHERE id = :id");
            $up->execute([':id' => $id]);

            // insertar en asistencias (usar qr_data si existe, si no usar MANUAL tag)
            $qr_raw = $p['qr_data'] ?? ('MANUAL|ID:' . $id);
            $insA = $pdo->prepare("INSERT INTO asistencias (preinscripcion_id, scanner, qr_raw, scan_at) VALUES (:pid, NULL, :qr, NOW())");
            $insA->execute([':pid' => $id, ':qr' => $qr_raw]);

            $pdo->commit();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'asistio' => 1, 'asistio_at' => date('Y-m-d H:i:s')]);
            exit;
        } else {
            // desmarcar: actualizar y eliminar registros de asistencias asociados
            $up = $pdo->prepare("UPDATE preinscripciones SET asistio = 0, asistio_at = NULL WHERE id = :id");
            $up->execute([':id' => $id]);
            $del = $pdo->prepare("DELETE FROM asistencias WHERE preinscripcion_id = :id");
            $del->execute([':id' => $id]);
            $pdo->commit();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'asistio' => 0, 'asistio_at' => null]);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* =========================
   Exportar CSV (con filtro de asistencia)
   ========================= */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $filtroEstado = trim((string)($_GET['estado'] ?? ''));
    $filtroAsistio = trim((string)($_GET['asistio'] ?? '')); // '1' o '0' o ''
    $whereParts = [];
    $params = [];

    if ($filtroEstado !== '' && in_array($filtroEstado, $estadosValidos, true)) {
        $whereParts[] = "p.estado = :estado";
        $params[':estado'] = $filtroEstado;
    }
    if ($filtroAsistio !== '' && in_array($filtroAsistio, ['0', '1'], true)) {
        $whereParts[] = "p.asistio = :asistio";
        $params[':asistio'] = (int)$filtroAsistio;
    }

    $where = '';
    if (!empty($whereParts)) {
        $where = ' WHERE ' . implode(' AND ', $whereParts);
    }

    $sqlExport = "
      SELECT 
        p.id, p.nombre, p.apellido, p.documento, p.email, p.telefono, p.fecha_registro, p.estado,
        p.qr_generado, p.qr_enviado, c.numero_comprobante, c.tipo,
        p.asistio, p.asistio_at
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
    fputcsv($out, [
        'ID','Nombre','Apellido','Documento','Email','Telefono','Fecha registro','Estado',
        'QR generado','QR enviado','Nro comprobante','Tipo pago','Asistio','Asistio_at'
    ], ';');

    foreach ($data as $r) {
        fputcsv($out, [
            $r['id'], $r['nombre'], $r['apellido'], $r['documento'], $r['email'], $r['telefono'],
            $r['fecha_registro'], $r['estado'], $r['qr_generado'], $r['qr_enviado'],
            $r['numero_comprobante'], $r['tipo'],
            isset($r['asistio']) ? (int)$r['asistio'] : 0,
            $r['asistio_at'] ?? ''
        ], ';');
    }

    fclose($out);
    exit;
}

/* =========================
   GET: filtros estado + asistencia
   ========================= */
$filtroEstado = trim((string)($_GET['estado'] ?? ''));
$filtroAsistio = trim((string)($_GET['asistio'] ?? '')); // '1' o '0' o ''

$whereParts = [];
$params = [];

if ($filtroEstado !== '' && in_array($filtroEstado, $estadosValidos, true)) {
    $whereParts[] = "p.estado = :estado";
    $params[':estado'] = $filtroEstado;
}
if ($filtroAsistio !== '' && in_array($filtroAsistio, ['0', '1'], true)) {
    $whereParts[] = "p.asistio = :asistio";
    $params[':asistio'] = (int)$filtroAsistio;
}

$where = '';
if (!empty($whereParts)) {
    $where = ' WHERE ' . implode(' AND ', $whereParts);
}

/* =========================
   Query principal (incluye asistio)
   ========================= */
$rows = [];
try {
    $stmt = $pdo->prepare("
      SELECT
        p.id, p.nombre, p.apellido, p.documento, p.email, p.telefono, p.fecha_registro, p.estado,
        p.qr_generado, p.qr_path, p.qr_enviado, p.qr_enviado_at,
        c.numero_comprobante, c.tipo,
        p.asistio, p.asistio_at
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
   Conteos tabs (sin filtrar por asistencia)
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
} catch (Throwable $e) {
}
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
  <style>
    .badge-asistio-yes { background:#28a745; color:#fff; padding:4px 8px; border-radius:6px; }
    .badge-asistio-no  { background:#6c757d; color:#fff; padding:4px 8px; border-radius:6px; }
    .filter-controls { display:flex; align-items:center; gap:8px; }
    .qr-thumb { max-width:100%; height:auto; display:block; margin:0 auto; }
  </style>
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
          <div class="card-header p-2" style="display:flex; justify-content:space-between; align-items:center;">
            <ul class="nav nav-pills" style="margin:0;">
              <?php
              $tabs = [
                  '' => ['Todos', $counts['todos']],
                  'pendiente' => ['Pendiente', $counts['pendiente']],
                  'pagado' => ['Pagado', $counts['pagado']],
              ];
              foreach ($tabs as $k => $info):
                  $active = ($filtroEstado === $k) ? 'active' : '';
                  $url = 'dashboard.php' . ($k !== '' ? '?estado=' . urlencode($k) : '');
                  // preserve asistio filter in tab urls
                  if ($filtroAsistio !== '') {
                      $url .= ($k === '' ? '?' : '&') . 'asistio=' . urlencode($filtroAsistio);
                  }
                  ?>
                <li class="nav-item">
                  <a class="nav-link <?= $active ?>" href="<?= $url ?>">
                      <?= $info[0] ?> <span class="badge badge-light ml-1"><?= (int)$info[1] ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>

            <div style="display:flex; gap:8px; align-items:center;">
              <div class="filter-controls">
                <label class="mb-0 mr-1">Filtrar por asistencia:</label>
                <select id="filterAsistio" class="form-control form-control-sm">
                  <option value="">Todos</option>
                  <option value="1" <?= $filtroAsistio === '1' ? 'selected' : '' ?>>Asistieron</option>
                  <option value="0" <?= $filtroAsistio === '0' ? 'selected' : '' ?>>No asistieron</option>
                </select>
              </div>

              <button id="btnNuevoInscripto" class="btn btn-primary btn-sm">
                <i class="fas fa-user-plus"></i> Nuevo inscripto
              </button>
            </div>
          </div>

          <div class="card-body">
            <a href="dashboard.php?export=1<?= $filtroEstado !== '' ? '&estado=' . urlencode($filtroEstado) : '' ?><?= $filtroAsistio !== '' ? '&asistio=' . urlencode($filtroAsistio) : '' ?>"
               class="btn btn-success mb-3">
              <i class="fas fa-file-excel"></i> Exportar a Excel (CSV)
            </a>

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
                  <th>Asistió</th>
                  <th>QR</th>
                  <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $estado = $r['estado'];
                    $badge = $estado === 'pagado' ? 'badge-success' : 'badge-warning';
                    $qrGenerado = (int)$r['qr_generado'] === 1;
                    $qrEnviado = (int)$r['qr_enviado'] === 1;
                    $asistio = isset($r['asistio']) ? (int)$r['asistio'] : 0;
                    $qrUrl = $r['qr_path'] ? ('/' . ltrim($r['qr_path'], '/')) : '';
                    ?>
                  <tr data-id="<?= (int)$r['id'] ?>">
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['documento'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['telefono'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['fecha_registro'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') ?></span></td>

                    <td class="text-center asistio-cell">
                      <?php if ($asistio === 1): ?>
                        <span class="badge-asistio-yes">SI</span>
                      <?php else: ?>
                        <span class="badge-asistio-no">NO</span>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <?= $qrGenerado ? '<span class="badge badge-info">Generado</span>' : '<span class="badge badge-secondary">Pendiente</span>' ?>
                    </td>

                    <td style="white-space:nowrap;">
                      <!-- Agregar comprobante: solo pendiente -->
                      <button
                          type="button"
                          class="btn btn-primary btn-sm btn-comprobante"
                          data-id="<?= (int)$r['id'] ?>"
                          data-nombre="<?= htmlspecialchars($r['nombre'] . ' ' . $r['apellido'], ENT_QUOTES, 'UTF-8') ?>"
                          <?= $estado === 'pendiente' ? '' : 'disabled' ?>
                      >
                        <i class="fas fa-receipt"></i> Comprobante
                      </button>

                      <!-- Generar QR: solo pagado y no generado -->
                      <form method="post" class="d-inline" style="display:inline-block;margin:0 3px;">
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

                      <!-- Enviar QR individual: solo si qr generado y no enviado -->
                      <form method="post" class="d-inline" style="display:inline-block;margin:0 3px;">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="accion" value="enviar_qr_individual">
                        <input type="hidden" name="preinscripcion_id" value="<?= (int)$r['id'] ?>">
                        <button
                            type="submit"
                            class="btn btn-success btn-sm"
                            <?= ($qrGenerado && !$qrEnviado) ? '' : 'disabled' ?>
                        >
                          <i class="fas fa-paper-plane"></i> Enviar QR
                        </button>
                      </form>

                      <!-- Ver QR (si existe) -->
                      <button
                        type="button"
                        class="btn btn-secondary btn-sm btn-ver-qr"
                        data-qr="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>"
                        data-nombre="<?= htmlspecialchars($r['nombre'] . ' ' . $r['apellido'], ENT_QUOTES, 'UTF-8') ?>"
                        <?= $qrGenerado ? '' : 'disabled' ?>
                      >
                        <i class="fas fa-eye"></i> Ver QR
                      </button>

                      <!-- Marcar asistencia (AJAX) -->
                      <?php if ($asistio === 0): ?>
                        <button type="button" class="btn btn-outline-success btn-sm btn-marcar-asistio" data-id="<?= (int)$r['id'] ?>" data-valor="1" style="margin-left:6px;">
                          <i class="fas fa-check"></i> Marcar asistencia
                        </button>
                      <?php else: ?>
                        <button type="button" class="btn btn-outline-danger btn-sm btn-marcar-asistio" data-id="<?= (int)$r['id'] ?>" data-valor="0" style="margin-left:6px;">
                          <i class="fas fa-times"></i> Quitar asistencia
                        </button>
                      <?php endif; ?>
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

<!-- Modal Ver QR -->
<div class="modal fade" id="modalVerQr" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Código QR - <span id="qr_nombre"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body text-center">
        <img id="qr_img" class="qr-thumb" src="" alt="QR">
        <p class="mt-2"><a id="qr_download" href="#" class="btn btn-sm btn-outline-primary" download>Descargar</a></p>
      </div>
    </div>
  </div>
</div>

<!-- Modal Agregar Inscripto -->
<div class="modal fade" id="modalAgregarInscripto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="formAgregarInscripto" method="post" class="modal-content" action="dashboard.php">
      <div class="modal-header">
        <h5 class="modal-title">Agregar nuevo inscripto</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="accion" value="agregar_inscripto">

        <div class="form-group">
          <label>Nombre *</label>
          <input name="nombre" class="form-control" required maxlength="100">
        </div>
        <div class="form-group">
          <label>Apellido *</label>
          <input name="apellido" class="form-control" required maxlength="100">
        </div>
        <div class="form-group">
          <label>Documento *</label>
          <input name="documento" class="form-control" required maxlength="40">
        </div>
        <div class="form-group">
          <label>Email</label>
          <input name="email" class="form-control" type="email" maxlength="150">
        </div>
        <div class="form-group">
          <label>Teléfono</label>
          <input name="telefono" class="form-control" maxlength="30">
        </div>
        <div class="form-group">
          <label>Estado</label>
          <select name="estado" class="form-control">
            <option value="pendiente">Pendiente</option>
            <option value="pagado">Pagado</option>
          </select>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Crear inscripto</button>
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
    // inicializar DataTable y guardar instancia
    var table = $("#tablaInscriptos").DataTable({
      responsive: true,
      autoWidth: false,
      pageLength: 25,
      order: [[0, 'desc']],
      language: { url: "https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json" }
    });

    // delegado: abrir modal comprobante
    $(document).on('click', '.btn-comprobante', function() {
      const id = $(this).data('id');
      const nombre = $(this).data('nombre') || '';
      $('#mc_preinscripcion_id').val(id);
      $('#mc_nombre').text(nombre);
      $('#modalComprobante').modal('show');
    });

    // delegado: ver QR
    $(document).on('click', '.btn-ver-qr', function() {
      const qr = $(this).data('qr') || '';
      const nombre = $(this).data('nombre') || '';
      if (!qr) return;
      $('#qr_nombre').text(nombre);
      $('#qr_img').attr('src', qr + '?t=' + Date.now());
      $('#qr_download').attr('href', qr);
      $('#modalVerQr').modal('show');
    });

    // delegado: marcar / desmarcar asistencia (AJAX)
    $(document).on('click', '.btn-marcar-asistio', function() {
      const $btn = $(this);
      const id = $btn.data('id');
      const valor = $btn.data('valor') === 1 || $btn.data('valor') === '1' ? '1' : '0';
      $btn.prop('disabled', true).text((valor === '1') ? 'Marcando...' : 'Quitando...');
      $.ajax({
        url: 'dashboard.php',
        method: 'POST',
        dataType: 'json',
        data: {
          accion: 'marcar_asistio',
          preinscripcion_id: id,
          valor: valor,
          csrf: '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>'
        },
        success: function(resp) {
          if (resp.ok) {
            // actualizar fila visualmente
            var $tr = $('tr[data-id="'+id+'"]');
            var $cell = $tr.find('.asistio-cell');
            if (resp.asistio == 1) {
              $cell.html('<span class="badge-asistio-yes">SI</span>');
              // cambiar botón a "Quitar asistencia"
              $tr.find('.btn-marcar-asistio').data('valor', '0').removeClass('btn-outline-success').addClass('btn-outline-danger').html('<i class="fas fa-times"></i> Quitar asistencia');
            } else {
              $cell.html('<span class="badge-asistio-no">NO</span>');
              $tr.find('.btn-marcar-asistio').data('valor', '1').removeClass('btn-outline-danger').addClass('btn-outline-success').html('<i class="fas fa-check"></i> Marcar asistencia');
            }
          } else {
            alert('Error: ' + (resp.message || 'No se pudo actualizar.'));
          }
          $btn.prop('disabled', false).text((valor === '1') ? 'Marcar asistencia' : 'Quitar asistencia');
        },
        error: function() {
          alert('Error de conexión al marcar asistencia.');
          $btn.prop('disabled', false).text((valor === '1') ? 'Marcar asistencia' : 'Quitar asistencia');
        }
      });
    });

    // filtro asistencia: cambiar querystring y recargar
    $('#filterAsistio').on('change', function() {
      const val = $(this).val();
      const params = new URLSearchParams(window.location.search);
      if (val === '') {
        params.delete('asistio');
      } else {
        params.set('asistio', val);
      }
      // mantener estado si existe
      window.location = window.location.pathname + (params.toString() ? ('?' + params.toString()) : '');
    });

    // abrir modal nuevo inscripto
    $('#btnNuevoInscripto').on('click', function() {
      $('#formAgregarInscripto')[0].reset();
      $('#modalAgregarInscripto').modal('show');
    });

    // envío AJAX del formulario de creación (agregar inscripto)
    $('#formAgregarInscripto').on('submit', function(e) {
      e.preventDefault();
      var $form = $(this);
      var $btn = $form.find('button[type="submit"]');
      $btn.prop('disabled', true).text('Creando...');

      $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        data: $form.serialize(),
        dataType: 'json',
        success: function(resp) {
          if (resp.ok) {
            var r = resp.record;
            // preparar badges y acciones (simplificado)
            var estadoBadge = '<span class="badge ' + (r.estado === 'pagado' ? 'badge-success' : 'badge-warning') + '">' + (r.estado || '') + '</span>';
            var qrBadge = (r.qr_generado == 1) ? '<span class="badge badge-info">Generado</span>' : '<span class="badge badge-secondary">Pendiente</span>';

            var acciones = '';
            acciones += '<button type="button" class="btn btn-primary btn-sm btn-comprobante" data-id="'+ r.id +'" data-nombre="'+ ( (r.nombre||'') + ' ' + (r.apellido||'') ).replace(/"/g,'&quot;') +'"><i class="fas fa-receipt"></i> Comprobante</button> ';
            acciones += '<form method="post" class="d-inline" style="display:inline-block;margin:0 3px;">' +
                       '<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">' +
                       '<input type="hidden" name="accion" value="generar_qr">' +
                       '<input type="hidden" name="preinscripcion_id" value="'+ r.id +'">' +
                       '<button type="submit" class="btn btn-info btn-sm" '+ (r.estado === 'pagado' ? '' : 'disabled') +'><i class="fas fa-qrcode"></i> Generar QR</button>' +
                       '</form>';

            acciones += '<button type="button" class="btn btn-secondary btn-sm btn-ver-qr" data-qr="" data-nombre="'+ ( (r.nombre||'') + ' ' + (r.apellido||'') ).replace(/"/g,'&quot;') +'" disabled><i class="fas fa-eye"></i> Ver QR</button>';

            acciones += '<button type="button" class="btn btn-outline-success btn-sm btn-marcar-asistio" data-id="'+ r.id +'" data-valor="1" style="margin-left:6px;"><i class="fas fa-check"></i> Marcar asistencia</button>';

            var fecha = r.fecha_registro || '';

            var newRow = table.row.add([
              r.id,
              htmlspecialchars_js(r.nombre || ''),
              htmlspecialchars_js(r.apellido || ''),
              htmlspecialchars_js(r.documento || ''),
              htmlspecialchars_js(r.email || ''),
              htmlspecialchars_js(r.telefono || ''),
              fecha,
              estadoBadge,
              (r.asistio == 1 ? '<span class="badge-asistio-yes">SI</span>' : '<span class="badge-asistio-no">NO</span>'),
              qrBadge,
              acciones
            ]).draw(false);

            $('#modalAgregarInscripto').modal('hide');
            $btn.prop('disabled', false).text('Crear inscripto');
          } else {
            alert(resp.message || 'Error al crear inscripto');
            $btn.prop('disabled', false).text('Crear inscripto');
          }
        },
        error: function(xhr, status, err) {
          alert('Error de conexión: ' + err);
          $btn.prop('disabled', false).text('Crear inscripto');
        }
      });
    });

    // helper JS para escapar texto
    function htmlspecialchars_js(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    }
  });
</script>
</body>
</html>