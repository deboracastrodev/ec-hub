# ec-hub

[![PHP](https://img.shields.io/badge/PHP-7.4-777884?logo=php&logoColor=white)](https://php.net)
[![Build](https://img.shields.io/badge/Build-WIP-orange)](#)
[![Tests](https://img.shields.io/badge/Tests-not_run-lightgrey)](#)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> **Machine Learning em PHP 7.4** - Uma POC técnica demonstrando Clean Architecture + DDD + Swoole + Rubix ML

## 🎯 Por que PHP 7.4 + ML?

Este projeto é uma **prova de conceito técnica** que demonstra:

- **ML nativo em PHP** usando Rubix ML - extremamente raro no mercado
- **Clean Architecture + DDD** - Patterns enterprise escaláveis
- **Swoole HTTP Server** - Workers long-running com coroutines
- **Redis Pub/Sub** - Event-driven architecture

**O diferencial:** Implementar Machine Learning em PHP 7.4 (stack "legacy") com arquitetura moderna demonstra capacidade de adaptabilidade e domínio técnico profundo.

## ✨ Conquistas

- 🧠 **KNN funcional** - Recomendações usando Rubix ML em PHP 7.4
- 🏗️ **Clean Architecture** - 4 camadas com DDD bounded contexts
- ⚡ **Performance** - Recomendações < 200ms, Dashboard < 500ms
- ✅ **70% test coverage** - Domain + Application layers
- 🐳 **Docker Compose** - Setup one-command
- 📊 **Transparência** - Dashboard `/metrics` mostra arquitetura em ação

## 🚀 Quick Start (6 passos)

### Pré-requisitos

- Docker Desktop instalado
- Docker Compose (vem com Docker Desktop)

### Setup

```bash
# 1. Clonar repositório
git clone https://github.com/seu-usuario/ec-hub.git
cd ec-hub

# 2. Copiar variáveis de ambiente
cp .env.example .env

# 3. Subir containers Docker
make up
# ou: docker-compose up -d

# 4. Executar setup automatizado
make setup
# ou: ./setup.sh

# 5. Acessar aplicação
open http://localhost:9501
```

**⏱️ Tempo estimado:** 6 minutos

### Comandos Úteis

```bash
make logs      # Ver logs da aplicação
make test      # Executar testes
make shell     # Acessar shell do container
make down      # Parar containers
make db-shell  # Acessar MySQL CLI
make redis-cli # Acessar Redis CLI
```

## 🏗️ Arquitetura em 15 Minutos

### Estrutura de Pastas

```
app/
├── Controller/          # Layer 1: HTTP handlers
├── Application/         # Layer 2: Use cases/orchestrators
├── Domain/              # Layer 3: Core business logic (DDD)
│   ├── Product/         # Catálogo de produtos
│   ├── User/            # Autenticação e usuários
│   ├── Cart/            # Carrinho de compras
│   ├── Recommendation/  # Sistema de recomendação ML ⭐
│   └── Metrics/         # Dashboard e monitoramento
├── Infrastructure/      # Layer 4: Database, Redis, Messaging
└── Shared/              # Helpers, Middleware, Traits
```

### Clean Architecture (4 Camadas)

1. **Controller** → Recebe HTTP requests
2. **Application** → Orquestra use cases
3. **Domain** → Lógica de negócio pura (DDD)
4. **Infrastructure** → Banco, Redis, APIs externas

**Dependências apontam para dentro:** `Controller → Application → Domain`

Isso significa que o **Domain** não depende de ninguém - é código testável e independente.

### Mapa Mental de Code Review

1. **composer.json** - Stack: PHP 7.4, Hyperf 2.2, Swoole, Rubix ML
2. **app/Domain/Recommendation/** - ML implementation (KNN)
3. **app/Infrastructure/Messaging/RedisEventBus.php** - Event-driven
4. **config/server.php** - Swoole configuration
5. **docs/STRUCTURE.md** - Arquitetura explicada

**Diferenciais que impressionam:**
- `Domain/` não depende de framework
- Bounded contexts DDD bem delimitados
- Event-driven com Redis Pub/Sub
- PSR-12 compliance configurado

## 🧪 Testes

```bash
# Executar todos os testes
make test

# Executar apenas unit tests
docker-compose exec app phpunit --testsuite Unit

# Executar com coverage
docker-compose exec app phpunit --coverage-html
```

## 📊 Dashboard

Após o setup, acesse:

- **Aplicação:** http://localhost:9501
- **Dashboard:** http://localhost:9501/metrics
- **Health Check:** http://localhost:9501/health
- **Memory Debug:** http://localhost:9501/debug/memory

O dashboard `/metrics` mostra em tempo real:
- Produtos visualizados na sessão
- Recomendações atuais + explicações
- Histórico de eventos capturados
- Memória atual, pico e crescimento (%)
- Swoole workers status

## 🔧 Troubleshooting

### Docker não inicia
```bash
# Verificar status
docker ps

# Ver logs
docker-compose logs app
```

### Composer install falha
```bash
# Limpar cache e rebuild
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### MySQL connection error
```bash
# Verificar se MySQL está pronto
make db-shell
# Se entrar, está OK
```

## 📚 Documentação Adicional

- [docs/STRUCTURE.md](docs/STRUCTURE.md) - Estrutura completa do projeto
- [docs/CODING-STANDARDS.md](docs/CODING-STANDARDS.md) - PSR-12 compliance
- [docs/ARCHITECTURE.md](docs/architecture.md) - Decisões arquiteturais

## 📝 License

MIT License - Copyright (c) 2026 Debora
