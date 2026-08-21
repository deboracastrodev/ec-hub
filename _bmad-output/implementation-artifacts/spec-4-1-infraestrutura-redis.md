---
title: 'Infraestrutura Redis'
type: 'feature'
created: '2026-08-21'
status: 'done'
baseline_revision: '54b2de2a228bc037a1f552174be2f675aba64054'
baseline_commit: '54b2de2a228bc037a1f552174be2f675aba64054'
review_loop_iteration: 0
followup_review_recommended: true
context:
  - '_bmad-output/implementation-artifacts/epic-4-context.md'
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** O Redis foi removido na remediação R5.5 porque não tinha consumidor. O Epic 4 agora depende dele como infraestrutura compartilhada para eventos e sessões, mas o compose, configuração, setup, checks e CI ainda só conhecem MySQL.

**Approach:** Reintroduzir uma infraestrutura Redis 7 local e de CI, instalar o cliente PHP puro Predis e provar conectividade/configuração sem antecipar o barramento de eventos ou o repositório de sessão das stories seguintes.

## Boundaries & Constraints

**Always:** Usar `redis:7-alpine` e `predis/predis`, sem `ext-redis`; declarar e ler `REDIS_HOST` e `REDIS_PORT`; manter o acesso ao Redis confinado à infraestrutura/configuração; preservar o funcionamento de MySQL, a validação de variáveis e os testes sem banco; cada teste que conecte Redis deve pertencer ao grupo `redis`.

**Block If:** A resolução de `predis/predis` não for compatível com PHP 8.4 e o lockfile atual, ou os testes de Redis exigirem uma escolha de retenção, modelagem de evento ou sessão que pertença às Stories 4.2/4.3.

**Never:** Não introduzir extensão PHP/binária Redis, Hyperf, Swoole, Pub/Sub, subscriber, armazenamento de sessão, captura de eventos, cache de recomendações ou credenciais reais; não restaurar os scripts antigos baseados em `new Redis()`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Configuração padrão | Processo sem `REDIS_HOST`/`REDIS_PORT` | Configuração retorna host `redis` e porta `6379`, sem abrir conexão | Valor inválido de porta deve falhar de modo explícito no limite de configuração |
| Serviço disponível | Redis iniciado pelo Compose ou serviço do CI | Cliente Predis do app executa `PING` e uma operação mínima de leitura/escrita | Falha de conexão produz falha não-zero no check/teste, sem fallback silencioso |
| Serviço indisponível | Redis não responde durante setup | `setup.sh` encerra após tentativas limitadas com diagnóstico Redis | Não executar migrações ou seed após o timeout |

</intent-contract>

## Code Map

- `docker-compose.yml` -- define `app` e MySQL; deve receber o serviço Redis, rede, healthcheck e variáveis/ordenação de inicialização do app.
- `composer.json` e `composer.lock` -- manifesto e resolução reproduzível; adicionar somente `predis/predis` como cliente PHP puro.
- `.env.example` e `bin/ci/check-env-vars.php` -- contrato de variáveis; o segundo detecta qualquer variável declarada sem `getenv()` correspondente.
- `config/bootstrap.php` -- inicialização já carrega `.env` com `PutenvAdapter`, mas não deve ganhar conexão Redis prematura; usar um arquivo de configuração dedicado e lazy.
- `setup.sh` -- aguarda MySQL antes de Composer/migração/seed; acrescentar espera limitada por `redis-cli ping` usando `docker compose`.
- `tests/docker/validate-docker-compose.sh`, `validate-env.sh`, `connectivity-test.sh` e `integration-test.sh` -- checks atuais tratam somente MySQL; validar serviço, variáveis e `PING`/leitura/escrita via Predis, nunca `ext-redis`.
- `tests/docker/MakefileTest.php` e `tests/docker/SetupScriptTest.php` -- atualmente exigem a ausência de Redis; inverter para o contrato da Story 4.1.
- `.github/workflows/ci.yml` -- jobs de testes; disponibilizar Redis e executar o grupo `redis` com host/porta explícitos sem tornar a suíte sem banco dependente dele.
- `tests/Integration/` e `phpunit.xml` -- padrão de atributos PHPUnit 12 e grupos; novo teste de conectividade/configuração Redis deve ser marcado `#[Group('redis')]`.
- `_bmad-output/implementation-artifacts/epic-4-context.md` -- contexto canônico do Epic 4; Pub/Sub e sessão permanecem dependências futuras.

## Tasks & Acceptance

**Execution:**

- [x] `composer.json` e `composer.lock` -- adicionar e resolver `predis/predis` -- fornecer o único cliente Redis permitido e lock reproduzível.
- [x] `config/redis.php`, `.env.example` e `config/bootstrap.php` -- expor configuração Redis lazy baseada em `getenv('REDIS_HOST')`/`getenv('REDIS_PORT')`, com defaults locais validados -- cumprir o contrato de ambiente sem conectar Redis em toda requisição.
- [x] `docker-compose.yml` e `setup.sh` -- adicionar Redis 7 Alpine, healthcheck, dependências e espera por `redis-cli ping` antes de migração/seed -- disponibilizar a infraestrutura local de forma determinística.
- [x] `tests/docker/check-redis.sh`, `tests/docker/validate-docker-compose.sh`, `tests/docker/validate-env.sh`, `tests/docker/connectivity-test.sh`, `tests/docker/integration-test.sh`, `tests/docker/MakefileTest.php` e `tests/docker/SetupScriptTest.php` -- reintroduzir verificações Redis via serviço/CLI e Predis, atualizando asserções de R5.5 -- provar o contrato local sem extensão binária.
- [x] `tests/Integration/Infrastructure/Redis/RedisConnectivityTest.php` e `phpunit.xml` quando necessário -- criar teste de configuração e `PING`/`SET`/`GET` marcado `redis` -- dar ao CI uma evidência PHP da infraestrutura.
- [x] `.github/workflows/ci.yml` -- disponibilizar `redis:7-alpine`, injetar host/porta e executar o grupo `redis` -- provar infraestrutura em CI preservando a separação da suíte sem banco.

**Acceptance Criteria:**

- Given o projeto após R5.5, when a Story 4.1 é aplicada, then `docker compose config` contém um serviço Redis com imagem `redis:7-alpine` e o app recebe `REDIS_HOST` e `REDIS_PORT`.
- Given um clone limpo, when `composer install` usa o lockfile, then `predis/predis` é instalado como dependência direta e nenhuma extensão `redis` é necessária.
- Given `.env.example`, when `php bin/ci/check-env-vars.php` é executado, then `REDIS_HOST` e `REDIS_PORT` estão declarados e lidos pelo código, sem variáveis órfãs.
- Given os serviços locais estão no ar, when `setup.sh` executa, then ele espera MySQL e Redis de forma limitada antes de migrar e popular o banco.
- Given os checks em `tests/docker/`, when executados contra Compose, then eles validam o serviço Redis e uma operação de conectividade do app por Predis.
- Given GitHub Actions, when o job Redis roda, then ele inicia Redis e o teste do grupo `redis` passa; when a suíte sem banco roda, then ela não requer Redis.

## Design Notes

`config/redis.php` deve apenas transformar ambiente em dados e validar a porta; o cliente deve ser criado pelo teste ou por uma futura porta de infraestrutura. Isso mantém a inicialização existente lazy e evita implementar por acidente o EventBus (4.2) ou `SessionRepository` (4.3).

## Verification

**Commands:**

- `composer validate --strict && composer check-platform-reqs` -- manifesto e plataforma válidos.
- `php bin/ci/check-env-vars.php` -- contrato `.env.example`/`getenv()` sincronizado.
- `bash -n setup.sh tests/docker/*.sh` -- scripts shell sintaticamente válidos.
- `docker compose config` -- Compose válido, com Redis e configuração do app resolvidos.
- `docker compose up -d redis app mysql && docker compose exec -T app vendor/bin/phpunit --group redis` -- teste PHP Redis verde.
- `tests/docker/validate-docker-compose.sh && tests/docker/validate-env.sh && tests/docker/connectivity-test.sh` -- checks locais de infraestrutura verdes.

## Review Triage Log

### 2026-08-21 — Review pass
- intent_gap: 0
- bad_spec: 0
- patch: 12 (high 4, medium 6, low 2)
- defer: 0
- reject: 3 (low 3)
- addressed_findings:
  - `[high] [patch]` Impediu que `vendor/` local sobrescreva as dependências resolvidas na imagem, com `.dockerignore`.
  - `[high] [patch]` Removeu a publicação da porta Redis no host; a comunicação permanece na rede interna do Compose.
  - `[high] [patch]` Alinhou README, metadata Composer e licença MIT; documentou Redis como infraestrutura presente, preservando Pub/Sub e sessão no Roadmap.
  - `[high] [patch]` Adicionou job de CI que executa a integração real do Compose, incluindo a conectividade do app por Predis.
  - `[medium] [patch]` Tornou as chaves Redis dos checks shell exclusivas por execução e o relatório de falha específico por serviço.
  - `[medium] [patch]` Validou os overrides de tentativas/intervalo do setup, refletiu o timeout configurado e conferiu o serviço `app` especificamente.
  - `[medium] [patch]` Exigiu `condition: service_healthy` para MySQL e Redis nos validadores de Compose.
  - `[medium] [patch]` Executou `check-redis.sh` dentro da integração Docker e no CI.
  - `[medium] [patch]` Manteve os testes de configuração Redis na suíte sem banco e marcou somente a conectividade de rede com o grupo `redis`.
  - `[low] [patch]` Cobriu por teste executado a interrupção do setup antes de migration/seed quando Redis não responde.
  - `[low] [patch]` Preservou a execução explícita do grupo `redis` no CI exigida pela story, apesar da execução também ocorrer na suíte completa.

## Auto Run Result

Resumo: Redis 7 foi reintroduzido como infraestrutura local e de CI com Predis, configuração por ambiente, espera determinística no setup e evidência executável sem implementar Pub/Sub, sessão ou outras stories do Epic 4.

Arquivos alterados:

- `.dockerignore` -- evita que dependências locais contaminem a imagem.
- `.env.example`, `config/redis.php`, `composer.json`, `composer.lock`, `LICENSE` e `README.md` -- cliente Predis, contrato de ambiente, licença e documentação coerentes.
- `docker-compose.yml`, `Dockerfile`, `Makefile` e `setup.sh` -- serviço Redis interno, imagem reproduzível e inicialização segura.
- `.github/workflows/ci.yml` -- grupo Redis e integração Compose obrigatórios no CI.
- `tests/Integration/Infrastructure/Redis/RedisConnectivityTest.php` e `tests/docker/` -- configuração, conectividade, saúde e falhas de setup cobertas.

Revisão: 12 patches aplicados (4 high, 6 medium, 2 low); 0 itens diferidos; 3 achados baixos rejeitados por não afetarem o contrato da story. A recomendação de revisão adicional é `true` (há patches high nesta passagem; score 20).

Verificação executada: `composer validate --strict`, `composer check-platform-reqs`, `php bin/ci/check-env-vars.php`, `bash -n setup.sh tests/docker/*.sh`, `docker compose config`, PHPUnit do grupo `redis` (1 teste/3 asserções), `SetupScriptTest` (12 testes/20 asserções), validadores Docker e `connectivity-test.sh` -- todos verdes. A integração Compose completa também passou com 27 checks.

Riscos residuais: os checks de conectividade e integração exigem Docker/Compose disponível; Redis não é exposto na máquina host, portanto depuração externa deve ocorrer pelo container.
