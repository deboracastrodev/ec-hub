.PHONY: help up down restart logs test cs-fix cs-check shell setup db-shell ps build clean install test-coverage test-unit test-integration test-feature migrate migrate-fresh seed db-reset

# Variáveis
COMPOSE := docker compose
APP_CONTAINER := ec-hub-app

# Ajuda
help: ## Show this help message
	@echo "Comandos disponíveis:"
	@echo "  make up        - Sobe os containers Docker"
	@echo "  make down      - Para e remove os containers"
	@echo "  make restart   - Reinicia os containers"
	@echo "  make logs      - Mostra logs da aplicação"
	@echo "  make test      - Executa testes PHPUnit (local, sem Docker)"
	@echo "  make cs-fix    - Executa PHP-CS-Fixer (local, sem Docker)"
	@echo "  make cs-check  - Verifica estilo sem corrigir (local, sem Docker)"
	@echo "  make stan      - Roda PHPStan nível 5 (local, sem Docker)"
	@echo "  make shell     - Acessa bash do container app"
	@echo "  make setup     - Executa script de setup"
	@echo "  make db-shell  - Acessa MySQL CLI"
	@echo "  make ps        - Lista status dos containers"
	@echo "  make build     - Rebuild Docker images"
	@echo "  make clean     - Limpa arquivos gerados"
	@echo "  make install   - Instala dependências Composer"

# Docker commands
up: ## Start Docker containers
	$(COMPOSE) up -d
	@echo "✅ Containers iniciados"
	@echo "🔧 Execute 'make setup' para configurar o banco de dados"

down: ## Stop Docker containers
	$(COMPOSE) down
	@echo "🛑 Containers parados"

restart: ## Restart Docker containers
	$(COMPOSE) restart
	@echo "🔄 Containers reiniciados"

logs: ## Show Docker logs
	$(COMPOSE) logs -f app

ps: ## Show running containers
	$(COMPOSE) ps

build: ## Rebuild Docker images
	$(COMPOSE) build --no-cache
	@echo "🔨 Images rebuildadas"

# Setup e configuração
setup: ## Execute setup script
	@echo "🚀 Executando setup..."
	@chmod +x setup.sh
	@./setup.sh

# Database commands (precisam do container do MySQL no ar)
migrate: ## Run database migrations
	$(COMPOSE) exec app php bin/migrate.php

migrate-fresh: ## Drop all tables and re-run migrations
	$(COMPOSE) exec app php bin/migrate-fresh.php

seed: ## Run database seeders
	$(COMPOSE) exec app php bin/seed.php

db-reset: ## Run migrations and seeders (fresh start)
	$(MAKE) migrate-fresh && $(MAKE) seed

# Shell access
shell: ## Open shell in app container
	$(COMPOSE) exec app bash

db-shell: ## Access MySQL CLI
	$(COMPOSE) exec mysql mysql -uroot -psecret ec_hub

# Development tools -- rodam local, sem precisar de Docker (a suíte sem
# @group db passa sem banco nenhum; testes que exigem MySQL de verdade
# fazem skip gracioso se não encontrarem conexão)
test: ## Run all tests
	vendor/bin/phpunit --testdox

cs-fix: ## Fix code style issues (PSR-12)
	vendor/bin/php-cs-fixer fix

cs-check: ## Check code style without fixing
	vendor/bin/php-cs-fixer fix --dry-run --diff

stan: ## Run PHPStan static analysis (level 5)
	vendor/bin/phpstan analyse --memory-limit=512M

test-coverage: ## Run tests with coverage report (precisa de driver de cobertura, ex. pcov)
	vendor/bin/phpunit --coverage-html=coverage/html --coverage-text

test-unit: ## Run unit tests only
	vendor/bin/phpunit --testsuite=Unit

test-integration: ## Run integration tests only
	vendor/bin/phpunit --testsuite=Integration

test-feature: ## Run feature tests only
	vendor/bin/phpunit --testsuite=Feature

# Maintenance
clean: ## Clean generated files
	rm -rf coverage/
	rm -rf vendor/
	rm -rf runtime/logs/*

install: ## Install dependencies
	composer install
