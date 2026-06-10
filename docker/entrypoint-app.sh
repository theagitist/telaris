#!/usr/bin/env bash
# app service entrypoint: render config, ensure writable data dirs, wait for the
# database, generate per-instance federation keys on first run, then start FPM.
set -euo pipefail

cd /var/www/html

# Fail fast on the truly-required settings (every instance needs its own DNS).
: "${TELARIS_HOSTNAME:?TELARIS_HOSTNAME is required (each Telaris instance must run on its own dedicated DNS name)}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASS:?DB_PASS is required}"

/usr/local/bin/render-config.sh

# Named-volume mounts start root-owned; the app runs as www-data.
# hg/content is the hotglue flat-file page store (one page per wormhole),
# written by the app at runtime; keep it on its own volume so pages persist.
mkdir -p uploads logs telaris-snapshots var secrets hg/content
chown www-data:www-data uploads logs telaris-snapshots var secrets hg/content

# Render the per-instance hotglue config (hg/user-config.inc.php). hotglue
# returns BASE_URL verbatim in asset links, so it must be the public https URL
# with a trailing slash. The Telaris auth bridge is the real authorization on
# the edit/json paths; AUTH_METHOD stays 'basic' with a fresh random password
# (never used, never shared) so hotglue's is_auth() is false for anonymous and
# unknown pages 404 instead of showing a create-page UI. USE_MIN_FILES=false
# serves the readable js/glue.js + js/edit.js that carry the CSRF-header patch.
# No secret in the image: this file is generated at container start.
HG_USER_CONFIG=/var/www/html/hg/user-config.inc.php
HG_PW="$(php -r 'echo bin2hex(random_bytes(24));')"
cat > "$HG_USER_CONFIG" <<PHP
<?php
@define('AUTH_METHOD', 'basic');
@define('AUTH_USER', 'telaris');
@define('AUTH_PASSWORD', '${HG_PW}');
@define('BASE_URL', 'https://${TELARIS_HOSTNAME}/hg/');
@define('CONTENT_DIR', '/var/www/html/hg/content');
@define('LOG_FILE', '/var/www/html/hg/content/log.txt');
@define('SHORT_URLS', false);
@define('SHOW_FRONTEND_ERRORS', false);
@define('USE_MIN_FILES', false);
error_reporting(0);
ini_set('display_errors', '0');
PHP
chown www-data:www-data "$HG_USER_CONFIG"
chmod 640 "$HG_USER_CONFIG"

# Wait for the database (bundled or external) before first-run setup.
echo "[entrypoint] waiting for database ${DB_HOST:-db}:${DB_PORT:-3306} ..."
for _ in $(seq 1 60); do
  if php -r '$o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
            if(getenv("DB_SSL_CA")){$o[PDO::MYSQL_ATTR_SSL_CA]=getenv("DB_SSL_CA");$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=false;}
            new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s",getenv("DB_HOST")?:"db",getenv("DB_PORT")?:"3306",getenv("DB_NAME")),getenv("DB_USER"),getenv("DB_PASS"),$o);' 2>/dev/null; then
    echo "[entrypoint] database reachable."; break
  fi
  sleep 2
done

# First-run federation identity + log-signing keys (per-instance, persisted in
# the secrets volume). Run as www-data so the 0600 keys are owned correctly.
if [ ! -f secrets/pluriverse.key ]; then
  echo "[entrypoint] minting federation identity key ..."
  su -s /bin/sh -c 'php bin/init-identity' www-data || echo "[entrypoint] WARN: init-identity failed"
fi
if [ ! -f secrets/log.key ]; then
  echo "[entrypoint] minting log-signing key ..."
  su -s /bin/sh -c 'php bin/init-log-key' www-data || echo "[entrypoint] WARN: init-log-key failed"
fi

# Schema builds lazily via db_ensure_* on first request; no migration step.
echo "[entrypoint] starting: $*"
exec "$@"
