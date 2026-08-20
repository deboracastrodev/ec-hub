# Project Structure - ec-hub

## Overview

O ec-hub segue **Clean Architecture**, organizado em 4 camadas com dependências apontando para dentro. O `Domain` não importa nenhuma biblioteca externa — nem mesmo o Rubix ML, que fica inteiramente atrás de uma interface (ver "O Domain e o Rubix ML" abaixo).

```
┌─────────────────────────────────────────────────────────────────┐
│                    Clean Architecture - 4 Layers                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│    ┌─────────────┐                                               │
│    │ Controller  │  ←── HTTP requests entram aqui                │
│    │  (Layer 1)  │                                               │
│    └──────┬──────┘                                               │
│           │ depende de                                           │
│    ┌──────▼──────┐                                               │
│    │Application  │  ←── Casos de uso / orquestradores            │
│    │  (Layer 2)  │                                               │
│    └──────┬──────┘                                               │
│           │ depende de                                           │
│    ┌──────▼──────┐                                               │
│    │   Domain    │  ←── Lógica de negócio pura (DDD)              │
│    │  (Layer 3)  │       ZERO dependências externas!              │
│    └─────────────┘                                               │
│                                                                   │
│    ┌─────────────┐                                               │
│    │Infrastructure│  ←── Banco, APIs externas, ML                │
│    │  (Layer 4)  │                                               │
│    └─────────────┘                                               │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

## Directory Structure

Árvore real (`find app -name '*.php'`), sem `.gitkeep` de andaimes vazios:

```
ec-hub/
├── app/
│   ├── Controller/
│   │   ├── ProductController.php
│   │   ├── RecommendationController.php
│   │   └── Exceptions/
│   │       └── InvalidRequestException.php
│   │
│   ├── Application/
│   │   ├── Product/
│   │   │   ├── GetProductList.php
│   │   │   └── GetProductDetail.php
│   │   ├── Recommendation/
│   │   │   ├── GenerateRecommendations.php     # caso de uso principal
│   │   │   └── RecommendationDTO.php
│   │   └── SEO/Service/
│   │       └── MetaTagsService.php             # OG/Twitter/JSON-LD por página
│   │
│   ├── Domain/
│   │   ├── Product/
│   │   │   ├── Model/Product.php
│   │   │   ├── Repository/ProductRepositoryInterface.php  # devolve Product, não array
│   │   │   └── Service/CategoryService.php
│   │   ├── Recommendation/
│   │   │   ├── Service/KNNService.php               # orquestra o NeighborFinder
│   │   │   ├── Service/NeighborFinderInterface.php  # porta -- sem tipo de Rubix aqui
│   │   │   ├── Service/RuleBasedFallback.php
│   │   │   ├── Model/RecommendationResult.php
│   │   │   ├── Exception/RecommendationException.php
│   │   │   └── ValueObject/RecommendationSettings.php
│   │   └── Shared/ValueObject/Money.php
│   │
│   ├── Infrastructure/
│   │   ├── ML/RubixNeighborFinder.php         # único arquivo que importa Rubix\*
│   │   └── Persistence/
│   │       ├── MySQL/ProductRepository.php    # hidrata Product a partir das rows
│   │       └── Migration/...
│   │
│   └── Shared/
│       ├── Container/Container.php            # container PSR-11 mínimo
│       └── Http/{Router,ErrorHandler,MatchedRoute}.php
│
├── config/
│   ├── bootstrap.php      # monta o Container; único ponto de wiring de dependências
│   ├── twig.php
│   └── recommendation.php # lido uma vez pelo bootstrap; vira RecommendationSettings
│
├── public/
│   └── index.php          # estáticos, roteamento, despacho, delega erro pro ErrorHandler
│
├── tests/
│   ├── Unit/               # sem banco, sem I/O
│   ├── Integration/        # HTTP + DB (testes que precisam de MySQL usam #[Group('db')])
│   ├── Support/            # RequiresDatabase (skip gracioso), InMemoryProductRepository
│   └── docker/             # valida Dockerfile/compose/.env.example/Makefile
│
├── views/
│   ├── layout/base.html.twig
│   ├── product/{listing,detail}.html.twig
│   └── error/{400,404,500}.html.twig
│
├── docs/
│   ├── STRUCTURE.md            # este arquivo
│   ├── architecture.md         # decisões (ADRs)
│   ├── CODING-STANDARDS.md     # PSR-12 + convenções do projeto
│   └── remediation-spec.md     # histórico da remediação de consistência
│
├── .github/workflows/ci.yml
├── vendor/          # gitignored
├── runtime/logs/    # gitignored
├── composer.json / composer.lock (versionado)
├── phpunit.xml
├── .php-cs-fixer.php
├── Makefile
├── docker-compose.yml
└── Dockerfile
```

## Bounded Contexts do Domain

### Product (`app/Domain/Product/`)
Catálogo: entidade `Product` (com `Money` para preço, `slug`), `ProductRepositoryInterface` (devolve entidades, não arrays), `CategoryService` (normalização de categoria, contagens).

### Recommendation (`app/Domain/Recommendation/`)
KNN item-a-item + fallback baseado em regras. `KNNService` não sabe nada de Rubix — pede vizinhos a um `NeighborFinderInterface`, calcula score e explicação (regra de negócio, fica no Domain). `RuleBasedFallback` cobre catálogo pequeno demais ou falha do KNN. `RecommendationSettings` (value object) carrega a estratégia de fallback e os ranges de score, injetada no construtor — nenhuma das duas classes lê `config/recommendation.php` do disco diretamente.

### Shared (`app/Domain/Shared/`)
Só `Money` (centavos como inteiro, evita erro de ponto flutuante). `getDecimal()` para a API/persistência, `getFormatted()` só na fronteira com a view (filtro Twig `BRL`).

## O Domain e o Rubix ML

`App\Domain\Recommendation\Service\NeighborFinderInterface` é a porta. `App\Infrastructure\ML\RubixNeighborFinder` é a única implementação, e o único arquivo do projeto que importa `Rubix\ML\*`:

```
Labeled dataset (features: categoria + preço, labels: product_id)
  -> OneHotEncoder    (categoria -> colunas binárias)
  -> MinMaxNormalizer (tudo escalado para [0, 1])
  -> BallTree::nearest($sample, $k)   (busca de k-NN)
```

`BallTree::nearest()` é a primitiva que todo estimador de k-NN do Rubix usa por baixo; está marcada `@internal` no Rubix, então uma quebra dela em uma atualização de versão fica confinada a este único arquivo — o `Domain` não é afetado.

## Container (`app/Shared/Container/Container.php`)

Implementa `Psr\Container\ContainerInterface`. `config/bootstrap.php` registra uma factory por classe/interface (chave = FQCN); a resolução é lazy e memoizada — uma entrada só é construída na primeira vez que alguém chama `get()` para ela. É essa laziness que garante que uma requisição de asset estático ou 404 nunca abre conexão com o MySQL: `PDO::class` só é resolvido se algo na cadeia de dependências de fato pedir por ele.

## Fluxo de requisição — `GET /products/{slug}`

```
1. public/index.php
   └─ static file? não → Router::match('GET', '/products/lampada-de-mesa')
      └─ casa a rota de padrão '/products/([A-Za-z0-9-]+)' → MatchedRoute

2. $container->get(ProductController::class)
   └─ resolve GetProductList, GetProductDetail, Twig\Environment, MetaTagsService
      (cada um resolvendo suas próprias dependências, até chegar em PDO::class)

3. ProductController::show('lampada-de-mesa')
   └─ GetProductDetail::executeByIdentifier()
      └─ ProductRepositoryInterface::findBySlug() (Infrastructure hidrata a row em Product)
      └─ Product::toArray() -- serialização acontece aqui, na borda Application/View

4. MetaTagsService::generateForPage('product.detail', [...])
   └─ og:title, canonical, JSON-LD Product

5. Twig renderiza product/detail.html.twig com 'product' e 'meta'
```

## Fluxo de requisição — `GET /api/recommendations?product_id=X`

```
1. public/index.php → Router casa a rota exata 'GET /api/recommendations' (api: true)

2. $container->get(RecommendationController::class)
   └─ resolve GenerateRecommendations
      └─ resolve KNNService (+ NeighborFinderInterface = RubixNeighborFinder)
      └─ resolve RuleBasedFallback (+ RecommendationSettings)

3. RecommendationController::getRecommendations()
   └─ valida product_id e limit (400 se inválidos)
   └─ GenerateRecommendations::execute()
      └─ produto não encontrado → RuleBasedFallback::getPopularRecommendations() (cold-start)
      └─ catálogo pequeno demais → RuleBasedFallback::getRecommendations()
      └─ senão → KNNService::recommend() (pede limit+1 vizinhos ao NeighborFinder,
                 exclui o próprio produto, monta RecommendationResult com score+explicação)
      └─ se o KNN devolver menos que o limit, completa com fallback

4. Exceção de domínio (RecommendationException), se houver, propaga sem
   tratamento até o ErrorHandler em public/index.php -- mapear pra 500 é
   responsabilidade da borda, não do Controller nem do caso de uso

5. Resposta JSON: { data: [...], meta: { source, count, response_time_ms } }
   Headers: X-Recommendation-Source, X-Response-Time
```

## Testes

Convenções:

- `tests/Unit/` — sem PDO, sem HTTP, sem filesystem
- `tests/Integration/` — pode tocar banco real (MySQL via Docker) ou SQLite in-memory; classes que exigem MySQL de verdade carregam `Tests\Support\RequiresDatabase` e o atributo `#[Group('db')]`, e fazem skip gracioso (não error) quando o banco está indisponível
- `tests/Integration/View/` nunca toca banco — usa `Tests\Support\InMemoryProductRepository`
- `tests/docker/` — scripts shell + testes PHPUnit que validam Dockerfile, docker-compose.yml, `.env.example` e Makefile contra o estado real do projeto

## Referências

- [Clean Architecture by Robert C. Martin](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Domain-Driven Design by Eric Evans](https://www.domainlanguage.com/ddd/)
- [PSR-11: Container Interface](https://www.php-fig.org/psr/psr-11/)
- [PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/)
