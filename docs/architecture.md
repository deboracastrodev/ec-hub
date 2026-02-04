# Architecture Decision Document

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

---

## Project Context Analysis

### Requirements Overview

**Functional Requirements:**

O ec-hub possui **119 Functional Requirements** organizados em 15 capability areas:

1. **Product Browsing & Discovery (FR1-FR6)** - Listagem de produtos, busca, filtros, paginação
2. **Recommendation System (FR7-FR14)** - ML KNN com Rubix ML, fallback rule-based
3. **Behavior Tracking & Events (FR15-FR20)** - Event stream, Redis Pub/Sub, session storage
4. **Transparency & Metrics Dashboard (FR21-FR28)** - Dashboard `/metrics` vitrine de arquitetura
5. **System Monitoring (FR29-FR35)** - Logging estruturado, health checks, performance monitoring
6. **Developer Experience (FR36-FR44)** - Setup funcional, Docker Compose, code review readiness
7. **Architecture & Code Quality (FR45-FR53)** - Clean Architecture + DDD, PSR-12, patterns
8. **Testing & Quality Assurance (FR54-FR60)** - Unit + Integração, 70% coverage
9. **Documentation & Learning (FR61-FR69)** - Learning Journal, README, API docs
10. **User Authentication** (FR70-FR85) - POC-level auth
11. **Shopping Cart** (FR86-FR94) - Cart state management
12. **Checkout Process** (FR95-FR102) - POC checkout (sem pagamento real)
13. **Post-MVP Features** (FR103-FR112) - Growth scope
14. **Vision Features** (FR113-FR119) - Future capabilities

**Non-Functional Requirements:**

**Performance (10 NFRs):**
- Recomendações < 200ms (NFR-PERF-01)
- Dashboard < 500ms (NFR-PERF-02)
- Memory < 256MB/worker Swoole (NFR-PERF-03)
- CPU < 10% em idle (NFR-PERF-04)
- 10 sessões simultâneas (NFR-PERF-10)

**Acessibilidade (10 NFRs):**
- WCAG AA completo (contrast 4.5:1, keyboard nav, screen reader)
- Semantic HTML5, ARIA labels

**Confiabilidade (8 NFRs):**
- 100% uptime (POC scope)
- 50+ interações sem falha
- Logs estruturados (JSON)
- Graceful degradation

**Segurança (8 NFRs):**
- HTTPS, env vars, input sanitization
- POC-level (sem produção real)

**Escalabilidade (4 NFRs):**
- Workers stateless
- Redis shared storage
- Connection pooling
- Horizontal scaling demonstrável

**Scale & Complexity:**

- Primary domain: Full-stack Web (Backend-heavy + ML)
- Complexity level: Medium-High (POC técnico desafiador)
- Estimated architectural components: 12-15

### Technical Constraints & Dependencies

**Stack Obrigatório (Diferencial Competitivo):**
- PHP 7.4 (versão específica - desafio técnico)
- Hyperf 2.2 (framework base)
- Swoole (coroutines, long-running workers)
- Redis (cache, session, pub/sub)
- Rubix ML (ML nativo em PHP - **raríssimo**)

**Infestrutura Local:**
- Docker Compose (MySQL/Postgres, Redis, Prometheus/Grafana)
- OpenTelemetry (opcional - excelente diferencial)

**Cloud (Opcional/Simulado):**
- AWS SNS/SQS/S3 com adapters locais
- Preparado para horizontal scaling

**Patterns Obrigatórios:**
- Clean Architecture (4 camadas: Controller → Application → Domain → Infra)
- DDD (Domain-Driven Design)
- Strategy Pattern (handlers)
- Outbox Pattern (eventos)
- Circuit Breaker (simples)

**CI/CD:**
- GitHub Actions (lint, tests, build docker, security checks)

### Cross-Cutting Concerns Identified

1. **Event-Driven Architecture** - Redis Pub/Sub para comunicação assíncrona entre componentes
2. **Session Management** - Armazenamento em Redis para workers Swoole stateless (horizontal scaling)
3. **Observability** - Logs estruturados + métricas + tracing (OpenTelemetry opcional)
4. **ML Recommendation Engine** - KNN com Rubix ML + fallback rule-based quando dados insuficientes
5. **Developer Experience** - Setup funcional em < 10min (Docker Compose), Learning Journal integrado
6. **Testing Strategy** - Unit + Integração (HTTP + DB + fila), 70% coverage como guia
7. **Technical Storytelling** - Dashboard `/metrics` como vitrine de arquitetura para recrutadores em ≤ 15min

---

## Starter Template Evaluation

### Primary Technology Domain

**Full-stack Web (Backend-heavy + ML)** com PHP 7.4 como core

Este é um projeto **não-convencional** - o stack está pré-definido pelos requisitos em PHP 7.4.

### Starter Options Considered

**Opção 1: hyperf-skeleton (Composer)**

```bash
composer create-project hyperf/hyperf-skeleton
```

- **Prós:** Estrutura base pronta, configuração de dependências automática
- **Contras:** Não demonstra domínio arquitetural profundo, estrutura genérica

**Opção 2: Configuração Customizada do Zero (ESCOLHIDA)**

- **Prós:** Demonstra completo domínio arquitetural, estrutura otimizada para Clean Architecture + DDD, cada decisão é documentada e justificada
- **Contras:** Requer mais tempo de setup inicials

**Architecture Decision:**

Não usaremos starter template. Construiremos do zero seguindo:

- Clean Architecture (4 camadas explícitas)
- DDD (Domain-Driven Design) com bounded contexts
- PSR-12 compliance
- Swoole HTTP Server customizado
- Redis Pub/Sub event bus customizado

### Current Versions Reference (2026)

| Componente | Versão | Fonte |
|------------|--------|-------|
| **PHP** | 7.4 | Requisito técnico |
| **Hyperf** | 2.2 | [hyperf.wiki/2.2](https://hyperf.wiki/2.2/) |
| **Swoole** | 4.x/5.x | [swoole-src GitHub](https://github.com/swoole/swoole-src) - compatível com PHP 7.4 |
| **Rubix ML** | latest (2025) | [RubixML/ML GitHub](https://github.com/RubixML/ML) |
| **Redis** | 7.x | docker-compose local |
| **MySQL** | 8.x | docker-compose local |

> **Note:** Swoole 6.x e Hyperf 3.x requerem PHP 8.0+. Para PHP 7.4, usamos Hyperf 2.2 + Swoole 4.x/5.x

---

### Architecture Decisions Made by Custom Setup

**Language & Runtime:**

- PHP 7.4 (desafio técnico proposital - demonstra adaptabilidade)
- Composer para gerenciamento de dependências
- PSR-12 coding standard

**Framework & HTTP Server:**

- Hyperf 2.2 (framework base - componente por componente, não skeleton)
- Swoole HTTP Server (coroutines, long-running workers)
- Custom server bootstrap (demonstra domínio de Swoole lifecycle)

**Styling Solution (Frontend):**

- MPA (Multi-Page Application) com Server-Side Rendering
- **Twig standalone** (twig/twig via Composer) - **Simplificação**: Não Hyperf View Integration
- CSS vanilla com BEM methodology (simples, manutenível)
- Progressive enhancement (mobile-first)
- WCAG AA compliance (contrast 4.5:1, ARIA)

**Build Tooling:**

- Docker Compose para desenvolvimento local
- Multi-stage build para produção
- PHP 7.4-FPM base image com Swoole extension
- Nginx como reverse proxy (opcional)

**Testing Framework:**

- PHPUnit (unit tests)
- Hyperf Testing (integration tests - HTTP + DB + Redis)
- Codeception (end-to-end tests, opcional)
- 70% coverage target (não fetiche, guia)

**Code Organization:**

```
app/
├── Controller/          # Interface layer (HTTP handlers)
├── Application/         # Use cases/orchestrators
├── Domain/              # Core business logic (DDD)
│   ├── Model/
│   ├── Repository/
│   ├── Service/
│   └── Event/
└── Infrastructure/      # External concerns
    ├── Persistence/
    ├── Messaging/
    └── Monitoring/
```

**Development Experience:**

- Docker Compose one-command setup
- Hot reload via Swoole reload
- Xdebug para profiling
- Logging estruturado (JSON)
- Health check endpoint `/health`

> **Note:** A primeira story de implementação será o setup do projeto com Docker Compose + estrutura base.

---

## Core Architectural Decisions

### Decision Priority Analysis

**Critical Decisions (Block Implementation):**

- Database schema management via Hyperf migrations
- Redis session storage for stateless Swoole workers
- Clean Architecture + DDD structure (4 camadas explícitas)
- Swoole HTTP Server customizado com coroutines
- Docker Compose para desenvolvimento local

**Important Decisions (Shape Architecture):**

- Data Mapper pattern (Domain vs Persistence separation)
- RFC 7807 for error responses (professional standard)
- Redis Pub/Sub for event-driven communication
- Twig component-based templates with BEM CSS
- GitHub Actions CI/CD pipeline
- Monolog structured logging (JSON)

**Deferred Decisions (Post-MVP):**

- Kubernetes deployment (FR119 - Vision scope)
- OpenTelemetry tracing (opcional para MVP)
- Advanced RBAC/ACL (simple role checks sufficient for POC)

---

### Data Architecture

| Decisão | Tecnologia | Versão | Rationale |
|---------|-----------|--------|-----------|
| **Database** | MySQL 8.x | 8.x | Padrão, bem documentado, compatível com PDO nativo |
| **Schema Mgmt** | PHP puro + PDO | - | **Simplificação**: Removido Hyperf Database devido a conflitos de dependência (73 pacotes → ~20 pacotes). Scripts PHP nativos em `bin/migrate.php` e `bin/seed.php`. |
| **Query Layer** | PDO nativo | - | **Simplificação**: PDO direto (vem no PHP 7.4) em vez de Hyperf Database. Menos dependências, mais controle, melhor performance. |
| **Model Pattern** | Data Mapper | - | Domain models independentes de DB (Clean Architecture) |
| **Seeding** | Faker + Golden Dataset | - | Dados realistas + casos específicos para testar ML |
| **Caching** | PHP Redis (predis/redis) | 7.x | Abstração sobre Redis, fácil de testar |

**Affects:** FR1-FR6 (Product Browsing), FR15-FR20 (Behavior Tracking), FR86-FR94 (Shopping Cart)

> **⚠️ ADR-001: Remoção do Hyperf Database** (2026-02-03)
>
> **Contexto:** Durante a Story 2.1, conflitos de dependência ocorreram:
> - `hyperf/router` não encontrado no composer
> - `rubix/ml` com issues de estabilidade (minimum-stability)
> - 73 pacotes instalados vs ~20 pacotes necessários
>
> **Decisão:** Simplificar usando PHP puro + PDO nativo
> - Migrations via `bin/migrate.php` (script PHP com PDO)
> - Seed via `bin/seed.php` (script PHP com Faker)
> - Repository implementations usando PDO diretamente
>
> **Benefícios:**
> - Redução de ~53 pacotes de dependência
> - Setup mais simples (sem framework dependencies)
> - Melhor performance (sem indireções do framework)
> - Clean Architecture mantida (Domain independente)
>
> **Trade-off:** Perde-se features "batteries-included" do Hyperf (ORM, Query Builder avançado), mas ganha-se simplicidade e controle total.

---

### Authentication & Security

| Decisão | Tecnologia | Versão | Rationale |
|---------|-----------|--------|-----------|
| **Auth Method** | Session-based (Redis) | - | Workers stateless, session em Redis enables horizontal scaling |
| **Password Hash** | bcrypt (PHP password_hash) | - | Padrão PHP 7.4, suficiente para POC |
| **Authorization** | Simple role checks | - | POC não precisa de ACL complexa (admin/user) |
| **CSRF Protection** | Hyperf CSRF middleware | - | Best practice, fácil de implementar |
| **Input Sanitization** | Hyperf Validation Request | - | Middleware-level, consistente |

**Affects:** FR70-FR85 (User Authentication - POC level)

---

### API & Communication Patterns

| Decisão | Tecnologia | Versão | Rationale |
|---------|-----------|--------|-----------|
| **API Style** | REST | - | Padrão, fácil de documentar, testável |
| **Error Response** | RFC 7807 (Problem Details) | - | Padrão industria |
| **Rate Limiting** | Redis Sliding Window | 7.x | Já temos Redis, simples e eficiente |
| **Event Bus** | Redis Pub/Sub | 7.x | Stack simplificado, visualizável no `/metrics` |
| **Async Workers** | Swoole Coroutines | 4.x/5.x | Nativo ao Swoole, diferencial técnico |

**Affects:** FR7-FR14 (Recommendation System), FR15-FR20 (Behavior Tracking), FR48-FR53 (Architecture)

---

### Frontend Architecture

| Decisão | Tecnologia | Versão | Rationale |
|---------|-----------|--------|-----------|
| **Template Engine** | Twig standalone | 3.x | **Simplificação**: `twig/twig` via Composer ao invés de Hyperf View Integration. Leve, simples, manutenível. |
| **Template Approach** | Component-based (Twig macros) | - | Reutilizável, `{% component %}` patterns |
| **CSS Organization** | BEM + Component-scoped | - | `product-list.css`, `dashboard.css` - manutenível |
| **State Management** | Server-only (PHP sessions) | - | MPA puro, sem JS complexo no frontend |
| **Form Handling** | Multipart + Server Validation | - | Padrão HTML, fácil de debugar |
| **JavaScript** | Vanilla ES6+ | - | Para dashboard `/metrics` (gráficos simples), sem framework |
| **Routing** | PHP puro (roteamento simples) | - | **Simplificação**: Router baseado em query params ou roteador leve (ex: nikic/fast-route) ao invés de Hyperf Router |

**Affects:** FR1-FR28 (All user-facing features), WCAG AA compliance (NFR-A11Y-01 to NFR-A11Y-10)

---

### Infrastructure & Deployment

| Decisão | Tecnologia | Versão | Rationale |
|---------|-----------|--------|-----------|
| **Local Dev** | Docker Compose | - | MySQL, Redis, Prometheus, Grafana, app |
| **CI/CD** | GitHub Actions | - | Lint (PHP-CS-Fixer), Tests, Build Docker, Security |
| **Environment Config** | .env + vlucas/phpdotenv | - | Padrão PHP (APP_ENV, DB_HOST, REDIS_HOST) |
| **Logging** | Monolog (Hyperf logging) | - | Padrão PHP, JSON formatter |
| **Metrics** | Prometheus + Grafana | - | Dashboard para demonstrar observability |
| **Health Checks** | `/health` endpoint | - | Swoole status, Redis connection, DB connection |

**Affects:** FR36-FR44 (Developer Experience), FR29-FR35 (System Monitoring), NFR-REL-01 to NFR-REL-08

---

### Decision Impact Analysis

**Implementation Sequence:**

1. **Infrastructure First** - Docker Compose + MySQL + Redis + estrutura base
2. **Data Layer** - Migrations + Models + Repositories
3. **Core Business Logic** - Domain services (ML recommendation, event tracking)
4. **API Layer** - Controllers + Routing + Error handling
5. **Frontend** - Twig templates + CSS + JavaScript minimal
6. **Observability** - Logging + Metrics + Health checks
7. **CI/CD** - GitHub Actions pipeline

**Cross-Component Dependencies:**

- Redis session → Session management → All stateful features
- Swoole workers → Event-driven architecture → ML recommendation engine
- Clean Architecture → All layers → Domain independence from infrastructure
- Docker Compose → All services → Local development parity

---

## Architecture Decision Records (ADRs)

### ADR-001: Remoção do Hyperf Database/Router em favor de PHP puro + PDO

**Status:** Aceito
**Data:** 2026-02-03
**Contexto:** Story 2.1 - Product Database & Seed Data

**Problema:**
Durante a implementação da Story 2.1, conflitos de dependência ocorreram ao tentar usar Hyperf:

```
Problem 1: Root composer.json requires hyperf/router, it could not be found
Problem 2: Root composer.json requires rubix/ml ^3.0, found rubix/ml[3.0.x-dev] but it does not match your minimum-stability
```

- Hyperf Database + Router + View adiciona ~53 pacotes extras
- Complexidade desnecessária para um projeto iniciante/POC
- Conflitos de versão entre dependências

**Decisão:**
Simplificar para PHP puro + PDO + Twig standalone:

| Componente | Antes (Hyperf) | Depois (PHP puro) |
|-----------|----------------|-------------------|
| Migrations | Hyperf Database | `bin/migrate.php` com PDO |
| Seed | Hyperf Seed | `bin/seed.php` com Faker |
| Database | Hyperf Database (Query Builder) | PDO nativo |
| Templates | Hyperf View Integration | Twig standalone (`twig/twig`) |
| Routing | Hyperf Router | PHP puro ou `nikic/fast-route` |

**Benefícios:**
- ✅ **~53 pacotes removidos** (73 → ~20 pacotes)
- ✅ **Setup mais simples** (sem framework dependencies)
- ✅ **Melhor performance** (sem indireções do framework)
- ✅ **Clean Architecture mantida** (Domain independente de infraestrutura)
- ✅ **Mais controle** sobre o código

**Trade-offs:**
- ❌ Perda de features "batteries-included" do Hyperf
- ❌ Mais código boilerplate (ex: roteamento manual)
- ❌ Sem ferramentas de developer experience do Hyperf

**Consequências:**
- Todas as referências a `Hyperf\Database`, `Hyperf\HttpServer\Router`, e `Hyperf\View` devem ser removidas
- Stories subsequentes devem usar PDO nativo, Twig standalone, e roteamento simples
- Migration paths futuras: Evoluir gradualmente ou adotar Hyperf apenas quando necessário

---

## Implementation Patterns & Consistency Rules

### Pattern Categories Defined

**Critical Conflict Points Identified:**
**25 areas** onde agentes de IA poderiam fazer escolhas diferentes - todas agora com padrões definidos.

---

### Naming Patterns

**Database Naming Conventions:**

- **Tables:** `snake_case` plural (`users`, `products`, `cart_items`)
- **Columns:** `snake_case` (`user_id`, `created_at`, `product_name`)
- **Foreign Keys:** `{referenced_table}_id` (não prefixo `fk_`)
- **Indexes:** `idx_{table}_{column}` (`idx_users_email`)

**API Naming Conventions:**

- **Endpoints:** Plural (`/users`, `/products`, `/cart/items`)
- **Route Params:** `{id}` format (`/users/{id}`)
- **Query Params:** `snake_case` (`?page=1&limit=20&sort_by=created_at`)
- **Headers:** Custom headers com prefixo `X-` (`X-Request-ID`, `X-Session-ID`)

**Code Naming Conventions (PSR-12):**

- **Classes:** `PascalCase` (`UserController`, `ProductService`, `CartRepository`)
- **Methods:** `camelCase` (`getUserData()`, `createProduct()`, `addToCart()`)
- **Variables:** `camelCase` (`$userId`, `$productName`, `$cartItems`)
- **Constants:** `UPPER_SNAKE_CASE` (`MAX_RETRIES`, `DEFAULT_LIMIT`, `CACHE_TTL`)

---

### Structure Patterns

**Project Organization:**

- **Tests:** `tests/` separado (Unit, Integration, Feature)
- **Components:** Por feature/bounded context (Product, User, Cart, Recommendation)
- **Shared Utils:** `app/Shared/` para cross-cutting concerns
- **Config:** `config/` na raiz (padrão Hyperf)

**File Structure:**

```
app/
├── Controller/           # HTTP handlers (interface layer)
├── Application/          # Use cases/orchestrators
├── Domain/               # Core business logic (DDD)
│   ├── Product/          # Product bounded context
│   ├── User/             # User bounded context
│   ├── Cart/             # Cart bounded context
│   └── Shared/           # Shared domain code
├── Infrastructure/       # External concerns
│   ├── Persistence/      # Database, Redis
│   ├── Messaging/        # Redis Pub/Sub
│   └── Monitoring/       # Logging, metrics
└── Shared/               # Cross-cutting utilities
    ├── Helpers/
    └── Traits/

tests/
├── Unit/                 # Domain logic tests
├── Integration/          # HTTP + DB + Redis tests
└── Feature/              # End-to-end scenarios

config/                   # Hyperf config files
public/                   # Web root
├── index.php
└── assets/
    ├── css/              # BEM-scoped CSS
    ├── js/               # Vanilla ES6+
    └── images/
```

---

### Format Patterns

**API Response Formats:**

**Success Response (wrapper):**
```json
{
  "data": {
    "id": 1,
    "name": "Produto Exemplo",
    "price": 99.90,
    "created_at": "2025-01-31T10:30:00Z"
  }
}
```

**Error Response (RFC 7807):**
```json
{
  "type": "/errors/validation-error",
  "title": "Validation Error",
  "detail": "The product name is required",
  "status": 400
}
```

**Data Exchange Formats:**

- **JSON Fields:** `snake_case` (consistente com database)
- **Dates:** ISO 8601 strings (`2025-01-31T10:30:00Z`)
- **Booleans:** JSON nativo (`true`/`false`)
- **Null:** `null` para valores ausentes

---

### Communication Patterns

**Event System Patterns (Redis Pub/Sub):**

- **Event Naming:** `noun.verb` em `snake_case` (`product.viewed`, `user.created`, `cart.item_added`)
- **Event Channels:** `events:{event_name}` (namespace explícito)
- **Event Payload:** `{ event, data, timestamp }` (estrutura consistente)

**Event Example:**
```json
{
  "event": "product.viewed",
  "data": {
    "product_id": 123,
    "user_id": 456,
    "session_id": "abc123"
  },
  "timestamp": "2025-01-31T10:30:00Z"
}
```

**State Management Patterns (Swoole Session):**

- **Session Keys:** `dot.notation` (`cart.items`, `user.id`, `recommendations.history`)
- **Update Pattern:** Imutável (set completo vs update parcial)
- **TTL:** Configurável por key

---

### Process Patterns

**Error Handling Patterns:**

- **Global Handler:** Hyperf exception middleware (centralizado)
- **Error Logging:** Monolog JSON com contexto (estruturado)
- **User Messages:** Mensagens amigáveis (sem stack traces para usuário)
- **HTTP Codes:** Semantic (400, 401, 403, 404, 500, 503)

**Loading State Patterns:**

- **Page Loading:** Twig skeleton screens (MPA loading)
- **AJAX Loading:** CSS spinner (visual feedback)
- **Timeout Handling:** Swoole timeout configuration

---

### Enforcement Guidelines

**All AI Agents MUST:**

1. Follow PSR-12 coding standard
2. Use `snake_case` for database and JSON fields
3. Use `camelCase` for PHP variables and methods
4. Use `PascalCase` for PHP classes
5. Wrap all success responses in `{ data: ... }`
6. Return RFC 7807 format for all errors
7. Name events as `noun.verb` in `snake_case`
8. Use `dot.notation` for session keys
9. Place tests in `tests/` directory (not co-located)
10. Organize code by bounded context (DDD)

**Pattern Enforcement:**

- **Code Review:** Verificar conformidade com padrões
- **PHP-CS-Fixer:** Auto-fix PSR-12 violations
- **Linting:** CI/CD pipeline verifica naming conventions
- **Documentation:** Violations devem ser documentadas em comentários

---

### Pattern Examples

**Good Examples:**

```php
// Database naming (migration)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_email (email)
);

// API endpoint (Hyperf router)
Router::get('/users/{id}', 'UserController@index');
Router::post('/products', 'ProductController@store');

// Event publishing (Redis Pub/Sub)
$event = [
    'event' => 'product.viewed',
    'data' => ['product_id' => $productId, 'user_id' => $userId],
    'timestamp' => date('c')
];
$redis->publish('events:product.viewed', json_encode($event));

// Session handling (Swoole session)
$session->set('cart.items', $cartItems);
$session->set('user.id', $userId);
```

**Anti-Patterns (Avoid):**

```php
// ❌ Wrong: Mixed naming conventions
class user_controller {}  // Should be UserController
function GetUserData() {}   // Should be getUserData()
$UserID = 123;             // Should be $userId

// ❌ Wrong: Inconsistent JSON format
return $user;  // Should be wrapped in { data: ... }

// ❌ Wrong: Event naming not following pattern
$redis->publish('UserCreatedEvent', ...);  // Should be user.created

// ❌ Wrong: Session keys not using dot notation
$session->set('cartItems', ...);  // Should be cart.items
```

---

### Quick Reference for AI Agents

| Pattern | Rule | Example |
|---------|------|---------|
| **Database tables** | `snake_case` plural | `users`, `cart_items` |
| **Database columns** | `snake_case` | `user_id`, `created_at` |
| **API endpoints** | Plural | `/users`, `/products` |
| **PHP classes** | `PascalCase` | `UserController` |
| **PHP methods** | `camelCase` | `getUserData()` |
| **PHP variables** | `camelCase` | `$userId` |
| **Events** | `noun.verb` | `product.viewed` |
| **Session keys** | `dot.notation` | `cart.items` |
| **JSON fields** | `snake_case` | `{"user_id": 123}` |

---

## Project Structure & Boundaries

### Complete Project Directory Structure

```
ec-hub/
├── README.md                        # Project overview + setup instructions
├── LEARNING_JOURNAL.md              # Technical challenges & solutions documented
├── composer.json                    # PHP dependencies
├── composer.lock                    # Locked versions
├── .env.example                     # Environment variables template
├── .env                             # Local environment (gitignored)
├── .gitignore                       # Git ignore rules
├── .php-cs-fixer.php                # PSR-12 coding standard config
├── phpunit.xml                      # PHPUnit configuration
├── phpstan.neon                     # Static analysis config
├── psalm.xml                        # Alternative static analysis (optional)
├── docker-compose.yml               # Local development stack
├── Dockerfile                       # Production image
├── .github/
│   └── workflows/
│       ├── ci.yml                   # Lint, test, build
│       └── security.yml             # Dependency audit
│
├── config/                          # Hyperf configuration
│   ├── autoload.php
│   ├── dependencies.php
│   ├── routes.php
│   ├── server.php                   # Swoole server configuration
│   └── logger.php
│
├── public/                          # Web root
│   ├── index.php                    # Entry point
│   └── assets/
│       ├── css/
│       │   ├── main.css             # Global styles
│       │   ├── product-list.css    # BEM-scoped
│       │   ├── product-detail.css
│       │   ├── dashboard.css        # /metrics dashboard
│       │   └── components.css       # Reusable components
│       ├── js/
│       │   ├── dashboard.js         # Minimal JS for metrics
│       │   └── charts.js            # Simple chart rendering
│       └── images/
│
├── app/                             # Application code (Clean Architecture)
│   │
│   ├── Controller/                  # Layer 1: Interface (HTTP handlers)
│   │   ├── ProductController.php
│   │   ├── RecommendationController.php
│   │   ├── CartController.php
│   │   ├── CheckoutController.php
│   │   ├── UserController.php
│   │   ├── MetricsController.php
│   │   └── HealthController.php
│   │
│   ├── Application/                 # Layer 2: Use cases (orchestrators)
│   │   ├── Product/
│   │   │   ├── GetProductList.php
│   │   │   ├── GetProductDetail.php
│   │   │   └── SearchProducts.php
│   │   ├── Recommendation/
│   │   │   ├── GenerateRecommendations.php
│   │   │   └── TrainModel.php
│   │   ├── Cart/
│   │   │   ├── AddItem.php
│   │   │   ├── RemoveItem.php
│   │   │   └── GetCart.php
│   │   ├── Order/
│   │   │   └── CreateOrder.php
│   │   └── User/
│   │       ├── Authenticate.php
│   │       └── Register.php
│   │
│   ├── Domain/                      # Layer 3: Core business logic (DDD)
│   │   │
│   │   ├── Product/                 # Product bounded context
│   │   │   ├── Model/
│   │   │   │   └── Product.php
│   │   │   ├── Repository/
│   │   │   │   ├── ProductRepositoryInterface.php
│   │   │   │   └── InMemoryProductRepository.php (for testing)
│   │   │   └── Service/
│   │   │       └── ProductSearchService.php
│   │   │
│   │   ├── Recommendation/          # Recommendation bounded context
│   │   │   ├── Model/
│   │   │   │   ├── Recommendation.php
│   │   │   │   └── UserBehavior.php
│   │   │   ├── Repository/
│   │   │   │   └── RecommendationRepositoryInterface.php
│   │   │   └── Service/
│   │   │       ├── KNNService.php           # Rubix ML implementation
│   │   │       └── RuleBasedFallback.php    # Fallback when insufficient data
│   │   │
│   │   ├── EventTracking/           # Event tracking bounded context
│   │   │   ├── Model/
│   │   │   │   └── Event.php
│   │   │   ├── Repository/
│   │   │   │   └── EventRepositoryInterface.php
│   │   │   └── Service/
│   │   │       └── EventPublisher.php       # Redis Pub/Sub
│   │   │
│   │   ├── Cart/                    # Cart bounded context
│   │   │   ├── Model/
│   │   │   │   └── Cart.php
│   │   │   ├── Repository/
│   │   │   │   └── CartRepositoryInterface.php
│   │   │   └── Service/
│   │   │       └── CartSessionService.php   # Swoole session management
│   │   │
│   │   ├── User/                    # User bounded context
│   │   │   ├── Model/
│   │   │   │   └── User.php
│   │   │   ├── Repository/
│   │   │   │   └── UserRepositoryInterface.php
│   │   │   └── Service/
│   │   │       └── AuthenticationService.php
│   │   │
│   │   ├── Order/                  # Order bounded context
│   │   │   ├── Model/
│   │   │   │   └── Order.php
│   │   │   └── Repository/
│   │   │       └── OrderRepositoryInterface.php
│   │   │
│   │   ├── Metrics/                # Metrics bounded context
│   │   │   ├── Service/
│   │   │   │   ├── MetricsCollector.php
│   │   │   │   └── SystemHealthService.php
│   │   │   └── Model/
│   │   │       └── HealthStatus.php
│   │   │
│   │   └── Shared/                 # Shared domain code
│   │       ├── ValueObject/
│   │       │   ├── Email.php
│   │       │   ├── Money.php
│   │       │   └── SessionId.php
│   │       └── Event/
│   │           └── DomainEvent.php
│   │
│   ├── Infrastructure/             # Layer 4: External concerns
│   │   │
│   │   ├── Persistence/            # Database access
│   │   │   ├── MySQL/
│   │   │   │   ├── ProductRepository.php
│   │   │   │   ├── UserRepository.php
│   │   │   │   ├── CartRepository.php
│   │   │   │   ├── OrderRepository.php
│   │   │   │   ├── EventRepository.php
│   │   │   │   └── RecommendationRepository.php
│   │   │   ├── Redis/
│   │   │   │   ├── SessionRepository.php
│   │   │   │   ├── CacheRepository.php
│   │   │   │   └── EventPublisher.php
│   │   │   └── Migration/
│   │   │       ├── 2025_01_31_000001_create_users_table.php
│   │   │       ├── 2025_01_31_000002_create_products_table.php
│   │   │       ├── 2025_01_31_000003_create_cart_items_table.php
│   │   │       ├── 2025_01_31_000004_create_orders_table.php
│   │   │       └── 2025_01_31_000005_create_events_table.php
│   │   │
│   │   ├── Messaging/              # Event bus (Redis Pub/Sub)
│   │   │   ├── RedisEventBus.php
│   │   │   └── EventSubscriber.php
│   │   │
│   │   └── Monitoring/             # Logging & metrics
│   │       ├── LoggerFactory.php
│   │       ├── MetricsExporter.php
│   │       └── HealthCheck.php
│   │
│   └── Shared/                     # Cross-cutting utilities
│       ├── Helper/
│       │   ├── ResponseFormatter.php
│       │   └── ErrorBuilder.php
│       ├── Middleware/
│       │   ├── AuthMiddleware.php
│       │   ├── ErrorHandlerMiddleware.php
│       │   └── RateLimitMiddleware.php
│       └── Trait/
│           └── ContainerAware.php
│
├── views/                           # Twig templates
│   ├── layout/
│   │   └── base.html.twig          # Base template
│   ├── product/
│   │   ├── list.html.twig
│   │   ├── detail.html.twig
│   │   └── search.html.twig
│   ├── cart/
│   │   └── cart.html.twig
│   ├── checkout/
│   │   └── checkout.html.twig
│   ├── user/
│   │   ├── login.html.twig
│   │   └── register.html.twig
│   ├── dashboard/
│   │   └── metrics.html.twig        # /metrics dashboard
│   └── component/                  # Reusable components (macros)
│       ├── product_card.html.twig
│       ├── recommendation_card.html.twig
│       └── pagination.html.twig
│
├── tests/                           # Test suite
│   ├── Unit/
│   │   ├── Domain/
│   │   │   ├── Product/
│   │   │   │   └── ProductTest.php
│   │   │   ├── Recommendation/
│   │   │   │   ├── KNNServiceTest.php
│   │   │   │   └── RuleBasedFallbackTest.php
│   │   │   └── Cart/
│   │   │       └── CartSessionServiceTest.php
│   │   └── Shared/
│   │       └── ValueObject/
│   │           └── MoneyTest.php
│   ├── Integration/
│   │   ├── Controller/
│   │   │   ├── ProductControllerTest.php
│   │   │   └── RecommendationControllerTest.php
│   │   ├── Repository/
│   │   │   ├── ProductRepositoryTest.php
│   │   │   └── UserRepositoryTest.php
│   │   └── Messaging/
│   │       └── RedisEventBusTest.php
│   ├── Feature/
│   │   ├── ProductBrowsingTest.php
│   │   ├── RecommendationFlowTest.php
│   │   └── CheckoutFlowTest.php
│   └── Helper/
│       ├── Fixture/
│       │   ├── ProductFixture.php
│       │   └── UserFixture.php
│       └── TestCase.php             # Base test class
│
├── database/
│   └── seeds/
│       ├── ProductSeeder.php       # Faker + golden dataset
│       └── UserSeeder.php
│
├── storage/                         # Runtime storage (gitignored)
│   └── logs/
│
├── var/                             # Runtime cache (gitignored)
│   └── cache/
│
└── docs/                            # Documentation
    ├── API.md                        # API documentation
    ├── ARCHITECTURE.md               # Architecture reference
    ├── DEPLOYMENT.md                 # Deployment guide
    └── TROUBLESHOOTING.md            # Common issues
```

---

### Architectural Boundaries

**API Boundaries:**

| Boundary | Endpoint(s) | Responsibility |
|----------|-----------|---------------|
| **Public API** | `GET /products`, `GET /products/{id}` | Product browsing |
| **Public API** | `POST /login`, `POST /register` | Authentication |
| **Session API** | `GET /cart`, `POST /cart/items` | Cart (requires session) |
| **Internal API** | `POST /recommendations` | ML recommendations (internal use) |
| **Monitoring API** | `GET /health`, `GET /metrics` | Health & metrics dashboard |

**Component Boundaries:**

| Context | Boundary | Communication |
|---------|----------|--------------|
| **Product** | Domain-only | No external dependencies |
| **Recommendation** | Domain + Infrastructure | Uses Rubix ML, Redis events |
| **Cart** | Domain + Infrastructure | Uses Swoole session |
| **EventTracking** | Domain + Infrastructure | Publishes to Redis Pub/Sub |

**Service Boundaries:**

| Service | Integration Pattern |
|---------|---------------------|
| **KNNService** | Isolated domain service (testable) |
| **EventPublisher** | Redis Pub/Sub (async) |
| **CartSessionService** | Swoole session (synchronous) |

**Data Boundaries:**

| Boundary | Pattern |
|----------|---------|
| **Domain → Persistence** | Repository interface (Data Mapper) |
| **Domain → Cache** | Redis adapter |
| **Domain → Events** | Event publisher interface |

---

### Requirements to Structure Mapping

**Feature/Epic Mapping:**

```
FR1-FR6: Product Browsing
├── Controller: ProductController
├── Application: Product\GetProductList, GetProductDetail
├── Domain: Product\Model\Product, Product\Repository\ProductRepositoryInterface
├── Infrastructure: Persistence\MySQL\ProductRepository
├── Views: product/list.html.twig, product/detail.html.twig
└── Tests: Unit/Domain/Product/, Integration/Controller/ProductControllerTest.php

FR7-FR14: Recommendation System (CORE DIFFERENTIAL)
├── Controller: RecommendationController
├── Application: Recommendation\GenerateRecommendations
├── Domain: Recommendation\Service\KNNService (Rubix ML), RuleBasedFallback
├── Infrastructure: Messaging\RedisEventBus
├── Tests: Unit/Domain/Recommendation/KNNServiceTest.php (CRITICAL)
└── Dashboard: /metrics endpoint para visualização

FR15-FR20: Behavior Tracking
├── Application: EventTracking\PublishEvent
├── Domain: EventTracking\Model\Event, EventTracking\Service\EventPublisher
├── Infrastructure: Persistence\Redis\EventPublisher
└── Tests: Integration/Messaging/RedisEventBusTest.php

FR21-FR28: Metrics Dashboard (VITRINE TÉCNICA)
├── Controller: MetricsController
├── Domain: Metrics\Service\MetricsCollector, SystemHealthService
├── Views: dashboard/metrics.html.twig
└── Scripts: public/assets/js/dashboard.js

FR70-FR85: Authentication (POC-level)
├── Controller: UserController
├── Application: User\Authenticate, Register
├── Domain: User\Model\User, User\Service\AuthenticationService
├── Infrastructure: Persistence\MySQL\UserRepository
└── Middleware: Shared\Middleware\AuthMiddleware
```

**Cross-Cutting Concerns:**

```
Authentication & Authorization
├── Middleware: Shared\Middleware\AuthMiddleware
├── Domain: User\Service\AuthenticationService
└── Session: Infrastructure\Redis\SessionRepository

Error Handling
├── Middleware: Shared\Middleware\ErrorHandlerMiddleware
├── Helper: Shared\Helper\ErrorBuilder (RFC 7807)
└── Formatter: Shared\Helper\ResponseFormatter

Logging & Monitoring
├── Infrastructure: Monitoring\LoggerFactory, MetricsExporter
├── Domain: Metrics\Service\MetricsCollector
└── Health: Infrastructure\Monitoring\HealthCheck

Session Management
├── Infrastructure: Redis\SessionRepository
├── Domain: Cart\Service\CartSessionService
└── Keys: cart.items, user.id (dot-notation)
```

---

### Integration Points

**Internal Communication:**

```
Controller → Application → Domain → Infrastructure

Example (Product List):
ProductController → GetProductList → ProductRepositoryInterface → MySQLProductRepository
```

**External Integrations:**

| Integration | Point | Protocol |
|-------------|-------|----------|
| **Database** | `Infrastructure\Persistence\MySQL\*Repository` | MySQL PDO |
| **Cache/Session** | `Infrastructure\Persistence\Redis\*Repository` | Redis TCP |
| **Event Bus** | `Infrastructure\Messaging\RedisEventBus` | Redis Pub/Sub |
| **ML Training** | `Domain\Recommendation\Service\KNNService` | In-process (Rubix ML) |

**Data Flow:**

```
User Request (HTTP)
    ↓
Swoole HTTP Server
    ↓
Controller (interface)
    ↓
Application (use case)
    ↓
Domain (business logic) → EventPublisher (Redis Pub/Sub)
    ↓
Infrastructure (persistence) → MySQL / Redis
    ↓
Response (RFC 7807 wrapper)
```

---

### File Organization Patterns

**Configuration Files:**

| File | Purpose |
|------|---------|
| `composer.json` | PHP dependencies (Hyperf, Swoole, Rubix ML) |
| `docker-compose.yml` | Local development (MySQL, Redis, Grafana) |
| `.env.example` | Environment variables template |
| `.php-cs-fixer.php` | PSR-12 coding standard rules |
| `phpunit.xml` | Test configuration |

**Source Organization:**

- **Controller/** - HTTP interface layer
- **Application/** - Use case orchestrators
- **Domain/** - Pure business logic (no framework dependencies)
- **Infrastructure/** - External concerns (DB, Redis, logging)

**Test Organization:**

- **tests/Unit/Domain/** - Domain logic tests (no framework)
- **tests/Integration/Controller/** - HTTP endpoint tests
- **tests/Integration/Repository/** - Database tests
- **tests/Feature/** - End-to-end scenarios

**Asset Organization:**

- **public/assets/css/** - BEM-scoped CSS files
- **public/assets/js/** - Minimal vanilla JavaScript
- **views/** - Twig templates (component-based)

---

### Development Workflow Integration

**Development Server Structure:**

```bash
docker-compose up          # Starts MySQL, Redis, Grafana
bin/hyperf.php            # Swoole HTTP server
```

**Build Process Structure:**

```bash
composer install          # Install dependencies
php bin/hyperf.php        # Run Swoole server
docker-compose build       # Build production image
```

**Deployment Structure:**

```bash
docker build -t ec-hub .
docker run -p 9501:9501 ec-hub
```

---

### Quick Reference: FR → File Mapping

| FR Range | Feature | Primary Files |
|----------|---------|--------------|
| FR1-FR6 | Product Browsing | `ProductController`, `GetProductList`, `ProductRepository` |
| FR7-FR14 | ML Recommendation | `KNNService` ⭐, `RuleBasedFallback` |
| FR15-FR20 | Event Tracking | `EventPublisher`, `RedisEventBus` |
| FR21-FR28 | Metrics Dashboard | `MetricsController`, `metrics.html.twig` |
| FR29-FR35 | Monitoring | `HealthCheck`, `MetricsCollector` |
| FR36-FR44 | Developer Experience | `README.md`, `docker-compose.yml` |
| FR45-FR53 | Architecture Quality | PSR-12 compliance, Clean Architecture |
| FR54-FR60 | Testing | `tests/` (70% coverage) |
| FR61-FR69 | Documentation | `LEARNING_JOURNAL.md` |
| FR70-FR85 | Authentication | `AuthMiddleware`, `AuthenticationService` |
| FR86-FR94 | Shopping Cart | `CartController`, `CartSessionService` |
| FR95-FR102 | Checkout | `CheckoutController`, `CreateOrder` |

⭐ = **Core Differentiator** - Rubix ML KNN em PHP 7.4

---

## Architecture Validation Results

### Coherence Validation ✅

**Decision Compatibility:**

Todas as decisões tecnológicas são mutuamente compatíveis:

- **Stack Version Compatibility:**
  - PHP 7.4 ✅ Hyperf 2.2 ✅ Swoole 4.x/5.x
  - Redis 7.x ✅ Session storage + Pub/Sub
  - MySQL 8.x ✅ Hyperf Database

- **Pattern Compatibility:**
  - Clean Architecture (4 camadas) ✅ DDD bounded contexts
  - PSR-12 coding standard ✅ PHP 7.4 features
  - RFC 7807 (errors) ✅ REST API

- **Architecture Patterns:**
  - Data Mapper ✅ Domain independence from infrastructure
  - Repository Interface ✅ Testabilidade via InMemory repositories
  - Event Publisher ✅ Redis Pub/Sub integration

**Pattern Consistency:**

Todos os padrões de implementação suportam as decisões arquiteturais:

- **Naming:** `snake_case` (DB/JSON) + `camelCase` (PHP) + `PascalCase` (Classes) = PSR-12 compliant
- **Structure:** `app/{Controller,Application,Domain,Infrastructure}` = Clean Architecture layers
- **Communication:** `events:noun.verb` + `dot.notation` sessions = Event-driven consistency

**Structure Alignment:**

A estrutura do projeto suporta todas as decisões:

- **Bounded Contexts:** Product, User, Cart, Recommendation, Metrics - cada um com suas camadas completas
- **Integration Points:** Redis (session, cache, pub/sub), MySQL (persistência) - claramente definidos
- **Test Isolation:** `tests/Unit/Domain/` permite testar lógica de negócio sem framework

---

### Requirements Coverage Validation ✅

**Epic/Feature Coverage:**

| FR Range | Feature | Arquitetura | Status |
|----------|---------|--------------|--------|
| FR1-FR6 | Product Browsing | ProductController + ProductRepository | ✅ |
| FR7-FR14 | **ML Recommendation** ⭐ | **KNNService (Rubix ML)** | ✅ |
| FR15-FR20 | Event Tracking | EventPublisher + Redis Pub/Sub | ✅ |
| FR21-FR28 | **Metrics Dashboard** 🎯 | MetricsController + `/metrics` endpoint | ✅ |
| FR29-FR35 | Monitoring | HealthCheck + LoggerFactory | ✅ |
| FR36-FR44 | Developer Experience | Docker Compose + README | ✅ |
| FR70-FR85 | Authentication | AuthMiddleware + AuthenticationService | ✅ |
| FR86-FR94 | Shopping Cart | CartController + CartSessionService | ✅ |
s
**Non-Functional Requirements Coverage:**

| NFR Category | Implementation | Status |
|--------------|---------------|--------|
| **Performance** | Swoole coroutines + Redis cache | ✅ |
| **Accessibility** | WCAG AA + BEM CSS + semantic HTML | ✅ |
| **Reliability** | Monolog JSON + health checks | ✅ |
| **Security** | bcrypt + CSRF middleware | ✅ |
| **Scalability** | Stateless workers + Redis shared storage | ✅ |

---

### Implementation Readiness Validation ✅

**Decision Completeness:**

- ✅ **Critical Decisions:** 5 (Data, Auth, API, Frontend, Infrastructure)
- ✅ **Technology Versions:** Todas especificadas (PHP 7.4, Hyperf 2.2, Swoole 4.x/5.x, Redis 7.x, MySQL 8.x)
- ✅ **Impact Analysis:** Implementation sequence definida

**Structure Completeness:**

- ✅ **Directory Tree:** 50+ arquivos/diretórios especificados
- ✅ **Component Boundaries:** 5 bounded contexts com camadas completas
- ✅ **Integration Points:** 4 externos (MySQL, Redis session, Redis cache, Redis Pub/Sub)

**Pattern Completeness:**

- ✅ **25 Conflict Points:** Todos identificados e resolvidos
- ✅ **10 Mandatory Rules:** Para agentes de IA seguirem
- ✅ **Good/Anti-Patterns:** Exemplos concretos fornecidos

---

### Gap Analysis Results

**Critical Gaps:** 0

**Important Gaps:** 0

**Nice-to-Have Gaps (Post-MVP):**

1. **OpenTelemetry Tracing** - Opcional para MVP, mencionado em NFRs
2. **Kubernetes Deployment** - FR119 (Vision scope), não necessário para POC
3. **Advanced RBAC/ACL** - Simple role checks sufficientes para POC

---

### Architecture Completeness Checklist

**✅ Requirements Analysis**
- [x] Project context thoroughly analyzed
- [x] Scale and complexity assessed (Medium-High technical POC)
- [x] Technical constraints identified (PHP 7.4, ML in PHP)
- [x] Cross-cutting concerns mapped (8 identified)

**✅ Architectural Decisions**
- [x] Critical decisions documented with versions
- [x] Technology stack fully specified
- [x] Integration patterns defined (Redis Pub/Sub, Session)
- [x] Performance considerations addressed (< 200ms ML, < 500ms dashboard)

**✅ Implementation Patterns**
- [x] Naming conventions established (PSR-12, snake_case/camelCase)
- [x] Structure patterns defined (Clean Architecture + DDD)
- [x] Communication patterns specified (RFC 7807, event naming)
- [x] Process patterns documented (error handling, loading states)

**✅ Project Structure**
- [x] Complete directory structure defined (50+ files/directories)
- [x] Component boundaries established (5 bounded contexts)
- [x] Integration points mapped (MySQL, Redis, Swoole)
- [x] Requirements to structure mapping complete (119 FRs mapped)

---

### Architecture Readiness Assessment

**Areas for Future Enhancement (Post-MVP):**

1. **Distributed Tracing** - OpenTelemetry para tracing de requests (NFR opcional)
2. **Container Orchestration** - Kubernetes deployment (FR119 Vision scope)
3. **Advanced Authorization** - RBAC/ACL se POC evoluir para produção

---

### Implementation Handoff

**AI Agent Guidelines:**

1. **Follow all architectural decisions exactly as documented** - Não desviar das versões especificadas
2. **Use implementation patterns consistently** - Seguir os 10 mandatory rules rigorosamente
3. **Respect project structure and boundaries** - Manter bounded contexts separados
4. **Refer to this document for all architectural questions** - Este documento é a fonte de verdade

**First Implementation Priority:**

```bash
docker-compose up              # Start MySQL, Redis, Grafana
composer install                # Install dependencies
```

**First Story:** Setup do projeto com estrutura base + Docker Compose configuration

---

### Quick Reference for Implementation

| Aspecto | Decisão | Localização |
|---------|--------|------------|
| **Stack** | PHP 7.4 + Hyperf 2.2 + Swoole | `composer.json` |
| **Database** | MySQL 8.x | `Infrastructure/Persistence/MySQL/` |
| **Cache/Session** | Redis 7.x | `Infrastructure/Persistence/Redis/` |
| **Events** | Redis Pub/Sub | `Infrastructure/Messaging/RedisEventBus` |
| **ML** | Rubix ML KNN | `Domain/Recommendation/Service/KNNService` |
| **Templates** | Twig | `views/` |
| **Tests** | PHPUnit + Hyperf Testing | `tests/` |
| **Patterns** | PSR-12 + Clean Arch + DDD | Todo o projeto |

---

## Architecture Completion Summary

### Workflow Completion

**Architecture Decision Workflow:** COMPLETED ✅
**Total Steps Completed:** 8
**Date Completed:** 2026-02-02
**Document Location:** [architecture.md](/Users/debor/Documents/sistemas/ec-hub/_bmad-output/planning-artifacts/architecture.md)

### Final Architecture Deliverables

**📋 Complete Architecture Document**

- All architectural decisions documented with specific versions
- Implementation patterns ensuring AI agent consistency
- Complete project structure with all files and directories
- Requirements to architecture mapping
- Validation confirming coherence and completeness

**🏗️ Implementation Ready Foundation**

- **5 architectural decisions** (Data, Auth, API, Frontend, Infrastructure)
- **25 conflict points** resolved with implementation patterns
- **5 bounded contexts** (Product, User, Cart, Recommendation, Metrics)
- **119 functional requirements** fully supported
- **40 non-functional requirements** addressed

**📚 AI Agent Implementation Guide**

- Technology stack with verified versions
- Consistency rules that prevent implementation conflicts
- Project structure with clear boundaries
- Integration patterns and communication standards

### Implementation Handoff

**For AI Agents:**
This architecture document is your complete guide for implementing ec-hub. Follow all decisions, patterns, and structures exactly as documented.

**First Implementation Priority:**

```bash
docker-compose up              # Start MySQL, Redis, Grafana
composer install                # Install dependencies
```

**Development Sequence:**

1. Initialize project with Docker Compose + structure base
2. Set up development environment per architecture
3. Implement core architectural foundations (migrations, repositories)
4. Build features following established patterns
5. Maintain consistency with documented rules

### Quality Assurance Checklist

**✅ Architecture Coherence**

- [x] All decisions work together without conflicts
- [x] Technology choices are compatible (PHP 7.4 + Hyperf 2.2 + Swoole 4.x/5.x)
- [x] Patterns support the architectural decisions
- [x] Structure aligns with all choices

**✅ Requirements Coverage**

- [x] All functional requirements (119 FRs) are supported
- [x] All non-functional requirements (40 NFRs) are addressed
- [x] Cross-cutting concerns (8) are handled
- [x] Integration points are defined

**✅ Implementation Readiness**

- [x] Decisions are specific and actionable
- [x] Patterns prevent agent conflicts
- [x] Structure is complete and unambiguous
- [x] Examples are provided for clarity

### Project Success Factors

**🎯 Clear Decision Framework**

Every technology choice was made collaboratively with clear rationale, ensuring all stakeholders understand the architectural direction.

**🔧 Consistency Guarantee**

Implementation patterns and rules ensure that multiple AI agents will produce compatible, consistent code that works together seamlessly.

**📋 Complete Coverage**

All project requirements are architecturally supported, with clear mapping from business needs to technical implementation.

**🏗️ Solid Foundation**

The custom setup (not skeleton) demonstrates deep architectural understanding following Clean Architecture + DDD principles.

---

**Architecture Status:** READY FOR IMPLEMENTATION ✅

**Next Phase:** Begin implementation using the architectural decisions and patterns documented herein.

**Document Maintenance:** Update this architecture when major technical decisions are made during implementation.

---

### Key Differentiators to Emphasize

1. **ML in PHP 7.4** - Extremely rare, demonstrates adaptability
2. **Event-Driven Architecture** - Redis Pub/Sub for all components
3. **Clean Architecture + DDD** - Enterprise-grade structure
4. **Server-Side Rendering** - MPA with Swoole (not conventional Next.js)
5. **Technical Storytelling** - Every architectural decision is visible and explainable
