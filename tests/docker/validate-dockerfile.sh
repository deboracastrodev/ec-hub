#!/bin/bash
# Validation script for Dockerfile
# This script validates the Dockerfile meets all requirements

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
DOCKERFILE_PATH="$PROJECT_ROOT/Dockerfile"
ERRORS=0

echo "🔍 Validating Dockerfile..."

# Check if Dockerfile exists
if [ ! -f "$DOCKERFILE_PATH" ]; then
  echo "❌ FAIL: Dockerfile not found at $DOCKERFILE_PATH"
  exit 1
fi
echo "✅ Dockerfile exists"

# Check PHP 8.4-cli base image (D1: platform target, R1.1)
if ! grep -q "FROM php:8.4-cli" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Dockerfile must use PHP 8.4-cli base image"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Base image: PHP 8.4-cli"
fi

# Check PDO extension
if ! grep -q "docker-php-ext-install pdo pdo_mysql" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Missing PDO/PDO_MYSQL extension"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ PDO extension found"
fi

# Check pcov (code coverage driver, R7.4)
if ! grep -q "pcov" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Missing pcov (needed for make test-coverage)"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ pcov found"
fi

# Check Composer installation
if ! grep -q "composer" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Missing Composer installation"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Composer found"
fi

# Check WORKDIR
if ! grep -q "WORKDIR /var/www/html" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: WORKDIR not set to /var/www/html"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ WORKDIR correctly set"
fi

if [ $ERRORS -gt 0 ]; then
  echo ""
  echo "❌ VALIDATION FAILED: $ERRORS error(s) found"
  exit 1
fi

echo ""
echo "✅ ALL VALIDATIONS PASSED"
exit 0
