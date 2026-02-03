#!/bin/bash
# Validation script for .env.example
# This script validates the .env.example meets all requirements

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
ENV_EXAMPLE="$PROJECT_ROOT/.env.example"
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.yml"
ERRORS=0

echo "🔍 Validating .env.example..."

# Check if .env.example exists
if [ ! -f "$ENV_EXAMPLE" ]; then
  echo "❌ FAIL: .env.example not found at $ENV_EXAMPLE"
  exit 1
fi
echo "✅ .env.example exists"

# Check required variables
REQUIRED_VARS=(
  "APP_ENV"
  "APP_DEBUG"
  "DB_HOST"
  "DB_PORT"
  "DB_DATABASE"
  "DB_USERNAME"
  "DB_PASSWORD"
  "REDIS_HOST"
  "REDIS_PORT"
  "SWOOLE_HTTP_SERVER_PORT"
)

for var in "${REQUIRED_VARS[@]}"; do
  if ! grep -q "^${var}=" "$ENV_EXAMPLE"; then
    echo "❌ FAIL: Missing variable ${var}"
    ERRORS=$((ERRORS + 1))
  else
    echo "✅ Variable ${var} found"
  fi
done

# Check APP_ENV=local
if ! grep -q "^APP_ENV=local" "$ENV_EXAMPLE"; then
  echo "❌ FAIL: APP_ENV must be set to 'local'"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ APP_ENV=local configured"
fi

# Check database credentials
if ! grep -q "MYSQL_ROOT_PASSWORD" "$COMPOSE_FILE"; then
  echo "❌ FAIL: MySQL credentials not configured in docker-compose.yml"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ MySQL credentials found in docker-compose.yml"
fi

# Check Redis configuration
if ! grep -q "REDIS_HOST=redis" "$ENV_EXAMPLE"; then
  echo "❌ FAIL: REDIS_HOST must be set to 'redis' (service name)"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ REDIS_HOST=redis configured"
fi

if [ $ERRORS -gt 0 ]; then
  echo ""
  echo "❌ VALIDATION FAILED: $ERRORS error(s) found"
  exit 1
fi

echo ""
echo "✅ ALL VALIDATIONS PASSED"
exit 0
