#!/bin/sh
# Entry point of the app image (web server, scheduler and test runner).
#
# Laravel 4.2 decrypts and unserializes cookies with APP_KEY, so the key
# must be secret and different for every installation. When APP_KEY is not
# given in the environment, this script reads it from APP_KEY_FILE
# (default /var/lib/helpdesk/app_key), creating that file with 32 random
# characters the first time. In the compose stack the directory is the
# named volume app-secrets, shared by app and scheduler; a container
# without the volume (the test runner) gets a fresh key every run.
# Processes started with "docker compose exec" skip this script and read
# the same file through app/config/app.php.
set -eu

KEY_FILE=${APP_KEY_FILE:-/var/lib/helpdesk/app_key}

if [ -z "${APP_KEY:-}" ]; then
	if [ ! -s "$KEY_FILE" ]; then
		mkdir -p "$(dirname "$KEY_FILE")"
		umask 077
		tmp=$(mktemp "$KEY_FILE.XXXXXX")
		LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32 > "$tmp"
		# ln fails if another container created the key first; keep theirs.
		ln "$tmp" "$KEY_FILE" 2>/dev/null || true
		rm -f "$tmp"
	fi

	# Readable by the web server user too, for "docker compose exec -u
	# www-data app php artisan ..." (app/config/app.php reads the file).
	chown www-data:www-data "$KEY_FILE" 2>/dev/null || true

	APP_KEY=$(cat "$KEY_FILE")
	[ "${#APP_KEY}" -eq 32 ] || { echo "entrypoint: $KEY_FILE does not hold a 32-character key" >&2; exit 1; }
	export APP_KEY
fi

exec "$@"
