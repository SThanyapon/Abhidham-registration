<?php

require_once __DIR__ . '/db.php';

/**
 * Minimal SMTP client (no attachments) so the app doesn't require Composer/PHPMailer
 * just to send OTP codes and backup notifications. Swap for PHPMailer if you need
 * attachments, HTML email, or more robust error handling.
 */
function sendEmail(string $to, string $subject, string $body): bool
{
    $config = getConfig()['mail'];

    $host = $config['encryption'] === 'ssl' ? 'ssl://' . $config['host'] : $config['host'];
    $socket = @fsockopen($host, $config['port'], $errno, $errstr, 10);

    if (!$socket) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }

    $read = function () use ($socket) {
        return fgets($socket, 512);
    };
    $write = function (string $command) use ($socket) {
        fwrite($socket, $command . "\r\n");
    };

    $read();
    $write('EHLO localhost');
    $read();

    if ($config['encryption'] === 'tls') {
        $write('STARTTLS');
        $read();
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write('EHLO localhost');
        $read();
    }

    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($config['username']));
    $read();
    $write(base64_encode($config['password']));
    $authResponse = $read();

    if (strpos($authResponse, '235') !== 0) {
        error_log("SMTP auth failed: $authResponse");
        fclose($socket);
        return false;
    }

    $write('MAIL FROM:<' . $config['from_email'] . '>');
    $read();
    $write('RCPT TO:<' . $to . '>');
    $read();
    $write('DATA');
    $read();

    $headers = "From: {$config['from_name']} <{$config['from_email']}>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $write($headers . "\r\n" . $body . "\r\n.");
    $sendResponse = $read();
    $write('QUIT');
    fclose($socket);

    return strpos($sendResponse, '250') === 0;
}
