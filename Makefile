.PHONY: help up down restart logs test cs-fix cs-check shell setup db-shell ps build clean install test-coverage test-unit test-integration test-feature migrate migrate-fresh seed db-reset test-e2e e2e-install test-performance benchmark-knn

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
	@echo "  make test      - Executa testes sem serviços externos (local, sem Docker)"
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
	@echo "  make e2e-install - Instala Playwright + Chromium (npm)"
	@echo "  make test-e2e  - Roda a suíte E2E (Playwright) contra o app no ar"
	@echo "  make test-performance - Roda a suíte de performance (container app, stack no ar)"
	@echo "  make benchmark-knn - Imprime o benchmark do KNN (100/1.000/5.000 produtos)"

# Docker commands
up: ## Start Docker containers
	$(COMPOSE) up -d --build
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

# Development tools -- rodam local, sem precisar de Docker. Testes que conectam
# serviços externos pertencem aos grupos db ou redis e ficam nas lanes de CI.
test: ## Run tests without external services
	vendor/bin/phpunit --testdox --exclude-group db --exclude-group redis

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

# E2E (Playwright) -- exige o app no ar (make up + make setup).
# E2E_BASE_URL sobrescreve o padrão http://localhost:9501.
e2e-install: ## Install Playwright and Chromium
	npm ci && npx playwright install chromium

test-e2e: ## Run E2E tests (Playwright, headless Chromium)
	npx playwright test

# Performance -- rodam dentro do container app (PHP, vendor/, MySQL, Redis e o
# servidor em 127.0.0.1:9501). Exigem o stack no ar (make up + make setup).
# PERF_BASE_URL sobrescreve o padrão http://127.0.0.1:9501.
test-performance: ## Run performance tests (tests/Performance, own phpunit config)
	$(COMPOSE) exec -T -e PERF_BASE_URL app vendor/bin/phpunit -c tests/Performance/phpunit.xml

benchmark-knn: ## Print KNN benchmark table
	$(COMPOSE) exec -T app php bin/benchmark-knn.php

# Maintenance
clean: ## Clean generated files
	rm -rf coverage/
	rm -rf vendor/
	rm -rf runtime/logs/*

install: ## Install dependencies
	composer install
