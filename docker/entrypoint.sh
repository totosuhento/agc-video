#!/bin/sh
# Entrypoint container: siapkan folder storage, atur port, jalankan cron internal, lalu Apache.
# Bisa berjalan sebagai user 1000 (NusaPod) maupun root.
set -e
APP=/var/www/html
ST="$APP/storage"

mkdir -p "$ST/sites" "$ST/sessions" 2>/dev/null || true

# Port Apache (konfigurasi memakai ${AGC_PORT}). Default 8080; hosting bisa menggantinya lewat variabel PORT.
export AGC_PORT="${PORT:-${AGC_PORT:-8080}}"
echo "[agc] Apache berjalan di port $AGC_PORT sebagai uid $(id -u)"

if [ "$(id -u)" = "0" ]; then
    # Root: storage milik user proses Apache (www-data), cron dijalankan sebagai user itu juga
    RUN_USER=$( . /etc/apache2/envvars 2>/dev/null; echo "${APACHE_RUN_USER:-www-data}" )
    chown -R "$RUN_USER":"$RUN_USER" "$ST" 2>/dev/null || chmod -R a+rwX "$ST" 2>/dev/null || true
    as_www() { su -s /bin/sh "$RUN_USER" -c "$1" 2>/dev/null || (umask 000; sh -c "$1"); }
else
    # Non-root (mis. NusaPod uid 1000): cukup pastikan storage bisa ditulis
    chmod -R u+rwX "$ST" 2>/dev/null || true
    as_www() { sh -c "$1"; }
fi

# Cron internal (tidak butuh crontab). Matikan dengan CRON_ENABLED=0
if [ "${CRON_ENABLED:-1}" = "1" ]; then
    (
        sleep 20
        while true; do
            as_www "/usr/local/bin/php $APP/cli/fetch.php" || true
            sleep "${FETCH_INTERVAL:-3600}"
        done
    ) &
    (
        sleep 180
        while true; do
            as_www "/usr/local/bin/php $APP/cli/enrich.php" || true
            sleep "${ENRICH_INTERVAL:-1800}"
        done
    ) &
fi

exec "$@"
