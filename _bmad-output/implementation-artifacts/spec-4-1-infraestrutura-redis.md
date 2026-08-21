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
deferred:
  - summary: >-
      Os checks Docker locais removem volumes nomeados do projeto durante o cleanup.
    evidence: |-
      `tests/docker/connectivity-test.sh` e `tests/docker/integration-test.sh` já executavam `docker compose down -v --remove-orphans` no baseline. Rodar os checks contra o projeto pode apagar dados MySQL e o cache Composer existentes, mesmo quando os serviços não foram criados exclusivamente pelo teste.
    location: >-
      tests/docker/connectivity-test.sh:18; tests/docker/integration-test.sh:45
    severity: high
  - summary: >-
      O agregador de checks da integração encerra na primeira falha em vez de produzir o resumo completo prometido.
    evidence: |-
      `tests/docker/integration-test.sh` já combinava `set -e` com `run_test` retornando status 1 no baseline. Uma validação negativa termina o script imediatamente, impedindo a execução dos checks restantes e o resumo agregado.
    location: >-
      tests/docker/integration-test.sh:8-42
    severity: medium
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
- patch: 11 (high 4, medium 5, low 2)
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

### 2026-08-21 — Review pass
- intent_gap: 0
- bad_spec: 0
- patch: 13 (high 1, medium 5, low 7)
- defer: 2 (high 1, medium 1, low 0)
- reject: 9 (low 9)
- addressed_findings:
  - `[high] [patch]` Restaurou uma interface local realmente independente de serviços ao excluir os grupos `db` e `redis` em `make test`, alinhando README e ajuda do Makefile.
  - `[medium] [patch]` Impediu que o cleanup Redis masque a falha original de conexão e garantiu remoção das chaves somente após escrita bem-sucedida nos três probes Predis.
  - `[medium] [patch]` Normalizou overrides numéricos do setup em base decimal, evitando que valores com zero à esquerda sejam interpretados como octais inválidos.
  - `[medium] [patch]` Passou a verificar os valores Redis no bloco do serviço `app` e no ambiente efetivo do container, sem permitir que defaults ocultem a ausência da injeção Compose.
  - `[medium] [patch]` Restringiu a validação de imagem e healthcheck ao bloco real do serviço Redis.
  - `[medium] [patch]` Ampliou `.dockerignore` para excluir variantes `.env*` potencialmente sensíveis, preservando apenas `.env.example`.
  - `[low] [patch]` Cobriu portas Redis numéricas fora de `1..65535`.
  - `[low] [patch]` Cobriu overrides inválidos do setup antes de qualquer chamada Docker.
  - `[low] [patch]` Atualizou a contagem aproximada e a descrição das lanes de teste no README.
  - `[low] [patch]` Documentou diagnóstico Redis pelos containers, coerente com a ausência deliberada de porta publicada no host.
  - `[low] [patch]` Excluiu o diretório HTML de cobertura do contexto Docker.
  - `[low] [patch]` Corrigiu a contagem inconsistente do log da revisão anterior de 12 para 11 achados endereçados.
  - `[low] [patch]` Tornou os checks inline de Redis seguros contra resíduos quando a leitura falha após a escrita.

### 2026-08-21 — Review pass
- intent_gap: 0
- bad_spec: 0
- patch: 8 (high 2, medium 5, low 1)
- defer: 0
- reject: 10 (low 10)
- addressed_findings:
  - `[high] [patch]` Exigiu a resposta literal `PONG` nos healthchecks e esperas Redis de Compose, setup e checks Docker, impedindo falso positivo para respostas de erro com status zero.
  - `[high] [patch]` Corrigiu o healthcheck MySQL para executar `SELECT 1` com a senha real `secret`, validando a mesma autenticação usada pelo app.
  - `[medium] [patch]` Substituiu `exit()` dentro dos probes Redis inline por exceções, garantindo a execução do `finally` e a limpeza da chave temporária.
  - `[medium] [patch]` Tornou a limpeza do teste PHPUnit best-effort sem mascarar a falha primária e marcou a chave como escrita antes de validar a resposta de `SET`.
  - `[medium] [patch]` Cobriu a leitura de overrides válidos de `REDIS_HOST` e `REDIS_PORT` pelo arquivo de configuração.
  - `[medium] [patch]` Cobriu dinamicamente o caminho feliz do setup e a ordem Redis, Composer, migration e seed.
  - `[medium] [patch]` Fixou por teste o contrato de `make test` excluir simultaneamente os grupos `db` e `redis`.
  - `[low] [patch]` Removeu janelas fixas de contexto dos checks de dependência do serviço `app`, preservando-os quando o bloco Compose crescer.

## Auto Run Result

Resumo: revisão fresca da infraestrutura Redis concluída; os probes agora rejeitam respostas Redis não-PONG, o healthcheck MySQL usa credenciais válidas, as chaves temporárias são limpas em falhas e os caminhos de configuração, setup e `make test` têm cobertura executável.

Arquivos alterados nesta revisão:

- `docker-compose.yml` — tornou os healthchecks Redis e MySQL semanticamente estritos.
- `setup.sh` — passou a aceitar Redis pronto somente após resposta literal `PONG`.
- `tests/Integration/Infrastructure/Redis/RedisConnectivityTest.php` — cobriu overrides e preservou diagnóstico/limpeza de chaves.
- `tests/docker/MakefileTest.php` — verificou as exclusões `db` e `redis` do target local.
- `tests/docker/SetupScriptTest.php` — atualizou o probe e cobriu o caminho feliz em ordem.
- `tests/docker/connectivity-test.sh` — preservou cleanup ao falhar e exigiu `PONG`.
- `tests/docker/integration-test.sh` — preservou cleanup, exigiu `PONG` e removeu checks frágeis por janela fixa.
- `tests/docker/validate-docker-compose.sh` — removeu dependência de janelas fixas ao inspecionar `app`.
- `_bmad-output/implementation-artifacts/spec-4-1-infraestrutura-redis.md` — registrou triagem, verificação e resultado final sem alterar entradas existentes de trabalho deferido.

Achados da revisão: 8 patches aplicados (2 high, 5 medium, 1 low), 0 itens novos deferidos e 10 sugestões rejeitadas como cosméticas, otimizações sem defeito demonstrado ou incompatíveis com a decisão explícita de deixar o cliente de produção para uma futura porta de infraestrutura.

Revisão de acompanhamento recomendada: `true`. Contagem de patches: high 2, medium 5, low 1; pontuação ponderada dos achados medium/low: `3 × 5 + 1 × 1 = 16`; além disso, houve patches high.

Verificação executada:

- `composer validate --strict && composer check-platform-reqs` — passou.
- `php bin/ci/check-env-vars.php` — passou; 12 variáveis sincronizadas.
- `bash -n setup.sh tests/docker/*.sh` — passou.
- PHPUnit focal sem Redis — 32 testes, 78 assertions, passou.
- PHP-CS-Fixer focal em dry-run — 0 arquivos corrigíveis, passou.
- `docker compose config` — passou, com healthchecks e dependências resolvidos.
- `tests/docker/validate-docker-compose.sh && tests/docker/validate-env.sh` — passou.
- `docker compose up -d --build redis app mysql && docker compose exec -T app vendor/bin/phpunit --group redis` — build e serviços saudáveis; 1 teste, 3 assertions, passou.
- `tests/docker/check-redis.sh` — passou.
- `vendor/bin/phpunit --exclude-group db --exclude-group redis` — após remover somente os containers da verificação, preservando volumes: 149 testes, 642 assertions, 1 skipped, passou com avisos preexistentes.
- `git diff --check` — passou.

Riscos residuais: `tests/docker/connectivity-test.sh` não foi executado porque a entrada deferida já existente registra que seu cleanup usa `docker compose down -v` e pode apagar volumes locais. A conectividade equivalente foi verificada pelo grupo PHPUnit `redis` dentro do app e por `check-redis.sh`. Os dois itens deferidos anteriores permanecem intactos e nenhum novo item foi adicionado.
