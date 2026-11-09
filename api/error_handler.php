<?php

set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});

set_exception_handler(function (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        echo 'Error: ' . $e->getMessage() . "\n";
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal server error']);
    exit;
});
