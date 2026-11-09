<?php

/**
 * CSRF / DNS-rebinding defense for state-changing requests.
 *
 * The app authenticates via bearer tokens in the request body (no cookies),
 * so classic CSRF is already mitigated. This check additionally rejects
 * cross-origin requests (Origin host != Host) as a defense-in-depth measure.
 *
 * Requests without an Origin header (curl, server-side clients, same-host
 * navigations, file:// setups sending Origin: null) are allowed.
 */
function require_valid_origin(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        return;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        return;
    }
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return;
    }
    // Strip any explicit port from the Host header before comparing, since
    // parse_url(PHP_URL_HOST) on the Origin never includes a port.
    $host = preg_replace('/:\d+$/', '', $host);
    $originHost = strtolower(trim((string) parse_url($origin, PHP_URL_HOST)));
    if ($originHost !== $host) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Origin not allowed']);
        exit;
    }
}