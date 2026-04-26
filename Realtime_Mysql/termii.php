<?php
require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

function sendSms($message, $phone){
  $logPrefix = "[SMS][" . date('Y-m-d H:i:s') . "]";

  error_log("$logPrefix Attempting — To: $phone | Message: $message");

  if (empty($phone)) {
      error_log("$logPrefix ABORTED — phone number is empty");
      return false;
  }

  $baseUrl   = $_ENV['BASE_URL']          ?? null;
  $apiKey    = $_ENV['TERMII_API_KEY']    ?? null;
  $senderId  = $_ENV['TERMII_SENDER_ID']  ?? null;

  if (!$baseUrl || !$apiKey || !$senderId) {
      error_log("$logPrefix ABORTED — missing Termii config: BASE_URL=" . ($baseUrl ?: 'MISSING') . " API_KEY=" . ($apiKey ? 'SET' : 'MISSING') . " SENDER_ID=" . ($senderId ?: 'MISSING'));
      return false;
  }

  error_log("$logPrefix Termii config — BASE_URL: $baseUrl | SENDER_ID: $senderId");

  $data = [
    "to"      => $phone,
    "from"    => $senderId,
    "type"    => "plain",
    "channel" => "generic",
    "api_key" => $apiKey,
    "sms"     => $message
  ];

  $curl = curl_init();
  curl_setopt_array($curl, [
    CURLOPT_URL            => $baseUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => "",
    CURLOPT_MAXREDIRS      => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST  => "POST",
    CURLOPT_POSTFIELDS     => json_encode($data),
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
  ]);

  $response = curl_exec($curl);
  $curlError = curl_error($curl);
  $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  curl_close($curl);

  if ($curlError) {
      error_log("$logPrefix FAILED — cURL error: $curlError");
      return false;
  }

  error_log("$logPrefix HTTP $httpCode — Response: $response");

  $decoded = json_decode($response, true);
  $status  = $decoded['message'] ?? $decoded['code'] ?? 'unknown';

  if ($httpCode >= 200 && $httpCode < 300) {
      error_log("$logPrefix SUCCESS — SMS dispatched to: $phone | Termii status: $status");
      return true;
  }

  error_log("$logPrefix FAILED — HTTP $httpCode | Termii status: $status");
  return false;
}
?>
