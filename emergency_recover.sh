#!/bin/bash
# ==============================================================================
# LUXQUOTE.APP - EMERGENCY STACK RECOVERY & REFRESH SCRIPT
# Safety Level: HIGH (Preserves persistent storage volumes)
# ==============================================================================

APP_DIR="/home/tamliteco/luxquote.app"
RESTORE_SCRIPT="$APP_DIR/luxquote_restore_to_last_deploy.sh"
TARGET_URL="https://quote.tamlite.co.uk"

echo "🚨 Starting emergency stack recovery for Luxquote..."

# 1. Navigate to the application root
cd "$APP_DIR" || { echo "❌ Error: Cannot access directory $APP_DIR"; exit 1; }

# 2. Stop containers cleanly to release stuck network ports and bindings
echo "🛑 Gracefully tearing down stuck containers..."
docker compose down

# 3. Spin containers back up and force Docker to re-evaluate the environment
echo "🚀 Booting containers back up freshly..."
docker compose up -d --force-recreate

# 4. Give the MySQL container an absolute buffer to initialize its internal engine
echo "⏳ Waiting 10 seconds for the database engine to warm up..."
sleep 10

# 5. Purge Laravel's compiled config and route caches
echo "⚡ Flushing Laravel optimization caches..."
docker compose exec -T laravel.test php artisan optimize:clear

# 6. Verify if the web application is responding normally
echo "🔍 Testing application health via $TARGET_URL..."
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$TARGET_URL")

# Accept 302 (Redirect) or 200 (OK) as healthy working states
if [ "$STATUS" -eq 302 ] || [ "$STATUS" -eq 200 ]; then
    echo "✅ Success: Luxquote is completely stable and responding with HTTP $STATUS!"
else
    echo "⚠️  Warning: Infrastructure is running, but the site returned unexpected HTTP $STATUS."
    echo "🔄 Initiating automated database data restore fallback..."
    
    if [ -f "$RESTORE_SCRIPT" ]; then
        bash "$RESTORE_SCRIPT"
    else
        echo "❌ Error: Database restore script missing at $RESTORE_SCRIPT"
        exit 1
    fi
fi
