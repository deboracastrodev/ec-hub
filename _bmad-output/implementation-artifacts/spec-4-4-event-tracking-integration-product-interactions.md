---
title: 'Event Tracking Integration (Product Interactions)'
type: 'feature'
created: '2026-08-21'
status: 'done'
baseline_revision: '9a3f901fb209ce2511d6927bb88162e1ae940543'
review_loop_iteration: 1
followup_review_recommended: true
context:
  - '_bmad-output/implementation-artifacts/epic-4-context.md'
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** As interações da compradora não chegam ao barramento Redis nem ficam associadas a uma sessão HTTP; consequentemente, `GET /api/recommendations` ignora o comportamento recente.

**Approach:** Criar um caso de uso de tracking reutilizável e uma borda HTTP com cookie de sessão, conectando visualização, clique e adição ao carrinho ao Redis Pub/Sub, ao estado limitado da sessão e à ordenação comportamental das recomendações.

## Boundaries & Constraints

**Always:** Usar `EventPublisherInterface` e `SessionRepositoryInterface`, publicar somente `product.viewed`, `product.clicked` e `cart.item_added` com `session_id`, `product_id` e `user_id` somente quando fornecido. Gerar cookie opaco `ec_hub_session_id` no servidor com `HttpOnly` e `SameSite=Lax`; não aceitar um identificador de sessão do cliente. Manter `product_id` obrigatório no contrato de recomendações e permitir `user_id` como identidade opcional de consulta, sem implementar autenticação. Falha no tracking não pode impedir a navegação, o clique ou o carrinho: registrar o erro e responder normalmente. Limitar o histórico por sessão a 50 eventos e renovar o TTL por meio do repositório existente.

**Block If:** O contrato existente de `EventPublisherInterface` ou `SessionRepositoryInterface` não suportar os dados exigidos sem mudança incompatível.

**Never:** Não criar worker long-running, não alterar a semântica dos envelopes Pub/Sub existentes, não adicionar extensão Redis nativa, não retornar ao cliente IDs de sessão nem implementar UI de histórico/`/metrics` (Story 4.5).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Visualização válida | GET de produto existente sem cookie | Cria cookie de sessão e publica `product.viewed` com produto e sessão; histórico contém o evento | Falha Redis é registrada e a página continua 200 |
| Clique e carrinho válidos | POST JSON com produto existente, sessão existente; carrinho com quantidade positiva | Publica `product.clicked` ou `cart.item_added`; carrinho é persistido para a segunda ação | Produto inválido ou payload inválido retorna 400/404 sem publicar |
| Personalização | Mesmo usuário/sessão consulta recomendações antes e após uma ou duas interações | Resposta continua com `product_id` obrigatório e reordena resultados para refletir produtos/interesses recentes sem duplicatas | Sem histórico, preserva o resultado base |
</intent-contract>

## Code Map

- `app/Domain/Event/EventPublisherInterface.php` -- porta de publicação; preservar contrato `publish(string, mixed)`.
- `app/Domain/Session/Repository/SessionRepositoryInterface.php` e `app/Infrastructure/Redis/SessionRepository.php` -- estado de sessão TTL e campos dot-notation a reutilizar.
- `app/Domain/Event/EventStoreInterface.php` e `app/Infrastructure/Messaging/RedisEventStore.php` -- padrão de porta Redis consultável; acrescentar uma porta de histórico indexada por sessão e, quando houver identidade, por `user_id`.
- `app/Infrastructure/Messaging/RedisEventBus.php` -- cria o envelope Pub/Sub canônico e valida nomes de eventos.
- `app/Controller/ProductController.php` -- borda da visualização confirmada de produto.
- `app/Controller/RecommendationController.php` e `app/Application/Recommendation/GenerateRecommendations.php` -- contrato atual `product_id` e ponto de extensão para ordenação por comportamento.
- `public/index.php` -- rotas HTTP atuais e passagem de query/headers aos controladores.
- `config/bootstrap.php` -- composição das dependências Redis, controladores e casos de uso.
- `views/product/detail.html.twig` -- superfície para os controles de clique e carrinho.
- `tests/Integration/Infrastructure/Messaging/RedisEventPubSubTest.php` e `tests/Integration/Infrastructure/Redis/SessionRepositoryTest.php` -- padrões de integração Redis existentes.

## Tasks & Acceptance

**Execution:**
- `app/Application/Event/TrackProductInteraction.php` e portas de domínio necessárias -- validar produto e payload, montar os três eventos, atualizar histórico limitado por sessão e por `user_id` quando informado, atualizar `cart.items`, e tratar publicação como best-effort.
- `app/Shared/Http/SessionContext.php`, `app/Controller/ProductController.php`, novo controlador de interação e `public/index.php` -- criar/ler cookie seguro, emitir visualização e expor `POST /api/events` e `POST /api/cart/items` com respostas HTTP validadas.
- `views/product/detail.html.twig` -- adicionar controles acessíveis que disparem os endpoints de clique e carrinho sem interferir no detalhe.
- `app/Application/Recommendation/GenerateRecommendations.php`, `app/Controller/RecommendationController.php` e `config/bootstrap.php` -- consultar o índice de `user_id` quando informado, ou de sessão caso contrário; reordenar deterministicamente resultados elegíveis e preservar o contrato atual e o fallback sem histórico.
- `tests/Unit/Application/Event/TrackProductInteractionTest.php`, testes de contexto de sessão, controlador/HTTP e integração Redis/recomendação -- cobrir cada linha da matriz, retenção exata de 50 eventos, validações de payload e baseline versus pós-interação por sessão e `user_id`.

**Acceptance Criteria:**
- Given uma pessoa navega até um produto existente, when a página é exibida, then `product.viewed` é publicado com `product_id`, sessão e timestamp, com `user_id` quando informado.
- Given uma sessão válida, when a pessoa clica em produto ou adiciona produto válido ao carrinho, then `product.clicked` ou `cart.item_added` é publicado e a interação fica consultável pelo identificador de sessão e pelo `user_id` opcional.
- Given uma consulta de recomendações com `product_id`, when a sessão ou `user_id` tem uma ou duas interações, then a resposta preserva seu formato e altera deterministicamente a prioridade conforme o comportamento, comprovada contra um baseline sem interação.
- Given Redis está indisponível, when uma interação de produto ocorre, then a ação da pessoa continua bem-sucedida sem expor erro interno.

## Design Notes

O evento precisa ser recuperável no fluxo síncrono da aplicação mesmo que não exista um worker Pub/Sub no request. Por isso, o caso de uso registra o histórico limitado antes da publicação best-effort; o Pub/Sub continua sendo a integração de eventos exigida. A fonte de consulta é indexada pelo `session_id` e, quando a borda recebe uma identidade opcional, também por `user_id`, para que o contrato `?user_id={id}` não dependa do cookie atual. Empates de prioridade preservam deterministicamente a ordem base das recomendações.

## Spec Change Log

### 2026-08-21 — revisão 1
- Gatilho: a consulta com `user_id` filtrava apenas o histórico do cookie atual, contrariando a disponibilidade por identidade solicitada pela história.
- Alteração: tarefas e notas de design agora exigem um índice limitado consultável por sessão e por `user_id`, com seleção explícita da fonte no pipeline de recomendações e ordenação determinística.
- Estado ruim evitado: uma consulta `GET /api/recommendations?user_id={id}` sem o cookie original ignoraria as próprias interações da pessoa.
- KEEP: preservar o cookie opaco HttpOnly/SameSite, o transporte Pub/Sub existente, a publicação best-effort e o limite de retenção de 50 eventos.

## Suggested Review Order

**Fluxo e persistência de interações**

- Valida, indexa e publica sem bloquear a experiência de produto.
  [`TrackProductInteraction.php:30`](../../app/Application/Event/TrackProductInteraction.php#L30)

- Mantém os dois índices Redis limitados e com TTL renovado.
  [`RedisEventHistoryRepository.php:21`](../../app/Infrastructure/Redis/RedisEventHistoryRepository.php#L21)

**Personalização comportamental**

- Seleciona explicitamente o índice da identidade ou da sessão.
  [`GenerateRecommendations.php:347`](../../app/Application/Recommendation/GenerateRecommendations.php#L347)

**Bordas HTTP e UI**

- Restringe os endpoints aos payloads de interação permitidos.
  [`ProductInteractionController.php:18`](../../app/Controller/ProductInteractionController.php#L18)

- Despacha JSON e emite cabeçalhos somente para recomendações.
  [`index.php:93`](../../public/index.php#L93)

**Composição e testes**

- Conecta as portas Redis às dependências da aplicação.
  [`bootstrap.php:102`](../../config/bootstrap.php#L102)

- Exercita publicação resiliente, carrinho e retenção exata de cinquenta eventos.
  [`TrackProductInteractionTest.php:17`](../../tests/Unit/Application/Event/TrackProductInteractionTest.php#L17)

## Review Triage Log

### 2026-08-21 — Review pass
- intent_gap: 0
- bad_spec: 1 (high 1, medium 0, low 0)
- patch: 5 (high 1, medium 3, low 1)
- defer: 0
- reject: 14
- addressed_findings:
  - `[high]` `[bad_spec]` O histórico por `user_id` não era consultável fora da sessão atual; a especificação passou a exigir índice limitado por identidade e seleção de fonte no pipeline.

## Verification

**Commands:**
- `vendor/bin/phpunit tests/Unit/Application/Event/TrackProductInteractionTest.php tests/Unit/Controller tests/Integration/Controller --testdox` -- expected: todos os cenários HTTP e de domínio passam.
- `vendor/bin/phpunit --group redis tests/Integration/Infrastructure/Messaging/RedisEventPubSubTest.php tests/Integration/Infrastructure/Redis/SessionRepositoryTest.php tests/Integration --testdox` -- expected: Pub/Sub, sessão e integração de personalização passam com Redis disponível.
- `make test-unit && make cs-check` -- expected: suíte unitária e estilo passam.

## Auto Run Result

Implementação: captura `product.viewed`, `product.clicked` e `cart.item_added` com sessão HTTP opaca; registra histórico Redis limitado por sessão e por `user_id`; mantém o carrinho na sessão; e personaliza as recomendações por histórico, com desempate estável.

Arquivos alterados: casos de uso e portas de evento/histórico, repositório Redis, controladores/rotas, composição de dependências, detalhe do produto e testes unitários de tracking, controller e personalização.

Revisão: 1 correção de especificação (índice por `user_id`) e 5 patches aplicados, incluindo ordenação determinística e cobertura de validação, retenção e baseline pós-interação. Itens adiados: 0. Itens rejeitados: 14. Recomendação de nova revisão: `true` (patches: high 1, medium 3, low 1; score 10).

Verificação: `make test-unit` (86 testes) passou; testes focados de tracking/personalização/controller (7 testes, 23 asserções) passaram; Redis Pub/Sub e repositório de sessão no container (19 testes, 112 asserções) passaram; `make cs-check` e `git diff --check` passaram.

Risco residual: a suíte ampla de integração requer schema MySQL local de produtos; no ambiente atual ela falha por tabela ausente, sem evidência de regressão desta história.
