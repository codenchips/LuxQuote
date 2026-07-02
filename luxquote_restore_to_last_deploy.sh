#!/bin/bash

# 1. Navigate to the app directory
cd /home/tamliteco/luxquote.app || exit 1

# 2. Find the absolute newest backup file matching the pattern
LATEST_BACKUP=$(ls -1t backups/*.sql.gz 2>/dev/null | head -n 1)

if [ -z "$LATEST_BACKUP" ]; then
    echo "❌ Error: No backup files found in backups/ matching pre-deploy-*.sql.gz"
    exit 1
fi

echo "🔄 Found latest backup: $LATEST_BACKUP"

# 3. Read DB credentials directly from your production .env file
if [ ! -f .env ]; then
    echo "❌ Error: .env file missing. Cannot extract database configuration."
    exit 1
fi

DB_DATABASE=$(grep '^DB_DATABASE=' .env | cut -d '=' -f2 | tr -d '\r"'\')
DB_USERNAME=$(grep '^DB_USERNAME=' .env | cut -d '=' -f2 | tr -d '\r"'\')
DB_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d '=' -f2 | tr -d '\r"'\')

echo "ℹ️  Preparing to restore into database: '$DB_DATABASE' using user: '$DB_USERNAME'..."

# 4. Stream the gzipped file directly into the MySQL container
zcat "$LATEST_BACKUP" | docker compose exec -T mysql mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"

# 5. Check if the MySQL import command completed successfully ($?)
if [ $? -eq 0 ]; then
    echo "✅ Success: Database successfully restored from $(basename "$LATEST_BACKUP")!"
    
    # 6. Flush Laravel caches so it immediately recognizes the restored data sessions
    echo "⚡ Flushing Laravel application optimization caches..."
    docker compose exec -T laravel.test php artisan optimize:clear
else
    echo "❌ Error: The database restore pipeline failed."
    exit 1
fi

