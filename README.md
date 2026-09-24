# ec-hub

[![CI](https://github.com/deboracastrodev/ec-hub/actions/workflows/ci.yml/badge.svg)](https://github.com/deboracastrodev/ec-hub/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4-777884?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> **ML nativo em PHP com Clean Architecture** — catálogo de produtos + recomendações item-a-item via KNN (Rubix ML), com fallback baseado em regras.

## O que existe hoje

- **Catálogo de produtos** — listagem paginada, filtro por categoria, página de detalhe (por slug ou id), SEO (Open Graph, Twitter Card, JSON-LD real por página)
- **API de recomendações** — `GET /api/recommendations?product_id=X` devolve produtos similares via KNN (Rubix ML: `OneHotEncoder` + `MinMaxNormalizer` + `BallTree`), com fallback automático baseado em regras (categoria/popularidade) quando o catálogo é pequeno demais ou o ML falha
- **Painel admin** — `/admin/products` com listagem, criação, edição e exclusão (soft delete) de produtos, para um único admin configurado no `.env` (ver [Painel admin](#painel-admin))
- **Clean Architecture** — 4 camadas (Controller/Application/Domain/Infrastructure); o Domain não importa nenhuma biblioteca externa, nem o Rubix ML (fica atrás de uma porta, em `App\Infrastructure\ML`)
- **PHP 8.4**, MySQL 8, Redis 7, Twig, servidor embutido do PHP (`php -S`) — sem Swoole
- **Mais de 140 testes** (PHPUnit 12), cobertura de linhas medida em **~81%**; a suíte sem grupos `db` e `redis` passa sem Docker
- **CI** (GitHub Actions): estilo (PSR-12), suíte sem serviços externos, suíte completa com MySQL/Redis + cobertura e integração do Compose

O que o projeto **não** faz ainda está listado no [Roadmap](#roadmap) — não é omissão, é escopo.

## Quick Start

### Pré-requisitos

- Docker Desktop (ou Docker Engine + Compose plugin)

### Setup

```bash
git clone https://github.com/deboracastrodev/ec-hub.git
cd ec-hub

cp .env.example .env

make up      # sobe os containers (app + mysql + redis)
make setup   # espera MySQL e Redis, instala dependências, roda migrations e seed

open http://localhost:9501
```

### Comandos úteis

```bash
make logs       # logs da aplicação
make test       # suíte sem serviços externos (local, sem Docker)
make cs-check   # estilo PSR-12 (local, sem Docker)
make shell      # bash dentro do container app
make db-shell   # MySQL CLI
make down       # para os containers
```

`make test` e `make cs-check` não precisam de Docker no ar — rodam direto com `vendor/bin/`. A suíte completa roda no CI com MySQL e Redis. Alvos de banco (`migrate`, `seed`, `db-shell`) precisam do container do MySQL.

### Painel admin

`/admin/products` gerencia o catálogo (criar, editar, excluir). Existe **um único admin**, sem tabela de usuários. As credenciais ficam no `.env`:

```bash
# 1. gere o hash da senha (nunca coloque a senha em texto puro no .env)
php -r "echo password_hash('sua-senha', PASSWORD_DEFAULT), PHP_EOL;"

# 2. no .env, com o hash entre aspas SIMPLES (ele contém "$"):
#    ADMIN_USERNAME=admin
#    ADMIN_PASSWORD_HASH='$2y$12$...'

# 3. recrie o container para ele receber as variáveis
make up

# 4. instalação que já existia antes do painel: crie a coluna products.deleted_at
make migrate
```

Com as duas variáveis vazias (ou o hash inválido) o painel fica **desligado**: o login mostra "Painel admin desabilitado…" e o resto do site segue normal. A exclusão é lógica: o produto some do catálogo, da página de detalhe (404) e das recomendações, mas a linha continua no banco com `deleted_at` preenchido. Não há tela para restaurar. Limitações (sem rate limit no login, sessão não revogável antes de expirar) estão em [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#11-limitações-conhecidas).

## Arquitetura

4 camadas, dependências apontando para dentro (`Controller → Application → Domain`; `Infrastructure` implementa interfaces que o `Domain` declara):

```
app/
├── Controller/       # HTTP handlers (ProductController, RecommendationController, Admin/)
├── Application/      # Casos de uso (GetProductList, GenerateRecommendations, ...)
├── Domain/
│   ├── Product/      # Catálogo — entidade, repositório (interface), CategoryService
│   └── Recommendation/  # KNNService, RuleBasedFallback, NeighborFinderInterface
├── Infrastructure/
│   ├── ML/            # RubixNeighborFinder — único arquivo que importa Rubix\*
│   └── Persistence/    # ProductRepository (MySQL/PDO)
└── Shared/
    ├── Container/     # Container PSR-11 mínimo
    └── Http/          # Router, ErrorHandler, SessionContext, AdminAuth
```

O `Domain` não depende de framework nem de biblioteca de ML — `App\Domain\Recommendation\Service\NeighborFinderInterface` é a porta; `App\Infrastructure\ML\RubixNeighborFinder` é a única implementação, e o único lugar do projeto que importa `Rubix\ML\*`.

Detalhes completos: [docs/STRUCTURE.md](docs/STRUCTURE.md) (árvore + fluxo de requisição), [docs/architecture.md](docs/architecture.md) (decisões, com o porquê) e [docs/ML.md](docs/ML.md) (como o KNN funciona, fallback e benchmarks).

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
make test                                                # sem serviços externos
vendor/bin/phpunit --exclude-group db --exclude-group redis  # sem MySQL/Redis
vendor/bin/phpunit --testsuite=Unit    # só unit
```

### E2E (Playwright)

Fluxos completos no navegador (Chromium headless) contra a aplicação no ar: navegação de produtos com a recomendação mudando, histórico em `/metrics` e `/health`. Exige Node.js e o stack rodando com banco migrado e semeado:

```bash
make up && make setup   # app em http://localhost:9501
make e2e-install        # npm ci + Chromium do Playwright (uma vez)
make test-e2e           # roda tests/e2e/ em modo headless
```

`E2E_BASE_URL` aponta a suíte para outra URL. Em falha, o screenshot da página fica em `test-results/`. O CI roda a mesma suíte no job `e2e`.

Cobertura medida (não aspiracional — ver [docs/remediation-spec.md](docs/remediation-spec.md) para como foi apurada): **linhas ~81%, métodos ~69%**. O CI falha se a cobertura cair abaixo de 70%.

### Performance

Suíte PHPUnit própria em `tests/Performance/` (config `tests/Performance/phpunit.xml`, fora da `phpunit.xml` raiz — `make test` e os jobs de teste comuns não a executam). Roda dentro do container `app` contra o stack no ar:

- **Tempo de resposta** — p95 de 50 requisições (após 5 de aquecimento), medido no cliente: `GET /api/recommendations` < 200 ms e `GET /metrics` < 500 ms
- **10 sessões simultâneas** — cookies de sessão distintos, recomendações concorrentes com p95 < 200 ms e `/metrics` de cada sessão sem dados das outras
- **Memória** — 1.000 requisições pelo `public/index.php` com o container reaproveitado crescem a memória menos de 10% (usa `ec_hub_test` e Redis db 15)
- **Benchmark do KNN** — 1.000 produtos sintéticos, índice treinado uma vez, p95 de `recommend()` < 200 ms

```bash
make up && make setup   # pré-requisito: stack no ar, banco migrado e semeado
make test-performance   # roda a suíte de performance
make benchmark-knn      # tabela: treino e p50/p95/máx de recommend() para 100, 1.000 e 5.000 produtos
```

Resultados medidos do benchmark, com data e ambiente: [docs/ML.md](docs/ML.md) (seção "Benchmarks medidos").

`PERF_BASE_URL` (padrão `http://127.0.0.1:9501`, visto de dentro do container) aponta os testes HTTP para outra URL; servidor inacessível faz os testes falharem, não serem pulados. O CI roda a suíte e o benchmark no job `performance`.

## Roadmap

Não implementado — fora do escopo atual, não abandonado no meio:

- **Captura de eventos / sessão** — recomendações hoje são item-a-item (por `product_id`); personalização por usuário/sessão depende disso existir
- **Dashboard `/metrics`, `/health`** — visibilidade em tempo real da arquitetura e do KNN
- **Swoole** — servidor assíncrono com workers e coroutines (hoje: `php -S`)
- **Redis Pub/Sub e cache de sessão** — a infraestrutura Redis já está disponível no stack local; essas funcionalidades de aplicação seguem no roadmap
- **Autenticação real** — `AUTH_REQUIRED=true` hoje só exige a *presença* do header `Authorization`, sem validar nada; é um placeholder, documentado como tal. O painel admin (Story 8.3) tem login próprio, mas só para um admin fixo no `.env`: não há usuários, papéis nem recuperação de senha

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

### Redis connection error
```bash
docker compose exec redis redis-cli ping
docker compose exec app vendor/bin/phpunit --group redis
```

## Documentação adicional

- [docs/STRUCTURE.md](docs/STRUCTURE.md) — estrutura de pastas e fluxo de requisição
- [docs/architecture.md](docs/architecture.md) — decisões arquiteturais (ADRs)
- [docs/ML.md](docs/ML.md) — KNN com Rubix ML: pipeline, features e similaridade, fallback e benchmarks medidos
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — build da imagem Docker, variáveis de produção, checklist pré-deploy, rollback e troubleshooting
- [docs/CODING-STANDARDS.md](docs/CODING-STANDARDS.md) — PSR-12 e convenções específicas do projeto
- [docs/remediation-spec.md](docs/remediation-spec.md) — histórico da remediação que trouxe o projeto ao estado atual
- [LEARNING_JOURNAL.md](LEARNING_JOURNAL.md) — desafios reais do projeto, com baseline, soluções ligadas a commits e before/after medido

## License

MIT License - Copyright (c) 2026 Debora
