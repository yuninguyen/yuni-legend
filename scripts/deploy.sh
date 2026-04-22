#!/usr/bin/env bash
# ============================================================
# RebateOps — Hostinger Deployment Script
# Chạy lần đầu: bash scripts/deploy.sh --fresh
# Chạy update:  bash scripts/deploy.sh
# ============================================================
set -e

APP_DIR="$HOME/rebateops"
REPO_URL="https://github.com/yuninguyen/RebateOps.git"
BRANCH="main"
PHP="php"   # Hostinger: đổi thành full path nếu cần, vd: /usr/bin/php8.2

# ── FIRST-TIME SETUP ────────────────────────────────────────
if [[ "$1" == "--fresh" ]]; then
    echo "==> Cloning repository..."
    git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
    cd "$APP_DIR"

    echo "==> Copying .env..."
    cp .env.production.example .env
    echo ""
    echo "!!! Mở file $APP_DIR/.env và điền DB_*, APP_KEY, GOOGLE_* trước khi tiếp tục."
    echo "!!! Sau đó chạy lại: bash scripts/deploy.sh"
    exit 0
fi

# ── DEPLOYMENT / UPDATE ──────────────────────────────────────
cd "$APP_DIR"

echo "==> Putting app in maintenance mode..."
$PHP artisan down --retry=30

echo "==> Pulling latest code..."
git pull origin "$BRANCH"

echo "==> Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Running database migrations..."
$PHP artisan migrate --force

echo "==> Clearing & rebuilding caches..."
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

echo "==> Linking storage..."
$PHP artisan storage:link --force 2>/dev/null || true

echo "==> Bringing app back online..."
$PHP artisan up

echo ""
echo "✓ Deploy complete."
echo "  App dir : $APP_DIR"
echo "  Public  : $APP_DIR/public  (set this as Document Root in hPanel)"
