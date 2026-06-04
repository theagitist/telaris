#!/usr/bin/env bash
# cron service: run the federation schedulers on a simple in-container loop.
#
# We use a loop rather than system cron because the jobs read DB_*/MAIL_* via
# getenv(), and system cron strips the environment. The loop inherits the full
# container env, and runs each job as www-data so any files it writes are owned
# correctly. Cadence mirrors etc/cron.d/*.sample (dispatch ~1m; pulls ~5m).
set -euo pipefail

cd /var/www/html

: "${TELARIS_HOSTNAME:?TELARIS_HOSTNAME is required}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASS:?DB_PASS is required}"

/usr/local/bin/render-config.sh
mkdir -p uploads logs telaris-snapshots var secrets
chown www-data:www-data uploads logs telaris-snapshots var secrets

run() { su -s /bin/sh -c "php $1" www-data >/dev/null 2>&1 || true; }

echo "[cron] scheduler loop started (dispatch ~60s; pulls ~5m)"
i=0
while true; do
  run "bin/pluriverse-dispatch"
  if [ $(( i % 5 )) -eq 0 ]; then
    run "bin/pluriverse-pull --endpoint=all"
    run "bin/galaxy-pull"
  fi
  i=$(( (i + 1) % 60 ))
  sleep 60
done
