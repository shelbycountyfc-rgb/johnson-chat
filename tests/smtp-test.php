<?php
/**
 * SMTP client integration test. Starts tests/smtp-sink.php on a loopback port,
 * sends through api/mailer.php, and verifies the captured message.
 *
 *   php tests/smtp-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$capture = __DIR__ . '/.smtp-capture-' . bin2hex(random_bytes(4)) . '.json';
$port = 2526 + random_int(0, 200);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/smtp-sink.php') . ' ' . $port . ' ' . escapeshellarg($capture);
$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($cmd, $descriptors, $pipes, __DIR__);
$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
        return;
    }
    $fail++;
    $failures[] = $name . ($detail !== '' ? "  [$detail]" : '');
    echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
}

try {
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start SMTP sink');
    }
    $ready = $capture . '.ready';
    $deadline = microtime(true) + 8;
    while (!is_file($ready) && microtime(true) < $deadline) {
        usleep(100000);
    }
    check('SMTP sink starts', is_file($ready));

    putenv('MAIL_TRANSPORT=smtp');
    putenv('MAIL_FROM=no-reply@example.test');
    putenv('MAIL_FROM_NAME=JohnsonChat Test');
    putenv('SMTP_HOST=127.0.0.1');
    putenv('SMTP_PORT=' . $port);
    putenv('SMTP_SECURE=none');
    putenv('SMTP_USER=tester');
    putenv('SMTP_PASS=secret-pass');
    putenv('SMTP_VERIFY=0');

    require_once $root . '/api/mailer.php';
    $result = app_mail_send('dest@example.test', 'SMTP feature test', "Hello from the mailer.\nSecond line.");
    check('SMTP send returns success', !empty($result['ok']) && ($result['transport'] ?? '') === 'smtp', json_encode($result));

    $deadline = microtime(true) + 5;
    while (!is_file($capture) && microtime(true) < $deadline) {
        usleep(100000);
    }
    check('SMTP server captured a message', is_file($capture));
    if (is_file($capture)) {
        $captured = json_decode((string) file_get_contents($capture), true);
        check('SMTP AUTH LOGIN credentials are sent', ($captured['auth_user'] ?? '') === 'tester' && ($captured['auth_pass'] ?? '') === 'secret-pass', json_encode($captured));
        check('SMTP envelope has the sender and recipient', ($captured['from'] ?? '') === 'no-reply@example.test' && ($captured['recipient'] ?? '') === 'dest@example.test', json_encode($captured));
        $data = (string) ($captured['data'] ?? '');
        check('SMTP message includes the subject', str_contains($data, 'Subject: SMTP feature test'), $data);
        $body = '';
        $parts = explode("\r\n\r\n", $data, 2);
        if (count($parts) === 2) {
            $body = (string) base64_decode(preg_replace('/\s+/', '', $parts[1]), true);
        }
        check('SMTP message is base64 encoded', str_contains($data, 'Content-Transfer-Encoding: base64') && $body === "Hello from the mailer.\nSecond line.", $body);
    }
} catch (Throwable $e) {
    check('SMTP integration test runs', false, $e->getMessage());
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) { @fclose($pipe); }
        proc_close($process);
    }
    @unlink($capture);
    @unlink($capture . '.ready');
}

echo "----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
if ($fail > 0) {
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "SMTP checks passed.\n";
exit(0);
