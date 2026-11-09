# Apache integration test harness

This directory contains a project-local Apache config so the `.htaccess` rules
and response headers can be tested without changing XAMPP's global config.

## Run

```bash
/c/xampp/apache/bin/httpd.exe -f "D:/projects/Johnson Chat/tests/apache/httpd.conf" -DFOREGROUND
```

In a second shell:

```bash
APP_URL=http://127.0.0.1:8080 /c/xampp/php/php.exe tests/apache-rules-test.php
APP_URL=http://127.0.0.1:8080 /c/xampp/php/php.exe tests/system-test.php
```

Stop the first shell with Ctrl+C.

## Requirements for a real deployment

- `AllowOverride All` on the document root (the method block uses `Require`).
- `mod_alias` (for `RedirectMatch`), `mod_headers`, and `mod_authz_core`.
- HTTPS if you want the HSTS header: it is emitted only when Apache sets
  `HTTPS=on`, so plain-HTTP development does not send it.
- `TraceEnable Off` in the virtual host. Apache handles TRACE in core before
  per-directory config, so `.htaccess` cannot disable it.

## What is blocked

`.git/`, `.freebuff/`, `api/data/`, `api/messages/`, `api/*.json`,
`api/debug_log.txt`, `logs/`, `mail_log/` (reset links), `private_messages/`,
`storage/`, `uploads/`, `webalizer/`, `tests/`, and `.ht*` files.

`api/setup.php` locks itself once an active administrator exists. Delete
`api/data/chat.db` or remove the administrator only when you intentionally want
to run the installer again.

## Notes

- The local config loads `C:/xampp/php/brotlidec.dll` before PHP because
  `php_curl.dll` imports it and Apache's `bin` directory ships only
  `brotlicommon.dll`.
- CSP allows inline styles because the standalone reset/privacy/terms pages use
  a small inline `<style>` block. Scripts are external-only.
- Logs from the harness are written here; remove `*.log` after debugging.
