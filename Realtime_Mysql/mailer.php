<?php
require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
date_default_timezone_set('Africa/Lagos');

/**
 * Send an email via the Resend API.
 *
 * @param  string $to      Recipient email address
 * @param  string $message HTML body
 * @param  string $subject Email subject line
 * @return bool
 */
function sendEmail(string $to, string $message, string $subject): bool
{
    $logPrefix = '[EMAIL][' . date('Y-m-d H:i:s') . ']';

    error_log("$logPrefix Attempting — To: $to | Subject: $subject");

    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("$logPrefix ABORTED — invalid or empty recipient: '$to'");
        return false;
    }

    if (empty($subject)) {
        error_log("$logPrefix ABORTED — subject is empty");
        return false;
    }

    $apiKey  = $_ENV['RESEND_API_KEY'] ?? null;
    $from    = $_ENV['RESEND_FROM']    ?? null;

    if (!$apiKey || !$from) {
        error_log("$logPrefix ABORTED — missing config: RESEND_API_KEY=" . ($apiKey ? 'SET' : 'MISSING') . " RESEND_FROM=" . ($from ?: 'MISSING'));
        return false;
    }

    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $message,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);

    $start    = microtime(true);
    $response = curl_exec($ch);
    $ms       = round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("$logPrefix FAILED — cURL error: $curlErr");
        return false;
    }

    $body = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        $id = $body['id'] ?? 'unknown';
        error_log("$logPrefix SUCCESS — delivered to: $to | id: $id | {$ms}ms");
        return true;
    }

    $errMsg = $body['message'] ?? $body['name'] ?? $response;
    error_log("$logPrefix FAILED — HTTP $httpCode | {$ms}ms | $errMsg");
    return false;
}
