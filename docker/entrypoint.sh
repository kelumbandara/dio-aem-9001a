#!/bin/sh
# Container entrypoint. Usage: entrypoint <role>
#   web        nginx + php-fpm (port 8080)
#   worker     php artisan horizon      (queue; needs Redis)
#   scheduler  php artisan schedule:work
#   migrate    php artisan migrate --force, then exit (run as the pre-deploy step, AFTER a database backup)
#   artisan …  any artisan command, e.g. `entrypoint artisan app:preflight`
set -eu

cd /var/www/html
role="${1:-web}"

# Fail fast: a wrongly configured production deployment must not start (docs/architecture/deployment.md §6).
# SKIP_PREFLIGHT=true exists for building images and emergencies; it is logged.
if [ "${SKIP_PREFLIGHT:-false}" = "true" ]; then
    echo "[entrypoint] WARNING: preflight skipped" >&2
elif [ "$role" = "migrate" ]; then
    # The migrate step is what brings the database up to date, so migrations still to run are expected here.
    php artisan app:preflight --allow-pending-migrations || { echo "[entrypoint] preflight failed: not starting" >&2; exit 1; }
elif [ "$role" != "artisan" ]; then
    php artisan app:preflight || { echo "[entrypoint] preflight failed: not starting" >&2; exit 1; }
fi

# Cache configuration, routes, views and events (faster start and requests). Needs the real environment, so it runs here, not at build time.
if [ "$role" != "artisan" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

case "$role" in
    web)
        exec supervisord -c /etc/supervisord.conf
        ;;
    worker)
        exec php artisan horizon
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    migrate)
        exec php artisan migrate --force
        ;;
    artisan)
        shift
        exec php artisan "$@"
        ;;
    *)
        echo "[entrypoint] unknown role: $role" >&2
        exit 2
        ;;
esac
