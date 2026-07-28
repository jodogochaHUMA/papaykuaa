<?php
// ============================================================
//  PROCESADOR DEL FORMULARIO DE PRE-INSCRIPCIÓN
//  Medidas de seguridad:
//   - Solo acepta POST
//   - Validación y sanitización de todos los campos
//   - CSRF token
//   - Prepared statements (anti SQL Injection)
//   - Rate limiting por sesión
//   - Headers de seguridad HTTP
//   - Envío SMTP autenticado con PHPMailer (anti-spam)
// ============================================================

// -- Headers de seguridad --
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Type: application/json; charset=utf-8');

// -- Solo POST --
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit;
}

session_start();
require_once 'db_config.php';

// ---- Rate limiting: máximo 3 envíos por sesión cada 10 minutos ----
$ahora = time();
if (!isset($_SESSION['rl_count'])) {
    $_SESSION['rl_count'] = 0;
    $_SESSION['rl_inicio'] = $ahora;
}
if ($ahora - $_SESSION['rl_inicio'] > 600) {
    $_SESSION['rl_count'] = 0;
    $_SESSION['rl_inicio'] = $ahora;
}
if ($_SESSION['rl_count'] >= 3) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'msg' => 'Demasiados intentos. Esperá unos minutos e intentá de nuevo.']);
    exit;
}

// ---- Verificar CSRF token ----
$csrf_recibido = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_recibido)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido. Recargá la página e intentá de nuevo.']);
    exit;
}

// ---- Sanitización y validación ----
$nombre    = trim(htmlspecialchars($_POST['nombre']    ?? '', ENT_QUOTES, 'UTF-8'));
$apellido  = trim(htmlspecialchars($_POST['apellido']  ?? '', ENT_QUOTES, 'UTF-8'));
$documento = trim(htmlspecialchars($_POST['documento'] ?? '', ENT_QUOTES, 'UTF-8'));
$email     = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
$telefono  = trim(htmlspecialchars($_POST['telefono']  ?? '', ENT_QUOTES, 'UTF-8'));

$errores = [];
if (mb_strlen($nombre)   < 2 || mb_strlen($nombre)   > 100) $errores[] = 'Nombre inválido.';
if (mb_strlen($apellido) < 2 || mb_strlen($apellido) > 100) $errores[] = 'Apellido inválido.';
if (mb_strlen($documento) < 4 || mb_strlen($documento) > 20) $errores[] = 'Número de documento inválido.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))               $errores[] = 'Correo electrónico inválido.';
if (!preg_match('/^[\d\s\+\-\(\)]{6,20}$/', $telefono))      $errores[] = 'Número de teléfono inválido.';

if (!empty($errores)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'msg' => implode(' ', $errores)]);
    exit;
}

// ---- Generar token único ----
$token = bin2hex(random_bytes(32)); // 64 caracteres hex

// ---- Guardar en base de datos ----
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Verificar si ya existe ese documento o email
    $check = $pdo->prepare('SELECT id FROM preinscripciones WHERE email = ? OR documento = ? LIMIT 1');
    $check->execute([$email, $documento]);
    if ($check->fetch()) {
        echo json_encode(['ok' => false, 'msg' => 'Ya existe una pre-inscripción con ese correo o número de documento.']);
        exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';

    $stmt = $pdo->prepare(
        'INSERT INTO preinscripciones (token, nombre, apellido, documento, email, telefono, ip_origen)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$token, $nombre, $apellido, $documento, $email, $telefono, $ip]);

} catch (PDOException $e) {
    error_log('[Preinscripcion DB] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Error interno. Por favor intentá más tarde.']);
    exit;
}

// ---- Enviar correo de confirmación ----
$correoEnviado = enviarCorreoConfirmacion($nombre, $apellido, $documento, $email, $telefono, $token);

$_SESSION['rl_count']++;

echo json_encode([
    'ok'     => true,
    'msg'    => '¡Pre-inscripción registrada correctamente! Revisá tu correo para la confirmación.',
    'correo' => $correoEnviado,
]);
exit;


// ============================================================
//  FUNCIÓN: Construir y enviar correo de confirmación
// ============================================================
function enviarCorreoConfirmacion($nombre, $apellido, $documento, $email, $telefono, $token) {

    $tokenCorto = strtoupper(substr($token, 0, 10));

    $cuerpoHtml = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Confirmación de Pre-inscripción</title></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);">

        <!-- Cabecera -->
        <tr>
          <td style="background:#060c22;padding:30px 40px;text-align:center;">
            <h1 style="color:#fff;margin:0;font-size:1.5rem;">Congreso Nacional de Educaci&#243;n Matem&#225;tica</h1>
            <p style="color:#f82249;margin:8px 0 0;font-size:1.1rem;font-weight:bold;">Papapykuaa</p>
          </td>
        </tr>

        <!-- Contenido -->
        <tr>
          <td style="padding:35px 40px;">
            <h2 style="color:#191919;font-size:1.3rem;margin-top:0;">&#161;Hola, ' . htmlspecialchars($nombre) . '!</h2>
            <p style="color:#444;line-height:1.7;">
              Tu <strong>pre-inscripci&#243;n</strong> como participante en el
              <strong>Congreso Nacional de Educaci&#243;n Matem&#225;tica &#8220;Papapykuaa&#8221;</strong>
              ha sido registrada exitosamente.
            </p>

            <!-- Datos registrados -->
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="background:#f9f9f9;border-radius:6px;padding:20px;margin:20px 0;border-left:4px solid #f82249;">
              <tr>
                <td style="padding:6px 0;">
                  <strong style="color:#333;">Resumen de tus datos registrados:</strong>
                </td>
              </tr>
              <tr>
                <td style="padding:4px 0;color:#555;">
                  <span style="color:#f82249;">&#9658;</span>
                  <strong>Nombre completo:</strong> ' . htmlspecialchars($nombre . ' ' . $apellido) . '
                </td>
              </tr>
              <tr>
                <td style="padding:4px 0;color:#555;">
                  <span style="color:#f82249;">&#9658;</span>
                  <strong>Documento:</strong> ' . htmlspecialchars($documento) . '
                </td>
              </tr>
              <tr>
                <td style="padding:4px 0;color:#555;">
                  <span style="color:#f82249;">&#9658;</span>
                  <strong>Correo electr&#243;nico:</strong> ' . htmlspecialchars($email) . '
                </td>
              </tr>
              <tr>
                <td style="padding:4px 0;color:#555;">
                  <span style="color:#f82249;">&#9658;</span>
                  <strong>Tel&#233;fono / WhatsApp:</strong> ' . htmlspecialchars($telefono) . '
                </td>
              </tr>
              <tr>
                <td style="padding:4px 0;color:#555;">
                  <span style="color:#f82249;">&#9658;</span>
                  <strong>C&#243;digo de pre-inscripci&#243;n:</strong>
                  <span style="font-family:monospace;background:#eee;padding:2px 8px;
                               border-radius:4px;font-weight:bold;">' . $tokenCorto . '</span>
                </td>
              </tr>
            </table>

            <!-- Próximos pasos -->
            <div style="background:#fff8e1;border-left:4px solid #ffc107;border-radius:6px;
                        padding:18px 20px;margin:20px 0;">
              <p style="margin:0;color:#555;line-height:1.7;">
                <strong style="color:#333;">Pr&#243;ximos pasos:</strong><br><br>
                En los <strong>pr&#243;ximos d&#237;as</strong> estaremos envi&#225;ndote la informaci&#243;n de la
                <strong>cuenta bancaria y los datos necesarios para realizar el pago del arancel</strong>
                correspondiente a tu categor&#237;a de inscripci&#243;n.<br><br>
                Te pedimos que est&#233;s atento/a a tu correo electr&#243;nico.
                Si ten&#233;s alguna consulta, pod&#233;s responder este correo.
              </p>
            </div>

            <p style="color:#444;line-height:1.7;">
              &#161;Te esperamos del <strong>15 al 17 de Octubre de 2026</strong> en la
              Universidad Nacional de Itap&#250;a, Encarnaci&#243;n, Paraguay!
            </p>
          </td>
        </tr>

        <!-- Footer del correo -->
        <tr>
          <td style="background:#f0f0f0;padding:20px 40px;text-align:center;border-top:1px solid #ddd;">
            <p style="margin:0;color:#888;font-size:0.85rem;">
              Congreso Nacional de Educaci&#243;n Matem&#225;tica &#8220;Papapykuaa&#8221;<br>
              Universidad Nacional de Itap&#250;a &#8211; Encarnaci&#243;n, Paraguay<br>
              <a href="https://repem.net" style="color:#f82249;text-decoration:none;">repem.net</a>
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>';

    $cuerpoTexto = "Hola $nombre,\n\n"
        . "Tu pre-inscripcion al Congreso Papapykuaa fue registrada exitosamente.\n\n"
        . "Datos registrados:\n"
        . "- Nombre completo : $nombre $apellido\n"
        . "- Documento       : $documento\n"
        . "- Correo          : $email\n"
        . "- Telefono        : $telefono\n"
        . "- Codigo          : $tokenCorto\n\n"
        . "En los proximos dias recibiras la informacion para el pago del arancel.\n\n"
        . "Saludos,\nCongreso Papapykuaa\nhttps://repem.net";

    return enviarSmtp($email, $nombre . ' ' . $apellido, $cuerpoHtml, $cuerpoTexto);
}


// ============================================================
//  FUNCIÓN: Envío SMTP autenticado con PHPMailer
// ============================================================
function enviarSmtp($paraEmail, $paraNombre, $cuerpoHtml, $cuerpoTexto) {

    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // ---- Configuración del servidor SMTP ----
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;           // smtp.hostinger.com
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;            // congreso@repem.net
        $mail->Password   = SMTP_PASS;            // contraseña del correo
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';

        // ---- Remitente y destinatario ----
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($paraEmail, $paraNombre);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);

        // ---- Contenido ----
        $mail->isHTML(true);
        $mail->Subject = 'Confirmación de Pre-inscripción – Congreso Papapykuaa';
        $mail->Body    = $cuerpoHtml;
        $mail->AltBody = $cuerpoTexto;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('[PHPMailer Error] ' . $mail->ErrorInfo);
        return false;
    }
}