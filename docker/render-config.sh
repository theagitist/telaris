#!/usr/bin/env bash
# Render /var/www/html/config.php for a containerized Telaris instance.
# The file reads every value from the environment via getenv() at runtime, so
# NO secret is ever written into config.php (defence for the "no PII/secrets in
# the image" guarantee). Shared by the app and cron entrypoints.
set -euo pipefail

CONFIG=/var/www/html/config.php

# Static content (single-quoted heredoc => no shell interpolation, no escaping
# hazards with passwords that contain quotes/backslashes).
cat > "$CONFIG" <<'PHP'
<?php
declare(strict_types=1);
// GENERATED at container start. Do not edit; configure via environment (.env).
// Values are read from the environment at runtime; this file holds no secrets.
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'telaris');
define('DB_USER', getenv('DB_USER') ?: 'telaris');
define('DB_PASS', getenv('DB_PASS') ?: '');
if (getenv('DB_SSL_CA')) {
    // External managed DB over TLS: getDB() adds sslmode=verify-ca with this CA
    // as sslrootcert, validating the chain without hostname verification
    // (managed-cluster cert CN != endpoint host).
    define('DB_SSL_CA', getenv('DB_SSL_CA'));
}
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('LOG_DIR', __DIR__ . '/logs');
define('SNAPSHOTS_DIR', __DIR__ . '/telaris-snapshots');
define('TELARIS_HOSTNAME', getenv('TELARIS_HOSTNAME') ?: '');
define('MAIL_SMTP_HOST', getenv('MAIL_SMTP_HOST') ?: '');
define('MAIL_SMTP_PORT', (int)(getenv('MAIL_SMTP_PORT') ?: 587));
define('MAIL_SMTP_USER', getenv('MAIL_SMTP_USER') ?: '');
define('MAIL_SMTP_PASS', getenv('MAIL_SMTP_PASS') ?: '');
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: '');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Telaris');
define('MAIL_SMTP_SECURE', getenv('MAIL_SMTP_SECURE') ?: 'tls');
// Comma-separated bridge names, e.g. TELARIS_BRIDGES=mocambos
define('TELARIS_BRIDGES', array_values(array_filter(array_map('trim', explode(',', getenv('TELARIS_BRIDGES') ?: '')))));
require_once __DIR__ . '/inc/db.php';
PHP

chown www-data:www-data "$CONFIG"
chmod 640 "$CONFIG"
