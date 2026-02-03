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
| Classes | `PascalCase` | `UserController`, `ProductService`, `ErrorBuilder` |
| Methods | `camelCase` | `getUserData()`, `createProduct()`, `isValid()` |
| Variables | `camelCase` | `$userId`, `$productName`, `$isValid` |
| Constants | `UPPER_SNAKE_CASE` | `MAX_RETRIES`, `DEFAULT_LIMIT` |
| Properties | `camelCase` (private/protected) | `private $connectionPool` |

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
namespace App\Domain\User\Repository;

interface UserRepositoryInterface
{
    public function findById(int $id): ?array;
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
│   └── ...
└── Infrastructure/       # Layer 4: External concerns
    ├── Persistence/
    ├── Messaging/
    └── Monitoring/
```

## API Response Format

### Success Response
```json
{
  "data": {
    "id": 1,
    "name": "Produto Exemplo"
  },
  "meta": {
    "timestamp": "2025-01-15T10:30:00+00:00",
    "status": 200
  }
}
```

### Error Response (RFC 7807)
```json
{
  "type": "/errors/validation-error",
  "title": "Validation Error",
  "detail": "The product name is required",
  "status": 400,
  "instance": "/requests/abc123"
}
```

## Testing Conventions

- Test files end with `Test.php`
- Test class names end with `Test`
- Test method names start with `test_` and describe the behavior
- Arrange-Act-Assert pattern for test structure
