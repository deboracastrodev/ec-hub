# ec-hub

[![CI](https://github.com/deboracastrodev/ec-hub/actions/workflows/ci.yml/badge.svg)](https://github.com/deboracastrodev/ec-hub/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4-777884?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> **ML nativo em PHP com Clean Architecture** — catálogo de produtos + recomendações item-a-item via KNN (Rubix ML), com fallback baseado em regras.

## O que existe hoje

- **Catálogo de produtos** — listagem paginada, filtro por categoria, página de detalhe (por slug ou id), SEO (Open Graph, Twitter Card, JSON-LD real por página)
- **API de recomendações** — `GET /api/recommendations?product_id=X` devolve produtos similares via KNN (Rubix ML: `OneHotEncoder` + `MinMaxNormalizer` + `BallTree`), com fallback automático baseado em regras (categoria/popularidade) quando o catálogo é pequeno demais ou o ML falha
- **Clean Architecture** — 4 camadas (Controller/Application/Domain/Infrastructure); o Domain não importa nenhuma biblioteca externa, nem o Rubix ML (fica atrás de uma porta, em `App\Infrastructure\ML`)
- **PHP 8.4**, MySQL 8, Twig, servidor embutido do PHP (`php -S`) — sem Swoole, sem Redis
- **137 testes** (PHPUnit 12), cobertura de linhas medida em **~81%**; a suíte sem grupo `db` passa sem Docker
- **CI** (GitHub Actions): estilo (PSR-12), suíte sem banco, suíte completa com MySQL + cobertura

O que o projeto **não** faz ainda está listado no [Roadmap](#roadmap) — não é omissão, é escopo.

## Quick Start

### Pré-requisitos

- Docker Desktop (ou Docker Engine + Compose plugin)

### Setup

```bash
git clone https://github.com/deboracastrodev/ec-hub.git
cd ec-hub

cp .env.example .env

make up      # sobe os containers (app + mysql)
make setup   # espera o MySQL, instala dependências, roda migrations e seed

open http://localhost:9501
```

### Comandos úteis

```bash
make logs       # logs da aplicação
make test       # suíte completa (roda local, sem precisar do container)
make cs-check   # estilo PSR-12 (local, sem Docker)
make shell      # bash dentro do container app
make db-shell   # MySQL CLI
make down       # para os containers
```

`make test` e `make cs-check` não precisam de Docker no ar — rodam direto com `vendor/bin/`. Alvos de banco (`migrate`, `seed`, `db-shell`) precisam do container do MySQL.

## Arquitetura

4 camadas, dependências apontando para dentro (`Controller → Application → Domain`; `Infrastructure` implementa interfaces que o `Domain` declara):

```
app/
├── Controller/       # HTTP handlers (ProductController, RecommendationController)
├── Application/      # Casos de uso (GetProductList, GenerateRecommendations, ...)
├── Domain/
│   ├── Product/      # Catálogo — entidade, repositório (interface), CategoryService
│   └── Recommendation/  # KNNService, RuleBasedFallback, NeighborFinderInterface
├── Infrastructure/
│   ├── ML/            # RubixNeighborFinder — único arquivo que importa Rubix\*
│   └── Persistence/    # ProductRepository (MySQL/PDO)
└── Shared/
    ├── Container/     # Container PSR-11 mínimo
    └── Http/          # Router, ErrorHandler
```

O `Domain` não depende de framework nem de biblioteca de ML — `App\Domain\Recommendation\Service\NeighborFinderInterface` é a porta; `App\Infrastructure\ML\RubixNeighborFinder` é a única implementação, e o único lugar do projeto que importa `Rubix\ML\*`.

Detalhes completos: [docs/STRUCTURE.md](docs/STRUCTURE.md) (árvore + fluxo de requisição) e [docs/architecture.md](docs/architecture.md) (decisões, com o porquê).

## API de recomendações

```
GET /api/recommendations?product_id={id}&limit={1-50, default 10}
```

```json
{
  "data": [
    { "id": 497, "name": "Bola Futebol Oficial", "price": 460.8, "score": 91.03, "explanation": "..." }
  ],
  "meta": { "source": "ml", "count": 20, "response_time_ms": 2.34, "generated_at": "2026-08-20T13:50:00+00:00" }
}
```

- `product_id` ausente ou inválido → `400`
- `limit` fora de `1..50` ou não numérico → `400`; acima de 50 → saturado em 50, sem erro
- `product_id` de um produto inexistente → `200` com fallback popular (cold-start), não erro
- `meta.source` é `ml`, `rules` ou `popular`, conforme de onde a resposta veio
- Headers de resposta: `X-Recommendation-Source`, `X-Response-Time`

## Testes

```bash
make test                              # suíte completa
vendor/bin/phpunit --exclude-group db  # sem MySQL
vendor/bin/phpunit --testsuite=Unit    # só unit
```

Cobertura medida (não aspiracional — ver [docs/remediation-spec.md](docs/remediation-spec.md) para como foi apurada): **linhas ~81%, métodos ~69%**. O CI falha se a cobertura cair abaixo de 70%.

## Roadmap

Não implementado — fora do escopo atual, não abandonado no meio:

- **Captura de eventos / sessão** — recomendações hoje são item-a-item (por `product_id`); personalização por usuário/sessão depende disso existir
- **Dashboard `/metrics`, `/health`** — visibilidade em tempo real da arquitetura e do KNN
- **Swoole** — servidor assíncrono com workers e coroutines (hoje: `php -S`)
- **Redis** — Pub/Sub, cache de sessão
- **Autenticação real** — `AUTH_REQUIRED=true` hoje só exige a *presença* do header `Authorization`, sem validar nada; é um placeholder, documentado como tal

## Troubleshooting

### Docker não inicia
```bash
docker ps
docker compose logs app
```

### Composer install falha
```bash
docker compose down
docker compose build --no-cache
docker compose up -d
```

### MySQL connection error
```bash
make db-shell   # se entrar, o MySQL está OK
```

## Documentação adicional

- [docs/STRUCTURE.md](docs/STRUCTURE.md) — estrutura de pastas e fluxo de requisição
- [docs/architecture.md](docs/architecture.md) — decisões arquiteturais (ADRs)
- [docs/CODING-STANDARDS.md](docs/CODING-STANDARDS.md) — PSR-12 e convenções específicas do projeto
- [docs/remediation-spec.md](docs/remediation-spec.md) — histórico da remediação que trouxe o projeto ao estado atual

## License

MIT License - Copyright (c) 2026 Debora
