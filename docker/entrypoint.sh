#!/bin/sh
# Spouštěcí skript kontejneru klienta. Role = první argument (web | worker | heard),
# ostatní argumenty se předají příkazu (např. „user add admin“ → php bin/user.php add admin).
set -e
cd /app
ROLE="${1:-web}"
export MC_ROLE="$ROLE"

wait_db() {
    i=0
    until php -r 'require "src/bootstrap.php"; db()->value("SELECT 1");' >/dev/null 2>&1; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "Databáze není dostupná ani po 2 minutách – zkontroluj MC_DB_* proměnné." >&2
            php -r 'require "src/bootstrap.php"; db()->value("SELECT 1");' || true
            exit 1
        fi
        echo "Čekám na databázi…"
        sleep 2
    done
}

case "$ROLE" in
    web)
        wait_db
        php bin/migrate.php
        mkdir -p data/backgrounds && chown -R www-data:www-data data
        exec apache2-foreground
        ;;
    worker)
        wait_db
        php bin/migrate.php >/dev/null
        exec su -s /bin/sh www-data -c 'exec php bin/worker.php'
        ;;
    heard)
        wait_db
        php bin/migrate.php >/dev/null
        while true; do
            su -s /bin/sh www-data -c 'php bin/heard.php' || true
            sleep 5
        done
        ;;
    migrate)
        wait_db
        exec php bin/migrate.php
        ;;
    user)
        shift
        wait_db
        exec php bin/user.php "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
