<?php
 require __DIR__ . '/../vendor/autoload.php';
  use PHPMailer\PHPMailer\PHPMailer;
  use PHPMailer\PHPMailer\Exception;
  use PHPMailer\PHPMailer\SMTP;  
  use Dotenv\Dotenv;

  $dotenv = Dotenv::createImmutable(__DIR__. '/../');
  $dotenv->load();

  function sendEmail($to, $message, $subject){
    date_default_timezone_set('Africa/Lagos');

    $mail = new PHPMailer(true);
    try {
    $mail->SMTPDebug = SMTP::DEBUG_OFF;                      
    $mail->isSMTP();                                                 
    $mail->Host       = $_ENV['MAILER_HOST'];             
    $mail->SMTPAuth   = true;                                                   
    $mail->Username   = $_ENV['USERNAME'];                         
    $mail->Password   = $_ENV['PASSWORD'];                              
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;                
    $mail->Port       = $_ENV['MAILER_PORT'];            

    //Recipient
    $mail->setFrom($_ENV['USERNAME'], $_ENV['TERMII_SENDER_ID']);
    $mail->addAddress($to);

    //Content
    $mail->isHTML(true);                                 
    $mail->Subject = $subject;
    $mail->Body    = $message;

    $mail->send();
    return true;
  } catch (Exception $e) {
    error_log("Mailer Error: {$mail->ErrorInfo}");
    return false;
  }
}
?>