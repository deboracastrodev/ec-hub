.PHONY: help cs-fix cs-check test test-coverage clean install

help: ## Show this help message
	@echo "Usage: make [target]"
	@echo ""
	@echo "Available targets:"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install dependencies
	composer install

cs-fix: ## Fix code style issues (PSR-12)
	vendor/bin/php-cs-fixer fix

cs-check: ## Check code style without fixing
	vendor/bin/php-cs-fixer fix --dry-run --diff

test: ## Run all tests
	vendor/bin/phpunit --testdox

test-coverage: ## Run tests with coverage report
	vendor/bin/phpunit --coverage-html=coverage/html --coverage-text

test-unit: ## Run unit tests only
	vendor/bin/phpunit --testsuite=Unit

test-integration: ## Run integration tests only
	vendor/bin/phpunit --testsuite=Integration

test-feature: ## Run feature tests only
	vendor/bin/phpunit --testsuite=Feature

clean: ## Clean generated files
	rm -rf coverage/
	rm -rf vendor/
	rm -rf runtime/logs/*

up: ## Start Docker containers
	docker-compose up -d

down: ## Stop Docker containers
	docker-compose down

logs: ## Show Docker logs
	docker-compose logs -f app

shell: ## Open shell in app container
	docker-compose exec app bash

ps: ## Show running containers
	docker-compose ps
