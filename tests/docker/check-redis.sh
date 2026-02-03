#!/bin/bash
# Redis health check script
# Wait for Redis to be ready

echo "🔍 Checking Redis connection..."

until docker exec ec-hub-redis redis-cli ping > /dev/null 2>&1; do
  echo "⏳ Waiting for Redis..."
  sleep 2
done

echo "✅ Redis is ready!"
exit 0
