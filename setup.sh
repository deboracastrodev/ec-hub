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

# Função para aguardar MySQL
wait_for_mysql() {
    echo -n "⏳ Aguardando MySQL..."
    local max_attempts=30
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if docker-compose exec -T mysql mysql -uroot -psecret -e "SELECT 1" > /dev/null 2>&1; then
            echo -e " ${GREEN}✓${NC}"
            return 0
        fi
        echo -n "."
        sleep 2
        attempt=$((attempt + 1))
    done

    echo -e " ${RED}✗${NC}"
    echo "❌ MySQL não está pronto após 60 segundos"
    exit 1
}

# Verificar se Docker está rodando
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker não está rodando. Inicie o Docker primeiro."
    exit 1
fi

# Verificar se containers estão rodando
if ! docker-compose ps | grep -q "Up"; then
    echo "⚠️  Containers não estão rodando. Execute 'make up' primeiro."
    exit 1
fi

# Aguardar serviços
wait_for_mysql

# Instalar dependências Composer
echo ""
echo "📦 Instalando dependências PHP..."
docker-compose exec -T app composer install --no-interaction

# Executar migrations
echo ""
echo "🗄️  Executando migrations..."
docker-compose exec -T app php bin/migrate.php

# Executar seeders
echo ""
echo "🌱 Populando banco de dados com produtos fictícios..."
docker-compose exec -T app php bin/seed.php

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
