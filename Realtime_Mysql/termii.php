<?php
require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
  
$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

function sendSms($message, $phone){
  $curl = curl_init();

  $BASE_URL = $_ENV['BASE_URL'];

  $data = [
    "to" => $phone,
    "from" => $_ENV['TERMII_SENDER_ID'],
    "type" => "plain",
    "channel" => "generic",
    "api_key" => $_ENV['TERMII_API_KEY'],
    "sms" => "Hi there, your child clocked in at $message"
  ];

  $post_data = json_encode($data);

  curl_setopt_array($curl, array(
  CURLOPT_URL => $BASE_URL,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS => $post_data,
  CURLOPT_HTTPHEADER => array(
    "Content-Type: application/json"
  ),
  ));

  $response = curl_exec($curl);
  if (curl_errno($curl)) {
        echo "cURL Error: " . curl_error($curl);
    }

  curl_close($curl);
  var_dump($response);
  echo $response;
}
?>
