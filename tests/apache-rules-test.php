<?php
/**
 * JohnsonChat Apache rules and response-header test.
 *
 * Requires Apache with AllowOverride All (see tests/apache/httpd.conf).
 *   APP_URL=http://127.0.0.1:8080 php tests/apache-rules-test.php
 *
 * Creates temporary probe files, verifies they cannot be fetched, and removes
 * only those files. It never reads or prints application data.
 */

declare(strict_types=1);

$BASE = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8080', '/');
$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  PASS  {$name}\n";
        return;
    }
    $fail++;
    $failures[] = $name . ($detail !== '' ? " ({$detail})" : '');
    echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** @return array{status:int,headers:array<string,string>,body:string,type:string} */
function req(string $method, string $path, array $headers = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_merge(['Accept: */*'], $headers),
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
            return strlen($line);
        },
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body, 'type' => $type];
}

$probes = [
    'api/_rules_probe.json' => '{"probe":true}',
    'api/calls/_rules_probe.json' => '{"probe":true}',
    'private_messages/_rules_probe.json' => '{"probe":true}',
    'uploads/_rules_probe.txt' => 'probe',
    'storage/_rules_probe.txt' => 'probe',
    'logs/_rules_probe.txt' => 'probe',
    'mail_log/_rules_probe.txt' => 'probe',
    'api/messages/_rules_probe.txt' => 'probe',
    '.git/config' => 'probe',
];
$createdDirs = [];
foreach (['logs', '.git'] as $dir) {
    if (!is_dir($ROOT . '/' . $dir)) {
        mkdir($ROOT . '/' . $dir, 0777, true);
        $createdDirs[] = $dir;
    }
}
foreach ($probes as $rel => $content) {
    file_put_contents($ROOT . '/' . $rel, $content);
}

try {
    echo "Apache rules and headers against {$BASE}\n";
    echo "----------------------------------------\n";

    echo "== Blocked files ==\n";
    $blocked = array_keys($probes);
    foreach (['api/active_users.json', 'api/friends.json', 'api/offline.json', 'api/private.json', 'api/rings.json', 'api/typing.json', 'api/data/chat.db', 'uploads/manifest.json', '.htaccess', 'tests/apache/httpd.conf'] as $rel) {
        if (is_file($ROOT . '/' . $rel) && !in_array($rel, $blocked, true)) {
            $blocked[] = $rel;
        }
    }
    foreach ($blocked as $rel) {
        $r = req('GET', '/' . $rel);
        check('/' . $rel . ' is blocked', $r['status'] === 404, 'status ' . $r['status']);
    }

    echo "== Case-insensitive Windows bypasses ==\n";
    foreach (['api/_rules_probe.json', 'api/data/chat.db', 'private_messages/_rules_probe.json', 'uploads/_rules_probe.txt', 'storage/_rules_probe.txt', 'logs/_rules_probe.txt', 'mail_log/_rules_probe.txt', 'api/messages/_rules_probe.txt', '.git/config', '.htaccess', 'tests/apache/httpd.conf'] as $rel) {
        if (!is_file($ROOT . '/' . $rel)) {
            continue;
        }
        $r = req('GET', '/' . strtoupper($rel));
        check('/' . strtoupper($rel) . ' is blocked', $r['status'] === 404, 'status ' . $r['status']);
    }

    echo "== Encoded and normalized traversal ==\n";
    foreach ([
        '/api/data%2Fchat.db',
        '/api%2Fdata/chat.db',
        '/api/data/./chat.db',
        '/api/data//chat.db',
        '/api/data/..%2fdata/chat.db',
        '/api/data%5Cchat.db',
    ] as $path) {
        $r = req('GET', $path);
        check($path . ' is blocked', $r['status'] === 404, 'status ' . $r['status']);
    }

    echo "== Allowed application routes ==\n";
    foreach (['/messnger.html', '/style.css', '/script.js', '/privacy.html', '/terms.html', '/api/health.php', '/api/schema.php'] as $path) {
        $r = req('GET', $path);
        check($path . ' is served', $r['status'] === 200, 'status ' . $r['status']);
    }
    $root = req('GET', '/');
    check('/ redirects to the app', $root['status'] === 302 && str_contains($root['headers']['location'] ?? '', 'messnger.html'), 'status ' . $root['status']);

    echo "== Response security headers ==\n";
    $expected = [
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'DENY',
        'x-xss-protection' => '1; mode=block',
        'referrer-policy' => 'strict-origin-when-cross-origin',
        'content-security-policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
    ];
    foreach (['/messnger.html', '/style.css', '/api/health.php', '/api/data/chat.db', '/API/DATA/chat.db'] as $path) {
        $r = req('GET', $path);
        foreach ($expected as $name => $value) {
            $actual = $r['headers'][$name] ?? '';
            check($path . ' sends ' . $name, strcasecmp($actual, $value) === 0, $actual === '' ? 'missing' : $actual);
        }
    }

    $plain = req('GET', '/messnger.html');
    check('HSTS is not sent over plain HTTP', !isset($plain['headers']['strict-transport-security']), $plain['headers']['strict-transport-security'] ?? 'none');
    $cspHeader = $plain['headers']['content-security-policy'] ?? '';
    check('CSP forbids inline scripts', str_contains($cspHeader, "script-src 'self';") && !str_contains($cspHeader, "script-src 'self' 'unsafe-inline'"), $cspHeader === '' ? 'missing' : $cspHeader);

    echo "== Content types ==\n";
    $types = [
        '/messnger.html' => 'text/html',
        '/style.css' => 'text/css',
        '/api/health.php' => 'application/json',
        '/api/data/chat.db' => 'text/html',
    ];
    foreach ($types as $path => $prefix) {
        $r = req('GET', $path);
        check($path . ' content type is ' . $prefix, str_starts_with($r['type'], $prefix), $r['type']);
    }

    echo "== Extension and page hardening ==\n";
    $health = req('GET', '/api/health.php');
    $healthJson = json_decode($health['body'], true);
    check('Apache loaded the curl extension', is_array($healthJson) && ($healthJson['curl_loaded'] ?? false) === true, is_array($healthJson) ? 'curl_loaded=false' : 'invalid JSON');

    $reset = req('GET', '/index.php?action=reset&token=invalid');
    check('reset password page is served', $reset['status'] === 200, 'status ' . $reset['status']);
    check('reset page uses external JavaScript', str_contains($reset['body'], 'src="reset.js"'), 'reset.js missing');
    check('reset page has no inline scripts', !preg_match('/<script(?![^>]*\bsrc=)/i', $reset['body']), 'inline script present');

    $setupLocked = false;
    $dbFile = $ROOT . '/api/data/chat.db';
    if (is_file($dbFile)) {
        try {
            $pdo = new PDO('sqlite:' . $dbFile);
            $setupLocked = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() > 0;
        } catch (Throwable $e) {
        }
    }
    if ($setupLocked) {
        $setup = req('GET', '/api/setup.php');
        check('setup API is locked after install', $setup['status'] === 404, 'status ' . $setup['status']);
    }

    echo "== Request methods and CORS ==\n";
    foreach (['TRACE', 'PUT', 'DELETE'] as $method) {
        $r = req($method, '/messnger.html');
        check($method . ' is rejected', in_array($r['status'], [403, 405], true), 'status ' . $r['status']);
    }
    $cors = req('GET', '/api/health.php', ['Origin: https://evil.example']);
    check('foreign Origin gets no CORS grant', !isset($cors['headers']['access-control-allow-origin']), $cors['headers']['access-control-allow-origin'] ?? 'none');

    echo "== No data leakage ==\n";
    $db = req('GET', '/api/data/chat.db');
    check('database body is not disclosed', !str_contains($db['body'], 'SQLite format 3'), 'SQLite header present');
    $ht = req('GET', '/.htaccess');
    check('.htaccess body is not disclosed', !str_contains($ht['body'], 'RedirectMatch'), 'rules present in body');
    $listing = req('GET', '/api/');
    check('API directory is not listable', $listing['status'] !== 200, 'status ' . $listing['status']);
} finally {
    foreach (array_keys($probes) as $rel) {
        @unlink($ROOT . '/' . $rel);
    }
    foreach ($createdDirs as $dir) {
        @rmdir($ROOT . '/' . $dir);
    }
}

echo "----------------------------------------\n";
printf("PASS: %d   FAIL: %d\n", $pass, $fail);
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}
echo "All checks passed.\n";
