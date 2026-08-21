---
title: 'Session Management (Redis Storage)'
type: 'feature'
created: '2026-08-21'
status: done
baseline_revision: '26f569ff3c6251816d8856022211ee5799e9a3cf'
baseline_commit: '26f569ff3c6251816d8856022211ee5799e9a3cf'
review_loop_iteration: 0
followup_review_recommended: true
context:
  - '_bmad-output/implementation-artifacts/epic-4-context.md'
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** A infraestrutura Redis já está disponível, mas a aplicação não tem uma porta nem um repositório para persistir o estado de uma sessão compartilhada entre processos. Sem isso, as próximas histórias não conseguem associar eventos e comportamento a uma sessão com retenção limitada.

**Approach:** Introduzir um contrato de sessão injetável e sua implementação Redis lazy com Predis, configurando um TTL validado e comprovando leitura, escrita, expiração e volume de interações contra Redis real.

## Boundaries & Constraints

**Always:** Usar exclusivamente `predis/predis` e a configuração existente de Redis; guardar os campos pelo `session_id` e preservar literalmente chaves em `dot.notation`; renovar um TTL positivo configurável a cada escrita; isolar detalhes Redis em Infrastructure, registrar dependências por FQCN no container lazy e limpar dados efêmeros de testes. Toda variável de ambiente adicionada deve estar sincronizada com `.env.example` e o Compose.

**Block If:** A API Predis disponível não permitir armazenar valor serializado e aplicar/renovar TTL sem uma janela que possa deixar estado de sessão sem expiração, ou se o contrato necessário para um consumidor exigir decidir semântica de carrinho, usuário autenticado ou eventos que pertence às histórias 4.2 ou 4.4.

**Never:** Não implementar Pub/Sub, subscriber, captura de eventos, middleware/cookie HTTP, carrinho, autenticação, cache de recomendações, Swoole ou qualquer extensão Redis nativa; não abrir uma conexão Redis durante o bootstrap de uma rota que não solicite o repositório.

**Value semantics:** A porta aceita somente valores nativos do JSON: `string`, `int`, `float`, `bool`, listas e mapas recursivamente compostos desses tipos. Objetos PHP, recursos e qualquer valor que não possa ser representado por JSON devem falhar explicitamente antes da persistência. `null` é reservado para indicar campo ausente ou sessão inexistente/expirada e não é um valor persistível; consumidores que precisam representar ausência não devem gravar o campo.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Persistir e recuperar | `session_id` válido, `cart.items` ou `user.id` e valor nativo JSON não nulo | A leitura da mesma sessão/campo devolve o valor JSON equivalente e a chave Redis recebe TTL | Identificador, campo, TTL ou serialização inválidos falham explicitamente antes de persistir |
| Sessão inexistente ou expirada | Campo ausente ou TTL vencido | Leitura devolve ausência (`null`); nenhum estado antigo reaparece | Operação Redis indisponível propaga falha do cliente, sem fallback em memória |
| Carga de sessão | Mais de 50 escritas consecutivas na mesma sessão | Todos os campos escritos continuam recuperáveis; a retenção não cresce sem limite | A escrita preserva TTL para impedir chave sem expiração |

</intent-contract>

## Code Map

- `config/redis.php` -- retorna host/porta validados sem construir cliente; reutilizar como fonte para a factory Predis.
- `config/bootstrap.php` -- container de factories FQCN lazy; adicionar somente as factories de `Predis\Client` e do contrato de sessão, sem alterar rotas nem abrir conexão cedo.
- `app/Shared/Container/Container.php` -- resolução lazy e memoizada que a nova integração deve preservar.
- `app/Domain/` -- convenção de contratos internos por contexto; não há contexto Session existente após R5.3, portanto criar a porta mínima de repositório aqui.
- `app/Infrastructure/Redis/SessionRepository.php` -- novo adaptador de Redis, único local para nomes de chave, serialização e comandos de armazenamento de sessão.
- `.env.example`, `docker-compose.yml` e `bin/ci/check-env-vars.php` -- contrato de ambiente; este último descobre divergências por `getenv()`.
- `tests/Integration/Infrastructure/Redis/RedisConnectivityTest.php` -- padrão existente de Predis, grupo `redis`, IDs aleatórios e limpeza tolerante no `finally`.
- `.github/workflows/ci.yml` e `Makefile` -- CI já inicia Redis para grupo `redis`; a suíte local comum o exclui.

## Tasks & Acceptance

**Execution:**

- [x] `app/Domain/Session/Repository/SessionRepositoryInterface.php` -- criar a porta tipada de leitura/escrita de dados por sessão e campo -- permitir consumidores futuros sem acoplamento a Redis.
- [x] `app/Infrastructure/Redis/SessionRepository.php` -- implementar o adaptador Predis com namespace de chave, campos `dot.notation`, serialização segura e atualização atômica do TTL -- persistir estado limitado e recuperável.
- [x] `config/session.php`, `.env.example`, `docker-compose.yml` e `config/bootstrap.php` -- expor e validar `SESSION_TTL`, injetar cliente/configuração e bind da interface de modo lazy -- tornar a duração configurável sem conexão prematura.
- [x] `tests/Integration/Infrastructure/Redis/SessionRepositoryTest.php` -- criar evidência Redis real de recuperar campos, expirar sessão e suportar ao menos 50 gravações, com polling limitado e limpeza -- cobrir matriz e critérios observáveis.
- [x] `tests/Integration/Infrastructure/Redis/SessionRepositoryConfigurationTest.php` quando necessário -- provar configuração inválida e resolução lazy sem Redis -- preservar a independência da suíte sem serviços.

**Acceptance Criteria:**

- Given Redis configurado pela Story 4.1, when `SessionRepository` armazena `cart.items` e `user.id` para um `session_id`, then ambos são recuperáveis pelo mesmo identificador e campos literais em `dot.notation`.
- Given `SESSION_TTL` válido, when a sessão recebe uma escrita, then sua expiração Redis é definida/renovada; when o TTL vence, then a leitura retorna `null`, comprovado por teste de integração agrupado como `redis`.
- Given uma sessão ativa, when recebe pelo menos 50 interações consecutivas, then todos os valores esperados continuam recuperáveis e a sessão mantém TTL positivo.
- Given uma rota não solicita o contrato de sessão, when o bootstrap é carregado, then nenhum cliente Redis é instanciado; when o contrato é solicitado, then ele recebe cliente Predis configurado por `REDIS_HOST` e `REDIS_PORT`.
- Given o contrato de ambiente, when `php bin/ci/check-env-vars.php` é executado, then `SESSION_TTL` e todas as variáveis Redis declaradas estão sincronizadas.

## Design Notes

Guardar uma sessão como hash Redis namespaced, com os campos em `dot.notation` usados literalmente, evita reserializar o objeto inteiro em cada interação. Valores devem ter uma representação estável e erros explícitos; a operação de escrita e renovação do TTL deve ser transacional para não deixar uma chave persistente caso o processo pare entre os comandos. O TTL é deslizante na escrita, pois a retenção é da sessão ativa, não de um snapshot inicial.

## Verification

**Commands:**

- `php bin/ci/check-env-vars.php` -- contrato de variáveis sincronizado.
- `vendor/bin/phpunit --filter SessionRepositoryConfigurationTest` -- configuração e lazy loading sem Redis passam.
- `docker compose up -d redis app && docker compose exec -T app vendor/bin/phpunit --filter SessionRepositoryTest --group redis` -- escrita, leitura, TTL e 50+ interações passam em Redis real.
- `vendor/bin/php-cs-fixer fix --dry-run --diff` -- código novo respeita estilo.
- `git diff --check` -- sem problemas de espaço em branco.

## Review Triage Log

### 2026-08-21 — Review pass
- intent_gap: 0
- bad_spec: 0
- patch: 3 (high 0, medium 3, low 0)
- defer: 0
- reject: 11 (high 0, medium 5, low 6)
- addressed_findings:
  - `[medium] [patch]` Cobriu `SESSION_TTL` não padrão desde a configuração até a factory lazy, evitando configuração positiva ignorada sem teste.
  - `[medium] [patch]` Tornou o teste Redis capaz de demonstrar renovação efetiva do TTL após ele decrescer, evitando falso verde de mera existência de TTL.
  - `[medium] [patch]` Atualizou os validadores Docker para exigir `SESSION_TTL` em `.env.example` e no ambiente do serviço `app`, evitando desvio do novo contrato de ambiente.

### 2026-08-21 — Review pass (revisão de acompanhamento)
- intent_gap: 2: (high 2, medium 0, low 0)
- bad_spec: 2: (high 0, medium 2, low 0)
- patch: 8: (high 0, medium 3, low 5)
- defer: 0
- reject: 10: (high 0, medium 3, low 7)
- addressed_findings:
  - none

### 2026-08-21 — Review pass
- intent_gap: 0
- bad_spec: 0
- patch: 4 (high 0, medium 4, low 0)
- defer: 0
- reject: 13 (high 0, medium 3, low 10)
- addressed_findings:
  - `[medium] [patch]` Preservou floats com fração zero na serialização JSON e cobriu o round-trip em Redis.
  - `[medium] [patch]` Limitou a profundidade de valores JSON para rejeitar estruturas excessivamente aninhadas ou circulares antes de gravar.
  - `[medium] [patch]` Tornou `SESSION_TTL` sobrescrevível pelo ambiente no Compose e validou esse contrato.
  - `[medium] [patch]` Comprovou em Redis real que o TTL não padrão configurado e injetado pelo container controla a expiração da chave.

### 2026-08-21 — Review pass (revisão de acompanhamento)
- intent_gap: 0
- bad_spec: 0
- patch: 10: (high 1, medium 9, low 0)
- defer: 0
- reject: 14: (high 0, medium 7, low 7)
- addressed_findings:
  - `[medium] [patch]` Passou a rejeitar `SESSION_TTL` explicitamente vazio, em vez de ocultar a configuração inválida com o valor padrão.
  - `[high] [patch]` Limitou o TTL à faixa segura de expiração antes de qualquer comando Redis, impedindo que `HSET` seja seguido por um `EXPIRE` inválido e deixe uma chave persistente.
  - `[medium] [patch]` Validou também os valores decodificados do Redis, rejeitando JSON sintaticamente válido que viola o contrato, como um float infinito.
  - `[medium] [patch]` Apertou a prova de injeção do TTL não padrão para exigir valor próximo ao configurado, eliminando falso verde com TTL menor hard-coded.
  - `[medium] [patch]` Comprovou que falhas de conexão Predis são propagadas sem fallback em memória.
  - `[medium] [patch]` Incluiu `SESSION_TTL` no validador de `.env.example` e do ambiente Compose.
  - `[medium] [patch]` Incluiu `SESSION_TTL` na verificação end-to-end do ambiente recebido pelo container `app`.
  - `[medium] [patch]` Cobriu identificador de sessão e campo inválidos antes de qualquer tentativa de conexão Redis.
  - `[medium] [patch]` Cobriu leitura de sessão/campo ausente com retorno `null`.
  - `[medium] [patch]` Verificou diretamente no hash Redis que os campos `cart.items` e `user.id` permanecem literais.

### 2026-08-21 — Review pass (revisão final)
- intent_gap: 0
- bad_spec: 0
- patch: 9: (high 1, medium 4, low 4)
- defer: 0
- reject: 12: (high 2, medium 6, low 4)
- addressed_findings:
  - `[high] [patch]` Removeu uma validação impossível de tipo de chave de array que fazia o PHPStan reprovar o adaptador.
  - `[medium] [patch]` Fez o teste Docker respeitar uma sobrescrita válida de `SESSION_TTL`, em vez de exigir sempre `1800`.
  - `[medium] [patch]` Comprovou que o mesmo campo permanece isolado entre identificadores de sessão diferentes.
  - `[medium] [patch]` Cobriu a rejeição explícita de JSON Redis malformado e do valor armazenado `null`.
  - `[medium] [patch]` Cobriu valores inválidos aninhados em listas e mapas, incluindo `null`, objeto e float infinito.
  - `[low] [patch]` Passou a rejeitar nomes de campo compostos somente por espaços.
  - `[low] [patch]` Substituiu a espera fixa do teste de renovação por polling limitado do decremento real do TTL.
  - `[low] [patch]` Evitou executar o grupo Redis duas vezes no job de cobertura do CI.
  - `[low] [patch]` Incluiu `SESSION_TTL` na verificação de ambiente do script Docker de integração.

## Auto Run Result

**Resumo:** A revisão final endureceu o repositório Redis de sessões e sua evidência automatizada: corrigiu a análise estática, tornou a validação de campo consistente, ampliou a cobertura de isolamento e corrupção de dados e alinhou os testes Docker/CI ao TTL configurável.

**Arquivos alterados:**

- `.env.example`, `docker-compose.yml`, `config/session.php` e `config/bootstrap.php` — configuram e injetam Redis/TTL de sessão de forma lazy.
- `app/Domain/Session/Repository/SessionRepositoryInterface.php` — define a porta de sessão.
- `app/Infrastructure/Redis/SessionRepository.php` — persiste campos JSON em hash Redis com TTL transacional e validação explícita.
- `tests/Integration/Infrastructure/Redis/SessionRepositoryConfigurationTest.php` — verifica configuração, entradas inválidas e resolução lazy.
- `tests/Integration/Infrastructure/Redis/SessionRepositoryTest.php` — verifica Redis real, isolamento, expiração, carga, corrupção e valores inválidos.
- `tests/docker/integration-test.sh`, `tests/docker/validate-docker-compose.sh` e `tests/docker/validate-env.sh` — validam o contrato de ambiente no Compose.
- `.github/workflows/ci.yml` — separa o grupo Redis da suíte de cobertura para evitar execução duplicada.
- `_bmad-output/implementation-artifacts/spec-4-3-session-management-redis-storage.md` — registra a especificação, triagem e resultado desta execução.

**Achados da revisão:** 9 patches aplicados (1 high, 4 medium, 4 low), nenhum item diferido e 12 apontamentos rejeitados após deduplicação e triagem.

**Revisão de acompanhamento:** `true`; contagem de patches = high 1, medium 4, low 4; score = `3 × 4 + 4 = 16`.

**Verificação:** `php bin/ci/check-env-vars.php` passou (13 variáveis); `SessionRepositoryConfigurationTest` passou (5 testes, 19 asserções); PHPStan passou sem erros; PHP CS Fixer não encontrou alterações; `git diff --check` passou; a suíte Redis real passou no PHP 8.4 (13 testes, 87 asserções).

**Riscos residuais:** A atomicidade depende das garantias `MULTI/EXEC` do Redis/Predis e não é submetida a fault injection entre comandos; limites de tamanho e quantidade de campos permanecem responsabilidade operacional porque não fazem parte da intenção desta história.
