# JohnsonChat

JohnsonChat is a PHP and SQLite real-time messaging application. It runs without Composer, Node.js, or a separate database server.

Features include:

- Public rooms and private chats
- Replies, pinned messages, message reactions, and deep links
- File and image sharing
- Moderation, blocking, rate limiting, and account controls
- Password reset and optional signup email verification
- SMTP-ready email with a local log fallback
- Installable PWA with offline app-shell caching
- Web Push notifications for private messages and `@mentions`

## Requirements

- PHP 8.0 or newer
- PHP extensions: `pdo_sqlite`, `json`, `mbstring`, `session`, `openssl`
- Apache with `AllowOverride All`
- Apache modules: `mod_alias` (or equivalent redirect support), `mod_headers`, and `mod_authz_core`
- HTTPS for production PWA and Web Push use

The integration test harness additionally uses PHP `curl`. The included Apache harness assumes XAMPP on Windows; adapt its paths if you clone the project elsewhere.

## Quick start with XAMPP

1. Place the project in your web root, or use the project-local test server described below.
2. Make these locations writable by Apache:
   - `api/data/`
   - `api/messages/`
   - `private_messages/`
   - `storage/`
   - `uploads/`
   - `mail_log/`
3. Open `setup/` in a browser and complete the installer:
   - Check requirements
   - Initialize the SQLite database
   - Create the administrator account
4. Open `messnger.html` and sign in.

The installer creates `api/data/chat.db` and locks itself after an active administrator exists. To intentionally reinstall, remove the test database or remove the administrator while the installer is inaccessible to untrusted users.

## Configuration

### Email

Email configuration lives in `api/mail_config.php`. The default transport is `log`, which writes messages to `mail_log/` for local development.

Set these environment variables before starting PHP/Apache for real email delivery:

| Variable | Purpose |
| --- | --- |
| `MAIL_TRANSPORT` | `log`, `smtp`, or `mail` |
| `MAIL_FROM` | Sender email address |
| `MAIL_FROM_NAME` | Sender display name |
| `APP_URL` | Public application base URL used in reset and activation links |
| `MAIL_REQUIRE_VERIFICATION` | Set to `1` to require signup email activation |
| `MAIL_EXPOSE_LINKS` | Set to `1` only in local/debug environments to return reset links in API responses |
| `SMTP_HOST` | SMTP hostname |
| `SMTP_PORT` | SMTP port, normally `587` or `465` |
| `SMTP_USER` | SMTP username |
| `SMTP_PASS` | SMTP password |
| `SMTP_SECURE` | `tls`, `ssl`, or `none` |
| `SMTP_VERIFY` | Set to `0` only for a trusted local test server |

Alternatively, create `api/mail_config.local.php` returning an override array. That file is intentionally ignored by Git. Never put SMTP credentials in a tracked file.

`MAIL_EXPOSE_LINKS` is unsafe for production because password-reset and activation tokens would be returned to the requester instead of only being delivered by email.

### Web Push

Push subscriptions and VAPID signing are built in. The first push-key request generates a P-256 key pair at `api/data/vapid.json`.

Set:

- `VAPID_SUBJECT` to a contact URI such as `mailto:admin@example.com`.
- `OPENSSL_CONF` only if PHP cannot locate an OpenSSL configuration file on the host.

Keep `api/data/vapid.json` private and preserve it across deployments. Replacing it invalidates existing push subscriptions. The file is ignored by Git and blocked from direct web access by `.htaccess`.

Production requirements:

- Serve the app over HTTPS
- Allow browser notifications for the site
- Keep `manifest.json`, `service-worker.js`, and both icon files deployed

Browsers treat `localhost` as a secure context for development; real devices should use HTTPS.

## Deep links

Public-room message links use:

```text
messnger.html?room=<room-id>#msg=<message-id>
```

The client loads the room, jumps to the message when it becomes available, and briefly highlights it. The message action menu also provides a copy-link action.

## Tests

Run the suites against a disposable local instance, never production. The integration suites expect a local test administrator; they use `admin` / `admin123` and can create that account through the setup endpoint on an empty instance.

### SMTP

No web server is required:

```bash
cd "/d/projects/Johnson Chat"
/c/xampp/php/php.exe -c /c/xampp/php/php.ini tests/smtp-test.php
```

This starts a loopback SMTP sink and verifies AUTH LOGIN, the envelope, subject, and base64 body.

### HTTP, rules, replies, pins, PWA, and push crypto

Start the project-local Apache configuration in one terminal:

```bash
/c/xampp/apache/bin/httpd.exe \
  -f "D:/projects/Johnson Chat/tests/apache/httpd.conf" \
  -DFOREGROUND
```

Then run each suite from another terminal:

```bash
cd "/d/projects/Johnson Chat"

APP_URL=http://127.0.0.1:8080 \
  /c/xampp/php/php.exe -c /c/xampp/php/php.ini tests/apache-rules-test.php

APP_URL=http://127.0.0.1:8080 \
  /c/xampp/php/php.exe -c /c/xampp/php/php.ini tests/system-test.php

APP_URL=http://127.0.0.1:8080 \
  /c/xampp/php/php.exe -c /c/xampp/php/php.ini tests/features-test.php
```

Stop Apache with `Ctrl+C` when finished.

The harness listens on `127.0.0.1:8080`, writes its PID and logs under `tests/apache/`, and contains absolute paths to this checkout. Update `tests/apache/httpd.conf` if the project is cloned to a different path. The detailed harness notes are in `tests/apache/README.md`.

## Security notes

- Runtime data is excluded by `.gitignore` and blocked from direct access by `.htaccess`:
  - `api/data/`
  - `api/messages/`
  - `api/*.json` state files
  - `api/calls/`
  - `mail_log/`
  - `private_messages/`
  - `storage/`
  - `uploads/`
  - test logs and PIDs
- Never commit `api/mail_config.local.php`, `api/data/chat.db`, `api/data/vapid.json`, uploads, or mail logs.
- Back up the database and VAPID key securely; both contain sensitive data.
- Protect or remove `setup/` after installation.
- Use HTTPS in production and configure `TraceEnable Off` in the Apache virtual host. HSTS is emitted only when Apache sees `HTTPS=on`.
- Do not enable `MAIL_EXPOSE_LINKS` outside local development.
