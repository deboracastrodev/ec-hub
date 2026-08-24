---
title: 'Metrics Dashboard Page Structure'
type: 'feature'
created: '2026-08-24'
status: 'done'
baseline_revision: 'a5a469209720de6cdf759d112caca08a55b92d4f'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '_bmad-output/implementation-artifacts/epic-5-context.md'
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** A rota `/metrics` já mostra somente o histórico de eventos da sessão, mas não comunica que é a vitrine técnica do ec-hub nem estabelece a estrutura visual exigida para o dashboard do Epic 5.

**Approach:** Evoluir a página existente para um dashboard HTML semântico, com o cabeçalho solicitado e uma estrutura responsiva que preserve o histórico já funcional como seção do dashboard. A página continua renderizada no servidor e sem depender de JavaScript; as stories 5.2–5.7 preencherão suas áreas com dados e atualizações específicas.

## Boundaries & Constraints

**Always:** Manter `GET /metrics` e a consulta isolada por sessão já existentes; renderizar com Twig e o layout-base; usar marcos semânticos, hierarquia de títulos e uma grade mobile-first funcional em 320 px, tablet (768 px) e desktop (1024 px); manter o carregamento sem novas chamadas remotas, banco ou Redis além da consulta atual ao histórico.

**Block If:** A estrutura necessária exigir alterar o contrato de eventos persistidos, expor o identificador de sessão no HTML, ou introduzir dados reais de Redis, memória, recomendações ou saúde que pertencem às stories 5.2–5.7.

**Never:** Não implementar divulgação progressiva, stream em tempo real, endpoint de memória ou health check; não simular métricas, recomendações, estado ML ou resultados de qualidade; não remover a lista, contador, estado vazio, ordenação ou isolamento por sessão entregues pela Story 4.5.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| DASHBOARD_WITH_EVENTS | `GET /metrics` com eventos na sessão atual | HTML 200 com o cabeçalho `ec-hub - System Metrics Dashboard`, regiões semânticas do dashboard e a seção de histórico existente contendo somente os eventos da sessão atual | Dados de outra sessão não são renderizados |
| DASHBOARD_EMPTY_SESSION | `GET /metrics` com sessão atual sem eventos | HTML 200 com a mesma estrutura do dashboard, contador zero e estado vazio acessível | Não há erro Twig nem área em branco |
| RESPONSIVE_LAYOUT | CSS carregado em viewport de 320 px, 768 px e 1024 px | Conteúdo legível, sem overflow horizontal; áreas do dashboard em uma coluna no mobile e grade apropriada em tablet/desktop | Sem JavaScript, a estrutura e o histórico permanecem visíveis |

</intent-contract>

## Code Map

- `public/index.php:70-79` -- já registra `GET /metrics` e encaminha ao dispatcher HTML; não alterar a rota nem introduzir endpoint adicional nesta story.
- `app/Controller/MetricsController.php:17-52` -- coleta e normaliza o histórico da sessão, aplica ordenação cronológica reversa estável e fornece `events`/`total` ao Twig; preservar o contrato e, se o novo template demandar dados estáticos de estrutura, fornecê-los explicitamente por causa de `strict_variables`.
- `views/metrics/history.html.twig:1-29` -- única view de métricas atual; transformar em dashboard, mantendo o `<ol>`, contador `aria-live`, item de evento e estado vazio como uma seção identificável de histórico.
- `views/layout/base.html.twig:1-91` -- fornece idioma, viewport, skip links, `<main>` e stylesheet global; reutilizar seus blocos e não duplicar o documento HTML.
- `public/assets/css/main.css:1-...` -- CSS global mobile-first já contém tokens, foco visível e breakpoints de 768/1024 px; acrescentar estilos BEM do dashboard sem afetar listagem ou detalhe de produto.
- `tests/Unit/Controller/MetricsControllerTest.php:15-73` -- prova os invariantes do controlador; atualizar apenas asserções de superfície que mudarem e preservar cobertura de ordenação, total, ausência de produto e vazio.
- `tests/Integration/Controller/MetricsHttpEndpointTest.php:19-113` -- exerce `GET /metrics` com Twig real e duas sessões; estender para provar cabeçalho do dashboard e continuidade do isolamento/estado vazio na borda HTTP.
- `tests/Integration/View/ResponsiveLayoutTest.php:52-...` -- padrão existente de teste de HTML/CSS responsivo; reutilizar para validar a estrutura e os breakpoints da página de métricas, sem navegador ou dados externos.
- `_bmad-output/implementation-artifacts/spec-4-5-event-history-display.md:79-89` -- entrega anterior que fixa os invariantes de histórico; é evidência somente-leitura para evitar regressão.

## Tasks & Acceptance

**Execution:**

- `views/metrics/history.html.twig` -- reorganizar a view como dashboard com cabeçalho explícito, regiões semânticas e seção de histórico preservada -- estabelece a superfície extensível do Epic 5 sem fabricar dados futuros.
- `public/assets/css/main.css` -- adicionar estilos mobile-first e BEM do dashboard, incluindo grade em 768 px e 1024 px -- torna a página legível em desktop e tablet sem alterar outras telas.
- `tests/Unit/Controller/MetricsControllerTest.php` -- adaptar asserções de renderização e manter cenários da matriz -- protege os dados e estados que a nova estrutura encapsula.
- `tests/Integration/Controller/MetricsHttpEndpointTest.php` -- verificar o dashboard por `GET /metrics`, título e continuidade do isolamento da sessão -- valida a superfície HTTP final.
- `tests/Integration/View/ResponsiveLayoutTest.php` -- acrescentar verificação estrutural/CSS para métricas nos breakpoints definidos -- evidencia responsividade sem depender de JavaScript.

**Acceptance Criteria:**

- Given Carlos acessa `GET /metrics`, when a página é renderizada, then recebe HTML 200 cujo cabeçalho visível é exatamente `ec-hub - System Metrics Dashboard`.
- Given uma sessão com eventos persistidos, when `GET /metrics` responde, then o dashboard preserva a seção de histórico com contador e apenas eventos da sessão atual em ordem cronológica reversa.
- Given a sessão atual não tem eventos, when `GET /metrics` responde, then o dashboard exibe contador zero e estado vazio acessível dentro da estrutura completa.
- Given a página é carregada sem JavaScript, when Carlos navega pelo conteúdo, then o dashboard e seu histórico estão disponíveis por HTML semântico, títulos hierárquicos e marcos identificáveis.
- Given o stylesheet é aplicado em 320 px, 768 px e 1024 px, when o dashboard é inspecionado, then não causa overflow horizontal, apresenta uma coluna no mobile e usa uma grade adequada em tablet e desktop.
- Given a requisição de `/metrics`, when o controlador renderiza a página, then não cria nova conexão a PDO nem busca dados de métricas ainda pertencentes às stories 5.2–5.7.

## Review Triage Log

### 2026-08-24 — Review pass

- intent_gap: 0
- bad_spec: 0
- patch: 1 (high 0, medium 0, low 1)
- defer: 0
- reject: 17
- addressed_findings:
  - `[low]` `[patch]` O título do documento alterado não era verificado pela borda HTTP; foi adicionada asserção para o elemento `<title>` exato em `GET /metrics`.

## Design Notes

A Story 4.5 já entregou o dado verificável disponível: histórico persistido por sessão. A Story 5.1 deve convertê-lo em uma moldura de produto consistente, não antecipar visualizações que dependeriam de fontes ainda não implementadas. Assim, o conteúdo presente ocupa uma seção real do dashboard, enquanto a arquitetura da página fica pronta para as áreas posteriores sem números decorativos ou promessas enganosas.

## Verification

**Commands:**

- `vendor/bin/phpunit tests/Unit/Controller/MetricsControllerTest.php tests/Integration/Controller/MetricsHttpEndpointTest.php tests/Integration/View/ResponsiveLayoutTest.php --testdox` -- expected: estrutura, histórico da sessão, estado vazio e verificações responsivas passam.
- `git diff --check` -- expected: sem erros de whitespace.

**Manual checks:**

- Abrir `/metrics` com e sem eventos de sessão e confirmar que o cabeçalho, regiões semânticas, contador e estado vazio permanecem legíveis tanto sem JavaScript quanto em viewport de tablet/desktop.

## Auto Run Result

**Resumo da mudança implementada:**

A página `/metrics` foi transformada na estrutura semântica e responsiva do dashboard técnico do ec-hub. O histórico de eventos existente continua isolado por sessão, ordenado e acessível, agora dentro de uma superfície pronta para as próximas stories do Epic 5 sem métricas simuladas.

**Arquivos alterados:**

- `views/metrics/history.html.twig` — estrutura do dashboard, cabeçalho solicitado e painel de histórico preservado.
- `public/assets/css/main.css` — estilos BEM mobile-first, proteção contra overflow e grade responsiva em 768 px e 1024 px.
- `tests/Unit/Controller/MetricsControllerTest.php` — cobertura da renderização do dashboard sem perder os invariantes do histórico.
- `tests/Integration/Controller/MetricsHttpEndpointTest.php` — cobertura HTTP do cabeçalho, título do documento, isolamento e estado vazio.
- `tests/Integration/View/ResponsiveLayoutTest.php` — cobertura da estrutura semântica e do contrato de grade responsiva.

**Revisão:**

- Patches aplicados: 1 (high 0, medium 0, low 1).
- Itens deferidos: 0.
- Itens rejeitados: 17 — sugestões fora da estrutura desta story, comportamentos preexistentes ou verificações que exigiriam introduzir uma infraestrutura de navegador não presente no repositório.

**Recomendação de revisão de follow-up:** false.

- Cálculo: `3 × 0 medium + 1 × 1 low = 1`, menor que 5; nenhum patch high.

**Verificação realizada:**

- `vendor/bin/phpunit tests/Unit/Controller/MetricsControllerTest.php tests/Integration/Controller/MetricsHttpEndpointTest.php tests/Integration/View/ResponsiveLayoutTest.php --testdox` — passou: 11 testes e 69 asserções.
- `git diff --check` — passou sem erros de whitespace.
- Auditoria da matriz: eventos, sessão vazia e contrato de layout responsivo são cobertos pelos testes unitário, HTTP e de view executados acima.

**Riscos residuais:**

- A confirmação visual em navegador dos breakpoints continua como inspeção manual; o repositório ainda não possui infraestrutura de teste de navegador. As regras CSS e a estrutura renderizada foram verificadas automaticamente.
