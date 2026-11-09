<?php

/** Web Push (RFC 8291/8188) and VAPID (RFC 8292) helpers. */

function wp_b64u(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function wp_b64u_decode(string $data): string
{
    $data = strtr(trim($data), '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? '' : $decoded;
}

function wp_openssl_config(): string
{
    $candidates = [
        getenv('OPENSSL_CONF') ?: '',
        'C:/xampp/apache/conf/openssl.cnf',
        'C:/xampp/php/extras/openssl/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/usr/local/etc/openssl/openssl.cnf',
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function wp_ec_key_new(): OpenSSLAsymmetricKey|false
{
    $options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];
    $config = wp_openssl_config();
    if ($config !== '') {
        $options['config'] = $config;
    }
    return openssl_pkey_new($options);
}

function wp_vapid_keys(): array
{
    static $keys = null;
    if (is_array($keys)) {
        return $keys;
    }

    $file = __DIR__ . '/data/vapid.json';
    if (is_file($file)) {
        $stored = json_decode((string) file_get_contents($file), true);
        if (is_array($stored) && !empty($stored['private']) && !empty($stored['public'])) {
            $keys = [
                'private' => $stored['private'],
                'public' => $stored['public'],
                'subject' => $stored['subject'] ?? (getenv('VAPID_SUBJECT') ?: 'mailto:admin@localhost'),
            ];
            return $keys;
        }
    }

    $resource = wp_ec_key_new();
    $config = wp_openssl_config();
    $exportOptions = $config !== '' ? ['config' => $config] : [];
    if (!$resource || !openssl_pkey_export($resource, $privatePem, null, $exportOptions)) {
        throw new RuntimeException('Could not generate VAPID key pair');
    }
    $details = openssl_pkey_get_details($resource);
    if (!isset($details['ec']['x'], $details['ec']['y'])) {
        throw new RuntimeException('Could not read VAPID public key');
    }
    $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    $keys = [
        'private' => $privatePem,
        'public' => wp_b64u($publicRaw),
        'subject' => getenv('VAPID_SUBJECT') ?: 'mailto:admin@localhost',
    ];
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($file, json_encode([
        'private' => $keys['private'],
        'public' => $keys['public'],
        'subject' => $keys['subject'],
    ], JSON_PRETTY_PRINT), LOCK_EX);
    return $keys;
}

function wp_public_key(): string
{
    return (string) wp_vapid_keys()['public'];
}

function wp_read_der_length(string $der, int &$pos): int
{
    $length = ord($der[$pos++]);
    if ($length & 0x80) {
        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$pos++]);
        }
    }
    return $length;
}

function wp_der_signature_to_raw(string $der): string
{
    $pos = 0;
    if (strlen($der) < 8 || ord($der[$pos++]) !== 0x30) {
        return '';
    }
    wp_read_der_length($der, $pos);
    if (ord($der[$pos++]) !== 0x02) {
        return '';
    }
    $rLen = wp_read_der_length($der, $pos);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;
    if (ord($der[$pos++]) !== 0x02) {
        return '';
    }
    $sLen = wp_read_der_length($der, $pos);
    $s = substr($der, $pos, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

function wp_vapid_jwt(string $endpoint): string
{
    $keys = wp_vapid_keys();
    $parts = parse_url($endpoint);
    if (!isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('Invalid push endpoint');
    }
    $audience = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $audience .= ':' . $parts['port'];
    }
    $header = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = wp_b64u(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $keys['subject'],
    ]));
    $signingInput = $header . '.' . $payload;
    $der = '';
    if (!openssl_sign($signingInput, $der, $keys['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign VAPID token');
    }
    $raw = wp_der_signature_to_raw($der);
    if (strlen($raw) !== 64) {
        throw new RuntimeException('Invalid VAPID signature');
    }
    return $signingInput . '.' . wp_b64u($raw);
}

function wp_raw_public_pem(string $raw): string
{
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $prefix . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Encrypt a push payload using aes128gcm.
 *
 * @return array{body:string, salt:string, local_public:string}
 */
function wp_encrypt_payload(string $payload, string $clientPublicRaw, string $authSecret): array
{
    if (strlen($clientPublicRaw) !== 65 || $clientPublicRaw[0] !== "\x04") {
        throw new RuntimeException('Invalid push p256dh key');
    }
    if (strlen($authSecret) !== 16) {
        throw new RuntimeException('Invalid push auth secret');
    }

    $clientPem = wp_raw_public_pem($clientPublicRaw);
    $ephemeral = wp_ec_key_new();
    if (!$ephemeral) {
        throw new RuntimeException('Could not create ephemeral push key');
    }
    $details = openssl_pkey_get_details($ephemeral);
    if (!isset($details['ec']['x'], $details['ec']['y'])) {
        throw new RuntimeException('Could not read ephemeral push key');
    }
    $localPublic = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    $shared = openssl_pkey_derive($clientPem, $ephemeral, 32);
    if ($shared === false) {
        throw new RuntimeException('Could not derive push shared secret');
    }

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $clientPublicRaw . $localPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    $tag = '';
    $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        throw new RuntimeException('Could not encrypt push payload');
    }

    return [
        'body' => $salt . pack('N', 4096) . chr(65) . $localPublic . $ciphertext . $tag,
        'salt' => $salt,
        'local_public' => $localPublic,
    ];
}

/** @return array{ok:bool,status:int,error?:string} */
function wp_send_subscription(array $subscription, array $payload): array
{
    $endpoint = (string) ($subscription['endpoint'] ?? '');
    $p256dh = wp_b64u_decode((string) ($subscription['keys']['p256dh'] ?? ''));
    $auth = wp_b64u_decode((string) ($subscription['keys']['auth'] ?? ''));
    if (!preg_match('#^https://#i', $endpoint) || $p256dh === '' || $auth === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Invalid push subscription'];
    }

    try {
        $encrypted = wp_encrypt_payload(json_encode($payload, JSON_UNESCAPED_UNICODE), $p256dh, $auth);
        $jwt = wp_vapid_jwt($endpoint);
    } catch (Throwable $e) {
        return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
    }

    $keys = wp_vapid_keys();
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Urgency: normal',
        ],
        CURLOPT_POSTFIELDS => $encrypted['body'],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status];
    }
    return ['ok' => false, 'status' => $status, 'error' => $error !== '' ? $error : ('HTTP ' . $status)];
}
