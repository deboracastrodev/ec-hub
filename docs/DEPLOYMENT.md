# Deploy — ec-hub

Guia para rodar a imagem Docker do ec-hub fora do `docker compose` de desenvolvimento. Descreve **apenas o que existe hoje** no repositório. O que ainda não foi implementado fica na seção [Roadmap (não implementado)](#roadmap-não-implementado).

## Sumário

1. [Estado atual e escopo](#1-estado-atual-e-escopo)
2. [Pré-requisitos](#2-pré-requisitos)
3. [Build da imagem](#3-build-da-imagem)
4. [Configuração de produção (variáveis)](#4-configuração-de-produção-variáveis)
5. [Execução](#5-execução)
6. [Migrations e dados](#6-migrations-e-dados)
7. [Checklist pré-deploy](#7-checklist-pré-deploy)
8. [Verificação pós-deploy](#8-verificação-pós-deploy)
9. [Rollback](#9-rollback)
10. [Troubleshooting](#10-troubleshooting)
11. [Limitações conhecidas](#11-limitações-conhecidas)
12. [Roadmap (não implementado)](#roadmap-não-implementado)

## 1. Estado atual e escopo

- **Não existe ambiente de produção provisionado** nem pipeline de deploy. O CI (`.github/workflows/ci.yml`) só testa: não publica imagem nem faz deploy.
- O que este guia cobre: **construir a imagem a partir do `Dockerfile` atual e rodá-la em qualquer host com Docker, apontando para um MySQL 8 e um Redis 7** acessíveis pela rede.
- O `docker-compose.yml` é o stack de **desenvolvimento** (bind mounts do código, `APP_DEBUG=true`, senha `secret`, MySQL publicado na porta 3306). Não use esse arquivo como está em produção.
- Stack da imagem: PHP 8.4 CLI (`php:8.4-cli`), PHP puro + PDO + Twig, servidor embutido `php -S` na porta **9501**.

## 2. Pré-requisitos

| Item | Versão | Observação |
|---|---|---|
| Docker | com BuildKit (`docker build`) | usado para construir e rodar a imagem |
| MySQL | 8 (o compose usa `mysql:8.0`) | banco criado previamente (ex.: `CREATE DATABASE ec_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`); a tabela `products` vem da migration (ver [seção 6](#6-migrations-e-dados)) |
| Redis | 7 (o compose usa `redis:7-alpine`) | sessão com TTL e event bus Pub/Sub, via `predis/predis` (não precisa de `ext-redis`) |
| Proxy reverso com TLS | qualquer | necessário para exposição pública: a imagem não termina TLS (ver [seção 11](#11-limitações-conhecidas)) |

Não há consumidor de eventos de longa duração para subir: `bin/consume-events.php` só aceita `--once` e é usado pelos testes.

## 3. Build da imagem

Use o SHA do commit como tag. Assim cada imagem é imutável e o rollback vira "voltar para a tag anterior".

O `COPY . .` copia tudo o que está no contexto de build. O `.dockerignore` espelha o `.gitignore`: diretórios de ferramentas e agentes (`.claude/`, `.bmad-loop/`, `_bmad/`, `_bmad-output/`, `.codex/`, `.agents/` etc.), IDE (`.vscode/`, `.idea/`), caches de PHPUnit/PHPStan/php-cs-fixer e arquivos de runtime (`var/`, `runtime/`, `*.log`) ficam fora do contexto. Por isso `docker build .` no clone de desenvolvimento é seguro:

```bash
SHA=$(git rev-parse --short HEAD)
docker build -t "ec-hub:$SHA" .
```

Esse build usa o diretório de trabalho, então alterações locais ainda não commitadas e arquivos novos que o Git não ignora (ainda sem `git add`) entram na imagem. Confira com `git status` antes. Para um build reprodutível do commit exato, use o `git archive` (opcional), que manda para o `docker build` só os arquivos versionados daquele commit:

```bash
SHA=$(git rev-parse --short <commit-a-publicar>)
git archive --format=tar "$SHA" | docker build -t "ec-hub:$SHA" -
```

O `docker build -` lê o contexto (um tar) da entrada padrão e continua respeitando o `.dockerignore` que vem dentro dele. Não é preciso fazer checkout nem ter a árvore limpa.

⚠️ Uma imagem construída com `docker build .` no clone de desenvolvimento **antes desta correção do `.dockerignore`** (como a `ec-hub:0c0550b` da verificação) pode conter arquivos locais privados, como configurações de ferramentas e histórico de sessões. **Não publique esse tipo de imagem em registry** e não a use como versão de produção: apague a antiga com `docker image rm` e reconstrua a mesma tag a partir do commit dela (`git archive <sha> | docker build -t ec-hub:<sha> -`), para que a tag continue apontando para o código daquele commit.

O que o `Dockerfile` faz:

- base `php:8.4-cli` + extensões `pdo pdo_mysql mbstring zip` + `pcov` (driver de cobertura);
- `composer install` **com dependências de dev** (PHPUnit, PHPStan, php-cs-fixer entram na imagem);
- `COPY . .` do código. O `.dockerignore` exclui `vendor/`, `.git/`, todo `.env*` exceto `.env.example`, artefatos Node/Playwright e tudo o que o `.gitignore` ignora (ferramentas, IDE, caches, runtime). **Nenhum `.env` entra na imagem**: as variáveis vêm do runtime (`-e` / `--env-file`);
- `chown www-data`, mas **sem instrução `USER`**: o processo roda como root;
- `CMD php -S 0.0.0.0:9501 -t public public/index.php`.

Resultado medido no build de verificação via `git archive`: imagem `ec-hub:57172ca` com **1,17 GB** (inclui dependências de dev e toolchain de build). O build anterior, feito com `docker build .` no clone de desenvolvimento, gerou 1,56 GB. A diferença vinha principalmente dos arquivos ignorados que entraram junto (só `.bmad-loop/` tinha 118 MB naquela imagem) e foi corrigida quando o `.dockerignore` passou a espelhar o `.gitignore`: um `docker build .` no clone de desenvolvimento também gera 1,17 GB (medido em 2026-09-24).

Se o host de destino não for a máquina de build, publique a imagem no registry que você usa (`docker tag` + `docker push`). O projeto não define nenhum registry.

## 4. Configuração de produção (variáveis)

`config/bootstrap.php` chama o phpdotenv com `safeLoad()` **imutável**: se existir um `.env` no diretório da aplicação ele é lido, mas nunca sobrescreve variáveis que já vieram do ambiente. Na imagem não há `.env`, então tudo vem do `docker run`.

A maioria dos valores é lida com `getenv('X') ?: default` e usa o default também quando a variável está **vazia**. As exceções são `SESSION_TTL` e `SESSION_COOKIE_SECRET` (vazias **falham**, ver regras abaixo).

| Variável | Obrigatória? | Default no código | Recomendado em produção | Onde é lida |
|---|---|---|---|---|
| `SESSION_COOKIE_SECRET` | **Sim** | nenhum | `openssl rand -hex 32` (64 caracteres), um por ambiente | `config/session.php` |
| `SESSION_TTL` | Não | `1800` | `1800` (segundos) | `config/session.php` |
| `APP_DEBUG` | Não | ausente = desligado | `false` | `config/twig.php` |
| `DB_HOST` | Não | `mysql` | host do seu MySQL | `config/bootstrap.php`, `bin/migrate.php`, `bin/migrate-fresh.php`, `bin/seed.php` |
| `DB_PORT` | Não | `3306` | `3306` | idem |
| `DB_DATABASE` | Não | `ec_hub` | `ec_hub` | idem |
| `DB_USERNAME` | Não | `root` | usuário dedicado, não `root` | idem |
| `DB_PASSWORD` | Não | vazia | senha forte do usuário dedicado | idem |
| `REDIS_HOST` | Não | `redis` | host do seu Redis | `config/redis.php` |
| `REDIS_PORT` | Não | `6379` | `6379` | `config/redis.php` |
| `AUTH_REQUIRED` | Não | desligado | ver nota abaixo | `app/Controller/RecommendationController.php` |
| `ADMIN_USERNAME` | Não | vazia (painel admin desligado) | nome do admin, se o painel for usado | `config/admin.php` |
| `ADMIN_PASSWORD_HASH` | Não | vazia (painel admin desligado) | saída de `password_hash()`, nunca a senha (ver regra abaixo) | `config/admin.php` |
| `RECOMMENDATION_ALGORITHM` | Não | `knn` | `knn` | `config/recommendation.php` |
| `RECOMMENDATION_AB_TEST` | Não | vazia (A/B desligado) | vazia, ou `knn,collaborative` para comparar | `config/recommendation.php` |
| `RECOMMENDATION_FALLBACK_STRATEGY` | Não | `hybrid` | `hybrid` | `config/recommendation.php` |
| `RECOMMENDATION_MIN_PRODUCTS_FOR_ML` | Não | `5` | `5` | `config/recommendation.php` |
| `APP_ENV` | Não (convenção) | não é lida pelo código | `production` | só `tests/docker/*.sh`; mantida no `.env.example` por convenção |

Regras de validação que derrubam a aplicação:

- **`SESSION_COOKIE_SECRET`** precisa ter **pelo menos 32 caracteres**. `config/session.php` é carregado em **toda requisição** (inclusive `/health`), então segredo ausente ou curto faz **todas as rotas falharem** com `InvalidArgumentException`.
- **`SESSION_TTL`**, se definida, precisa ser um inteiro ≥ 1. Definida e **vazia** também falha (só a ausência usa o default).
- **`REDIS_PORT`** precisa ser um inteiro entre 1 e 65535 (vazia usa o default).
- **`APP_DEBUG`** só liga o modo debug com o valor exato `true`. Qualquer outro valor (inclusive ausente) desliga debug e liga o cache do Twig em `var/cache/twig`.
- **`AUTH_REQUIRED=true`** (sem diferenciar maiúsculas) só exige a **presença** de um header `Authorization` não vazio em `GET /api/recommendations`. Nenhum token é validado. Não trate isso como autenticação.
- **`DB_PORT`** e **`RECOMMENDATION_MIN_PRODUCTS_FOR_ML`** são convertidas com `(int)` **sem validação**: um valor não numérico vira `0` sem erro (porta 0 derruba a conexão; limiar 0 faz o ML ser tentado com qualquer catálogo). Use inteiros positivos.
- **`RECOMMENDATION_ALGORITHM`** aceita `knn` e `collaborative` (sem diferenciar maiúsculas, espaços nas pontas são ignorados; ausente ou vazia usa `knn`). Qualquer outro valor lança `InvalidArgumentException` com a lista de valores aceitos, em vez de cair no default em silêncio. A configuração é carregada sob demanda, então a falha aparece em **toda** requisição a `GET /api/recommendations`; as demais rotas não são afetadas.
- **`RECOMMENDATION_AB_TEST`** liga o teste A/B entre dois algoritmos (Story 8.2). O valor é separado por vírgula e cada item passa por `trim` + minúsculas; o 1º é a variante A e o 2º a B (ex.: `knn,collaborative`). Vazia ou ausente desliga o A/B. Ligada, precisa ter **exatamente 2 nomes distintos** entre `knn` e `collaborative`; `knn`, `knn,knn`, `knn,svd` ou três itens lançam `InvalidArgumentException` citando `RECOMMENDATION_AB_TEST`. Como a do algoritmo, a falha aparece em toda requisição a `GET /api/recommendations` e `GET /api/ab-tests/results`; o `/metrics` continua no ar e mostra "Comparação indisponível". As métricas por algoritmo ficam no Redis, sem TTL (ver [ML.md](ML.md#ab-testing-entre-algoritmos)). O sujeito do A/B é o `user_id` da query, senão o `session_id`. O `user_id` não é autenticado: quem o envia escolhe o próprio braço e pode inflar `unique_subjects` variando o valor. Um visitante que ora manda `user_id`, ora não, pode cair nos dois braços. Não use o resultado do A/B como decisão sem considerar isso.
- **`ADMIN_USERNAME` + `ADMIN_PASSWORD_HASH`** ligam o painel `/admin/products` (Story 8.3), com um único admin. O usuário passa por `trim`; o hash precisa ser reconhecido por `password_get_info()` (gere com `php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"`). Com qualquer uma das duas vazia, ou com um hash inválido (por exemplo, a senha em texto puro), o painel fica **desligado** (fail-closed): o login sempre falha com "Painel admin desabilitado: configure ADMIN_USERNAME e ADMIN_PASSWORD_HASH". Nada derruba a aplicação, e as demais rotas não são afetadas. O hash contém `$`: no `.env` (lido pelo phpdotenv e pelo Compose) ponha o valor entre **aspas simples**; no `--env-file` do `docker run`, **sem aspas** (ver nota abaixo do exemplo). A sessão do admin é um cookie `ec_hub_admin` assinado com o `SESSION_COOKIE_SECRET` e válido por 2 h. Trocar a senha, o usuário ou o segredo invalida as sessões abertas.
- **`RECOMMENDATION_FALLBACK_STRATEGY`** aceita `hybrid`, `category_only` e `popularity_only`. Um valor desconhecido cai em `hybrid` sem erro.

A lista canônica de variáveis é o [`.env.example`](../.env.example). O script `php bin/ci/check-env-vars.php` (roda no CI) garante que ela bate com os `getenv()` do código nos dois sentidos.

Exemplo de arquivo de ambiente para produção (mantenha **fora** do repositório, com permissão restrita):

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_HOST=<host-do-mysql>
DB_PORT=3306
DB_DATABASE=ec_hub
DB_USERNAME=<usuario-dedicado>
DB_PASSWORD=<senha-forte>
REDIS_HOST=<host-do-redis>
REDIS_PORT=6379
SESSION_TTL=1800
SESSION_COOKIE_SECRET=<saida de: openssl rand -hex 32>
AUTH_REQUIRED=false
ADMIN_USERNAME=<nome-do-admin, ou vazio para desligar o painel>
ADMIN_PASSWORD_HASH=<saida de: php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;">
RECOMMENDATION_ALGORITHM=knn
RECOMMENDATION_AB_TEST=
RECOMMENDATION_FALLBACK_STRATEGY=hybrid
RECOMMENDATION_MIN_PRODUCTS_FOR_ML=5
```

O `--env-file` do Docker não é um parser de dotenv. Ele não remove aspas e não aceita `export` nem comentário no fim da linha. Escreva `DB_PASSWORD=abc`, não `DB_PASSWORD="abc"`: com aspas, elas passam a fazer parte do valor. Isso vale também para o `ADMIN_PASSWORD_HASH`: no `--env-file` o hash vai **sem aspas** (`ADMIN_PASSWORD_HASH=$2y$12$...`), e o `--env-file` não interpreta o `$`. Já no `.env` de desenvolvimento ele vai entre aspas simples. Com aspas no `--env-file`, o hash deixa de ser reconhecido e o painel fica desligado.

## 5. Execução

Comando recomendado:

```bash
docker run -d --name ec-hub \
  --restart unless-stopped \
  -p 127.0.0.1:9501:9501 \
  --env-file /caminho/seguro/ec-hub.env \
  ec-hub:<sha> \
  php -d display_errors=0 -d log_errors=1 -S 0.0.0.0:9501 -t public public/index.php
```

- **Por que sobrescrever o `CMD`:** a imagem não tem `php.ini` ativo (`Loaded Configuration File => (none)`), então o PHP roda com `display_errors=STDOUT` e `log_errors=Off`. Com o `CMD` padrão, um erro fatal de bootstrap (por exemplo, segredo de cookie curto) devolve **HTTP 200 com a mensagem e o stack trace no corpo** e não registra nada no `docker logs`. Com `-d display_errors=0 -d log_errors=1` a mesma exceção (`InvalidArgumentException` do `config/session.php`, verificada com segredo vazio) devolve **HTTP 500** sem detalhes e o erro vai para o `docker logs`. Os dois comportamentos foram observados na verificação abaixo. Exceções lançadas dentro das rotas são capturadas pelo `public/index.php` e passam pelo `ErrorHandler`, que responde 500 genérico sem a mensagem (comportamento lido no código, não reproduzido nesta verificação).
- **Rede:** o container precisa alcançar o MySQL e o Redis. Os defaults `DB_HOST=mysql` e `REDIS_HOST=redis` só resolvem se o container estiver numa rede Docker onde esses nomes existem (`--network <rede>`). Fora disso, defina `DB_HOST`/`REDIS_HOST` com o hostname ou IP acessível a partir do container. O mesmo vale para os `docker run` de migration e rollback.
- **`-p 127.0.0.1:9501:9501`** publica a porta só na interface local, para o proxy reverso (TLS) ficar na frente. Não exponha o `php -S` direto na internet. Isso vale para um proxy instalado no próprio host. Se o proxy também rodar em container, o `127.0.0.1` do host não é alcançável a partir dele: coloque os dois na mesma rede Docker (`--network`) e aponte o proxy para `ec-hub:9501`.

### Verificação real (2026-09-23)

Comandos executados nesta máquina (macOS, Docker Compose 5.5.1) contra o stack de desenvolvimento no ar, usando a rede do compose (`ec-hub_ec-hub-network`, o nome real prefixado pelo projeto) no lugar de um MySQL/Redis de produção. O `--env-file` ficou fora do repositório, com `APP_DEBUG=false` e um segredo gerado na hora.

```bash
# registro histórico da 1ª rodada, antes da correção do .dockerignore; NÃO copie este build, use o da seção 3
docker build -t ec-hub:$(git rev-parse --short HEAD) .          # → ec-hub:0c0550b, build concluído
docker run -d --name ec-hub-prod-check --network ec-hub_ec-hub-network -p 9601:9501 \
  --env-file <arquivo-fora-do-repo> ec-hub:0c0550b
curl -s http://localhost:9601/health
docker rm -f ec-hub-prod-check
```

Resultado observado em 2026-09-23 23:21 (-03):

| Verificação | Resultado |
|---|---|
| `GET /health` | HTTP 200, `"status": "healthy"`, `mysql: up`, `redis: up` |
| `GET /api/recommendations?product_id=323` (produto existente) | HTTP 200, `X-Recommendation-Source: ml` |
| `GET /` | HTTP 200 |
| `printenv APP_DEBUG` no container | `false` (cache do Twig gravado em `var/cache/twig`) |
| `id -un` no container | `root` |
| `REDIS_HOST=nao-existe` | `/health` → HTTP 200 com `"status": "unhealthy"`, `redis: down` |
| `SESSION_COOKIE_SECRET=curto`, `CMD` padrão | HTTP 200 com o `Fatal error` e o stack trace no corpo |
| `SESSION_COOKIE_SECRET` vazio, `CMD` com `-d display_errors=0 -d log_errors=1` | HTTP 500 sem corpo de erro; `PHP Fatal error ... SESSION_COOKIE_SECRET must contain at least 32 characters` no `docker logs` |
| `php bin/migrate.php` via imagem | exit 0 (idempotente) |

Segunda rodada, em 2026-09-23 23:29 (-03), depois da revisão. Nela foram executados os comandos **como estão neste guia**: build via `git archive` (seção 3), o comando recomendado acima (com `--restart`, `-p 127.0.0.1:…`, `--env-file` e o `CMD` sobrescrito), a migration da seção 6, a verificação pós-deploy da seção 8 e o rollback da seção 9. As únicas diferenças foram `--network ec-hub_ec-hub-network` e a porta do host `9601`, porque a 9501 é usada pelo `ec-hub-app` do compose.

| Verificação | Resultado |
|---|---|
| `git archive … \| docker build -t ec-hub:57172ca -` | build concluído; a imagem não contém `.claude/`, `.bmad-loop/`, `_bmad/` nem `.vscode/` |
| `curl … /health \| grep -q '"status": "healthy"'` | `OK` |
| `GET /api/recommendations?product_id=323` | HTTP 200, `X-Recommendation-Source: ml` |
| `php bin/migrate.php` via imagem | `Migration concluída com sucesso!` |
| Guard do rollback com tag inexistente | mensagem "ausente", container em execução intocado |
| Rollback para `ec-hub:0c0550b` | container recriado, `/health` → `OK` |

## 6. Migrations e dados

A única tabela no MySQL é `products`. Sessões, eventos, as métricas do A/B (`ec-hub:ab-metrics:*`) e os contadores HTTP de `/api/metrics` (hash `ec-hub:http-metrics`, sem TTL) ficam no Redis.

Rode a migration com a mesma imagem. O arquivo de ambiente é uma **cópia do da aplicação com `DB_USERNAME`/`DB_PASSWORD` de um usuário com privilégios de schema** (ver privilégios abaixo). O usuário da aplicação só tem `SELECT` e não consegue migrar:

```bash
docker run --rm --env-file /caminho/seguro/ec-hub-migrate.env ec-hub:<sha> php bin/migrate.php
# acrescente --network <rede> se o MySQL só for alcançável por uma rede Docker (ver seção 5)
```

- `bin/migrate.php` faz `CREATE TABLE IF NOT EXISTS products`, completa `slug` vazio e garante a coluna `deleted_at` (soft delete do painel admin, Story 8.3) com o índice `idx_products_deleted_at`. É **idempotente e só aditivo**. **Não existe down-migration.**
- **Instalação existente, anterior ao painel admin:** rode a migration (`make migrate` no ambiente de desenvolvimento, ou o `docker run … php bin/migrate.php` acima) **antes** de subir a versão nova. A migration verifica a coluna com `SHOW COLUMNS … LIKE 'deleted_at'` e só faz o `ALTER TABLE` quando ela falta. Sem a coluna, toda leitura de produto falha, porque as queries filtram `deleted_at IS NULL`.
- Exceção: numa tabela `products` **antiga, sem a coluna `slug` e com mais de uma linha**, a migration falha. Ela adiciona `slug` com valor vazio em todas as linhas e cria o índice único **antes** de preencher os slugs, e o índice não aceita as duplicatas. Isso só afeta instalações anteriores à coluna `slug`. Uma base nova, ou uma que já tem a coluna, não é afetada (comportamento lido em `bin/migrate.php`).
- ⚠️ **`bin/seed.php` apaga os dados:** o `ProductSeeder` executa `DELETE FROM products` antes de inserir o catálogo de exemplo. Nunca rode em uma base com dados reais.
- ⚠️ **`bin/migrate-fresh.php`** (e `make migrate-fresh` / `make db-reset`) faz `DROP TABLE`. Nunca rode em produção.
- Privilégios do usuário do banco: a migration precisa de `CREATE`, `ALTER`, `INDEX`, `SELECT` e `UPDATE` em `ec_hub`. A aplicação em execução lê `products` (`SELECT`) e, com o painel admin ligado, também grava: criar produto faz `INSERT` e editar/excluir faz `UPDATE` (a exclusão é lógica, nunca `DELETE`). Por isso são duas credenciais: o usuário de migration (no `ec-hub-migrate.env`) e o da aplicação (no `ec-hub.env`), com `GRANT SELECT, INSERT, UPDATE ON ec_hub.products TO '<usuario>'@'<host>';`. Se o painel ficar desligado, `GRANT SELECT` basta; com ele ligado e só `SELECT`, salvar no painel devolve 500. Se preferir um único usuário, ele precisa de todos os privilégios de migration.
- Operações manuais precisam de mais do que isso. A carga do catálogo precisa de `INSERT`. O restore de um backup do `mysqldump` precisa de `DROP`, `CREATE`, `ALTER`, `INSERT` e `LOCK TABLES`, porque o arquivo recria a tabela. Faça essas operações com um usuário administrativo, ou acrescente esses privilégios ao usuário de migration.
- Uma base nova fica sem produtos até você carregar o catálogo. Com o catálogo vazio a home e as recomendações não têm o que mostrar. O seed só serve para ambientes descartáveis. **O repositório não tem script de importação de catálogo:** os produtos entram pelo painel `/admin/products` (um a um) ou por `INSERT` na tabela `products` (colunas `name`, `description`, `price`, `category`, `slug` único, `image_url`), com a ferramenta de banco que você usa.
- **Exclusão lógica (FR106):** excluir no painel só preenche `products.deleted_at`. A linha fica no banco, some de todas as leituras (catálogo, detalhe, recomendações) e o `slug` continua reservado pelo índice único. Não há restauração pelo painel: se precisar, faça `UPDATE products SET deleted_at = NULL WHERE id = <id>` com um usuário administrativo.

## 7. Checklist pré-deploy

- [ ] CI verde no commit que vai subir (static checks, testes, integração Docker, E2E, performance).
- [ ] Imagem construída com tag = SHA do commit (`ec-hub:<sha>`), e a tag atual anotada para rollback.
- [ ] `SESSION_COOKIE_SECRET` gerado com `openssl rand -hex 32` (≥ 32 caracteres), guardado fora do repositório. Trocar o segredo invalida as sessões existentes.
- [ ] `APP_DEBUG=false`.
- [ ] `DB_*` apontando para o MySQL de produção, com usuário dedicado (não `root`) e senha forte. Não reaproveite `secret` do compose.
- [ ] `REDIS_HOST`/`REDIS_PORT` apontando para o Redis de produção.
- [ ] `SESSION_TTL`, se definida, é um inteiro ≥ 1 (não vazia).
- [ ] Painel admin: ou `ADMIN_USERNAME` e `ADMIN_PASSWORD_HASH` vazias (painel desligado), ou um usuário e um hash gerado com `password_hash()` (no `--env-file`, sem aspas). O usuário do banco da aplicação tem `INSERT` e `UPDATE` em `products` se o painel estiver ligado ([seção 6](#6-migrations-e-dados)).
- [ ] MySQL e Redis alcançáveis a partir do host do container.
- [ ] **Backup do MySQL feito** antes de rodar a migration (ver [seção 9](#9-rollback)).
- [ ] `php bin/migrate.php` executado via imagem, **sem** `seed` e **sem** `migrate-fresh`.
- [ ] Proxy reverso com TLS na frente do `php -S`, bloqueando `/debug/memory`, `/metrics`, `/api/ab-tests/results` e `/api/metrics` se não devem ser públicos (o Prometheus pode continuar raspando por dentro da rede). `/admin/*` só por HTTPS; se possível, restrinja por IP ou VPN no proxy (o login não tem rate limit).
- [ ] Container rodando com `-d display_errors=0 -d log_errors=1` (ver [seção 5](#5-execução)).
- [ ] Catálogo de produtos carregado na base (uma base nova fica vazia, ver [seção 6](#6-migrations-e-dados)).
- [ ] Imagem da versão atual (a do rollback) ainda disponível no host ou no registry.

### Atualização de uma versão em execução

Ordem recomendada:

1. Anotar a tag em uso: `docker inspect --format '{{.Config.Image}}' ec-hub`.
2. Backup do MySQL ([seção 9](#9-rollback)).
3. Migration com a **imagem nova** ([seção 6](#6-migrations-e-dados)).
4. Trocar o container só se a imagem nova estiver no host, no mesmo encadeamento do rollback: `docker image inspect ec-hub:<sha-nova> >/dev/null && docker rm -f ec-hub && docker run …` com o comando da [seção 5](#5-execução). Com um único container há alguns segundos de indisponibilidade entre os dois comandos. Se o `docker run` falhar, nenhum container fica no ar: vá direto para o [rollback](#9-rollback).
5. [Verificação pós-deploy](#8-verificação-pós-deploy); se falhar, [rollback](#9-rollback).

## 8. Verificação pós-deploy

`/health` **sempre responde HTTP 200**. O estado real está no campo `status` do JSON, então não use o código HTTP como health check:

```bash
curl -s --max-time 5 http://localhost:9501/health | grep -q '"status": "healthy"' && echo OK || echo FALHOU
```

Resposta esperada:

```json
{
    "status": "healthy",
    "services": {
        "mysql": { "status": "up" },
        "redis": { "status": "up" }
    }
}
```

`healthy` só aparece quando MySQL **e** Redis estão `up`. Se algum estiver `down`, o campo vira `unhealthy`, ainda com HTTP 200.

Depois confira uma recomendação real (use um `product_id` que exista na sua base):

```bash
curl -s -D - -o /dev/null "http://localhost:9501/api/recommendations?product_id=<id>"
# HTTP/1.1 200 OK · X-Recommendation-Source: ml (ou rules / popular quando o fallback responde)
```

E acompanhe os logs:

```bash
docker logs --tail 50 ec-hub
```

### Métricas (`GET /api/metrics`)

Export das métricas do sistema (Story 8.4), em JSON (padrão) ou no formato de texto do Prometheus 0.0.4:

```bash
curl -s "http://localhost:9501/api/metrics"                     # JSON: data.{requests, errors, response_times, memory, recommendations, event_bus} + meta.sources
curl -s "http://localhost:9501/api/metrics?format=prometheus"   # Content-Type: text/plain; version=0.0.4
```

- `format` aceita `json` ou `prometheus` (com `trim` e sem diferenciar maiúsculas). Ausente ou vazio vira `json`; outro valor devolve `400`.
- As duas respostas levam `Cache-Control: no-store`.
- Cada fonte é lida à parte. Se uma falhar (Redis fora do ar, `RECOMMENDATION_AB_TEST` inválida), a resposta continua `200`: a seção dela sai `null`, `meta.sources.<fonte>` vira `false` e no Prometheus as famílias dela somem e `ec_hub_metrics_source_up{source="<fonte>"}` vale `0`.
- Famílias Prometheus: `ec_hub_http_requests_total{method,route,status}`, `ec_hub_http_errors_total{method,route}` (5xx), o histograma `ec_hub_http_request_duration_seconds{method,route}` (buckets de 5 ms a 5 s e `+Inf`), `ec_hub_memory_usage_bytes`, `ec_hub_memory_peak_usage_bytes`, `ec_hub_memory_growth_percent`, `ec_hub_recommendation_{requests,items,ml_items}_total{algorithm}`, o summary `ec_hub_recommendation_response_time_seconds{algorithm}` (só `_sum` e `_count`, sem quantis; o `_sum` sai da média arredondada em 2 casas vezes o número de requisições, então pode recuar um pouco entre dois scrapes; use a razão `_sum / _count`, não `rate()` do `_sum`), `ec_hub_event_bus_connected`, `ec_hub_events_published_total` e `ec_hub_metrics_source_up{source}`.
- O rótulo `route` é a rota do roteador, nunca a URI: `/products/{param}`, `/admin/products/{param}/edit`, e `unmatched` para uma URL sem rota (inclusive um caminho conhecido com o método errado). O rótulo `method` vira `OTHER` para verbos fora de `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` e `OPTIONS`. Assim a cardinalidade fica limitada ao número de rotas.

Exemplo de `scrape_config`:

```yaml
scrape_configs:
  - job_name: ec-hub
    metrics_path: /api/metrics
    params:
      format: [prometheus]
    static_configs:
      - targets: ["ec-hub:9501"]
```

O alvo precisa alcançar a aplicação sem passar pelo proxy que bloqueia `/api/metrics` para o público: rode o Prometheus na mesma rede Docker (porta interna) ou libere o IP dele no proxy.

Os contadores HTTP acumulam desde a criação do hash, inclusive entre deploys. Para zerar: `redis-cli DEL ec-hub:http-metrics`. Em Prometheus isso aparece como um reset de counter, que `rate()` e `increase()` já tratam.

## 9. Rollback

### Aplicação

Cada imagem tem a tag do SHA do commit. Para voltar, suba a tag anterior com o mesmo arquivo de ambiente:

```bash
docker image inspect ec-hub:<sha-anterior> >/dev/null \
  && docker rm -f ec-hub \
  && docker run -d --name ec-hub --restart unless-stopped -p 127.0.0.1:9501:9501 \
       --env-file /caminho/seguro/ec-hub.env \
       ec-hub:<sha-anterior> \
       php -d display_errors=0 -d log_errors=1 -S 0.0.0.0:9501 -t public public/index.php
```

Se a imagem anterior não estiver no host, o `docker image inspect` falha e nada é removido: faça o pull do registry e repita. Se o `docker run` falhar depois do `docker rm`, nenhum container fica no ar. Veja o erro na saída do comando (por exemplo, porta ocupada ou `--env-file` inexistente), corrija e rode de novo só o `docker run`. Os comandos estão encadeados com `&&` em vez de `exit`, para não fechar o terminal de quem cola o bloco.

Depois repita a [verificação pós-deploy](#8-verificação-pós-deploy).

### Banco de dados

**O schema não tem rollback automático.** As migrations são aditivas e não existe down-migration. Por isso o backup vem **antes** de migrar. Os clientes `mysqldump`/`mysql` precisam estar instalados na máquina onde o comando roda, porque a imagem da aplicação não os inclui. O backup funciona com um usuário que só tem `SELECT`. Sem `--no-tablespaces`, o MySQL 8 exige o privilégio `PROCESS` e o `mysqldump` imprime `Access denied; you need (at least one of) the PROCESS privilege(s)`. O restore precisa dos privilégios listados na [seção 6](#6-migrations-e-dados):

```bash
# backup (antes do deploy)
mysqldump -h <host> -u <usuario> -p --single-transaction --no-tablespaces <DB_DATABASE> > backup-$(date +%Y%m%d-%H%M).sql

# restore (se o deploy precisar ser desfeito)
mysql -h <host> -u <usuario> -p <DB_DATABASE> < backup-<data>.sql
```

Hoje a migration cria `products` se ela não existir, completa `slug` e acrescenta a coluna `deleted_at`. Uma versão anterior da aplicação continua subindo com o schema atual, mas **ignora o `deleted_at`**: depois de um rollback para uma versão anterior ao painel admin, os produtos excluídos pelo painel voltam a aparecer no catálogo e nas recomendações. Se isso importar, restaure o backup ou apague essas linhas de vez antes do rollback (`DELETE FROM products WHERE deleted_at IS NOT NULL`, com um usuário administrativo). Se uma migration futura mudar colunas, a compatibilidade deixa de existir e o restore do backup passa a ser o caminho.

### Redis

Sessões e eventos no Redis não têm migração. Em um rollback eles permanecem. Trocar o `SESSION_COOKIE_SECRET` invalida os cookies de sessão existentes.

## 10. Troubleshooting

Problemas do ambiente de desenvolvimento (Docker não sobe, Composer, conexão MySQL/Redis no compose) estão no [Troubleshooting do README](../README.md#troubleshooting). Aqui ficam os específicos de rodar a imagem.

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Toda rota (inclusive `/health`) falha; com o `CMD` padrão o corpo mostra `Fatal error: Uncaught InvalidArgumentException: SESSION_COOKIE_SECRET must contain at least 32 characters` | segredo ausente, vazio ou com menos de 32 caracteres | gerar com `openssl rand -hex 32` e recriar o container |
| Mesmo quadro com `SESSION_TTL must be an integer...` | `SESSION_TTL` definida vazia ou não numérica | remover a variável ou usar um inteiro ≥ 1 |
| Mesmo quadro com `REDIS_PORT must be an integer between 1 and 65535` | `REDIS_PORT` inválida | corrigir o valor |
| `/health` responde 200 mas com `"status": "unhealthy"` e `mysql: down` | MySQL inacessível: host/porta/credenciais erradas, ou container fora da rede do banco | conferir `DB_*`; testar de dentro do container (`docker exec ec-hub php -r 'new PDO("mysql:host=".(getenv("DB_HOST") ?: "mysql").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME") ?: "root", getenv("DB_PASSWORD") ?: ""); echo "ok\n";'`) |
| `/health` com `redis: down` | Redis inacessível | conferir `REDIS_HOST`/`REDIS_PORT` e a rede (`--network`) |
| Páginas/API devolvem 500 genérico, `/health` com MySQL `up` | tabela `products` inexistente ou outro erro de runtime | rodar `php bin/migrate.php` via imagem; ver `docker logs` (com `log_errors=1`) |
| `docker run` falha com `port is already allocated` | porta 9501 do host ocupada (por exemplo, o `ec-hub-app` do compose) | mapear outra porta do host (`-p 127.0.0.1:9601:9501`); a porta interna continua 9501 |
| Template editado dentro do container em execução não muda a página | com `APP_DEBUG` diferente de `true`, o Twig usa o cache em `var/cache/twig` sem `auto_reload` | não edite arquivos no container: construa e suba uma imagem nova (cada container começa com o cache vazio) |
| Erros detalhados ou `DebugExtension` do Twig em produção | `APP_DEBUG=true` herdado do `.env.example`/compose | usar `APP_DEBUG=false` |
| Erro fatal aparece no navegador com stack trace | container rodando com o `CMD` padrão (sem `php.ini`, `display_errors=STDOUT`) | usar o comando da [seção 5](#5-execução) |

## 11. Limitações conhecidas

- **Servidor `php -S`:** servidor embutido do PHP, um único processo, sem TLS e sem gerenciamento de workers. Para exposição pública, coloque um proxy reverso com TLS na frente.
- **Cookie de sessão sem `Secure` atrás do proxy:** o `SessionContext` só marca o cookie como `Secure` quando `$_SERVER['HTTPS']` está definido. Com TLS terminado no proxy, o `php -S` não recebe esse valor e o cookie sai sem `Secure` (continua `HttpOnly` e `SameSite=Lax`).
- **Imagem com dependências de dev:** `composer install` roda sem `--no-dev`, e a imagem inclui `pcov` e a toolchain de build (1,17 GB medidos no build via `git archive`).
- **Roda como root:** o `Dockerfile` faz `chown www-data`, mas não tem `USER`.
- **Sem `php.ini` ativo:** `display_errors=STDOUT` e `log_errors=Off` com o `CMD` padrão (mitigação na [seção 5](#5-execução)).
- **`/health` sempre HTTP 200:** o estado está só no JSON. Orquestradores que olham apenas o código HTTP não detectam `unhealthy`.
- **`/debug/memory`, `/metrics`, `/api/ab-tests/results` e `/api/metrics` são públicos**, sem autenticação. Bloqueie no proxy se não devem ficar expostos.
- **Métricas HTTP de `/api/metrics` com cobertura parcial:** assets estáticos servidos pelo `public/index.php` não são contados, nem as falhas fatais que escapam do `try/catch` principal (por exemplo, erro no bootstrap). A requisição do próprio scrape só aparece no scrape seguinte.
- **Memória em `/api/metrics` é a da requisição do scrape:** com `php -S` cada requisição é isolada, então `ec_hub_memory_*` mede o processo que respondeu ao export, não um agregado da aplicação.
- **+1 transação no Redis por requisição roteada:** o `MULTI/EXEC` dos contadores HTTP (3 comandos, alguns round-trips). Ela usa um cliente Predis próprio com timeouts de 0,25 s: com o Redis fora do ar a resposta não muda, mas cada requisição pode esperar até ~0,25 s pela tentativa de conexão (em vez dos 5 s padrão do Predis) e registra um `warning` no logger.
- **`AUTH_REQUIRED=true` só exige a presença do header** `Authorization`. Não há validação de token.
- **Painel admin com um único admin** (usuário e hash no ambiente), sem tabela de usuários, papéis, cadastro ou recuperação de senha.
- **Login do admin sem rate limit** nem bloqueio por tentativas. A senha é verificada com `password_verify`, mas nada impede força bruta além do custo do hash: restrinja `/admin` no proxy.
- **Sessão do admin não revogável antes de expirar:** o cookie `ec_hub_admin` é stateless (HMAC, sem nada no servidor) e vale 2 h. O logout apaga o cookie do navegador, mas um cookie copiado continua válido até expirar. Para invalidar todas as sessões na hora, troque a senha (`ADMIN_PASSWORD_HASH`), o usuário ou o `SESSION_COOKIE_SECRET` e recrie o container.
- **Cookie do admin sem `Secure` atrás do proxy:** mesma regra do cookie de sessão (só com `$_SERVER['HTTPS']`). O cookie é `HttpOnly`, `SameSite=Strict` e restrito ao caminho `/admin`.
- **Sessão do admin não é renovada com o uso:** ela expira 2 h depois do login, mesmo com o admin ativo (não há renovação deslizante). Um formulário enviado depois disso é redirecionado para o login e **os dados digitados se perdem**. Em edições longas, salve antes de completar 2 h de sessão.
- **Exclusão de produto sem restauração:** o painel só faz soft delete (`deleted_at`) e não tem "desfazer"; a restauração é manual no banco ([seção 6](#6-migrations-e-dados)).
- **Slug de produto excluído continua reservado:** o índice único de `slug` vale para linhas excluídas também. Um produto novo com o mesmo nome de um excluído recebe o sufixo `-1` (por exemplo, `notebook-pro-1`), e a URL pública antiga continua respondendo 404.
- **Migrations aditivas, sem down-migration.** `bin/seed.php` apaga `products`, e `bin/migrate-fresh.php` faz `DROP TABLE`.
- **O KNN treina a cada requisição** que chega ao ML (não há cache do modelo). Detalhes em [docs/ML.md — Limitações conhecidas](ML.md#10-limitações-conhecidas).
- **Redis sem senha e conexões sem TLS:** `config/redis.php` só lê host e porta (não há `REDIS_PASSWORD`), e a conexão PDO não tem opção de TLS. Mantenha os dois numa rede privada.
- **Sem pipeline de deploy nem registry:** build, publicação e troca de container são manuais.

## Roadmap (não implementado)

Nada nesta seção existe no código hoje.

- **PHP-FPM + Nginx** no lugar do `php -S`, com múltiplos workers e TLS no Nginx.
- **Imagem de produção separada:** multi-stage, `composer install --no-dev --optimize-autoloader`, sem `pcov` nem ferramentas de build, com `php.ini-production` ativo.
- **Usuário não-root** na imagem (`USER www-data`).
- **`/health` devolvendo HTTP 503** quando `unhealthy`, para orquestradores e load balancers.
- **Pipeline de deploy:** job no CI que publica a imagem com a tag do SHA e faz o deploy.
- **Cookie `Secure` atrás de proxy:** respeitar `X-Forwarded-Proto` de um proxy confiável para marcar o cookie de sessão como `Secure`.
- **Cache do modelo serializado (Story 10.1):** persistir o índice KNN treinado para não retreinar a cada requisição.

---

Veja também: [README](../README.md) · [architecture.md (ADRs)](architecture.md) · [ML.md](ML.md) · [STRUCTURE.md](STRUCTURE.md)
