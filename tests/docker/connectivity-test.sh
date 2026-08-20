#!/bin/bash
# Test ID: INFRA-P0-001
# Tags: @p0 @smoke @connectivity
# Full connectivity test between app and services
# This script tests if app container can connect to MySQL

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

cd "$PROJECT_ROOT"

# Track test failures
MYSQL_PASSED=false

# Cleanup trap - always runs regardless of success/failure
cleanup() {
  echo ""
  echo "🐢 Stopping services..."
  docker compose down -v --remove-orphans || true
}
trap cleanup EXIT INT TERM

echo "🧪 Test ID: INFRA-P0-001 - Container Connectivity"
echo "====================================================="
echo ""

echo "🐢 Starting services for connectivity test..."
docker compose up -d mysql app

echo ""
echo "⏳ Waiting for services to be ready (deterministic health checks)..."

# Use deterministic health checks instead of fixed sleep
# Source environment variables for credentials
if [ -f .env ]; then
  source .env
else
  echo "⚠️  Warning: .env file not found, using default values from .env.example"
  source .env.example
fi

# Wait for MySQL with health check
MAX_RETRIES=30
RETRY_COUNT=0
echo "Waiting for MySQL to be healthy..."
until docker exec ec-hub-mysql mysql -h"${DB_HOST:-localhost}" -u"${DB_USERNAME:-root}" -p"${DB_PASSWORD:-secret}" -e "SELECT 1" > /dev/null 2>&1; do
  RETRY_COUNT=$((RETRY_COUNT + 1))
  if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
    echo "❌ MySQL health check timeout after ${MAX_RETRIES} attempts"
    cleanup
    exit 1
  fi
  echo "  Attempt ${RETRY_COUNT}/${MAX_RETRIES}..."
  sleep 2
done
echo "✅ MySQL is ready!"

# Wait for app container to be ready
RETRY_COUNT=0
echo "Waiting for app container to be ready..."
until docker exec ec-hub-app php -r "exit(0);" > /dev/null 2>&1; do
  RETRY_COUNT=$((RETRY_COUNT + 1))
  if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
    echo "❌ App container health check timeout after ${MAX_RETRIES} attempts"
    cleanup
    exit 1
  fi
  echo "  Attempt ${RETRY_COUNT}/${MAX_RETRIES}..."
  sleep 2
done
echo "✅ App container is ready!"

echo ""
echo "🔍 Testing connectivity..."

# Test MySQL from app
echo "Testing MySQL connection from app..."
if docker exec ec-hub-app php -r "
try {
    \$pdo = new PDO('mysql:host=${DB_HOST:-mysql};dbname=${DB_DATABASE:-ec_hub}', '${DB_USERNAME:-root}', '${DB_PASSWORD:-secret}');
    echo '✅ MySQL connection successful\n';
    exit(0);
} catch (PDOException \$e) {
    echo '❌ MySQL connection failed: ' . \$e->getMessage() . '\n';
    exit(1);
}
" 2>/dev/null; then
  echo "✅ MySQL connectivity OK"
  MYSQL_PASSED=true
else
  echo "❌ MySQL connectivity FAILED"
fi

echo ""
echo "====================================================="
echo "📊 Test Result Summary"
echo "====================================================="

if [ "$MYSQL_PASSED" = true ]; then
  echo -e "✅ ALL CONNECTIVITY TESTS PASSED!"
  echo ""
  echo "Results:"
  echo "  MySQL:  ✅ PASS"
  exit 0
else
  echo -e "❌ CONNECTIVITY TESTS FAILED!"
  echo ""
  echo "Results:"
  echo "  MySQL:  ❌ FAIL"
  echo ""
  echo "Failure: MySQL is unreachable from app container"
  exit 1
fi
