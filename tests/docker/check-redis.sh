#!/bin/bash
# Test ID: INFRA-P1-002
# Tags: @p1 @health-check @redis
# Redis health check script
# Wait for Redis to be ready using environment variables

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
REDIS_HOST="${REDIS_HOST:-redis}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_AUTH="${REDIS_AUTH:-null}"

echo "🔍 Checking Redis connection..."
echo "  Host: ${REDIS_HOST}"
echo "  Port: ${REDIS_PORT}"

MAX_RETRIES=30
RETRY_COUNT=0

# Build redis-cli command with optional auth
if [ "$REDIS_AUTH" != "null" ] && [ -n "$REDIS_AUTH" ]; then
  REDIS_CMD="docker exec ec-hub-redis redis-cli -h ${REDIS_HOST} -p ${REDIS_PORT} -a ${REDIS_AUTH} ping"
else
  REDIS_CMD="docker exec ec-hub-redis redis-cli -h ${REDIS_HOST} -p ${REDIS_PORT} ping"
fi

until eval "$REDIS_CMD" > /dev/null 2>&1; do
  RETRY_COUNT=$((RETRY_COUNT + 1))
  if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
    echo "❌ Redis health check timeout after ${MAX_RETRIES} attempts"
    exit 1
  fi
  echo "⏳ Waiting for Redis... (${RETRY_COUNT}/${MAX_RETRIES})"
  sleep 2
done

echo "✅ Redis is ready!"
exit 0
