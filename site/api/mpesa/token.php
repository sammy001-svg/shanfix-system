<?php
/**
 * M-PESA Daraja — Access Token Helper
 * Include this file to use getMpesaToken() and getMpesaBaseUrl().
 */

require_once '../../includes/env_loader.php';
loadEnv(__DIR__ . '/../../.env');

function getMpesaBaseUrl(): string {
    return ($_ENV['MPESA_ENV'] ?? 'sandbox') === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function getMpesaToken(): ?string {
    $key    = $_ENV['MPESA_CONSUMER_KEY']    ?? '';
    $secret = $_ENV['MPESA_CONSUMER_SECRET'] ?? '';

    if (empty($key) || empty($secret)) {
        error_log('M-PESA: MPESA_CONSUMER_KEY or MPESA_CONSUMER_SECRET not set in .env');
        return null;
    }

    $creds = base64_encode("{$key}:{$secret}");
    $url   = getMpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Basic {$creds}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    if ($errno) {
        error_log("M-PESA token cURL error #{$errno}");
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}
