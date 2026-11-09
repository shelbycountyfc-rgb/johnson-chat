<?php
/**
 * Mail configuration.
 *
 * The default transport is "log", which writes messages to ../mail_log/ so
 * local development does not need an SMTP server. For real delivery set
 * MAIL_TRANSPORT=smtp and the SMTP_* variables, or create
 * mail_config.local.php (ignored by git) that returns an override array.
 */
$config = [
    'transport' => getenv('MAIL_TRANSPORT') ?: 'log',
    'from_email' => getenv('MAIL_FROM') ?: 'no-reply@johnsonchat.local',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'JohnsonChat',
    'app_url' => getenv('APP_URL') ?: '',
    'expose_links' => getenv('MAIL_EXPOSE_LINKS') === '1',
    'require_verification' => getenv('MAIL_REQUIRE_VERIFICATION') === '1',
    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: '',
        'port' => (int) (getenv('SMTP_PORT') ?: 587),
        'user' => getenv('SMTP_USER') ?: '',
        'pass' => getenv('SMTP_PASS') ?: '',
        'secure' => getenv('SMTP_SECURE') ?: 'tls', // tls, ssl, none
        'verify' => getenv('SMTP_VERIFY') !== '0',
    ],
];

$local = __DIR__ . '/mail_config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
