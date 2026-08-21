#!/bin/bash

# setup.sh - Script de setup automatizado do ec-hub
# Este script configura o banco de dados e popula com dados de teste

set -e  # Exit on error

echo "🚀 Configurando ec-hub..."
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

max_attempts="${SETUP_MAX_ATTEMPTS:-30}"
retry_interval="${SETUP_RETRY_INTERVAL_SECONDS:-2}"

if ! [[ "$max_attempts" =~ ^[1-9][0-9]*$ ]]; then
    echo "❌ SETUP_MAX_ATTEMPTS deve ser um inteiro positivo (recebido: $max_attempts)"
    exit 1
fi

if ! [[ "$retry_interval" =~ ^[0-9]+$ ]]; then
    echo "❌ SETUP_RETRY_INTERVAL_SECONDS deve ser um inteiro não negativo (recebido: $retry_interval)"
    exit 1
fi

max_attempts=$((10#$max_attempts))
retry_interval=$((10#$retry_interval))
wait_duration_seconds=$((max_attempts * retry_interval))

# Função para aguardar MySQL
wait_for_mysql() {
    echo -n "⏳ Aguardando MySQL..."
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if docker compose exec -T mysql mysql -uroot -psecret -e "SELECT 1" > /dev/null 2>&1; then
            echo -e " ${GREEN}✓${NC}"
            return 0
        fi
        echo -n "."
        sleep "$retry_interval"
        attempt=$((attempt + 1))
    done

    echo -e " ${RED}✗${NC}"
    echo "❌ MySQL não está pronto após ${wait_duration_seconds} segundos"
    exit 1
}

wait_for_redis() {
    echo -n "⏳ Aguardando Redis..."
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if [ "$(docker compose exec -T redis redis-cli --raw ping 2>/dev/null)" = "PONG" ]; then
            echo -e " ${GREEN}✓${NC}"
            return 0
        fi
        echo -n "."
        sleep "$retry_interval"
        attempt=$((attempt + 1))
    done

    echo -e " ${RED}✗${NC}"
    echo "❌ Redis não está pronto após ${wait_duration_seconds} segundos"
    exit 1
}

# Verificar se Docker está rodando
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker não está rodando. Inicie o Docker primeiro."
    exit 1
fi

# Verificar se containers estão rodando
if ! docker compose ps --status running --services | grep -qx "app"; then
    echo "⚠️  O container app não está rodando. Execute 'make up' primeiro."
    exit 1
fi

# Aguardar serviços
wait_for_mysql
wait_for_redis

# Instalar dependências Composer
echo ""
echo "📦 Instalando dependências PHP..."
docker compose exec -T app composer install --no-interaction

# Executar migrations
echo ""
echo "🗄️  Executando migrations..."
docker compose exec -T app php bin/migrate.php

# Executar seeders
echo ""
echo "🌱 Populando banco de dados com produtos fictícios..."
docker compose exec -T app php bin/seed.php

echo ""
echo -e "${GREEN}✅ Setup completo!${NC}"
echo ""
echo "🎉 O ec-hub está pronto para uso!"
echo ""
echo "📍 Acesse: http://localhost:9501"
echo ""
echo "Comandos úteis:"
echo "  make logs    - Ver logs da aplicação"
echo "  make test    - Executar testes"
echo "  make shell   - Acessar shell do container"
echo "  make down    - Parar containers"
