#!/bin/sh
set -eu

cd /var/www/html

fail() {
    printf 'Startup error: %s\n' "$1" >&2
    exit 1
}

enabled() {
    case "$1" in
        true|TRUE|1|yes|YES|on|ON) return 0 ;;
        *) return 1 ;;
    esac
}

PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*) fail 'PORT must be an integer between 1 and 65535.' ;;
esac
[ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || fail 'PORT must be between 1 and 65535.'

[ -n "${APP_KEY:-}" ] || fail 'Set APP_KEY in the service environment before deployment.'

if [ "${APP_ENV:-production}" = 'production' ]; then
    case "${APP_DEBUG:-false}" in
        false|FALSE|False|'(false)'|'(FALSE)'|'(False)'|0|'') ;;
        *) fail 'APP_DEBUG must be false in production.' ;;
    esac
fi

if [ "${APP_ENV:-production}" = 'production' ] && [ "${DB_CONNECTION:-mysql}" = 'sqlite' ]; then
    printf '%s\n' 'SQLite is ephemeral on Render Free; ticket and inventory stored procedures require MySQL.' >&2
fi

certificate="${MYSQL_ATTR_SSL_CA:-${DB_SSL_CA:-}}"
if [ -n "$certificate" ]; then
    certificate_setting='DB_SSL_CA'
    if [ -n "${MYSQL_ATTR_SSL_CA:-}" ]; then
        certificate_setting='MYSQL_ATTR_SSL_CA'
    fi

    [ -f "$certificate" ] || fail "The database CA file configured by $certificate_setting does not exist. Add the matching Render Secret File and use /etc/secrets/<filename>."
    [ -r "$certificate" ] || fail "The database CA file configured by $certificate_setting is unreadable by the startup process."
    [ -s "$certificate" ] || fail "The database CA file configured by $certificate_setting is empty. Paste the complete PEM certificate into the Render Secret File."

    # Render secret mounts may not be readable by www-data. Stage only this CA
    # at runtime, outside the web root, without changing the mounted secret.
    if ! su-exec www-data test -r "$certificate"; then
        mkdir -p /run/ticketing-mysql-ca
        chown root:www-data /run/ticketing-mysql-ca
        chmod 0750 /run/ticketing-mysql-ca
        application_certificate="$(mktemp /run/ticketing-mysql-ca/ca.XXXXXX)"
        cp -- "$certificate" "$application_certificate" || fail 'Could not prepare the database CA certificate for the application user.'
        chown root:www-data "$application_certificate"
        chmod 0640 "$application_certificate"
        su-exec www-data test -r "$application_certificate" || fail 'The runtime database CA certificate is unreadable by the application user.'

        # All Artisan commands, PHP-FPM and background processes inherit this.
        export DB_SSL_CA="$application_certificate"
        export MYSQL_ATTR_SSL_CA="$application_certificate"
        printf '%s\n' 'Prepared the database CA certificate for application processes.'
    fi
fi

# These paths contain only runtime data. Existing SQLite files are untouched.
mkdir -p bootstrap/cache storage/app/private storage/app/public \
    storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    /tmp/nginx-client-body /tmp/nginx-fastcgi /tmp/nginx-proxy /tmp/nginx-uwsgi /tmp/nginx-scgi
chown -R www-data:www-data bootstrap/cache storage \
    /tmp/nginx-client-body /tmp/nginx-fastcgi /tmp/nginx-proxy /tmp/nginx-uwsgi /tmp/nginx-scgi
chmod -R ug+rwX bootstrap/cache storage

# Supervisor and Nginx reopen their standard log streams after dropping UID.
# Render can supply root-owned pipes, so prepare only these inherited streams
# while startup still has permission to change their ownership.
for runtime_stream in /proc/self/fd/1 /proc/self/fd/2; do
    if ! su-exec www-data test -w "$runtime_stream"; then
        chown www-data:www-data "$runtime_stream" || fail 'Could not prepare application stdout/stderr for the runtime user.'
    fi
    su-exec www-data test -w "$runtime_stream" || fail 'Application stdout/stderr is not writable by the runtime user.'
done

case "${RENDER_GIT_COMMIT:-}" in
    ''|*[!0-9a-fA-F]*) ;;
    *) printf 'Starting application revision %.12s\n' "$RENDER_GIT_COMMIT" ;;
esac

for feature in RUN_REVERB RUN_SCHEDULER RUN_QUEUE_WORKER; do
    # Only a fixed list of variable names is read; no input is executed as code.
    value="$(printenv "$feature" || true)"
    if enabled "$value"; then
        export "$feature=true"
    else
        export "$feature=false"
    fi
done

if [ "${BROADCAST_CONNECTION:-}" = 'reverb' ] && [ "$RUN_REVERB" = 'false' ]; then
    printf '%s\n' 'Reverb publishing is configured. Start an external Reverb service or set RUN_REVERB=true.' >&2
fi
if [ "$RUN_SCHEDULER" = 'false' ]; then
    printf '%s\n' 'SLA processing and automatic ticket urgency require an external scheduler or RUN_SCHEDULER=true.' >&2
fi
case "${QUEUE_CONNECTION:-sync}" in
    sync|null) ;;
    *)
        if [ "$RUN_QUEUE_WORKER" = 'false' ]; then
            printf '%s\n' 'The queue connection requires an external worker or RUN_QUEUE_WORKER=true.' >&2
        fi
        ;;
esac

sed "s/__PORT__/$PORT/g" /var/www/html/docker/nginx-site.conf.template > /etc/nginx/http.d/default.conf
su-exec www-data nginx -e stderr -t
su-exec www-data php-fpm -t

su-exec www-data php artisan package:discover --no-interaction
su-exec www-data php artisan config:cache --no-interaction

# Explicit opt-in only. No seed, refresh, fresh, rollback, or migration in builds.
if enabled "${RUN_MIGRATIONS:-false}"; then
    printf '%s\n' 'RUN_MIGRATIONS is enabled: running pending migrations.'
    su-exec www-data php artisan migrate --force --no-interaction
fi

su-exec www-data php artisan app:check-storage --no-interaction
su-exec www-data php artisan route:cache --no-interaction
su-exec www-data php artisan view:cache --no-interaction

# A shared UID lets Supervisor stop web and background process groups without
# CAP_KILL, which managed runtimes may remove even from container root.
exec su-exec www-data "$@"
