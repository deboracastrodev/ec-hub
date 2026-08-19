# Spec de Remediação de Consistência — ec-hub

**Status:** pronta para decomposição e execução no BMAD Loop
**Data:** 2026-08-18
**Autoria:** análise de consistência do repositório em `main` (c7fc6e4)

> **Uso no BMAD Loop:** este arquivo é o contrato-fonte da remediação. O loop não
> executa Markdown diretamente: cada `R<n>.<m>` deve ser convertido em uma story
> independente no `stories.yaml` da spec ou em um arquivo de story do
> `implementation-artifacts/`. A decomposição atual está em
> `_bmad-output/specs/remediation-consistency/` (`SPEC.md` + `stories.yaml`).
> Não enviar este documento inteiro como uma única tarefa de desenvolvimento.

<frozen-after-approval>

## Contrato de execução no BMAD Loop

### Unidade de trabalho

- Uma execução implementa **um único item `R<n>.<m>`**. Itens só podem ser agrupados
  quando compartilham o mesmo objetivo, arquivos principais e critério de aceite;
  o agrupamento deve aparecer explicitamente na story.
- Cada story deve copiar para si: problema, mudança, aceite, arquivos prováveis,
  dependências e comandos de verificação do item correspondente.
- O agente pode escolher detalhes de implementação dentro das decisões desta
  spec, mas não pode alterar contrato, escopo, ordem de fases ou critérios de
  aceite sem criar um bloqueio para decisão humana.
- A saída de cada story é: código/docs/testes implementados, verificações verdes,
  resumo de evidências e um commit atômico. Não misturar formatação, limpeza ou
  melhorias não relacionadas.

### Ordem e gates

1. Executar as fases de `4. Ordem de execução` na ordem indicada.
2. Dentro de uma fase, paralelizar apenas stories sem arquivos compartilhados e
   sem dependência entre si. Na dúvida, executar sequencialmente.
3. O gate de saída de uma fase deve ser comprovado antes de iniciar a seguinte.
   Um teste que falha por causa de uma fase anterior é bloqueio, não motivo para
   mascarar a falha ou alterar o aceite.
4. R1.1–R1.5 devem ser tratados como uma migração coordenada de plataforma;
   R2.1–R2.6 como o gate mínimo de suíte; R3–R5 como refatorações incrementais.
5. R6 e R7 só podem declarar conclusão depois que a implementação correspondente
   existir e os comandos de verificação forem executáveis.

### Checkpoints e bloqueios

- `spec_checkpoint: true` para a primeira story de cada fase e para R1.5, R3.1,
  R4.2 e R5.6, pois esses itens fixam decisões com impacto transversal.
- `done_checkpoint: true` para toda story que altera contrato público, bootstrap,
  persistência, CI ou documentação de entrada (`README`, `architecture.md`).
- Se uma story não puder ser concluída, preservar o estado, registrar evidência
  concreta e criar uma entrada em `deferred-work.md` com `status: open`. Não
  reordenar fases, reduzir o aceite ou inventar uma solução de produto.
- Uma decisão que contradiga D1–D5 ou o bloco `<frozen-after-approval>` deve ir
  para decisão humana; o loop não a resolve por inferência.

### Verificação obrigatória por story

O story deve terminar com os comandos realmente executados e seus resultados.
Quando um comando exigir Docker, banco ou uma extensão ausente, declarar a
dependência e classificar a story como bloqueada até que ela esteja disponível.
Os checks globais da seção `7. Definition of Done` são o gate final, não
substituem o aceite específico de cada `R<n>.<m>`.

### Estado inicial da fila

Todos os itens `R1.1`–`R7.6` estão `pending` nesta spec. O executor deve derivar
o status da story e dos artefatos do repositório; não marcar item como concluído
apenas porque o texto da mudança foi escrito. Itens opcionais, como R7.6, devem
ser marcados `deferred` ou `out-of-scope` com justificativa, nunca simplesmente
apagados.

</frozen-after-approval>

---

## 1. Contexto

O ec-hub acumulou três iterações de intenção técnica — Hyperf/Swoole → Rubix ML → PHP puro — sem remover as anteriores. O resultado é um repositório com **três camadas de verdade divergentes**:

1. o que o README e os docs prometem;
2. o que os arquivos de configuração pressupõem;
3. o que o código realmente executa.

O código de negócio que existe (catálogo de produtos + recomendações) é competente e testado. O entorno é que está inconsistente: dependências que não batem com o runtime, configs de frameworks nunca instalados, código morto documentado como ativo, e uma suíte de testes que não passa.

Esta spec cataloga todos os pontos encontrados e define a remediação.

### Estado medido (baseline)

| Métrica | Valor em `main` |
|---|---|
| Suíte PHPUnit | 134 testes — **33 errors, 2 failures, 1 skipped** |
| Violações PSR-12 | 11 arquivos em `app/`, 14 em `tests/` |
| PHP: manifesto / container / vendor / local | `^7.4` / `7.4` / exige `>=8.1` / `8.5.2` |
| CI | inexistente |
| Diretórios só com `.gitkeep` em `app/` | 25 |
| Uso real do Rubix ML | 1 chamada (`Euclidean::compute`) |

---

## 2. Decisões

Decisões tomadas antes desta spec, que definem o escopo.

### D1 — Alvo de plataforma: PHP 8.4

A restrição a PHP 7.4 era premissa de POC ("ML em stack legacy") e foi abandonada. O alvo passa a ser **PHP 8.4**, com a resolução do Composer **pinada** para que máquina local (8.5.2) e container resolvam exatamente as mesmas versões.

> **Consequência:** o argumento de venda do projeto deixa de ser "ML em PHP 7.4" e passa a ser "ML nativo em PHP com Clean Architecture". O README precisa refletir isso (R6.1).

### D2 — Usar o Rubix ML de verdade

Hoje o `KNNService` implementa KNN à mão e usa o Rubix apenas para calcular uma distância euclidiana, arrastando ~10 dependências transitivas (amphp, tensor, stemmer) para isso. O `KNNService` será reescrito sobre a API real do Rubix (`Labeled`, `Pipeline`, `BallTree`), e a implementação sai do `Domain` para a `Infrastructure` — o Domain passa a depender só de uma porta própria.

### D3 — Documentação descreve o estado atual

README, `STRUCTURE.md` e `architecture.md` passam a descrever apenas o que existe. Swoole, Redis Pub/Sub, session tracking e o dashboard `/metrics` (Epics 4 e 5) viram uma seção **Roadmap** explicitamente marcada como não implementada.

### D4 — Contrato da API: `product_id`

`GET /api/recommendations?product_id=X` — similaridade item-a-item, que é o que está implementado. `user_id` volta quando o Epic 4 (captura de eventos e sessão) existir.

### D5 — Manter Clean Architecture, podar o que não existe

A estrutura de camadas fica. O que sai são os andaimes vazios de contextos não implementados (`Cart`, `User`, `Metrics`, `Messaging`, `Monitoring`) — o Roadmap documenta a intenção melhor do que 25 diretórios com `.gitkeep`.

---

## 3. Workstreams

Cada item traz: **problema** (com evidência), **mudança** e **critério de aceite**.
Prioridades: **P0** quebra execução · **P1** inconsistência estrutural · **P2** coerência e higiene.

---

### WS1 — Plataforma e dependências · P0

O container não consegue rodar o código que está instalado. Este workstream vem primeiro porque todos os outros dependem de um ambiente que resolve.

#### R1.1 — Migrar para PHP 8.4 e pinar a resolução

**Problema:** `Dockerfile:1` fixa `php:7.4-cli`; `composer.json:6` declara `"php": "^7.4"`; o `vendor/` instalado tem `twig/twig 3.23` (exige `>=8.1.0`) e `psr/log 3.0.2` (exige `>=8.0.0`); a máquina local roda 8.5.2. Quatro versões diferentes de PHP em jogo.

**Mudança:**
- `Dockerfile`: `FROM php:8.4-cli`
- `composer.json`: `"php": "^8.4"`
- `composer.json`: adicionar `config.platform.php = "8.4.0"` — assim a máquina local (8.5) resolve exatamente as versões que rodam no container
- Revisar as extensões do Dockerfile: `pdo`, `pdo_mysql`, `mbstring`, `zip` continuam necessárias; `libpng-dev`/`libxml2-dev` podem sair se nenhuma extensão as usar

**Aceite:** `docker compose build && docker compose run --rm app php -v` reporta 8.4.x; `composer install` completa dentro do container; `composer check-platform-reqs` passa no container e na máquina local.

#### R1.2 — Declarar `psr/log` explicitamente

**Problema:** `app/Controller/RecommendationController.php:8` e `app/Application/Recommendation/GenerateRecommendations.php:12` importam `Psr\Log\LoggerInterface`, mas `psr/log` não está no `composer.json` — vem carona pelo `rubix/ml`. Se o Rubix sair ou mudar, o projeto quebra sem aviso.

**Mudança:** adicionar `"psr/log": "^3.0"` em `require`.

**Aceite:** `composer why psr/log` mostra `ec-hub/app` como requerente direto.

#### R1.3 — Versionar o `composer.lock`

**Problema:** `.gitignore:31` ignora o `composer.lock`. Para uma aplicação (não biblioteca) isso elimina builds reproduzíveis — e é exatamente o motivo de o drift de R1.1 ter passado despercebido.

**Mudança:** remover `composer.lock` do `.gitignore`, commitar o lock. Manter `/vendor/` ignorado.

**Aceite:** `git ls-files composer.lock` retorna o arquivo; clone limpo + `composer install` produz o mesmo `vendor/` que a máquina de origem.

#### R1.4 — Atualizar dependências de produção

**Problema:** versões presas a restrições da era 7.4.

**Mudança:**

| Pacote | De | Para |
|---|---|---|
| `twig/twig` | `^3.0` | `^3.28` |
| `rubix/ml` | `^2.2` | `^2.5` |
| `vlucas/phpdotenv` | `^5.0` | `^5.6` |
| `psr/container` | `^1.0` | `^2.0` (ver R5.7) |
| `psr/log` | — | `^3.0` (novo, R1.2) |

**Aceite:** `composer outdated --direct` não lista pacotes de produção desatualizados; suíte verde.

#### R1.5 — Atualizar ferramentas de desenvolvimento

**Problema:** `phpunit/phpunit 8.5.52` (de 2019) e `friendsofphp/php-cs-fixer 3.9.5` — este último recusa rodar em PHP > 8.1, motivo pelo qual `make cs-check` não funciona na máquina local hoje.

**Mudança:**

| Pacote | De | Para |
|---|---|---|
| `phpunit/phpunit` | `^8.0` | `^12.5` |
| `friendsofphp/php-cs-fixer` | `^3.0` | `^3.95` |
| `mockery/mockery` | `^1.4` | `^1.6` |
| `fakerphp/faker` | `^1.20` | `^1.24` |

> ⚠️ **Maior risco desta spec.** PHPUnit 8 → 12 é a migração mais custosa do plano: data providers passam a ser obrigatoriamente `static`, anotações `@test`/`@dataProvider`/`@group` migram para atributos PHP, `assertRegExp` e afins foram removidos, e o schema do `phpunit.xml` mudou. Ver R7.4 e a nota de risco na seção 6.

**Aceite:** `vendor/bin/php-cs-fixer --version` roda sem `PHP_CS_FIXER_IGNORE_ENV`; `vendor/bin/phpunit --version` reporta 12.x; suíte verde.

#### R1.6 — Parar de versionar cache de teste

**Problema:** `.phpunit.result.cache` (20 KB de estado local de execução) está commitado.

**Mudança:** `git rm --cached .phpunit.result.cache` e adicionar ao `.gitignore`.

**Aceite:** arquivo ausente de `git ls-files`.

---

### WS2 — Correções que quebram execução · P0

#### R2.1 — Criar os templates de erro faltantes

**Problema:** `public/index.php:154` renderiza `error/400.html.twig` e `:172`/`:184` renderizam `error/500.html.twig`. Nenhum dos dois existe — só `views/error/404.html.twig`. Qualquer erro interno numa rota HTML dispara `Twig\Error\LoaderError` **de dentro do bloco catch**, produzindo tela branca em vez de página de erro.

**Mudança:**
- criar `views/error/400.html.twig` e `views/error/500.html.twig`, estendendo `layout/base.html.twig` no mesmo padrão do 404
- envolver a renderização de erro num fallback: se o Twig falhar, emitir HTML mínimo em texto puro. Um handler de erro nunca pode depender de I/O que também pode falhar

**Aceite:** teste de integração que força exceção numa rota HTML e assere status 500 + corpo não vazio; mesmo teste com o loader do Twig sabotado ainda retorna 500 com corpo.

#### R2.2 — Corrigir o teste desatualizado de cold-start

**Problema:** `tests/Integration/Application/Recommendation/GenerateRecommendationsIntegrationTest::test_throws_exception_for_nonexistent_product` espera `RecommendationException`. O commit `754e90b` mudou o comportamento para retornar fallback popular (`GenerateRecommendations.php:74-80`) e o teste não foi atualizado — foi commitado quebrado.

**Mudança:** reescrever o teste para o comportamento atual (produto inexistente → lista de populares, `source = popular`). Renomear para `test_returns_popular_fallback_for_nonexistent_product`. Corrigir também o `@throws` mentiroso no docblock de `execute()`.

**Aceite:** teste passa e o nome descreve o comportamento real; docblock coerente com o código.

#### R2.3 — Corrigir a asserção do teste de setup

**Problema:** `tests/docker/SetupScriptTest::test_setup_script_runs_seeders` assere que `setup.sh` contém a string `db:seed`; o script executa `php bin/seed.php`.

**Mudança:** alinhar a asserção ao comando real. Aproveitar para revisar `tests/docker/*` inteiro — são testes que fazem `assertStringContainsString` em arquivos de config, frágeis por construção. Verificar comportamento (o script roda? o compose sobe?) ou reduzir o escopo a lint de sintaxe (`bash -n`, `docker compose config`).

**Aceite:** suíte `Docker` verde; nenhum teste assere substring de comando que possa mudar sem quebrar nada de fato.

#### R2.4 — Tornar o PDO lazy no bootstrap

**Problema:** `config/bootstrap.php:14` instancia o `PDO` numa IIFE avaliada no `require`. Toda requisição abre conexão MySQL — incluindo servir um arquivo CSS estático ou responder 404. E é a causa direta dos 33 errors da suíte: até `ResponsiveLayoutTest`, que só verifica templates Twig, exige banco.

**Mudança:** trocar a instância por uma factory memoizada — o PDO só conecta quando alguém realmente pede. Encaixa naturalmente no container de R5.7.

**Aceite:** requisição a `/assets/css/main.css` e a uma rota inexistente não abrem conexão (verificável com `SHOW STATUS LIKE 'Connections'` ou com o MySQL derrubado); testes de view rodam sem banco.

#### R2.5 — Padronizar o isolamento dos testes

**Problema:** parte dos testes injeta `$GLOBALS['EC_HUB_TEST_CONTAINER']` (gancho declarado em `public/index.php:24`), parte instancia o bootstrap real e exige MySQL. Não há regra.

**Mudança:**
- `Tests\TestCase` base que monta um container de teste com repositórios em memória
- `tests/Unit` e `tests/Integration/View` **nunca** tocam banco
- testes que exigem MySQL de verdade ficam num grupo `@group db`, com `markTestSkipped` claro quando o banco não está disponível — nunca error
- substituir o gancho `$GLOBALS` por injeção explícita quando R5.6 extrair o roteamento

**Aceite:** `vendor/bin/phpunit --exclude-group db` passa **verde, sem Docker rodando**. Com MySQL no ar, a suíte completa passa verde.

#### R2.6 — Suíte verde é a porta de entrada

**Mudança:** ao fim do WS2, `vendor/bin/phpunit` sem MySQL retorna 0 errors / 0 failures excluindo `@group db`. Este é o pré-requisito para qualquer item de WS3 em diante — sem baseline verde não há como saber o que uma refatoração quebrou.

**Aceite:** ver R2.5.

---

### WS3 — Contrato da API e coerência de domínio · P1

#### R3.1 — Renomear o contrato para `product_id`

**Problema:** o controller valida `user_id` (`RecommendationController.php:118-141`) e entrega o valor para `GenerateRecommendations::execute(int $targetProductId)`, que faz `productRepository->findById()`. O contrato HTTP e o domínio discordam sobre o que aquele número significa. O próprio código denuncia: o log de fallback se chama `'cold_start_unknown_user'` (`GenerateRecommendations.php:76`) logo após um `findById` de **produto** falhar. Não existe tabela de usuários — só `create_products_table`.

**Mudança (conforme D4):**
- rota: `GET /api/recommendations?product_id=X&limit=N`
- `validateUserId()` → `validateProductId()`, com mensagens `product_id is required` / `product_id must be a positive integer`
- log `cold_start_unknown_user` → `cold_start_unknown_product`
- atualizar os 4 arquivos de teste do endpoint e a documentação do contrato
- registrar em `docs/architecture.md` que `user_id` está reservado para o Epic 4

**Aceite:** nenhuma ocorrência de `user_id` em `app/`; testes do endpoint usam `product_id`; erro 400 cita `product_id`.

#### R3.2 — Unificar as duas `RecommendationException`

**Problema:** existem duas classes com o mesmo nome — `App\Controller\Exceptions\RecommendationException` (estende `RuntimeException`, carrega `httpCode`) e `App\Domain\Recommendation\Exception\RecommendationException` (estende `DomainException`). O controller captura uma e relança a outra (`RecommendationController.php:96-103`).

**Mudança:** manter **uma** exceção de domínio (`App\Domain\Recommendation\Exception\RecommendationException`), sem noção de HTTP. O mapeamento domínio → status HTTP vira responsabilidade única do handler de erro na borda (R5.6). `InvalidRequestException` permanece na camada Controller — validação de request é preocupação legítima de HTTP.

**Aceite:** uma única classe `RecommendationException`; nenhuma classe de domínio conhece código HTTP; o mapeamento vive num só lugar e tem teste.

#### R3.3 — `Money` de ponta a ponta

**Problema:** o value object `Money` guarda centavos corretamente para evitar erro de ponto flutuante — e a disciplina é abandonada na primeira fronteira:

```
Money (centavos int)
  → Product::toArray()          → float decimal
  → RecommendationDTO::$price   → string formatada "1.299,90"
  → normalizePrice() com regex  → float          (RecommendationController.php:279)
  → filtro Twig |BRL            → string         (config/twig.php:30)
```

Cinco conversões, das quais uma é um regex que desfaz a formatação que a camada anterior acabou de aplicar.

**Mudança:**
- `RecommendationDTO::$price` passa a ser `float` (decimal), e ganha `price_formatted` separado quando a view precisar
- excluir `RecommendationController::normalizePrice()` inteiro
- **um único ponto de formatação por saída:** API devolve número; HTML formata no Twig via filtro `BRL`
- documentar a regra em `docs/CODING-STANDARDS.md`

**Aceite:** `normalizePrice` não existe mais; JSON da API traz `price` numérico; nenhuma camada além da view produz string de moeda.

#### R3.4 — Repositório do Domain devolve entidades

**Problema:** `ProductRepositoryInterface` está no `Domain` mas todos os métodos retornam `array` (`ProductRepositoryInterface.php:20-37`). A forma da tabela vaza para dentro do domínio e a entidade `Product` vira opcional — só é montada ad hoc dentro de `GenerateRecommendations::arrayToProduct()`.

**Mudança:**
- assinaturas da interface passam a `?Product` / `list<Product>`
- a hidratação (`Product::fromArray`) desce para um `ProductMapper` na `Infrastructure`
- `GetProductList` / `GetProductDetail` passam a receber entidades; a serialização para a view acontece na borda

**Aceite:** a interface do Domain não menciona `array` em retornos de leitura; `arrayToProduct()` sai do use case; testes de repositório assertam tipos de entidade.

#### R3.5 — Injetar configuração, não ler disco na Application

**Problema:** `GenerateRecommendations.php:314` e `:328` fazem `require config/recommendation.php` dentro do construtor — lógica duplicada em dois métodos privados, executada a cada instanciação. É exatamente o acoplamento a infraestrutura que a Clean Architecture existe para impedir. Além disso, o branch de fallback por `getenv()` é código morto: o arquivo de config sempre existe.

**Mudança:** criar `RecommendationSettings` (objeto imutável com `strategy`, `minProductsForMl`, faixas de score), montado no bootstrap a partir de `config/recommendation.php`, e injetado no construtor.

**Aceite:** nenhum `require` de config dentro de `app/`; `GenerateRecommendations` é construível em teste sem tocar o filesystem.

#### R3.6 — Corrigir a semântica de `limit`

**Problema:** `RecommendationController::validateAndParseLimit()` força `MIN_LIMIT = 5` — `?limit=1` devolve 5 itens, silenciosamente. Um limite mínimo que sobrescreve o pedido do cliente não é validação, é comportamento surpresa.

**Mudança:** faixa válida `1..50`. Valor fora da faixa: `limit > 50` satura em 50; `limit < 1` ou não numérico → 400 explícito em vez de silêncio.

**Aceite:** `?limit=1` devolve 1 item; `?limit=0` e `?limit=abc` retornam 400; `?limit=999` devolve no máximo 50.

#### R3.7 — Mover `CategoryService` para dentro das camadas

**Problema:** `App\Service\CategoryService` (`app/Service/CategoryService.php:5`) está fora das quatro camadas documentadas, e é importado pela camada Application (`GetProductList.php:8`).

**Mudança:** mover para `App\Domain\Product\Service\CategoryService` — é lógica de negócio de catálogo (normalização de acentos, agrupamento, contagem), não orquestração. Eliminar o diretório `app/Service/`.

**Aceite:** `app/Service/` não existe; nenhum import fora da árvore de camadas.

---

### WS4 — Rubix ML de verdade · P1

#### R4.1 — Corrigir o teto de recomendações imposto por `k`

**Problema — bug funcional, não só de estilo:** `KNNService::recommend()` fatia os vizinhos por `$this->k` (fixo em 5) **antes** de aplicar `$limit` (que aceita até 50):

```php
$neighborIndices = array_slice(array_keys($distances), 0, $this->k, true);  // KNNService.php:101
```

Depois `buildRecommendations()` ainda exclui o próprio produto-alvo. Resultado: **o ML nunca devolve mais de 4 itens**, independente do `limit` pedido. Isso torna `MAX_LIMIT = 50` decorativo e explica por que `GenerateRecommendations` tem lógica de "completar com fallback quando o ML devolve menos que o pedido" (`GenerateRecommendations.php:105-115`) — é um remendo para este bug. Na prática, a maior parte de uma resposta com `limit` alto é rule-based, anunciada como ML.

**Mudança:** a busca de vizinhos passa a usar `k = limit + 1` (o `+1` cobre a exclusão do alvo). `k` deixa de ser estado do serviço e vira parâmetro da consulta.

**Aceite:** com catálogo de 50 produtos, `?limit=20` devolve 20 recomendações com `source = ml`; teste que assere `count == limit` para limites 1, 10 e 50.

#### R4.2 — Reescrever o `KNNService` sobre a API do Rubix

**Problema:** o `KNNService` implementa à mão one-hot encoding (`:117-129`), min-max scaling (`:146-190`) e ordenação por distância — e usa o Rubix apenas para `Euclidean::compute()` (`:140`). O próprio docblock admite: *"Uses manual KNN implementation"* (`:14`), enquanto o README vende "KNN usando Rubix ML".

**Mudança (conforme D2):** substituir o pipeline manual pela API real do Rubix:

| Feito à mão hoje | Substituto no Rubix |
|---|---|
| `extractSingleProductFeatures()` — one-hot de categoria | `Rubix\ML\Transformers\OneHotEncoder` |
| `normalizeFeatures()` / `normalizeSingleFeature()` | `Rubix\ML\Transformers\MinMaxNormalizer` |
| `calculateDistances()` + `asort()` + `array_slice()` | `Rubix\ML\Graph\Trees\BallTree::nearest($sample, $k)` |
| índice paralelo `$productsIndex` | labels do `Rubix\ML\Datasets\Labeled` (label = `product_id`) |

Estrutura: dataset `Labeled` com features `[categoria, preço]` e labels = ids de produto; `Pipeline` com os dois transformers; `BallTree` (kernel `Euclidean`) para a busca. `nearest()` devolve a tripla `[samples, labels, distances]` — os labels dão o id direto, eliminando o índice paralelo.

Manter o score atual `100 * (1 / (1 + d))` e as explicações em português (`generateExplanation()`), que são requisito de produto (FR11, FR12) e não têm equivalente no Rubix.

> **Ressalva técnica:** `Rubix\ML\Graph\Trees\Spatial` está anotado `@internal` no upstream. É a API correta para busca de vizinhos (os estimadores públicos classificam, não devolvem vizinhos), mas o risco de quebra entre minors é real — o que torna R4.3 obrigatório, não opcional.

**Aceite:** nenhum cálculo de distância ou normalização escrito à mão em `app/`; recomendações continuam determinísticas e os testes de ordenação/score passam; `composer why rubix/ml` justifica o peso da dependência.

#### R4.3 — Tirar o Rubix de dentro do Domain

**Problema:** `app/Domain/Recommendation/Service/KNNService.php:9` importa `Rubix\ML\Kernels\Distance\Euclidean`, e `:27` tipa uma propriedade com ela. O `Domain` depende de biblioteca externa — violando frontalmente a regra que o README e o `STRUCTURE.md` vendem como diferencial ("Domain não depende de framework", "ZERO dependências nas camadas externas").

**Mudança:** inverter a dependência.
- `Domain`: interface `NeighborFinderInterface` — recebe produtos e um alvo, devolve `RecommendationResult[]`. Sem nenhum tipo do Rubix na assinatura.
- `Infrastructure/ML/RubixNeighborFinder`: implementação, único lugar do projeto que importa `Rubix\*`.
- `Domain/Recommendation/Service/KNNService`: passa a orquestrar a porta, mantendo score e explicações.

Isso também contém o risco de `@internal` de R4.2: uma eventual quebra do Rubix fica confinada a uma classe da Infrastructure.

**Aceite:** `grep -rn "Rubix" app/Domain` retorna vazio; existe teste unitário do `KNNService` com um `NeighborFinder` fake, sem Rubix.

#### R4.4 — Evitar treinar o modelo a cada requisição

**Problema:** `GenerateRecommendations::ensureModelTrained()` treina o KNN por instância de use case, ou seja, a cada requisição HTTP, carregando até 1000 produtos (`loadProducts()`, `:194`). Com catálogo de POC não dói; é o primeiro gargalo real do requisito de < 200ms (FR10).

**Mudança:** persistir a árvore treinada em `var/cache/` com invalidação por `MAX(updated_at)` da tabela de produtos. `Rubix\ML\Persisters\Filesystem` cobre o caso.

**Aceite:** teste que mede duas chamadas consecutivas e assere que a segunda não retreina; documentar a estratégia de invalidação em `docs/architecture.md`.

> Se o prazo apertar, este é o item de WS4 que pode ficar para depois — é otimização, não correção de inconsistência. R4.1 a R4.3 não são adiáveis.

---

### WS5 — Coerência arquitetural e poda · P2

#### R5.1 — Deletar código morto

**Problema:** quatro classes sem nenhum consumidor:

| Arquivo | Observação |
|---|---|
| `app/Shared/Router/SimpleRouter.php` | implementa `Psr\Http\Message\ResponseInterface` e instancia `GuzzleHttp\Psr7\Stream` — **nenhum dos dois está instalado**. Chamar isso é fatal error garantido |
| `app/Shared/Helper/ResponseFormatter.php` | `STRUCTURE.md` afirma que as respostas passam por aqui; na verdade é `json_encode` inline em `public/index.php:139` |
| `app/Shared/Helper/ErrorBuilder.php` | sem consumidores |
| `app/Shared/Helper/RequestFactory.php` | sem consumidores |

**Mudança:** deletar os quatro. O roteamento real vive em `public/index.php` e será extraído em R5.6.

**Aceite:** arquivos removidos; suíte verde; nenhuma referência pendente.

#### R5.2 — Deletar configs de frameworks nunca instalados

**Problema:**

| Arquivo | O que é |
|---|---|
| `config/autoload.php` | config de **Hyperf** (`scan`, `annotations`, `BASE_PATH`) — Hyperf nunca foi instalado |
| `config/server.php` | config de **Swoole** (`SWOOLE_PROCESS`, `Swoole\Constant`, `SWOOLE_HOOK_ALL`) — Swoole não está instalado; carregar o arquivo é fatal error |
| `config/autoload/databases.php` | chama `env()`, função definida apenas dentro de `config/server.php` — órfão de um órfão |

**Mudança:** deletar os três. A configuração real de banco está em `config/bootstrap.php`.

**Aceite:** `config/` contém apenas `bootstrap.php`, `twig.php`, `recommendation.php`; nada em `config/` referencia Swoole ou Hyperf.

#### R5.3 — Podar os andaimes vazios

**Problema:** 25 diretórios em `app/` contêm apenas `.gitkeep`. `Cart`, `User` e `Metrics` têm interfaces de repositório sem nenhuma implementação; `Infrastructure/Messaging/` e `Infrastructure/Monitoring/` estão vazios e são citados no README como se existissem.

**Mudança (conforme D5):** remover as árvores de `Cart`, `User`, `Metrics`, `Messaging`, `Monitoring`, incluindo as três interfaces órfãs. O Roadmap do README (R6.1) documenta a intenção com mais honestidade.

**Aceite:** todo diretório em `app/` contém pelo menos um `.php`; nenhuma interface sem implementação nem consumidor.

#### R5.4 — Integrar o `MetaTagsService`

**Problema:** `app/Domain/SEO/Service/MetaTagsService.php` tem teste unitário completo e **nenhum consumidor**. Enquanto isso, `views/product/detail.html.twig` sobrescreve apenas o bloco `title` — `og:title`, `og:description`, `canonical` e `structured_data` da página de produto ficam com os defaults genéricos de `layout/base.html.twig`. Ou seja: duas implementações de SEO, e a que está em uso é a pior. A story 2-6 está marcada como `done`.

**Mudança:** o `ProductController` passa a chamar o `MetaTagsService` e injetar os metadados no template; `detail.html.twig` sobrescreve os blocos OG/Twitter/canonical com dados reais do produto e emite JSON-LD `schema.org/Product` em `structured_data`. Mover o serviço para `Application` — geração de metatag é orquestração de apresentação, não regra de negócio, e `Domain/SEO` não é um bounded context real.

**Aceite:** teste de integração assere que `/products/{slug}` traz `og:title` com o nome do produto e JSON-LD válido; `MetaTagsService` tem consumidor em produção.

#### R5.5 — Remover o Redis do compose até existir consumidor

**Problema:** `docker-compose.yml` sobe um serviço Redis, `setup.sh` tem uma função `wait_for_redis()` que bloqueia o setup esperando por ele, o `Makefile` expõe `make redis-cli` — e **nada no código conecta em Redis**. O Dockerfile nem instala a extensão.

**Mudança:** remover o serviço `redis` do compose, `wait_for_redis()` do `setup.sh` e o alvo `redis-cli` do Makefile. Volta junto com o Epic 4.

**Aceite:** `docker compose config` não lista Redis; `./setup.sh` completa sem esperar por serviço inexistente.

#### R5.6 — Extrair o roteamento de `public/index.php`

**Problema:** `public/index.php` tem ~190 linhas acumulando cinco responsabilidades: servir arquivos estáticos, casar rotas, montar dependências à mão num `switch` sobre nome de classe (`:106-123`), despachar, e tratar erros com três blocos catch quase idênticos. Nada disso é testável sem HTTP real — daí `RecommendationApiLiveHttpTest` precisar de um servidor na porta 9501 e acabar sempre skipped.

**Mudança:**
- `App\Shared\Http\Router` — tabela de rotas + match, testável isoladamente
- `App\Shared\Http\ErrorHandler` — mapeamento exceção → status/corpo, num lugar só (consome R3.2)
- `public/index.php` reduzido a: carregar autoload, montar container, delegar
- servir estáticos deixa de ser problema da aplicação em produção; manter apenas como conveniência do `php -S`
- o gancho `$GLOBALS['EC_HUB_TEST_CONTAINER']` (`:24`) desaparece — o container passa a ser argumento

**Aceite:** `public/index.php` com menos de 30 linhas; `Router` e `ErrorHandler` com teste unitário; `RecommendationApiLiveHttpTest` deixa de precisar de porta aberta (ou é removido em favor do teste de integração já existente).

#### R5.7 — Usar o `psr/container` que já está declarado

**Problema:** `psr/container` está em `composer.json:8` e **nunca é usado** — `grep` por `ContainerInterface` em `app/`, `config/` e `public/` não retorna nada. O container real é um array aninhado de closures em `config/bootstrap.php`, acessado por chaves mágicas como `$container['services']['generate_recommendations']($container)` (`public/index.php:119`). Sem tipagem, sem autocomplete, sem erro de compilação se a chave mudar. E o factory `'category'` registrado em `bootstrap.php:44` nunca é chamado — `public/index.php:109` instancia `new CategoryService` direto.

**Mudança:** container mínimo implementando `Psr\Container\ContainerInterface`, com serviços registrados por nome de classe (FQCN) e resolução memoizada. Encaixa R2.4 (PDO lazy) sem gambiarra.

**Aceite:** `bootstrap.php` devolve um `ContainerInterface`; acesso por FQCN; nenhuma definição registrada sem consumidor.

#### R5.8 — Carregar o `.env` (ou remover o `phpdotenv`)

**Problema:** `vlucas/phpdotenv` é dependência declarada e **nada no projeto o instancia** — `grep -rn "Dotenv"` retorna vazio. O README manda `cp .env.example .env` no passo 2 do Quick Start, mas esse `.env` nunca é lido: as variáveis chegam só via `environment:` do compose. Quem seguir o README ao pé da letra e editar o `.env` não verá efeito nenhum.

**Mudança:** carregar o `.env` no bootstrap com `Dotenv::createImmutable()`, com fallback silencioso quando o arquivo não existe (o compose continua funcionando por variável de ambiente).

**Aceite:** alterar `RECOMMENDATION_FALLBACK_STRATEGY` no `.env` muda o comportamento observável; rodar sem `.env` continua funcionando.

---

### WS6 — Documentação · P2

#### R6.1 — Reescrever o README

**Problema:** o README descreve um sistema que não existe. Levantamento completo:

| README afirma | Realidade |
|---|---|
| "Swoole HTTP Server — workers long-running com coroutines" | não instalado; `Dockerfile:30` roda `php -S` |
| "Redis Pub/Sub — event-driven architecture" | `Infrastructure/Messaging/` vazio |
| Mapa de code review aponta `app/Infrastructure/Messaging/RedisEventBus.php` | arquivo inexistente |
| Mapa de code review aponta `config/server.php` | config de Swoole órfã (R5.2) |
| "composer.json — Stack: PHP 7.4, Hyperf 2.2, Swoole, Rubix ML" | Hyperf e Swoole nunca instalados |
| Dashboard `/metrics`, `/health`, `/debug/memory` | nenhuma dessas rotas existe |
| "✅ 70% test coverage" | suíte não passa; cobertura nunca medida |
| "🧠 KNN funcional usando Rubix ML" | KNN manual; Rubix usado para uma chamada (R4.2) |
| Badge "Tests: not_run" | honesto, e contradiz o item de 70% três linhas acima |

Vale registrar a causa: o README foi escrito na story 1-4, no Epic 1, descrevendo a **visão final** do produto. Os Epics 4 e 5 nunca foram implementados e o texto nunca foi reconciliado. Não é invenção — é documentação de estado futuro sem rótulo.

**Mudança (conforme D3):**
- seção **O que existe hoje**: PHP 8.4, `php -S`, MySQL, Twig, catálogo com paginação e filtro por categoria, API de recomendações KNN com fallback rule-based
- seção **Roadmap**, explicitamente marcada como não implementada: Epic 4 (captura de eventos e sessão), Epic 5 (dashboard `/metrics`, `/health`), Swoole, Redis Pub/Sub
- Quick Start com os comandos que realmente funcionam, validado num clone limpo
- mapa de code review apontando só para arquivos existentes
- badges refletindo a realidade (CI de R7.3 gera as de build e testes)
- reposicionar o diferencial (D1): "ML nativo em PHP com Clean Architecture", sem apelar para 7.4

**Aceite:** todo caminho de arquivo citado no README existe; toda rota citada responde; todo comando citado roda num clone limpo. Verificável por script no CI.

#### R6.2 — Alinhar `STRUCTURE.md`

**Problema:** documenta cinco bounded contexts (`Product`, `User`, `Cart`, `Recommendation`, `Metrics`) dos quais dois existem; lista arquivos que nunca foram criados (`ProductService.php`, `AuthenticationService.php`, `CartSessionService.php`, `MetricsCollector.php`); e o "Request Flow Example" descreve um caminho fictício, incluindo formatação pelo `ResponseFormatter` (R5.1) e um `app/Application/ProductService.php` inexistente.

**Mudança:** reescrever a partir da árvore real pós-poda. O fluxo de requisição passa a rastrear `/products/{slug}` e `/api/recommendations?product_id=` de verdade, arquivo por arquivo.

**Aceite:** todo arquivo citado existe; a árvore bate com `find app -name '*.php'`.

#### R6.3 — Revisar `architecture.md`

**Mudança:** registrar as decisões D1–D5 como ADRs datadas, incluindo o que foi descartado e por quê (Hyperf, Swoole, PHP 7.4). O histórico de decisões é o que impede a próxima pessoa de "restaurar" o `config/server.php`.

**Aceite:** cada decisão desta spec tem entrada correspondente, com data e alternativa rejeitada.

#### R6.4 — Corrigir o `.env.example`

**Problema:** contém 20+ variáveis de pool Hyperf/Swoole/Redis que ninguém lê (`SCAN_CACHEABLE`, `DB_POOL_*`, `REDIS_POOL_*`, `SWOOLE_*`) e **não contém** as três que o código realmente usa: `AUTH_REQUIRED`, `RECOMMENDATION_FALLBACK_STRATEGY`, `RECOMMENDATION_MIN_PRODUCTS_FOR_ML`.

**Mudança:** reduzir às variáveis efetivamente lidas — `APP_ENV`, `APP_DEBUG`, `DB_*`, `AUTH_REQUIRED`, `RECOMMENDATION_*` — cada uma com comentário de uma linha e o default.

**Aceite:** toda variável do `.env.example` aparece em algum `getenv()`/`$_ENV` do código, e vice-versa. Verificável por script no CI.

#### R6.5 — Documentar as regras que faltaram

**Mudança:** acrescentar ao `docs/CODING-STANDARDS.md` as convenções que este trabalho estabelece e que não estavam escritas em lugar nenhum: onde dinheiro é formatado (R3.3), que o Domain não importa biblioteca externa (R4.3), que repositório devolve entidade (R3.4), que configuração é injetada e não lida do disco (R3.5).

**Aceite:** cada regra nova tem uma frase e um exemplo de código.

---

### WS7 — Ferramentas e CI · P1

#### R7.1 — Corrigir o `.php-cs-fixer.php`

**Problema — a causa raiz de toda a deriva de estilo:** `.php-cs-fixer.php:37-38` chama `setFinder()` **duas vezes**. A segunda sobrescreve a primeira, então o linter **nunca analisou `app/`** desde que o projeto existe.

```php
->setFinder(PhpCsFixer\Finder::create()->in('app'))    // descartado
->setFinder(PhpCsFixer\Finder::create()->in('tests')); // vence
```

**Mudança:** um único `Finder` com `->in(['app', 'tests'])`.

**Aceite:** `vendor/bin/php-cs-fixer fix --dry-run -v` lista arquivos de ambos os diretórios.

#### R7.2 — Zerar as violações PSR-12

**Problema:** com o finder corrigido, 11 arquivos de `app/` e 14 de `tests/` violam PSR-12. O padrão é revelador: os arquivos do **Epic 2** (produtos) passam; os do **Epic 3** (recomendações) violam — todos com `declare(strict_types=1);` colado no `<?php` sem linha em branco. O estilo derivou entre épicos e nada avisou, porque o linter estava cego (R7.1).

**Mudança:** rodar `php-cs-fixer fix` em commit isolado, exclusivamente de formatação, para não misturar ruído com mudança de comportamento.

**Aceite:** `cs-check` sai com código 0; o commit de formatação não altera nenhum teste.

#### R7.3 — Criar o pipeline de CI

**Problema:** não existe CI. `.github/` contém apenas agentes BMAD (`.github/agents/`), que inclusive estão no `.gitignore`. Nenhum dos problemas desta spec teria sobrevivido a um pipeline básico.

**Mudança:** `.github/workflows/ci.yml` rodando em push e PR:

1. `composer validate --strict` + `composer install`
2. `composer check-platform-reqs`
3. `cs-check` (R7.1)
4. PHPUnit sem banco (`--exclude-group db`)
5. PHPUnit completo com serviço MySQL 8
6. verificação de documentação: todo caminho citado no README existe; `.env.example` e `getenv()` batem (R6.1, R6.4)

**Aceite:** pipeline verde em `main`; PR com violação de estilo, teste quebrado ou caminho inexistente no README é barrado.

#### R7.4 — Atualizar o `phpunit.xml` e medir cobertura de verdade

**Problema:** o `phpunit.xml` usa o schema 8.0; não há bloco `<coverage>` nem `<source>` — a afirmação de "70% de cobertura" do README e do `STRUCTURE.md` nunca foi medida por ninguém.

**Mudança:**
- migrar o schema (`vendor/bin/phpunit --migrate-configuration` após R1.5)
- configurar cobertura sobre `app/`
- **medir a cobertura real primeiro** e definir o threshold a partir do número medido, subindo depois. Um threshold aspiracional que ninguém atinge é a mesma classe de problema que esta spec inteira está corrigindo
- documentar o número medido no README

**Aceite:** `composer test` produz relatório de cobertura; o threshold do CI corresponde ao valor medido; README cita o número real.

#### R7.5 — Fazer o Makefile funcionar fora do Docker

**Problema:** todos os alvos de teste e lint fazem `docker-compose exec app ...`, o que exige container rodando — mas os testes unitários rodam perfeitamente na máquina local após R2.5. Além disso, `docker-compose` (v1) está descontinuado; o comando atual é `docker compose`.

**Mudança:** `COMPOSE := docker compose`; alvos locais (`test-unit`, `cs-check`) rodam direto com `vendor/bin/`; alvos que precisam de banco mantêm o prefixo do compose. Remover `redis-cli` (R5.5) e o `test-recommendation`, que aponta para caminhos de story específicos.

**Aceite:** `make test-unit` e `make cs-check` funcionam sem Docker; `make help` lista só alvos que existem.

#### R7.6 — Análise estática (opcional)

**Mudança:** adicionar PHPStan nível 5, subindo gradualmente. Os docblocks já estão bem anotados (`array<string, mixed>` etc.), então o custo inicial é baixo — e teria pego sozinho o `psr/log` não declarado (R1.2) e o `GuzzleHttp\Psr7\Stream` inexistente (R5.1).

**Aceite:** PHPStan nível 5 verde no CI.

---

## 4. Ordem de execução

As fases são sequenciais; itens dentro de uma fase são paralelizáveis.

| Fase | Itens | Objetivo | Porta de saída |
|---|---|---|---|
| **0 — Baseline** | R1.6, R7.1, R7.2 | Linter enxergando `app/`, estilo zerado, cache fora do git | commit só de formatação, isolado |
| **1 — Plataforma** | R1.1 – R1.5 | PHP 8.4, lock versionado, deps atualizadas | `composer check-platform-reqs` verde nos dois ambientes |
| **2 — Suíte verde** | R2.1 – R2.6 | Testes passam sem Docker | `phpunit --exclude-group db` = 0 falhas |
| **3 — CI** | R7.3, R7.4 | Rede de segurança antes de refatorar | pipeline verde em `main` |
| **4 — Contrato e domínio** | R3.1 – R3.7 | `product_id`, `Money` coerente, config injetada | suíte verde; contrato da API documentado |
| **5 — Rubix** | R4.1 – R4.4 | ML de verdade, fora do Domain | `grep Rubix app/Domain` vazio; `limit` respeitado |
| **6 — Poda** | R5.1 – R5.8 | Código morto e configs órfãs fora | todo arquivo em `app/` tem consumidor |
| **7 — Documentação** | R6.1 – R6.5, R7.5, R7.6 | Docs = realidade | verificação de docs verde no CI |

**Por que esta ordem.** Fase 0 antes de tudo porque um commit de formatação misturado com mudança de comportamento torna qualquer revisão impossível. Fase 2 antes de 4 porque refatorar sem baseline verde é refatorar às cegas. Fase 3 antes de 4 porque o CI é o que impede a deriva de voltar. Fase 7 por último porque documentar antes da poda documentaria coisas que estão prestes a sumir.

---

## 5. Fora de escopo

Explicitamente **não** faz parte deste trabalho — vai para o Roadmap do README (R6.1):

- **Epic 4** — captura de eventos, session tracking, recomendações por usuário
- **Epic 5** — dashboard `/metrics`, `/health`, `/debug/memory`
- **Swoole** — servidor assíncrono, workers, coroutines
- **Redis** — Pub/Sub, cache de sessão, event bus
- **Autenticação real** — o `AUTH_REQUIRED` atual só checa presença do header `Authorization`, sem validar nada. Fica como está, mas será **documentado como placeholder**, não como autenticação
- **Contextos `Cart` e `User`** — removidos em R5.3, retornam quando houver requisito

---

## 6. Riscos

| Risco | Probabilidade | Impacto | Mitigação |
|---|---|---|---|
| **Migração PHPUnit 8 → 12** (R1.5) — data providers precisam virar `static`, anotações viram atributos, asserções removidas | alta | alto | Fase própria, antes de qualquer refatoração de produção. Se o custo explodir, parar em PHPUnit 10 (`>=8.1`) e subir depois — o ganho principal é rodar em PHP 8.4, e o 10 já entrega isso |
| **Reescrita do Rubix muda os resultados** (R4.2) — `MinMaxNormalizer` e `BallTree` podem produzir ordenação diferente do código manual em casos de empate | média | médio | Capturar as saídas atuais como fixtures **antes** de mexer; comparar depois. Diferença de ordenação em empate é aceitável; diferença no conjunto de produtos não é |
| **`Spatial` é `@internal` no Rubix** (R4.2) | média | baixo | R4.3 confina o uso a uma classe da Infrastructure atrás de interface do Domain. Substituir vira trabalho de um arquivo |
| **Deletar código "que alguém pode querer"** (R5.1, R5.3) | baixa | baixo | Está tudo no histórico do git. Deletar é reversível; código morto documentado como vivo custa mais caro |
| **Fase 6 e 7 nunca acontecerem** — a poda e os docs são P2 e é o que se abandona quando o prazo aperta | **alta** | **alto** | São exatamente os itens que causaram o problema original. Tratar como parte do trabalho, não como sobra. O check de documentação no CI (R7.3) é o que garante que não regride |
| **Mudança de contrato quebra consumidor** (R3.1) | baixa | baixo | Não há consumidor externo — o endpoint não está publicado |

---

## 7. Definition of Done

A remediação está concluída quando **todas** as afirmações abaixo forem verdadeiras e verificáveis por comando:

- [ ] `composer check-platform-reqs` passa no container e na máquina local, com a mesma resolução
- [ ] `composer.lock` está versionado
- [ ] `vendor/bin/phpunit --exclude-group db` = **0 errors, 0 failures**, sem Docker rodando
- [ ] `vendor/bin/phpunit` completo (com MySQL) = 0 errors, 0 failures
- [ ] `vendor/bin/php-cs-fixer fix --dry-run` sai com código 0, cobrindo `app/` **e** `tests/`
- [ ] CI verde em `main`, barrando estilo, teste e documentação
- [ ] `grep -rn "Rubix" app/Domain/` retorna vazio
- [ ] `grep -rn "user_id" app/` retorna vazio
- [ ] Nenhum arquivo em `app/` sem consumidor (checável com PHPStan ou busca de referências)
- [ ] Todo diretório em `app/` contém pelo menos um `.php`
- [ ] Todo caminho de arquivo citado no README existe
- [ ] Toda rota citada no README responde
- [ ] Toda variável do `.env.example` é lida pelo código, e vice-versa
- [ ] `?limit=N` devolve exatamente N recomendações para N em 1..50
- [ ] A cobertura citada no README é a cobertura medida

---

## Apêndice — Rastreabilidade

Cada achado da análise, e onde é resolvido.

| # | Achado | Item |
|---|---|---|
| 1 | Dockerfile PHP 7.4 vs vendor exigindo 8.1+ vs local 8.5 | R1.1 |
| 2 | `composer.lock` no `.gitignore` | R1.3 |
| 3 | `psr/log` usado sem ser declarado | R1.2 |
| 4 | `error/400` e `error/500.html.twig` inexistentes | R2.1 |
| 5 | Teste de cold-start desatualizado após `754e90b` | R2.2 |
| 6 | `SetupScriptTest` assere `db:seed` inexistente | R2.3 |
| 7 | PDO eager no bootstrap → 33 errors | R2.4 |
| 8 | Isolamento de testes inconsistente | R2.5 |
| 9 | `user_id` tratado como `product_id` | R3.1 |
| 10 | Duas classes `RecommendationException` | R3.2 |
| 11 | `Money` → float → string → regex → Twig | R3.3 |
| 12 | Repositório do Domain devolve `array` | R3.4 |
| 13 | Application faz `require` de config do disco | R3.5 |
| 14 | `MIN_LIMIT = 5` sobrescreve o pedido do cliente | R3.6 |
| 15 | `App\Service\CategoryService` fora das camadas | R3.7 |
| 16 | `k = 5` limita o ML a 4 recomendações | R4.1 |
| 17 | Rubix usado só para `Euclidean::compute` | R4.2 |
| 18 | `Domain` importa `Rubix\ML\*` | R4.3 |
| 19 | KNN retreinado a cada requisição | R4.4 |
| 20 | `SimpleRouter` referencia Guzzle não instalado | R5.1 |
| 21 | `ResponseFormatter`, `ErrorBuilder`, `RequestFactory` mortos | R5.1 |
| 22 | `config/autoload.php` (Hyperf), `config/server.php` (Swoole), `databases.php` órfão | R5.2 |
| 23 | 25 diretórios só com `.gitkeep`; 3 interfaces sem implementação | R5.3 |
| 24 | `MetaTagsService` testado e nunca usado; OG tags genéricas | R5.4 |
| 25 | Redis no compose e no `setup.sh` sem consumidor | R5.5 |
| 26 | `public/index.php` com 5 responsabilidades | R5.6 |
| 27 | `psr/container` declarado e nunca usado | R5.7 |
| 28 | `vlucas/phpdotenv` declarado; `.env` nunca é carregado | R5.8 |
| 29 | README descreve Swoole, Redis, Hyperf, `/metrics`, 70% cobertura | R6.1 |
| 30 | `STRUCTURE.md` cita arquivos e fluxos inexistentes | R6.2 |
| 31 | `.env.example` com vars mortas, sem as vars reais | R6.4 |
| 32 | `setFinder()` duplicado — `app/` nunca lintado | R7.1 |
| 33 | 11 + 14 arquivos violando PSR-12 | R7.2 |
| 34 | CI inexistente | R7.3 |
| 35 | `.phpunit.result.cache` versionado | R1.6 |
| 36 | `phpunit.xml` no schema antigo; 70% nunca medido | R7.4 |
| 37 | Makefile exige Docker para tudo; `docker-compose` v1 | R7.5 |
