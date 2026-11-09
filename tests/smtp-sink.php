<?php
/**
 * Minimal SMTP sink for tests.
 * Usage: php tests/smtp-sink.php <port> <capture.json>
 * Accepts one connection, supports EHLO, AUTH LOGIN, MAIL, RCPT, DATA, QUIT.
 */
declare(strict_types=1);

$port = (int) ($argv[1] ?? 2526);
$capture = (string) ($argv[2] ?? __DIR__ . '/.smtp-capture.json');
$ready = $capture . '.ready';
@unlink($ready);
@unlink($capture);

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "sink listen failed: $errstr\n");
    exit(1);
}
file_put_contents($ready, 'ready');
$conn = @stream_socket_accept($server, 25);
if (!$conn) {
    @unlink($ready);
    exit(1);
}
stream_set_timeout($conn, 15);

$write = static function ($conn, string $line): void {
    fwrite($conn, $line . "\r\n");
};
$read = static function ($conn): string {
    $line = fgets($conn);
    return $line === false ? '' : rtrim($line, "\r\n");
};

$write($conn, '220 localhost smtp sink');
$authUser = '';
$authPass = '';
$from = '';
$recipient = '';
$data = '';

while (($line = $read($conn)) !== '') {
    $upper = strtoupper($line);
    if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
        fwrite($conn, "250-localhost\r\n250-AUTH LOGIN\r\n250 OK\r\n");
    } elseif (str_starts_with($upper, 'AUTH LOGIN')) {
        $write($conn, '334 VXNlcm5hbWU6');
        $authUser = base64_decode($read($conn), true) ?: '';
        $write($conn, '334 UGFzc3dvcmQ6');
        $authPass = base64_decode($read($conn), true) ?: '';
        $write($conn, '235 2.7.0 Authentication successful');
    } elseif (str_starts_with($upper, 'MAIL FROM:')) {
        $from = trim(substr($line, 10), " <>");
        $write($conn, '250 OK');
    } elseif (str_starts_with($upper, 'RCPT TO:')) {
        $recipient = trim(substr($line, 8), " <>");
        $write($conn, '250 OK');
    } elseif ($upper === 'DATA') {
        $write($conn, '354 End data with <CR><LF>.<CR><LF>');
        $data = '';
        while (($chunk = fgets($conn)) !== false) {
            if ($chunk === ".\r\n" || $chunk === ".\n") {
                break;
            }
            $data .= preg_replace('/^\.\./m', '.', $chunk);
        }
        $write($conn, '250 2.0.0 Message accepted');
    } elseif ($upper === 'QUIT') {
        $write($conn, '221 Bye');
        break;
    } elseif ($upper === 'RSET') {
        $write($conn, '250 OK');
    } else {
        $write($conn, '250 OK');
    }
}

file_put_contents($capture, json_encode([
    'auth_user' => $authUser,
    'auth_pass' => $authPass,
    'from' => $from,
    'recipient' => $recipient,
    'data' => $data,
], JSON_PRETTY_PRINT));

fclose($conn);
fclose($server);
@unlink($ready);
exit(0);
