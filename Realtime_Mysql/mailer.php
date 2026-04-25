<?php
 require __DIR__ . '/../vendor/autoload.php';
  use PHPMailer\PHPMailer\PHPMailer;
  use PHPMailer\PHPMailer\Exception;
  use PHPMailer\PHPMailer\SMTP;
  use Dotenv\Dotenv;

  $dotenv = Dotenv::createImmutable(__DIR__. '/../');
  $dotenv->load();
  date_default_timezone_set('Africa/Lagos');

  function sendEmail($to, $message, $subject){
    $logPrefix = "[EMAIL][" . date('Y-m-d H:i:s') . "]";

    error_log("$logPrefix Attempting — To: $to | Subject: $subject");

    if (empty($to)) {
        error_log("$logPrefix ABORTED — recipient email is empty");
        return false;
    }

    $host     = $_ENV['MAILER_HOST']      ?? null;
    $username = $_ENV['MAILER_USERNAME']  ?? null;
    $password = $_ENV['MAILER_PASSWORD']  ?? null;
    $port     = $_ENV['MAILER_PORT']      ?? null;
    $sender   = $_ENV['TERMII_SENDER_ID'] ?? null;

    if (!$host || !$username || !$password || !$port) {
        error_log("$logPrefix ABORTED — missing SMTP config: HOST=" . ($host ?: 'MISSING') . " USER=" . ($username ?: 'MISSING') . " PORT=" . ($port ?: 'MISSING') . " PASS=" . ($password ? 'SET' : 'MISSING'));
        return false;
    }

    error_log("$logPrefix SMTP config — HOST: $host | PORT: $port | USER: $username | SENDER_NAME: $sender");

    $mail = new PHPMailer(true);
    try {
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) use ($logPrefix) {
        error_log("$logPrefix [SMTP] " . trim($str));
    };
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $username;
    $mail->Password   = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = $port;

    $mail->setFrom($username, $sender);
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $message;

    $mail->send();
    error_log("$logPrefix SUCCESS — email delivered to: $to");
    return true;
  } catch (Exception $e) {
    error_log("$logPrefix FAILED — PHPMailer: {$mail->ErrorInfo}");
    error_log("$logPrefix FAILED — Exception: " . $e->getMessage());
    return false;
  }
}
?>
