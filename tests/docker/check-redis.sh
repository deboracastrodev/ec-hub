#!/bin/bash
# Smoke check for the Redis Compose service. PHP-level Predis verification is
# deliberately kept in connectivity-test.sh and RedisConnectivityTest.php.

set -euo pipefail

if ! docker compose ps --status running redis | grep -q redis; then
  echo "❌ Redis service is not running"
  exit 1
fi

if ! docker compose exec -T redis redis-cli ping | grep -qx 'PONG'; then
  echo "❌ Redis did not respond to PING"
  exit 1
fi

echo "✅ Redis service responds to PING"
