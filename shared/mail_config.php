<?php

$env = static fn (string $key): string => trim((string) getenv($key));
$provider = strtolower($env('BARANGAY420_MAIL_PROVIDER'));
$apiKey = $env('BARANGAY420_BREVO_API_KEY');
$host = $env('BARANGAY420_SMTP_HOST');
$username = $env('BARANGAY420_SMTP_USER');
$password = $env('BARANGAY420_SMTP_PASS');
$encryption = strtolower($env('BARANGAY420_SMTP_ENCRYPTION'));
$fromEmail = $env('BARANGAY420_SMTP_FROM');
$fromName = $env('BARANGAY420_SMTP_FROM_NAME');
$appBaseUrl = $env('BARANGAY420_APP_BASE_URL');
$appEnv = $env('APP_ENV');
$port = (int) ($env('BARANGAY420_SMTP_PORT') ?: ($encryption === 'ssl' ? 465 : 587));

if ($provider === '' || $fromEmail === '' || $fromName === '' || $appBaseUrl === '' || $appEnv === ''
    || ($provider === 'brevo' && $apiKey === '')
    || ($provider === 'smtp' && ($host === '' || $username === '' || $password === ''))) {
    $privateConfigPath = getenv('BARANGAY420_PRIVATE_SMTP_CONFIG');
    if (!is_string($privateConfigPath) || $privateConfigPath === '') {
        $privateConfigPath = DIRECTORY_SEPARATOR === '\\'
            ? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'barangay420-smtp.php'
            : __DIR__ . DIRECTORY_SEPARATOR . 'private_config.php';
    }

    if (is_file($privateConfigPath) && is_readable($privateConfigPath)) {
        $privateConfig = require $privateConfigPath;
        if (!is_array($privateConfig)) {
            throw new RuntimeException('Private SMTP configuration is invalid.');
        }
        $provider = $provider !== '' ? $provider : strtolower(trim((string) ($privateConfig['mail_provider'] ?? 'smtp')));
        $apiKey = $apiKey !== '' ? $apiKey : trim((string) ($privateConfig['brevo_api_key'] ?? ''));
        $host = $host !== '' ? $host : trim((string) ($privateConfig['host'] ?? ''));
        $username = $username !== '' ? $username : trim((string) ($privateConfig['username'] ?? ''));
        $password = $password !== '' ? $password : trim((string) ($privateConfig['password'] ?? ''));
        $encryption = $encryption !== '' ? $encryption : strtolower(trim((string) ($privateConfig['encryption'] ?? '')));
        $fromEmail = $fromEmail !== '' ? $fromEmail : trim((string) ($privateConfig['from_email'] ?? ''));
        $fromName = $fromName !== '' ? $fromName : trim((string) ($privateConfig['from_name'] ?? ''));
        $appBaseUrl = $appBaseUrl !== '' ? $appBaseUrl : trim((string) ($privateConfig['app_base_url'] ?? ''));
        $appEnv = $appEnv !== '' ? $appEnv : trim((string) ($privateConfig['app_env'] ?? ''));
        $port = (int) ($env('BARANGAY420_SMTP_PORT') ?: ($privateConfig['port'] ?? ($encryption === 'ssl' ? 465 : 587)));
    }
}

if ($provider === '') {
    $provider = 'smtp';
}
if (!in_array($provider, ['smtp', 'brevo'], true)) {
    throw new RuntimeException('Authentication email provider is invalid.');
}
if ($provider === 'brevo' && $apiKey === '') {
    throw new RuntimeException('Brevo API is not configured.');
}
if ($provider === 'smtp' && ($host === '' || $username === '' || $password === '')) {
    throw new RuntimeException('SMTP is not configured.');
}
if ($provider === 'smtp' && !in_array($encryption, ['tls', 'ssl'], true)) {
    throw new RuntimeException('SMTP must use authenticated TLS.');
}
if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || $fromName === '') {
    throw new RuntimeException('Authentication email sender configuration is invalid.');
}
if ($provider === 'smtp' && ($port < 1 || $port > 65535)) {
    throw new RuntimeException('SMTP port configuration is invalid.');
}

return [
    'provider' => $provider,
    'api_key' => $apiKey,
    'host' => $host,
    'port' => $port,
    'username' => $username,
    'password' => $password,
    'encryption' => $encryption,
    'from_email' => $fromEmail,
    'from_name' => $fromName,
    'app_base_url' => $appBaseUrl,
    'app_env' => $appEnv,
];
