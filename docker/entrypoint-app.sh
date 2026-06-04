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
mkdir -p uploads logs telaris-snapshots var secrets
chown www-data:www-data uploads logs telaris-snapshots var secrets

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
