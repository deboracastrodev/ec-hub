# Project Structure - ec-hub

## Overview

This project follows **Clean Architecture** with **Domain-Driven Design (DDD)** patterns. The architecture is organized into 4 layers with dependencies pointing inward, making the core business logic independent of external concerns.

```
┌─────────────────────────────────────────────────────────────────┐
│                    Clean Architecture - 4 Layers                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│    ┌─────────────┐                                               │
│    │ Controller  │  ←── HTTP requests enter here                │
│    │  (Layer 1)  │                                               │
│    └──────┬──────┘                                               │
│           │ depends on                                           │
│    ┌──────▼──────┐                                               │
│    │Application  │  ←── Use cases / Orchestrators                │
│    │  (Layer 2)  │                                               │
│    └──────┬──────┘                                               │
│           │ depends on                                           │
│    ┌──────▼──────┐                                               │
│    │   Domain    │  ←── Core business logic (DDD)                │
│    │  (Layer 3)  │       NO dependencies outward!                │
│    └─────────────┘                                               │
│                                                                   │
│    ┌─────────────┐                                               │
│    │Infrastructure│  ←── Database, Redis, External APIs          │
│    │  (Layer 4)  │                                               │
│    └─────────────┘                                               │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

## Directory Structure

```
ec-hub/
├── app/                              # Application code (PSR-4 autoloading)
│   ├── Controller/                   # Layer 1: Interface Layer
│   │   └── .gitkeep                  # HTTP request handlers, controllers
│   │
│   ├── Application/                  # Layer 2: Use Cases
│   │   └── .gitkeep                  # Application services, orchestrators
│   │
│   ├── Domain/                       # Layer 3: Core Business Logic (DDD)
│   │   ├── Product/                  # Product bounded context
│   │   │   ├── Model/                #   Entities, Value Objects
│   │   │   ├── Repository/           #   Repository interfaces
│   │   │   └── Service/              #   Domain services
│   │   ├── User/                     # User bounded context
│   │   │   ├── Model/
│   │   │   ├── Repository/
│   │   │   └── Service/
│   │   ├── Cart/                     # Cart bounded context
│   │   ├── Recommendation/           # Recommendation bounded context
│   │   ├── Metrics/                  # Metrics bounded context
│   │   └── Shared/                   # Shared domain code
│   │       ├── ValueObject/          # Shared value objects
│   │       └── Event/                # Domain events
│   │
│   ├── Infrastructure/               # Layer 4: External Concerns
│   │   ├── Persistence/              # Database, ORM
│   │   ├── Messaging/                # Redis Pub/Sub
│   │   └── Monitoring/               # Logging, metrics collection
│   │
│   └── Shared/                       # Cross-cutting utilities
│       ├── Helper/                   # ResponseFormatter, ErrorBuilder
│       ├── Middleware/               # HTTP middleware
│       └── Trait/                    # Reusable traits
│
├── config/                           # Configuration files
│   ├── autoload.php                 # PSR-4 autoloading
│   └── server.php                   # Swoole HTTP server config
│
├── public/                           # Public web root
│   └── index.php                    # Application entry point
│
├── tests/                            # Test suite (PHPUnit)
│   ├── Unit/                         #   Unit tests (Domain/Application)
│   ├── Integration/                  #   Integration tests (HTTP + DB + Redis)
│   └── Feature/                      #   Feature/E2E tests
│
├── views/                            # View templates (if needed)
│   ├── product/
│   ├── user/
│   ├── cart/
│   ├── recommendation/
│   └── metrics/
│
├── docs/                             # Documentation
│   ├── STRUCTURE.md                 # This file
│   └── CODING-STANDARDS.md          # PSR-12 coding standards
│
├── vendor/                           # Composer dependencies (gitignored)
├── runtime/                          # Runtime files (gitignored)
│   └── logs/                        # Application logs
│
├── .env.example                      # Environment variables template
├── .gitignore                        # Git ignore rules
├── .php-cs-fixer.php                # PSR-12 code style configuration
├── composer.json                     # PHP dependencies
├── phpunit.xml                       # PHPUnit configuration
├── Makefile                          # Development commands
├── docker-compose.yml                # Docker services
└── Dockerfile                        # PHP-FPM container
```

## DDD Bounded Contexts

### 1. Product Context (`app/Domain/Product/`)
**Purpose:** Catalog management and product information

| Component | Description |
|-----------|-------------|
| `Model/Product.php` | Product entity (id, name, price, category) |
| `Repository/ProductRepositoryInterface.php` | Product data access contract |
| `Service/ProductService.php` | Product business logic |

**Examples:** Listing products, filtering by category, product details

---

### 2. User Context (`app/Domain/User/`)
**Purpose:** User management and authentication

| Component | Description |
|-----------|-------------|
| `Model/User.php` | User entity (id, email, password_hash) |
| `Repository/UserRepositoryInterface.php` | User data access contract |
| `Service/AuthenticationService.php` | Authentication logic |

**Examples:** User registration, login, profile management

---

### 3. Cart Context (`app/Domain/Cart/`)
**Purpose:** Shopping cart and session management

| Component | Description |
|-----------|-------------|
| `Model/Cart.php` | Cart entity |
| `Model/CartItem.php` | Cart item entity |
| `Repository/CartRepositoryInterface.php` | Cart data access contract |
| `Service/CartSessionService.php` | Redis-based cart session logic |

**Examples:** Add to cart, remove item, update quantity, persist cart

---

### 4. Recommendation Context (`app/Domain/Recommendation/`)
**Purpose:** ML-based product recommendations

| Component | Description |
|-----------|-------------|
| `Service/KNNService.php` | K-Nearest Neighbors using Rubix ML |
| `Service/RuleBasedFallback.php` | Fallback to rule-based recommendations |
| `Repository/RecommendationRepositoryInterface.php` | Cache recommendation results |

**Examples:** "Users who viewed X also viewed Y", "Recommended for you"

---

### 5. Metrics Context (`app/Domain/Metrics/`)
**Purpose:** Event tracking and system monitoring

| Component | Description |
|-----------|-------------|
| `Service/MetricsCollector.php` | Collect user events |
| `Service/SystemHealthService.php` | Memory, CPU, Swoole stats |
| `Repository/MetricsRepositoryInterface.php` | Metrics storage |

**Examples:** Page views, product interactions, session tracking

---

## Understanding the Architecture

#### Step 1: Observe as 4 Camadas

```bash
cd ec-hub
ls app/
```

You'll see 4 folders representing Clean Architecture layers:
1. **Controller** - Receives HTTP requests
2. **Application** - Orchestrates use cases
3. **Domain** - Pure business logic (independent!)
4. **Infrastructure** - Database, Redis, external APIs

#### Step 2: Explore DDD Bounded Contexts

```bash
ls app/Domain/
```

Each folder is a **bounded context** - an independent business domain:
- **Product** - Catalog management
- **User** - Authentication & users
- **Cart** - Shopping cart
- **Recommendation** - ML recommendations (core differentiator!)
- **Metrics** - Monitoring & analytics

Each context has:
- `Model/` - Business entities
- `Repository/` - Data access interfaces (decoupled from implementation!)
- `Service/` - Domain logic

#### Step 3: Understand Dependency Direction

Open any file in `Controller/`:
```bash
cat app/Controller/ProductController.php  # (example)
```

You'll see it depends on `Application` layer.

Open any file in `Application/`:
```bash
cat app/Application/ProductService.php  # (example)
```

You'll see it depends on `Domain` layer.

**Key Insight:** Dependencies point **inward**. Domain layer has ZERO dependencies on outer layers - making it testable and business-focused.

#### Step 4: Check Repository Interfaces

```bash
cat app/Domain/Product/Repository/ProductRepositoryInterface.php
```

Notice it's an **interface**, not implementation! The actual database code lives in `Infrastructure/`. This is **Dependency Inversion Principle** (SOLID).

#### Step 5: Review Code Quality Setup

```bash
cat .php-cs-fixer.php  # PSR-12 coding standards
cat phpunit.xml         # 70% test coverage required
cat Makefile            # Developer-friendly commands
```

---

## Key Architectural Decisions

| Decision | Rationale |
|----------|-----------|
| **Clean Architecture** | Separates business logic from technical concerns |
| **DDD Bounded Contexts** | Organizes code by business domain, not technical layers |
| **Repository Interfaces** | Enables testing without database (Dependency Inversion) |
| **PSR-12 Standards** | Industry-accepted PHP coding style |
| **70% Test Coverage Target** | Ensures code quality in Domain + Application layers |
| **Swoole HTTP Server** | High-performance async PHP for production |

---

## Request Flow Example

When a user requests `/products/123`:

```
1. public/index.php (Swoole HTTP Server)
   └─ Receives request

2. app/Controller/ProductController.php
   └─ Validates HTTP input
   └─ Calls Application layer

3. app/Application/ProductService.php
   └─ Orchestrates use case
   └─ Calls Domain layer

4. app/Domain/Product/Service/ProductService.php
   └─ Executes business logic
   └─ Uses Repository interface

5. app/Infrastructure/Persistence/ProductRepository.php
   └─ Executes SQL query
   └─ Returns data

6. Response flows back through layers
   └─ Formatted by app/Shared/Helper/ResponseFormatter.php
   └─ Returned as JSON (RFC 7807 format for errors)
```

---

## Getting Started

```bash
# Install dependencies
make install

# Fix code style
make cs-fix

# Run tests
make test

# Start Docker containers
make up

# View logs
make logs
```

---

## References

- [Clean Architecture by Robert C. Martin](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Domain-Driven Design by Eric Evans](https://www.domainlanguage.com/ddd/)
- [PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/)
- [RFC 7807: Problem Details for HTTP APIs](https://datatracker.ietf.org/doc/html/rfc7807)
