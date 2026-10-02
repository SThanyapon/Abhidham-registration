<?php

require_once __DIR__ . '/db.php';

/**
 * Minimal SMTP client so the app doesn't require Composer/PHPMailer just to send OTP codes and
 * backups. Plain-text body plus optional attachments, each
 * ['filename' => string, 'content' => raw bytes, 'mime' => string]. Swap for PHPMailer if you need
 * HTML email or more robust error handling.
 */
function sendEmail(string $to, string $subject, string $body, array $attachments = []): bool
{
    $config = getConfig()['mail'];

    $host = $config['encryption'] === 'ssl' ? 'ssl://' . $config['host'] : $config['host'];
    $socket = @fsockopen($host, $config['port'], $errno, $errstr, 10);

    if (!$socket) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }

    $read = function () use ($socket) {
        $response = '';
        do {
            $line = fgets($socket, 512);
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        return $response;
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

    $body = str_replace(["\r\n", "\r"], "\n", $body);
    if ($attachments === []) {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message = $body;
    } else {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $message = "--$boundary\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n"
            . $body . "\n";
        foreach ($attachments as $attachment) {
            $filename = str_replace(['"', "\r", "\n"], '', $attachment['filename']);
            $message .= "--$boundary\n"
                . "Content-Type: {$attachment['mime']}; name=\"$filename\"\n"
                . "Content-Transfer-Encoding: base64\n"
                . "Content-Disposition: attachment; filename=\"$filename\"\n\n"
                . chunk_split(base64_encode($attachment['content']), 76, "\n");
        }
        $message .= "--$boundary--\n";
    }

    // SMTP DATA: CRLF line endings, and a line starting with "." is escaped by doubling it.
    $message = preg_replace('/^\./m', '..', $message);
    $message = str_replace("\n", "\r\n", $message);

    $write($headers . "\r\n" . $message . "\r\n.");
    $sendResponse = $read();
    $write('QUIT');
    fclose($socket);

    return strpos($sendResponse, '250') === 0;
}
