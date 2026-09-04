<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!empty($_SESSION['admin_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$maxAttempts = 5;
$lockMinutes = 15;

if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['lock_until'])) $_SESSION['lock_until'] = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $error = 'Solicitud inválida.';
    } elseif (time() < (int)$_SESSION['lock_until']) {
        $error = 'Cuenta temporalmente bloqueada. Intentá más tarde.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare("SELECT id, username, password_hash, is_active FROM admin_users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        $ok = $user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash']);

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin_user_id'] = (int)$user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['login_attempts'] = 0;
            $_SESSION['lock_until'] = 0;
            header('Location: dashboard.php');
            exit;
        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= $maxAttempts) {
                $_SESSION['lock_until'] = time() + ($lockMinutes * 60);
                $_SESSION['login_attempts'] = 0;
            }
            $error = 'Usuario o contraseña incorrectos.';
            usleep(350000);
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login Admin | Papapykuaa</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <img src="../img/logo_intro.png" alt="Papapykuaa" class="logo">
      <h1>Acceso Administrador</h1>
      <p>Ingresá tus credenciales para ver inscriptos.</p>

      <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <label>Usuario</label>
        <input type="text" name="username" required maxlength="60">
        <label>Contraseña</label>
        <input type="password" name="password" required>
        <button type="submit">Ingresar</button>
      </form>
    </div>
  </div>
</body>
</html>