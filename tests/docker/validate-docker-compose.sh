#!/bin/bash
# Validation script for docker-compose.yml
# This script validates the docker-compose.yml meets all requirements

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.yml"
ERRORS=0

echo "🔍 Validating docker-compose.yml..."

# Check if docker-compose.yml exists
if [ ! -f "$COMPOSE_FILE" ]; then
  echo "❌ FAIL: docker-compose.yml not found at $COMPOSE_FILE"
  exit 1
fi
echo "✅ docker-compose.yml exists"

# Check app service
if ! grep -q "app:" "$COMPOSE_FILE"; then
  echo "❌ FAIL: Missing 'app' service"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App service found"
fi

# Check mysql service
if ! grep -q "mysql:" "$COMPOSE_FILE"; then
  echo "❌ FAIL: Missing 'mysql' service"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ MySQL service found"
fi

# Check MySQL 8.x image
if ! grep -q "mysql:8" "$COMPOSE_FILE" && ! grep -q "mysql:8.0" "$COMPOSE_FILE"; then
  echo "❌ FAIL: MySQL service must use MySQL 8.x image"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ MySQL 8.x image found"
fi

redis_service=$(awk '/^  redis:/{in_redis=1; next} in_redis && /^  [[:alnum:]_-]+:/{exit} in_redis {print}' "$COMPOSE_FILE")

if [ -z "$redis_service" ] || ! printf '%s\n' "$redis_service" | grep -qx '    image: redis:7-alpine'; then
  echo "❌ FAIL: Redis service must use redis:7-alpine"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Redis 7 Alpine service found"
fi

if ! printf '%s\n' "$redis_service" | grep -q 'redis-cli.*ping'; then
  echo "❌ FAIL: Redis service must define a redis-cli ping healthcheck"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Redis healthcheck found"
fi

# Check network configuration
if ! grep -q "networks:" "$COMPOSE_FILE"; then
  echo "❌ FAIL: Missing network configuration"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Network configuration found"
fi

# Check volumes configuration
if ! grep -q "volumes:" "$COMPOSE_FILE"; then
  echo "❌ FAIL: Missing volumes configuration"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Volumes configuration found"
fi

# Check app service depends on MySQL and Redis
app_service=$(awk '/^  app:/{in_app=1; next} in_app && /^  [[:alnum:]_-]+:/{exit} in_app {print}' "$COMPOSE_FILE")

if ! printf '%s\n' "$app_service" | grep -q "mysql:"; then
  echo "❌ FAIL: App service must depend on mysql"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App MySQL dependency found"
fi

if ! printf '%s\n' "$app_service" | grep -q "depends_on"; then
  echo "❌ FAIL: App service must depend on mysql"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App service dependencies found"
fi

if ! printf '%s\n' "$app_service" | grep -q 'redis:'; then
  echo "❌ FAIL: App service must depend on redis"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App Redis dependency found"
fi

if ! printf '%s\n' "$app_service" | grep -q 'SESSION_TTL=${SESSION_TTL:-1800}'; then
  echo "❌ FAIL: App service must allow SESSION_TTL override with a default of 1800"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App SESSION_TTL override found"
fi

if ! printf '%s\n' "$app_service" | grep -q 'SESSION_COOKIE_SECRET=${SESSION_COOKIE_SECRET:?SESSION_COOKIE_SECRET must be configured}'; then
  echo "❌ FAIL: App service must require SESSION_COOKIE_SECRET"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App SESSION_COOKIE_SECRET requirement found"
fi

if ! printf '%s\n' "$app_service" | grep -A 2 '^      mysql:' | grep -q 'condition: service_healthy'; then
  echo "❌ FAIL: App MySQL dependency must wait for service_healthy"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App MySQL health dependency found"
fi

if ! printf '%s\n' "$app_service" | grep -A 2 '^      redis:' | grep -q 'condition: service_healthy'; then
  echo "❌ FAIL: App Redis dependency must wait for service_healthy"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App Redis health dependency found"
fi

if [ $ERRORS -gt 0 ]; then
  echo ""
  echo "❌ VALIDATION FAILED: $ERRORS error(s) found"
  exit 1
fi

echo ""
echo "✅ ALL VALIDATIONS PASSED"
exit 0
