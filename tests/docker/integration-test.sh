#!/bin/bash
# Test ID: INFRA-P1-003
# Tags: @p1 @integration @docker
# Full integration test for docker-compose infrastructure
# This validates AC #1 completely with real container-to-container communication

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
cd "$PROJECT_ROOT"

echo "🧪 Test ID: INFRA-P1-003 - Docker Compose Integration"
echo "====================================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Helper function
run_test() {
  local test_name="$1"
  local test_command="$2"

  echo -n "Testing: $test_name... "

  if eval "$test_command" > /dev/null 2>&1; then
    echo -e "${GREEN}PASS${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
    return 0
  else
    echo -e "${RED}FAIL${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    return 1
  fi
}

# Cleanup trap for containers
cleanup_containers() {
  if [ "$CONTAINERS_STARTED" = true ]; then
    echo ""
    echo "🐢 Cleaning up test containers..."
    docker compose down -v --remove-orphans || true
  fi
}
trap cleanup_containers EXIT INT TERM

CONTAINERS_STARTED=false

# Test 1: Dockerfile exists and is valid
echo "📋 Test Group 1: Dockerfile Validation"
run_test "Dockerfile exists" "[ -f Dockerfile ]"
run_test "Dockerfile uses PHP 8.4-cli" "grep -q 'FROM php:8.4-cli' Dockerfile"
run_test "Dockerfile has Composer" "grep -q 'composer' Dockerfile"
echo ""

# Test 2: docker-compose.yml exists and is valid
echo "📋 Test Group 2: Docker Compose Validation"
run_test "docker-compose.yml exists" "[ -f docker-compose.yml ]"
run_test "docker-compose.yml is valid" "docker compose config > /dev/null 2>&1"
run_test "App service defined" "grep -q 'app:' docker-compose.yml"
run_test "MySQL service defined" "grep -q 'mysql:' docker-compose.yml"
run_test "Redis 7 Alpine service defined" "grep -q 'redis:7-alpine' docker-compose.yml"
echo ""

# Test 3: Port mappings
echo "📋 Test Group 3: Port Configuration"
run_test "App port 9501 mapped" "grep -q '9501:9501' docker-compose.yml"
run_test "MySQL port 3306 mapped" "grep -q '3306:3306' docker-compose.yml"
echo ""

# Test 4: Volume configuration
echo "📋 Test Group 4: Volume Configuration"
run_test "MySQL data volume configured" "grep -q 'mysql-data:' docker-compose.yml"
run_test "Composer cache volume configured" "grep -q 'composer-cache:' docker-compose.yml"
run_test "App code volume mounted" "grep -q './app:/var/www/html/app' docker-compose.yml"
echo ""

# Test 5: Environment configuration
echo "📋 Test Group 5: Environment Configuration"
run_test ".env.example exists" "[ -f .env.example ]"
run_test "APP_ENV=local in .env.example" "grep -q '^APP_ENV=local' .env.example"
run_test "DB configuration in .env.example" "grep -q '^DB_HOST=' .env.example"
run_test "Redis and session configuration in .env.example" "grep -q '^REDIS_HOST=' .env.example && grep -q '^REDIS_PORT=' .env.example && grep -q '^SESSION_TTL=' .env.example"
echo ""

# Test 6: Network configuration
echo "📋 Test Group 6: Network Configuration"
run_test "Network defined" "grep -q 'networks:' docker-compose.yml"
run_test "ec-hub-network defined" "grep -q 'ec-hub-network:' docker-compose.yml"
echo ""

# Test 7: Dependencies
echo "📋 Test Group 7: Service Dependencies"
run_test "App depends on MySQL" "awk '/^  app:/{in_app=1; next} in_app && /^  [[:alnum:]_-]+:/{exit} in_app {print}' docker-compose.yml | grep -q 'mysql'"
run_test "App depends on Redis" "awk '/^  app:/{in_app=1; next} in_app && /^  [[:alnum:]_-]+:/{exit} in_app {print}' docker-compose.yml | grep -q 'redis'"
run_test "MySQL dependency waits for health" "awk '/^  app:/{in_app=1; next} in_app && /^  [[:alnum:]_-]+:/{exit} in_app {print}' docker-compose.yml | grep -A 2 '^      mysql:' | grep -q 'condition: service_healthy'"
run_test "Redis dependency waits for health" "awk '/^  app:/{in_app=1; next} in_app && /^  [[:alnum:]_-]+:/{exit} in_app {print}' docker-compose.yml | grep -A 2 '^      redis:' | grep -q 'condition: service_healthy'"
echo ""

# Test 8: Docker Build test - ALWAYS build to validate Dockerfile
echo "📋 Test Group 8: Docker Build Test"
echo -e "${YELLOW}Building Docker image (validates Dockerfile is correct)...${NC}"
if docker build -t ec-hub-app:integration-test . > /tmp/build.log 2>&1; then
  echo -e "${GREEN}Docker build: PASS${NC}"
  TESTS_PASSED=$((TESTS_PASSED + 1))
else
  echo -e "${RED}Docker build: FAIL${NC}"
  echo "Check /tmp/build.log for details"
  TESTS_FAILED=$((TESTS_FAILED + 1))
fi
echo ""

# Test 9: Real Container Communication Integration Test
echo "📋 Test Group 9: Real Container Communication"
echo "Starting containers for integration test..."
echo -e "${YELLOW}(This will take 30-60 seconds for services to become healthy)${NC}"

# Source env vars
if [ -f .env ]; then
  source .env
else
  source .env.example 2>/dev/null || true
fi

# Start containers
CONTAINERS_STARTED=true
docker compose up -d --build mysql redis app

# Wait for MySQL
MAX_RETRIES=30
RETRY_COUNT=0
echo -n "Waiting for MySQL..."
until docker exec ec-hub-mysql mysql -h"${DB_HOST:-localhost}" -u"${DB_USERNAME:-root}" -p"${DB_PASSWORD:-secret}" -e "SELECT 1" > /dev/null 2>&1; do
  RETRY_COUNT=$((RETRY_COUNT + 1))
  if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
    echo -e " ${RED}TIMEOUT${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    cleanup_containers
    exit 1
  fi
  echo -n "."
  sleep 2
done
echo -e " ${GREEN}READY${NC}"

# Wait for Redis
RETRY_COUNT=0
echo -n "Waiting for Redis..."
until [ "$(docker exec ec-hub-redis redis-cli --raw ping 2>/dev/null)" = "PONG" ]; do
  RETRY_COUNT=$((RETRY_COUNT + 1))
  if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
    echo -e " ${RED}TIMEOUT${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    exit 1
  fi
  echo -n "."
  sleep 2
done
echo -e " ${GREEN}READY${NC}"

# Wait for app
RETRY_COUNT=0
echo -n "Waiting for app container..."
until docker exec ec-hub-app php -r "exit(0);" > /dev/null 2>&1; do
  RETRY_COUNT=$((RETRY_COUNT + 1))
  if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
    echo -e " ${RED}TIMEOUT${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    cleanup_containers
    exit 1
  fi
  echo -n "."
  sleep 2
done
echo -e " ${GREEN}READY${NC}"

echo ""

# Test app -> MySQL connectivity
MYSQL_PASSED=false
REDIS_PASSED=false

run_test "App receives Redis and session environment" \
  "docker exec ec-hub-app php -r \"exit(getenv('REDIS_HOST') === 'redis' && getenv('REDIS_PORT') === '6379' && getenv('SESSION_TTL') === '1800' ? 0 : 1);\""

echo -n "Testing: App → MySQL connectivity... "
if docker exec ec-hub-app php -r "
try {
    \$pdo = new PDO('mysql:host=${DB_HOST:-mysql};dbname=${DB_DATABASE:-ec_hub}', '${DB_USERNAME:-root}', '${DB_PASSWORD:-secret}');
    \$stmt = \$pdo->query('SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = \'${DB_DATABASE:-ec_hub}\'');
    \$result = \$stmt->fetch();
    exit(0);
} catch (PDOException \$e) {
    exit(1);
}
" 2>/dev/null; then
  echo -e "${GREEN}PASS${NC}"
  TESTS_PASSED=$((TESTS_PASSED + 1))
  MYSQL_PASSED=true
else
  echo -e "${RED}FAIL${NC}"
  TESTS_FAILED=$((TESTS_FAILED + 1))
fi

echo -n "Testing: App → Redis connectivity through Predis... "
if docker exec ec-hub-app php -r "
require 'vendor/autoload.php';
\$config = require 'config/redis.php';
\$redis = new Predis\\Client(\$config);
\$key = 'ec-hub:integration-test:' . bin2hex(random_bytes(8));
\$keyWritten = false;
try {
    if (\$redis->ping()->getPayload() !== 'PONG') { throw new RuntimeException('Redis PING failed'); }
    \$redis->set(\$key, 'predis');
    \$keyWritten = true;
    if (\$redis->get(\$key) !== 'predis') { throw new RuntimeException('Redis read/write failed'); }
} finally {
    if (\$keyWritten) { try { \$redis->del([\$key]); } catch (Throwable) {} }
}
" 2>/dev/null; then
  echo -e "${GREEN}PASS${NC}"
  TESTS_PASSED=$((TESTS_PASSED + 1))
  REDIS_PASSED=true
else
  echo -e "${RED}FAIL${NC}"
  TESTS_FAILED=$((TESTS_FAILED + 1))
fi

run_test "Redis Compose smoke check" "./tests/docker/check-redis.sh"

echo ""

# Summary
echo "====================================================="
echo "📊 Test Summary"
echo "====================================================="
echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
echo -e "${RED}Failed: $TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
  echo -e "${GREEN}✅ ALL TESTS PASSED!${NC}"
  echo ""
  echo "✅ Infrastructure validated:"
  echo "  - Dockerfile builds successfully"
  echo "  - docker-compose.yml is valid"
  echo "  - All containers start correctly"
  echo "  - App connects to MySQL"
  echo "  - App connects to Redis through Predis"
  echo ""
  echo "Next steps:"
  echo "  1. Run 'docker compose up -d' to start all services"
  echo "  2. Run 'docker compose ps' to check service status"
  echo "  3. Run 'docker compose logs app' to view app logs"
  exit 0
else
  echo -e "${RED}❌ SOME TESTS FAILED${NC}"
  echo ""
  if [ "$MYSQL_PASSED" = false ]; then
    echo "Failure: MySQL is unreachable from app container"
  fi
  if [ "$REDIS_PASSED" = false ]; then
    echo "Failure: Redis is unreachable from app container"
  fi
  echo ""
  echo "The infrastructure test found issues that must be addressed"
  echo "before the story can be marked as complete."
  exit 1
fi
