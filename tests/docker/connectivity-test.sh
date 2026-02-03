#!/bin/bash
# Full connectivity test between app and services
# This script tests if app container can connect to MySQL and Redis

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

cd "$PROJECT_ROOT"

echo "🐢 Starting services for connectivity test..."
docker-compose up -d mysql redis app

echo "⏳ Waiting for services to be ready..."
sleep 10

echo ""
echo "🔍 Testing connectivity..."

# Test MySQL from app
echo "Testing MySQL connection from app..."
if docker exec ec-hub-app php -r "
try {
    \$pdo = new PDO('mysql:host=mysql;dbname=ec_hub', 'root', 'secret');
    echo '✅ MySQL connection successful\n';
    exit(0);
} catch (PDOException \$e) {
    echo '❌ MySQL connection failed: ' . \$e->getMessage() . '\n';
    exit(1);
}
" 2>/dev/null; then
  echo "✅ MySQL connectivity OK"
else
  echo "❌ MySQL connectivity FAILED"
fi

# Test Redis from app
echo "Testing Redis connection from app..."
if docker exec ec-hub-app php -r "
try {
    \$redis = new Redis();
    \$redis->connect('redis', 6379);
    echo '✅ Redis connection successful\n';
    exit(0);
} catch (Exception \$e) {
    echo '❌ Redis connection failed: ' . \$e->getMessage() . '\n';
    exit(1);
}
" 2>/dev/null; then
  echo "✅ Redis connectivity OK"
else
  echo "❌ Redis connectivity FAILED"
fi

echo ""
echo "🐢 Stopping services..."
docker-compose down

echo "✅ Connectivity test complete!"
exit 0
