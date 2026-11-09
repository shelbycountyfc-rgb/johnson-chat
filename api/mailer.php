<?php

/**
 * Small, dependency-free mailer.
 *
 * Transports:
 *   log  - write the message to ../mail_log/ (development default)
 *   smtp - send through an SMTP server, optionally STARTTLS/SSL + AUTH
 *   mail - hand off to PHP's mail() function
 */

function app_mail_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/mail_config.php';
    }
    return $config;
}

function app_mail_transport(): string
{
    return strtolower((string) (app_mail_config()['transport'] ?? 'log'));
}

function app_mail_debug_links(): bool
{
    $config = app_mail_config();
    return (bool) ($config['expose_links'] ?? false) || app_mail_transport() === 'log';
}

function app_mail_require_verification(): bool
{
    return (bool) (app_mail_config()['require_verification'] ?? false);
}

/** Absolute base URL for links placed in email. */
function app_base_url(): string
{
    $configured = trim((string) (app_mail_config()['app_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

function app_mail_header_text(string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

function app_mail_log(string $to, string $subject, string $body, string $status = 'sent'): void
{
    $dir = __DIR__ . '/../mail_log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $name = 'mail-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';
    $safeSubject = str_replace(["\r", "\n"], ' ', $subject);
    @file_put_contents(
        $dir . '/' . $name,
        "Status: $status\nTo: $to\nSubject: $safeSubject\n\n$body\n---\n",
        LOCK_EX
    );
}

/** @return array{ok:bool,transport:string,error?:string} */
function app_mail_send(string $to, string $subject, string $body): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'transport' => app_mail_transport(), 'error' => 'Invalid recipient address'];
    }
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $transport = app_mail_transport();

    if ($transport === 'log') {
        app_mail_log($to, $subject, $body, 'logged');
        return ['ok' => true, 'transport' => 'log'];
    }

    if ($transport === 'mail') {
        $config = app_mail_config();
        $headers = app_mail_headers($config, $to, $subject);
        $ok = @mail($to, app_mail_header_text($subject), $body, $headers);
        if (!$ok) {
            app_mail_log($to, $subject, 'mail() failed', 'error');
            return ['ok' => false, 'transport' => 'mail', 'error' => 'mail() failed'];
        }
        return ['ok' => true, 'transport' => 'mail'];
    }

    if ($transport === 'smtp') {
        $error = '';
        if (app_smtp_send($to, $subject, $body, $error)) {
            return ['ok' => true, 'transport' => 'smtp'];
        }
        app_mail_log($to, $subject, 'SMTP failed: ' . $error, 'error');
        return ['ok' => false, 'transport' => 'smtp', 'error' => $error];
    }

    app_mail_log($to, $subject, 'Unknown transport: ' . $transport, 'error');
    return ['ok' => false, 'transport' => $transport, 'error' => 'Unknown mail transport'];
}

function app_mail_headers(array $config, string $to, string $subject): string
{
    $fromEmail = (string) ($config['from_email'] ?? 'no-reply@localhost');
    $fromName = (string) ($config['from_name'] ?? 'JohnsonChat');
    return implode("\r\n", [
        'From: ' . app_mail_header_text($fromName) . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . app_mail_header_text($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(app_base_url(), PHP_URL_HOST) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: JohnsonChat',
    ]);
}

function app_smtp_read($fp): string
{
    $response = '';
    while (($line = fgets($fp, 2048)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return $response;
}

function app_smtp_cmd($fp, string $command, array $expected, string &$error): string
{
    if (@fwrite($fp, $command . "\r\n") === false) {
        $error = 'Could not write to SMTP server';
        return '';
    }
    $response = app_smtp_read($fp);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        $error = 'SMTP ' . $code . ': ' . trim(preg_replace('/\s+/', ' ', $response));
        return '';
    }
    return $response;
}

function app_smtp_send(string $to, string $subject, string $body, string &$error): bool
{
    $config = app_mail_config();
    $smtp = $config['smtp'] ?? [];
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 587);
    $secure = strtolower((string) ($smtp['secure'] ?? 'tls'));
    $fromEmail = (string) ($config['from_email'] ?? '');
    if ($host === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'SMTP host/from address is not configured';
        return false;
    }

    $scheme = $secure === 'ssl' ? 'ssl' : 'tcp';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => (bool) ($smtp['verify'] ?? true),
            'verify_peer_name' => (bool) ($smtp['verify'] ?? true),
            'allow_self_signed' => false,
        ],
    ]);
    $fp = @stream_socket_client(
        $scheme . '://' . $host . ':' . $port,
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$fp) {
        $error = 'SMTP connection failed: ' . $errstr;
        return false;
    }
    stream_set_timeout($fp, 10);

    $greeting = app_smtp_read($fp);
    if ((int) substr($greeting, 0, 3) !== 220) {
        $error = 'SMTP greeting failed: ' . trim($greeting);
        fclose($fp);
        return false;
    }

    $helo = 'EHLO ' . (gethostname() ?: 'localhost');
    if (app_smtp_cmd($fp, $helo, [250], $error) === '') {
        fclose($fp);
        return false;
    }

    if ($secure === 'tls') {
        if (app_smtp_cmd($fp, 'STARTTLS', [220], $error) === '') {
            fclose($fp);
            return false;
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $error = 'SMTP STARTTLS negotiation failed';
            fclose($fp);
            return false;
        }
        if (app_smtp_cmd($fp, $helo, [250], $error) === '') {
            fclose($fp);
            return false;
        }
    }

    $user = (string) ($smtp['user'] ?? '');
    $pass = (string) ($smtp['pass'] ?? '');
    if ($user !== '') {
        if (app_smtp_cmd($fp, 'AUTH LOGIN', [334], $error) === '') {
            fclose($fp);
            return false;
        }
        if (app_smtp_cmd($fp, base64_encode($user), [334], $error) === '') {
            fclose($fp);
            return false;
        }
        if (app_smtp_cmd($fp, base64_encode($pass), [235], $error) === '') {
            fclose($fp);
            return false;
        }
    }

    if (app_smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250], $error) === '') {
        fclose($fp);
        return false;
    }
    if (app_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251], $error) === '') {
        fclose($fp);
        return false;
    }
    if (app_smtp_cmd($fp, 'DATA', [354], $error) === '') {
        fclose($fp);
        return false;
    }

    $headers = app_mail_headers($config, $to, $subject);
    $encodedBody = chunk_split(base64_encode($body), 76, "\r\n");
    $data = $headers . "\r\n\r\n" . $encodedBody;
    // Dot-stuffing is required even for base64 content.
    $data = preg_replace('/^\./m', '..', $data);
    if (@fwrite($fp, $data . "\r\n.\r\n") === false) {
        $error = 'Could not write SMTP message';
        fclose($fp);
        return false;
    }
    $response = app_smtp_read($fp);
    if ((int) substr($response, 0, 3) !== 250) {
        $error = 'SMTP message rejected: ' . trim($response);
        fclose($fp);
        return false;
    }

    app_smtp_cmd($fp, 'QUIT', [221], $error);
    fclose($fp);
    return true;
}
