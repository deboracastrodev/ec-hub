#!/bin/bash
# Full integration test for docker-compose infrastructure
# This is the main test that validates AC #1 completely

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
cd "$PROJECT_ROOT"

echo "🧪 Integration Test - Docker Compose Infrastructure"
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

# Test 1: Dockerfile exists and is valid
echo "📋 Test Group 1: Dockerfile Validation"
run_test "Dockerfile exists" "[ -f Dockerfile ]"
run_test "Dockerfile uses PHP 7.4-FPM" "grep -q 'FROM php:7.4-fpm' Dockerfile"
run_test "Dockerfile has Swoole extension" "grep -q 'swoole' Dockerfile"
run_test "Dockerfile has Composer" "grep -q 'composer' Dockerfile"
echo ""

# Test 2: docker-compose.yml exists and is valid
echo "📋 Test Group 2: Docker Compose Validation"
run_test "docker-compose.yml exists" "[ -f docker-compose.yml ]"
run_test "docker-compose.yml is valid" "docker-compose config > /dev/null 2>&1"
run_test "App service defined" "grep -q 'app:' docker-compose.yml"
run_test "MySQL service defined" "grep -q 'mysql:' docker-compose.yml"
run_test "Redis service defined" "grep -q 'redis:' docker-compose.yml"
echo ""

# Test 3: Port mappings
echo "📋 Test Group 3: Port Configuration"
run_test "App port 9501 mapped" "grep -q '9501:9501' docker-compose.yml"
run_test "MySQL port 3306 mapped" "grep -q '3306:3306' docker-compose.yml"
run_test "Redis port 6379 mapped" "grep -q '6379:6379' docker-compose.yml"
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
run_test "Redis configuration in .env.example" "grep -q '^REDIS_HOST=' .env.example"
echo ""

# Test 6: Network configuration
echo "📋 Test Group 6: Network Configuration"
run_test "Network defined" "grep -q 'networks:' docker-compose.yml"
run_test "ec-hub-network defined" "grep -q 'ec-hub-network:' docker-compose.yml"
echo ""

# Test 7: Dependencies
echo "📋 Test Group 7: Service Dependencies"
run_test "App depends on MySQL" "grep -A 20 'app:' docker-compose.yml | grep -q 'mysql'"
run_test "App depends on Redis" "grep -A 20 'app:' docker-compose.yml | grep -q 'redis'"
echo ""

# Test 8: Build test (only if containers don't exist)
echo "📋 Test Group 8: Docker Build Test"
if ! docker images | grep -q "ec-hub-app"; then
  echo -e "${YELLOW}Building Docker image (this may take a few minutes)...${NC}"
  if docker build -t ec-hub-app:test . > /tmp/build.log 2>&1; then
    echo -e "${GREEN}Docker build: PASS${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
  else
    echo -e "${RED}Docker build: FAIL${NC}"
    echo "Check /tmp/build.log for details"
    TESTS_FAILED=$((TESTS_FAILED + 1))
  fi
else
  echo -e "${YELLOW}Docker build: SKIPPED (image already exists)${NC}"
fi
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
  echo "Next steps:"
  echo "  1. Run 'docker-compose up -d' to start all services"
  echo "  2. Run 'docker-compose ps' to check service status"
  echo "  3. Run 'docker-compose logs app' to view app logs"
  exit 0
else
  echo -e "${RED}❌ SOME TESTS FAILED${NC}"
  exit 1
fi
