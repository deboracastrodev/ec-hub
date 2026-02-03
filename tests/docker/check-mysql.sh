#!/bin/bash
# Test ID: INFRA-P1-001
# Tags: @p1 @health-check @mysql
# MySQL health check script
# Wait for MySQL to be ready using environment variables

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

cd "$PROJECT_ROOT"

# Source environment variables
if [ -f .env ]; then
  source .env
else
  # Fallback to .env.example if .env doesn't exist
  if [ -f .env.example ]; then
    source .env.example
  fi
fi

# Use environment variables with defaults
DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-secret}"
DB_DATABASE="${DB_DATABASE:-ec_hub}"

echo "🔍 Checking MySQL connection..."
echo "  Host: ${DB_HOST}"
echo "  Port: ${DB_PORT}"
echo "  Database: ${DB_DATABASE}"

MAX_RETRIES=30
RETRY_COUNT=0

until docker exec ec-hub-mysql mysql -h"${DB_HOST}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "SELECT 1" > /dev/null 2>&1; do
  RETRY_COUNT=$((RETRY_COUNT + 1))
  if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
    echo "❌ MySQL health check timeout after ${MAX_RETRIES} attempts"
    exit 1
  fi
  echo "⏳ Waiting for MySQL... (${RETRY_COUNT}/${MAX_RETRIES})"
  sleep 2
done

echo "✅ MySQL is ready!"
exit 0
