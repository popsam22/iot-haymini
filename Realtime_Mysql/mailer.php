<?php
  require __DIR__ . '/../vendor/autoload.php';
  use PHPMailer\PHPMailer\PHPMailer;
  use PHPMailer\PHPMailer\Exception;
  use PHPMailer\PHPMailer\SMTP;
  use Dotenv\Dotenv;
  
  $dotenv = Dotenv::createImmutable(__DIR__. '/../');
  $dotenv->load();

  function sendEmail($to, $message, $subject){
    $mail = new PHPMailer(true);
    try {
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;                     
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
    $mail->isHTML(false);                                 
    $mail->Subject = $subject;
    $mail->Body    = $message;

    $mail->send();
    echo 'Message has been sent';
  } catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
  }
}
?>