<?php
/**
 * CallNow CRM Lightweight Mail Delivery Helper
 * Supports SMTP (SSL/TLS) and PHP mail() fallback with RFC-compliant headers.
 */

function sendSystemEmail(string $to, string $subject, string $bodyText): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $smtpHost = getenv('SMTP_HOST') ?: (defined('SMTP_HOST') ? SMTP_HOST : '');
    $smtpPort = (int)(getenv('SMTP_PORT') ?: (defined('SMTP_PORT') ? SMTP_PORT : 465));
    $smtpUser = getenv('SMTP_USERNAME') ?: (defined('SMTP_USERNAME') ? SMTP_USERNAME : '');
    $smtpPass = getenv('SMTP_PASSWORD') ?: (defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'no-reply@callnow.com');
    $smtpFromName = getenv('SMTP_FROM_NAME') ?: (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'CallNow CRM');
    $smtpEncryption = strtolower(getenv('SMTP_ENCRYPTION') ?: (defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'ssl'));

    // Attempt SMTP if host and credentials are configured
    if (!empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
        try {
            $sent = sendViaSmtpSocket($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom, $smtpFromName, $smtpEncryption, $to, $subject, $bodyText);
            if ($sent) {
                return true;
            }
        } catch (Throwable $e) {
            error_log("[Mailer] SMTP delivery failed: " . $e->getMessage());
        }
    }

    // Fallback to PHP mail() with proper headers
    $cleanFromName = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $smtpFromName);
    $headers = [
        "From: =?UTF-8?B?" . base64_encode($cleanFromName) . "?= <{$smtpFrom}>",
        "Reply-To: {$smtpFrom}",
        "X-Mailer: PHP/" . phpversion(),
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
    ];

    return @mail($to, $subject, $bodyText, implode("\r\n", $headers));
}

function sendViaSmtpSocket(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $fromEmail,
    string $fromName,
    string $encryption,
    string $to,
    string $subject,
    string $body
): bool {
    $protocol = ($encryption === 'ssl' || $port === 465) ? 'ssl://' : '';
    $timeout = 10;
    $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        throw new RuntimeException("Could not connect to SMTP host {$host}:{$port} ($errno $errstr)");
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = function() use ($socket) {
        $data = '';
        while (!feof($socket)) {
            $str = fgets($socket, 515);
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };

    $sendCommand = function(string $cmd, string $expectedCode) use ($socket, $readResponse) {
        fputs($socket, $cmd . "\r\n");
        $res = $readResponse();
        if (substr($res, 0, 3) !== $expectedCode) {
            throw new RuntimeException("SMTP Command failed: {$cmd} -> {$res}");
        }
        return $res;
    };

    $greeting = $readResponse();
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket);
        throw new RuntimeException("SMTP greeting unexpected: " . $greeting);
    }

    $hostname = gethostname() ?: 'localhost';
    $sendCommand("EHLO {$hostname}", '250');

    if ($encryption === 'tls' || ($port === 587 && $protocol === '')) {
        $sendCommand("STARTTLS", '220');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            throw new RuntimeException("TLS handshake negotiation failed");
        }
        $sendCommand("EHLO {$hostname}", '250');
    }

    $sendCommand("AUTH LOGIN", '334');
    $sendCommand(base64_encode($user), '334');
    $sendCommand(base64_encode($pass), '235');

    $sendCommand("MAIL FROM:<{$fromEmail}>", '250');
    $sendCommand("RCPT TO:<{$to}>", '250');
    $sendCommand("DATA", '354');

    $cleanFromName = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $fromName);
    $headers = [
        "From: =?UTF-8?B?" . base64_encode($cleanFromName) . "?= <{$fromEmail}>",
        "To: <{$to}>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "Date: " . date('r'),
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
    ];

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $sendCommand($emailData, '250');
    $sendCommand("QUIT", '221');
    fclose($socket);

    return true;
}
