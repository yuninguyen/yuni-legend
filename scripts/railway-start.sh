#!/usr/bin/env bash
# Web service startup script for Railway

set -e

# Write google-auth.json from env var (set in Railway dashboard)
if [ -n "$GOOGLE_SERVICE_ACCOUNT_JSON" ]; then
    echo "$GOOGLE_SERVICE_ACCOUNT_JSON" > storage/app/google-auth.json
    echo "[start] google-auth.json written from env"
fi

# Ensure storage is linked
php artisan storage:link --force 2>/dev/null || true

# Run migrations and seeders on every deploy
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force

# Start web server
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
