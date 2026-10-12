<?php

require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function trusted_app_base_url(): string {
    $base = trim((string) getenv('BARANGAY420_APP_BASE_URL'));
    $config = null;
    if ($base === '') {
        $config = require __DIR__ . '/mail_config.php';
        $base = trim((string) ($config['app_base_url'] ?? ''));
    }
    if ($base === '' || preg_match('/[\r\n]/', $base)) {
        throw new RuntimeException('Application base URL is not configured.');
    }

    $parts = parse_url($base);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Application base URL is invalid.');
    }

    $appEnv = trim((string) getenv('APP_ENV'));
    if ($appEnv === '') {
        $config = $config ?? require __DIR__ . '/mail_config.php';
        $appEnv = trim((string) ($config['app_env'] ?? ''));
    }
    if ($appEnv === 'production' && strtolower($parts['scheme']) !== 'https') {
        throw new RuntimeException('Production application base URL must use HTTPS.');
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        throw new RuntimeException('Application base URL scheme is invalid.');
    }

    return rtrim($base, '/');
}

function auth_email_link(string $portal, string $page, string $token): ?string {
    try {
        $base = trusted_app_base_url();
    } catch (Throwable $error) {
        error_log('Authentication email delivery failed.');
        return null;
    }
    $portalPath = $portal === 'admin' ? '/admin/' : '/resident/';
    return $base . $portalPath . $page . '?token=' . rawurlencode($token);
}

function send_auth_email(string $email, string $subject, string $htmlBody, string $plainBody): bool {
    try {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email recipient is invalid.');
        }

        $config = require __DIR__ . '/mail_config.php';
        if ($config['provider'] === 'brevo') {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('The cURL extension is required for Brevo email delivery.');
            }

            $payload = json_encode([
                'sender' => [
                    'name' => $config['from_name'],
                    'email' => $config['from_email'],
                ],
                'to' => [['email' => $email]],
                'subject' => $subject,
                'htmlContent' => $htmlBody,
                'textContent' => $plainBody,
            ], JSON_THROW_ON_ERROR);
            $curl = curl_init('https://api.brevo.com/v3/smtp/email');
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'accept: application/json',
                    'api-key: ' . $config['api_key'],
                    'content-type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($response === false) {
                error_log('Authentication email API request failed: ' . curl_error($curl));
                curl_close($curl);
                return false;
            }
            curl_close($curl);
            if ($status < 200 || $status >= 300) {
                error_log('Authentication email API returned HTTP ' . $status . '.');
                return false;
            }
            return true;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = $config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = $config['encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 10;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;
        $mail->send();
        return true;
    } catch (Throwable $error) {
        error_log('Authentication email delivery failed.');
        return false;
    }
}

function send_password_reset_email(string $email, string $token, string $portal = 'resident'): bool {
    $url = auth_email_link($portal, 'reset-password.php', $token);
    if ($url === null) {
        return false;
    }
    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"></head><body>'
        . '<p>We received a request to reset your Barangay 420 password.</p>'
        . '<p><a href="' . $escapedUrl . '">Reset your password</a></p>'
        . '<p>This link expires in 15 minutes and can only be used once. If you did not request this, ignore this message.</p>'
        . '</body></html>';
    $plain = "We received a request to reset your Barangay 420 password.\n\n"
        . "Reset your password using this one-time link (expires in 15 minutes):\n{$url}\n\n"
        . "If you did not request this, ignore this message.";
    return send_auth_email($email, 'Reset Your Barangay 420 Password', $html, $plain);
}

function send_email_verification_email(string $email, string $token, string $portal = 'resident'): bool {
    $url = auth_email_link($portal, 'verify-email.php', $token);
    if ($url === null) {
        return false;
    }
    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"></head><body>'
        . '<p>Verify your email address for your Barangay 420 account.</p>'
        . '<p><a href="' . $escapedUrl . '">Verify email address</a></p>'
        . '<p>This link expires in 24 hours. Your account will remain subject to the usual approval process.</p>'
        . '</body></html>';
    $plain = "Verify your email address for your Barangay 420 account.\n\n"
        . "Use this one-time link (expires in 24 hours):\n{$url}\n\n"
        . "Your account will remain subject to the usual approval process.";
    return send_auth_email($email, 'Verify Your Barangay 420 Email Address', $html, $plain);
}

function send_password_changed_email(string $email): bool {
    $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"></head><body>'
        . '<p>Your Barangay 420 account password was changed.</p>'
        . '<p>If you did not make this change, contact the barangay office immediately.</p>'
        . '</body></html>';
    $plain = "Your Barangay 420 account password was changed.\n\n"
        . "If you did not make this change, contact the barangay office immediately.";
    return send_auth_email($email, 'Your Barangay 420 Password Was Changed', $html, $plain);
}
