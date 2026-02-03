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

# Check redis service
if ! grep -q "redis:" "$COMPOSE_FILE"; then
  echo "❌ FAIL: Missing 'redis' service"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Redis service found"
fi

# Check MySQL 8.x image
if ! grep -q "mysql:8" "$COMPOSE_FILE" && ! grep -q "mysql:8.0" "$COMPOSE_FILE"; then
  echo "❌ FAIL: MySQL service must use MySQL 8.x image"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ MySQL 8.x image found"
fi

# Check Redis 7.x image
if ! grep -q "redis:7" "$COMPOSE_FILE"; then
  echo "❌ FAIL: Redis service must use Redis 7.x image"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Redis 7.x image found"
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

# Check app service depends_on mysql and redis
if ! grep -A 20 "app:" "$COMPOSE_FILE" | grep -q "depends_on"; then
  echo "❌ FAIL: App service must depend on mysql and redis"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ App service dependencies found"
fi

if [ $ERRORS -gt 0 ]; then
  echo ""
  echo "❌ VALIDATION FAILED: $ERRORS error(s) found"
  exit 1
fi

echo ""
echo "✅ ALL VALIDATIONS PASSED"
exit 0
