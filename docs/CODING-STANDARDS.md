# Coding Standards - ec-hub

## PSR-12 Compliance

This project follows **PSR-12: Extended Coding Style** per the story requirements.

Run code style checks with:
```bash
make cs-check  # Check without fixing
make cs-fix    # Auto-fix issues
```

## Naming Conventions

### Database
| Type | Convention | Example |
|------|------------|---------|
| Tables | `snake_case` plural | `users`, `cart_items`, `product_categories` |
| Columns | `snake_case` | `user_id`, `created_at`, `product_name` |
| Indexes | `idx_` prefix | `idx_users_email`, `idx_products_category` |
| Foreign keys | `fk_` prefix | `fk_cart_items_user_id` |

### PHP Code
| Type | Convention | Example |
|------|------------|---------|
| Classes | `PascalCase` | `ProductController`, `CategoryService`, `KNNService` |
| Methods | `camelCase` | `getRecommendations()`, `findByCategory()`, `isTrained()` |
| Variables | `camelCase` | `$productId`, `$categoryLabel`, `$isApiRoute` |
| Constants | `UPPER_SNAKE_CASE` | `MAX_LIMIT`, `DEFAULT_LIMIT` |
| Properties | `camelCase` (private/protected) | `private ProductRepositoryInterface $productRepository` |

### Files
| Type | Convention | Example |
|------|------------|---------|
| Classes | `PascalCase.php` | `UserController.php`, `ProductRepository.php` |
| Interfaces | `PascalCaseInterface.php` | `ProductRepositoryInterface.php` |
| Traits | `PascalCaseTrait.php` | `CacheableTrait.php` |
| Tests | `PascalCaseTest.php` | `UserServiceTest.php` |

## Code Style Rules

### PHP Opening Tag
```php
<?php

declare(strict_types=1);
```

### Class Definition
```php
namespace App\Domain\Product\Repository;

interface ProductRepositoryInterface
{
    // Devolve entidade, não array -- ver "Repositório devolve entidade" abaixo
    public function findById(int $id): ?Product;
}
```

### Method Declarations
```php
public function getUserData(int $userId): array
{
    return $this->repository->findById($userId);
}
```

### Control Structures
```php
if ($condition) {
    // action
} elseif ($otherCondition) {
    // other action
} else {
    // default action
}

foreach ($items as $item) {
    // process item
}
```

### Arrays
Use short syntax `[]`:
```php
$items = ['apple', 'banana', 'cherry'];
$data = [
    'id' => 1,
    'name' => 'Product',
];
```

## Clean Architecture Layer Organization

```
app/
├── Controller/           # Layer 1: HTTP request handlers
├── Application/          # Layer 2: Use cases / Orchestrators
├── Domain/               # Layer 3: Core business logic (DDD)
│   ├── Product/         # Bounded context
│   │   ├── Model/       # Entities, Value Objects
│   │   ├── Repository/  # Repository interfaces
│   │   └── Service/     # Domain services
│   └── Recommendation/  # Bounded context (KNN + fallback)
├── Infrastructure/       # Layer 4: External concerns
│   ├── ML/               # Rubix ML lives only here -- see rule below
│   └── Persistence/
└── Shared/                # Container (PSR-11), Http (Router, ErrorHandler)
```

Ver [docs/STRUCTURE.md](STRUCTURE.md) para a árvore completa e atualizada.

## Regras estabelecidas na remediação de consistência

Estas regras não eram escritas em lugar nenhum antes da remediação de 2026-08 ([docs/remediation-spec.md](remediation-spec.md)) e causaram inconsistência real no código. Documentadas aqui para não se repetirem.

### O Domain nunca importa biblioteca externa

Nenhuma classe em `app/Domain/` pode ter um `use` de um pacote de terceiros (Rubix, Twig, PDO, Guzzle, etc.). Quando o Domain precisa de uma capacidade externa, ele declara uma interface (`NeighborFinderInterface`, `ProductRepositoryInterface`) e a implementação real fica em `app/Infrastructure/`.

```php
// ERRADO -- app/Domain/Recommendation/Service/KNNService.php
use Rubix\ML\Kernels\Distance\Euclidean;

// CERTO -- a interface fica no Domain, a implementação no Infrastructure
// app/Domain/Recommendation/Service/NeighborFinderInterface.php
interface NeighborFinderInterface { /* sem tipo de Rubix aqui */ }
// app/Infrastructure/ML/RubixNeighborFinder.php
use Rubix\ML\Graph\Trees\BallTree; // só aqui
```

### Repositório do Domain devolve entidade, não array

`ProductRepositoryInterface::findById()` devolve `?Product`, não `?array`. A hidratação (`Product::fromArray($row)`) acontece dentro da implementação MySQL, em `app/Infrastructure/`. Quando o array precisar sair de novo (para a view, para o JSON), a serialização (`Product::toArray()`) acontece na borda Application → Controller — nunca dentro do Domain.

### Configuração é injetada, nunca lida do disco dentro de Application/Domain

Nenhuma classe fora de `config/bootstrap.php` faz `require 'config/*.php'` ou `getenv()` diretamente. Configuração vira um value object (`RecommendationSettings`) montado uma vez no bootstrap e injetado no construtor de quem precisa dela.

### `Money` até a última fronteira; formatação só na view

`App\Domain\Shared\ValueObject\Money` guarda centavos como inteiro. `getDecimal()` (float) é o que atravessa API, DTO e persistência. `getFormatted()` ("R$ x,xx") só é chamado no filtro Twig `BRL`, na view — nunca dentro de Controller, Application ou Domain. Se você está fazendo parse de string de moeda com regex em algum lugar do código, algo está errado — o valor já deveria estar chegando como `float`.

## API Response Format

### Success Response
```json
{
  "data": [
    { "id": 1, "name": "Produto Exemplo", "price": 149.9, "score": 91.03, "explanation": "..." }
  ],
  "meta": {
    "source": "ml",
    "count": 1,
    "response_time_ms": 2.34,
    "generated_at": "2026-08-20T13:50:00+00:00"
  }
}
```

### Error Response

O formato real, usado por `App\Shared\Http\ErrorHandler` (não RFC 7807 -- isso nunca chegou a ser implementado, apesar de citado em versões anteriores desta documentação):

```json
{
  "error": "product_id must be a positive integer",
  "code": 400
}
```

## Testing Conventions

- Test files end with `Test.php`
- Test class names end with `Test`
- Test method names start with `test_` and describe the behavior
- Arrange-Act-Assert pattern for test structure
- Testes que exigem MySQL real carregam `Tests\Support\RequiresDatabase` e o
  atributo `#[Group('db')]` (skip gracioso se o banco não estiver
  disponível, nunca error) -- ver `tests/Unit/...` e
  `tests/Integration/...` para exemplos
