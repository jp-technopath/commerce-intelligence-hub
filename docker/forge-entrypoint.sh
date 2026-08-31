#!/bin/sh
set -eu

role="${FORGE_RUNTIME_ROLE:-web}"
proxy_pid=""

stop_proxy() {
    if [ -n "$proxy_pid" ]; then
        kill "$proxy_pid" 2>/dev/null || true
        wait "$proxy_pid" 2>/dev/null || true
    fi
}

stop_web() {
    if [ -n "${app_pid:-}" ]; then
        kill "$app_pid" 2>/dev/null || true
        wait "$app_pid" 2>/dev/null || true
    fi
    stop_proxy
}

run_and_cleanup() {
    "$@" &
    app_pid=$!
    trap 'stop_web; exit 0' INT TERM
    set +e
    wait "$app_pid"
    status=$?
    set -e
    stop_web
    exit "$status"
}

trap stop_proxy EXIT INT TERM

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ -n "${CLOUD_SQL_CONNECTION_NAME:-}" ]; then
    cloud-sql-proxy \
        --psc \
        --address=127.0.0.1 \
        --port="${DB_PORT:-5432}" \
        "${CLOUD_SQL_CONNECTION_NAME}" &
    proxy_pid=$!

    attempts=0
    until php -r '$socket = @fsockopen("127.0.0.1", (int) getenv("DB_PORT") ?: 5432); if ($socket === false) { exit(1); } fclose($socket);'; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge "${CLOUD_SQL_PROXY_STARTUP_ATTEMPTS:-30}" ]; then
            echo 'Cloud SQL Auth Proxy did not become ready.' >&2
            exit 70
        fi
        sleep 1
    done
fi

php artisan config:cache --no-interaction

case "$role" in
    web)
        trap - EXIT INT TERM
        port="${PORT:-8080}"
        sed -ri "s!Listen [0-9]+!Listen ${port}!" /etc/apache2/ports.conf
        sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${port}>!" /etc/apache2/sites-available/000-default.conf
        sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!' /etc/apache2/sites-available/000-default.conf
        run_and_cleanup apache2-foreground
        ;;
    queue)
        run_and_cleanup php artisan queue:work \
            --queue="${FORGE_QUEUE_NAMES:-default,syncs,meetings}" \
            --sleep="${FORGE_QUEUE_SLEEP_SECONDS:-3}" \
            --tries="${FORGE_QUEUE_TRIES:-3}" \
            --timeout="${FORGE_QUEUE_TIMEOUT_SECONDS:-900}" \
            --max-time="${FORGE_QUEUE_MAX_TIME_SECONDS:-300}" \
            --stop-when-empty \
            --no-interaction
        ;;
    scheduler)
        run_and_cleanup php artisan schedule:run --no-interaction
        ;;
    migrate)
        run_and_cleanup php artisan migrate --force --no-interaction
        ;;
    *)
        echo "Unsupported FORGE_RUNTIME_ROLE: ${role}" >&2
        exit 64
        ;;
esac
