#!/usr/bin/env bash
# Queue worker startup script for Railway

set -e

# Write google-auth.json from env var (same as web service)
if [ -n "$GOOGLE_SERVICE_ACCOUNT_JSON" ]; then
    echo "$GOOGLE_SERVICE_ACCOUNT_JSON" > storage/app/google-auth.json
fi

exec php artisan queue:work \
    --tries=3 \
    --backoff=60 \
    --max-time=3600 \
    --sleep=3
