#!/bin/bash
# MySQL health check script
# Wait for MySQL to be ready

echo "🔍 Checking MySQL connection..."

until docker exec ec-hub-mysql mysql -hlocalhost -uroot -psecret -e "SELECT 1" > /dev/null 2>&1; do
  echo "⏳ Waiting for MySQL..."
  sleep 2
done

echo "✅ MySQL is ready!"
exit 0
