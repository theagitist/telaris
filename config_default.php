<?php
declare(strict_types=1);

// Database configuration
define('DB_HOST', '');
define('DB_PORT', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('UPLOAD_DIR', __DIR__ . '/uploads'); // In production, use an absolute path outside the app directory
define('LOG_DIR', __DIR__ . '/logs');
define('SNAPSHOTS_DIR', __DIR__ . '/telaris-snapshots'); // Where local system snapshots are stored. In production, use an absolute path outside the app directory and prefix with the site name (e.g. /var/backups/starmaps-snapshots) so it is not mistaken for another app's backups.
define('QUOTA_BYTES', 0); // Per-instance storage quota in bytes; 0 = unlimited. Containerized self-service instances set this from TELARIS_QUOTA_BYTES; standalone installs leave it 0.

// Canonical public hostname for this instance (no scheme, no trailing slash),
// e.g. 'starmaps.polivoxia.ca'. Used to build absolute links in outgoing email
// (so they point at the right action, not the request Host header, which is
// attacker-controllable and is empty when mail is sent from the CLI/cron) and
// as the federation identity host. setup.php auto-fills this from the hostname
// you install under; leave blank only if you cannot set it (the code then falls
// back to the cached request host, then the OS hostname).
define('TELARIS_HOSTNAME', '');

// Outgoing mail (Mailgun or any SMTP relay). Required for password-reset emails and
// bulk-user-creation account invitations. Leave blank to disable mail features.
// Use STARTTLS on port 25 or 587 (recommended); 465 uses implicit SSL.
// NOTE: these MAIL_* values (and TELARIS_HOSTNAME above) can also be set from the
// admin Global Settings page, which stores them in the database and takes
// precedence over the constants here. Editing them in the UI avoids the config.php
// file-permission hazard; the constants below remain the first-run seed + fallback.
define('MAIL_SMTP_HOST', '');           // e.g. smtp.mailgun.org or smtp.eu.mailgun.org
define('MAIL_SMTP_PORT', 587);          // 25 / 587 = STARTTLS, 465 = implicit SSL
define('MAIL_SMTP_USER', '');           // SMTP login (often postmaster@<your-mailgun-domain>)
define('MAIL_SMTP_PASS', '');           // SMTP credential from the Mailgun dashboard
define('MAIL_FROM_ADDRESS', '');        // e.g. no-reply@your-verified-domain
define('MAIL_FROM_NAME', '');           // e.g. Telaris
// MAIL_SMTP_SECURE: 'tls' (STARTTLS), 'ssl' (implicit SSL), or '' (no encryption — not recommended).
define('MAIL_SMTP_SECURE', 'tls');

// Bridges. Each entry is the name of a handler in inc/bridges/{name}.php and
// enables the matching "Import from..." surface in admin. Default empty.
// Add a bridge name (e.g. define('TELARIS_BRIDGES', ['some-bridge'])) to
// enable that bridge's admin button, modal, JS, and CLI on this instance.
define('TELARIS_BRIDGES', []);

require_once __DIR__ . '/inc/db.php';
