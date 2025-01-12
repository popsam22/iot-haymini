<?php
  use PHPMailer\PHPMailer\PHPMailer;
  use PHPMailer\PHPMailer\Exception;
  use PHPMailer\PHPMailer\SMTP;

  require  '../vendor/autoload.php';

  function sendEmail($to, $message, $subject){
    $mail = new PHPMailer(true);
    try {
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;                     
    $mail->isSMTP();                                     
    $mail->Host       = 'smtp.gmail.com';             
    $mail->SMTPAuth   = true;                                   
    $mail->Username   = 'sobiechie7@gmail.com';                     
    $mail->Password   = 'ncuj cjgj lwam apcz';                              
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;          
    $mail->Port       = 465;            

    //Recipient
    $mail->setFrom('sobiechie7@gmail.com', 'Mailer');
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