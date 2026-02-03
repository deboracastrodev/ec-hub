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

# Check PHP 7.4-FPM base image
if ! grep -q "FROM php:7.4-fpm" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Dockerfile must use PHP 7.4-FPM base image"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Base image: PHP 7.4-FPM"
fi

# Check PDO extension
if ! grep -q "docker-php-ext-install pdo pdo_mysql" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Missing PDO/PDO_MYSQL extension"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ PDO extension found"
fi

# Check Redis extension
if ! grep -q "redis" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Missing Redis extension"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Redis extension found"
fi

# Check BCMath extension
if ! grep -q "bcmath" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Missing BCMath extension"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ BCMath extension found"
fi

# Check Swoole extension
if ! grep -q "swoole" "$DOCKERFILE_PATH"; then
  echo "❌ FAIL: Missing Swoole extension"
  ERRORS=$((ERRORS + 1))
else
  echo "✅ Swoole extension found"
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
