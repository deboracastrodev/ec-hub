# ML: recomendações com KNN (Rubix ML)

> **ML nativo em PHP com Clean Architecture.** O projeto recomenda produtos similares (item-a-item) com k-vizinhos mais próximos, usando o [Rubix ML](https://rubixml.com) dentro do próprio processo PHP 8.4. Não há serviço Python nem API externa. A biblioteca fica isolada na camada `Infrastructure`, atrás de uma porta declarada pelo `Domain`.

Este documento descreve **apenas o que existe hoje no código**. O que ainda não foi implementado está na seção [Roadmap (não implementado)](#roadmap-não-implementado). A decisão de usar o Rubix de verdade e o risco aceito estão no [ADR-003](architecture.md#adr-003--rubix-ml-real-mantido-fora-do-domain) e não são repetidos aqui.

## Sumário

1. [Visão geral: onde fica cada peça](#1-visão-geral-onde-fica-cada-peça)
2. [Pipeline KNN com Rubix ML](#2-pipeline-knn-com-rubix-ml)
3. [Features, similaridade e score](#3-features-similaridade-e-score)
4. [ML ou fallback: quem responde](#4-ml-ou-fallback-quem-responde)
5. [Fallback baseado em regras](#5-fallback-baseado-em-regras)
6. [Personalização por histórico](#6-personalização-por-histórico)
7. [Testando o Domain sem Rubix](#7-testando-o-domain-sem-rubix)
8. [Benchmarks medidos](#8-benchmarks-medidos)
9. [Avaliação offline](#9-avaliação-offline)
10. [Limitações conhecidas](#10-limitações-conhecidas)
11. [Roadmap (não implementado)](#roadmap-não-implementado)

---

## 1. Visão geral: onde fica cada peça

```
GET /api/recommendations?product_id=X&limit=N
        │
        ▼
Controller      RecommendationController        valida product_id/limit, mede tempo, monta a resposta
        │
        ▼
Application     GenerateRecommendations         decide ML × fallback, completa, personaliza
        │
        ▼
Domain          RecommendationStrategy (porta, Strategy Pattern)
                  ├── KNNService ──► NeighborFinderInterface (porta, sem tipos Rubix)   'knn' (padrão)
                  └── CollaborativeFilteringService ──► EventStoreInterface             'collaborative'
                RuleBasedFallback, ConfidenceCalculator, ExplanationGenerator
                                   ▲
                                   │ implementa
Infrastructure  RubixNeighborFinder             único arquivo que importa Rubix\ML\*
```

| Peça | Arquivo | Responsabilidade |
|---|---|---|
| Estratégia | [`RecommendationStrategy`](../app/Domain/Recommendation/Service/RecommendationStrategy.php) | porta do algoritmo: `getName()`, `isTrained()`, `train(array $products)`, `recommend(Product $target, int $limit)`. O caso de uso depende só dela |
| Porta | [`NeighborFinderInterface`](../app/Domain/Recommendation/Service/NeighborFinderInterface.php) | `train(array $products)`, `isTrained()`, `nearest(Product $target, int $k)`, que devolve `list<array{product: Product, distance: float}>` |
| Implementação | [`RubixNeighborFinder`](../app/Infrastructure/ML/RubixNeighborFinder.php) | pipeline Rubix: encoding, normalização e índice `BallTree` |
| Regra de negócio | [`KNNService`](../app/Domain/Recommendation/Service/KNNService.php) | estratégia `knn`: pede vizinhos, descarta o próprio produto, calcula score e rank |
| Regra de negócio | [`CollaborativeFilteringService`](../app/Domain/Recommendation/Service/CollaborativeFilteringService.php) | estratégia `collaborative`: similaridade item-item por co-ocorrência de interações entre sessões (ver abaixo) |
| Caso de uso | [`GenerateRecommendations`](../app/Application/Recommendation/GenerateRecommendations.php) | escolhe entre ML e fallback, completa resultados, re-ordena por histórico |
| Fallback | [`RuleBasedFallback`](../app/Domain/Recommendation/Service/RuleBasedFallback.php) | recomendações por categoria e popularidade |
| Confiança | [`ConfidenceCalculator`](../app/Domain/Recommendation/Utility/ConfidenceCalculator.php) | score → `high`/`medium`/`low` e rótulo em português |
| Explicação | [`ExplanationGenerator`](../app/Domain/Recommendation/Service/ExplanationGenerator.php) | textos de `explanation` e `reasons` |
| Configuração | [`RecommendationSettings`](../app/Domain/Recommendation/ValueObject/RecommendationSettings.php) + [`config/recommendation.php`](../config/recommendation.php) | algoritmo ativo, estratégia de fallback, mínimo de produtos, faixas de score |
| A/B testing | [`RecommendationExperiment`](../app/Application/Recommendation/RecommendationExperiment.php) + [`AbTestAssigner`](../app/Domain/Recommendation/Service/AbTestAssigner.php) | atribui a variante por hash, escolhe o caso de uso do braço, grava a amostra por algoritmo e expõe `results()` (Story 8.2) |
| HTTP | [`RecommendationController`](../app/Controller/RecommendationController.php) | `meta.algorithm` com o algoritmo que atendeu (o do braço atribuído com A/B; senão o de `RECOMMENDATION_ALGORITHM`), `meta.ab_variant` (`"A"`, `"B"` ou `null`, Story 8.2), `DEFAULT_LIMIT = 10`, `MAX_LIMIT = 50` (acima disso o valor é truncado para 50, sem erro; `limit` que não seja inteiro positivo, `product_id` ausente ou inválido e `user_id` presente mas vazio ou não-string respondem 400), log `Slow recommendation` acima de 200 ms |

O mapeamento nome → estratégia fica num único lugar, a closure `$strategyFor` em [`config/bootstrap.php`](../config/bootstrap.php) (excerto):

```php
$strategyFor = static fn (ContainerInterface $c, string $algorithm): RecommendationStrategy => match ($algorithm) {
    KNNService::NAME => $c->get(KNNService::class),
    CollaborativeFilteringService::NAME => $c->get(CollaborativeFilteringService::class),
    default => throw new \LogicException(sprintf(
        'Algoritmo de recomendação sem estratégia registrada: "%s".',
        $algorithm
    )),
};

// ...
NeighborFinderInterface::class => fn () => new RubixNeighborFinder(),

RecommendationStrategy::class => fn (ContainerInterface $c) => $strategyFor(
    $c,
    $c->get(RecommendationSettings::class)->getAlgorithm()
),
```

Ela é usada em dois pontos de seleção: a entrada `RecommendationStrategy::class` (o algoritmo padrão de `RECOMMENDATION_ALGORITHM`) e a factory lazy passada ao `RecommendationExperiment` (Story 8.2), que monta o caso de uso do braço atribuído pelo A/B. Para o algoritmo padrão, a factory reaproveita a entrada `GenerateRecommendations::class`; para o outro, cria um `GenerateRecommendations` com os mesmos colaboradores e `$strategyFor($c, $algorithm)`.

O algoritmo vem de `RECOMMENDATION_ALGORITHM` (ver [Configuração](#configuração)). O caso de uso loga `info` `Recommendation algorithm used` com `algorithm`, `target_product_id` e `count` (itens devolvidos pela estratégia) sempre que a estratégia responde, e a API devolve o nome em `meta.algorithm`. Sem A/B, um processo usa um algoritmo só. Com `RECOMMENDATION_AB_TEST`, cada sujeito é dividido entre dois algoritmos (ver [A/B testing entre algoritmos](#ab-testing-entre-algoritmos)).

### Filtragem colaborativa item-a-item (`collaborative`)

[`CollaborativeFilteringService`](../app/Domain/Recommendation/Service/CollaborativeFilteringService.php) é Domain puro (sem Rubix, sem biblioteca nova). No `train()`, lê do `EventStoreInterface` os eventos `product.viewed`, `product.clicked` e `cart.item_added` gravados por `TrackProductInteraction` e monta, para cada produto do catálogo treinado, o conjunto de sessões (`session_id`, nunca `user_id`) que interagiram com ele. Uma sessão conta uma vez por produto, qualquer que seja o número ou o tipo de eventos. A similaridade entre dois produtos é o cosseno entre esses conjuntos:

```
sim(a, b) = |S_a ∩ S_b| / sqrt(|S_a| * |S_b|)          score = sim × 100, em (0, 100]
```

Exemplo: sessões s1 {1,2,3}, s2 {1,2}, s3 {1,3}, s4 {2}. Para o produto 1: sim(1,3) = 2/√6 ≈ 0,816 (score 81,6) e sim(1,2) = 2/√9 ≈ 0,667 (score 66,7), então a ordem é [3, 2]. Empates são desfeitos por `product_id` crescente, e o produto-alvo nunca aparece.

- Eventos com `data` sem `session_id` ou `product_id` válido, e eventos de produtos fora do catálogo treinado, são ignorados.
- Produto sem nenhuma sessão em comum com outro: a estratégia devolve lista vazia e o caso de uso completa com o fallback (itens `rules`/`popular`), com `meta.algorithm` ainda `collaborative`.
- Se o event store falhar no `train()`, a exceção cai no fallback `ml_error` (seção 4).
- A explicação (`Quem se interessou por %s também se interessou por este produto (%d%% de afinidade)`) e os `reasons` (`co_interaction` e, se a categoria for a mesma, `category`) vêm do `ExplanationGenerator`. O caso de uso preserva explicação e `reasons` que a estratégia já preencheu; só gera o texto do KNN quando `reasons` chega vazio.
- Os itens continuam com `source: "ml"`. O score do CF (afinidade entre sessões) e o do KNN (distância de features) não são comparáveis entre si, e as faixas de `confidence_level` da seção 3 foram pensadas para o KNN.

### A/B testing entre algoritmos

Com `RECOMMENDATION_AB_TEST=knn,collaborative` (1º = variante A, 2º = variante B), cada requisição a `GET /api/recommendations` é atendida pelo algoritmo do braço do seu sujeito:

- **Sujeito:** o `user_id` da query (sem espaços nas pontas), se vier; senão o `session_id` do cookie. Sem nenhum dos dois, vale `RECOMMENDATION_ALGORITHM` e a variante é `null`.
- **Atribuição:** [`AbTestAssigner`](../app/Domain/Recommendation/Service/AbTestAssigner.php) é determinístico e sem estado: `hexdec(substr(hash('sha256', $sujeito), 0, 8)) % 2 === 0` → A, senão B. O mesmo sujeito cai sempre no mesmo braço, então nada é gravado na sessão nem em cookie. Com 1.000 ids distintos cada variante fica entre 45% e 55% (coberto por `AbTestAssignerTest`).
- **Roteamento:** [`RecommendationExperiment`](../app/Application/Recommendation/RecommendationExperiment.php) recebe uma factory lazy (`Closure(string): GenerateRecommendations`, memoizada por algoritmo) montada em `config/bootstrap.php`. Só o caso de uso do braço sorteado é construído e treinado na requisição. O algoritmo padrão reaproveita a entrada `GenerateRecommendations` do container; o outro recebe os mesmos colaboradores, trocando só a estratégia.
- **Resposta:** `meta.algorithm` é o algoritmo que de fato atendeu, e `meta.ab_variant` (campo aditivo) é `"A"`, `"B"` ou `null` (A/B desligado ou sem sujeito). O resto do contrato não muda.

**Métricas por algoritmo.** Toda resposta, com ou sem A/B, grava uma amostra na chave do algoritmo que atendeu, derivada da resposta já formatada: número de itens, itens com `source: "ml"`, soma e contagem dos `score` numéricos, `meta.response_time_ms` e o sujeito (se houver). [`RedisAlgorithmMetricsRepository`](../app/Infrastructure/Redis/RedisAlgorithmMetricsRepository.php) soma isso no hash `ec-hub:ab-metrics:{algoritmo}` (`HINCRBY`/`HINCRBYFLOAT`) e conta sujeitos únicos no HyperLogLog `ec-hub:ab-metrics:{algoritmo}:subjects` (`PFADD`/`PFCOUNT`, contagem aproximada), tudo num único `MULTI/EXEC` e sem TTL. Falha ao gravar vira log `warning` `Não foi possível registrar métricas do algoritmo.` e nunca quebra a resposta.

**Onde ver.** `RecommendationExperiment::results()` é o único ponto de leitura e alimenta as duas saídas, que mostram os mesmos números:

- `GET /api/ab-tests/results` (JSON): `{"data": {"enabled", "variants", "algorithms": [...]}, "meta": {"generated_at"}}`. Cada item de `algorithms` tem `algorithm`, `variant`, `requests`, `unique_subjects`, `total_items`, `ml_items`, `ml_item_rate` (% de itens `ml`), `avg_response_time_ms` e `avg_score`. Os derivados são arredondados para 2 casas e ficam `null` sem dados. A lista traz sempre `knn` e `collaborative`, nessa ordem, mesmo zerados. Se o Redis falhar, a rota responde 500 pelo `ErrorHandler`.
- `/metrics`: painel "Comparação de algoritmos (A/B)", com o status do teste em texto, uma tabela com uma linha por algoritmo e o link "Exportar resultados (JSON)". Se a configuração for inválida, o Redis estiver fora ou o resultado vier malformado, o painel mostra "Comparação indisponível", sem o link de export (o endpoint também falharia), e o resto da página continua funcionando.

**Zerar o experimento:** apague as chaves no Redis, por exemplo `redis-cli --scan --pattern 'ec-hub:ab-metrics:*' | xargs redis-cli DEL`. Não há janela de tempo: os contadores acumulam desde a última limpeza.

As convenções gerais (Domain sem biblioteca externa, configuração injetada, dinheiro) estão em [CODING-STANDARDS.md](CODING-STANDARDS.md).

## 2. Pipeline KNN com Rubix ML

Versão em uso: `rubix/ml` 2.5.5 (restrição `^2.5` no `composer.json`).

```
Product ──► [categoria, preço decimal] ──► Labeled (label = product_id)
        ──► OneHotEncoder ──► MinMaxNormalizer ──► BallTree(30, Euclidean) ──► nearest()
```

Treino, em [`RubixNeighborFinder::train()`](../app/Infrastructure/ML/RubixNeighborFinder.php) (excerto real):

```php
public function __construct(?BallTree $tree = null)
{
    $this->tree = $tree ?? new BallTree(30, new Euclidean());
    // ...
}

public function train(array $products): void
{
    // ...
    foreach ($products as $product) {
        // ...
        $this->productsById[$id] = $product;
        $samples[] = $this->featuresFor($product);
        $labels[] = $id;
    }

    // Fresh, unfitted transformers every training run -- reusing already-
    // fitted ones here would silently keep scaling new data against an
    // old catalog's min/max and category set.
    $this->categoryEncoder = new OneHotEncoder();
    $this->normalizer = new MinMaxNormalizer();

    $dataset = new Labeled($samples, $labels);
    $dataset->apply($this->categoryEncoder)
        ->apply($this->normalizer);

    $this->tree->grow($dataset);
    $this->trained = true;
}
```

Consulta, em `RubixNeighborFinder::nearest()`. A amostra de consulta passa pelos **mesmos** transformers já ajustados no treino, para ficar na mesma escala do índice:

```php
$query = new Unlabeled([$this->featuresFor($target)]);
$query->apply($this->categoryEncoder)
    ->apply($this->normalizer);

[, $labels, $distances] = $this->tree->nearest($query->samples()[0], $k);
```

Cada peça do Rubix tem um papel:

| Componente | Papel |
|---|---|
| `Labeled` | dataset com as features de cada produto; o label é o `product_id`, que permite voltar do vizinho encontrado para a entidade `Product` |
| `OneHotEncoder` | transforma a categoria (texto) em colunas binárias, uma por categoria |
| `MinMaxNormalizer` | leva cada coluna para `[0, 1]`, para que o preço (R$ 10 a R$ 5.000) não domine a distância |
| `BallTree(30, new Euclidean())` | índice espacial para busca de k-vizinhos (30 é o tamanho máximo de folha), com distância euclidiana |
| `BallTree::nearest()` | devolve labels e distâncias dos k vizinhos mais próximos. É `@internal` no Rubix, e esse risco está registrado no ADR-003 |

Os estimadores públicos do Rubix (por exemplo `KNearestNeighbors`) classificam, mas não devolvem os vizinhos com as distâncias. Por isso o projeto usa o `BallTree` diretamente, contido em um único arquivo.

## 3. Features, similaridade e score

### Features

Cada produto é representado por duas features, em `RubixNeighborFinder::featuresFor()`:

```php
return [$product->getCategory(), $product->getPrice()->getDecimal()];
```

- **categoria** (categórica): vira colunas one-hot
- **preço decimal** (contínua): normalizado min-max sobre o catálogo treinado

Nome, descrição e histórico de navegação não entram no vetor de features. O histórico é usado depois, só para re-ordenar os resultados (ver [seção 6](#6-personalização-por-histórico)).

### Similaridade: distância euclidiana

Depois do encoding, dois produtos da **mesma** categoria diferem apenas na coluna de preço normalizado, então a distância é `|preço_a − preço_b|` normalizado, entre 0 e 1. Dois produtos de categorias **diferentes** diferem em duas colunas one-hot (um 1 que vira 0 e um 0 que vira 1), então a distância é `√(2 + Δpreço²)`, sempre ≥ √2 ≈ 1,414. Na prática, **mesma categoria sempre fica mais perto que categoria diferente**, e o preço desempata dentro da categoria.

### Score e confiança

Em [`KNNService`](../app/Domain/Recommendation/Service/KNNService.php), a distância vira um score de 0 a 100:

```php
$score = max(0, min(100, 100 * (1 / (1 + $neighbor['distance']))));
```

O `ConfidenceCalculator` traduz o score em nível e rótulo:

| Score | `confidence_level` | `score_label` |
|---|---|---|
| ≥ 80 | `high` | Alta similaridade |
| ≥ 50 e < 80 | `medium` | Média similaridade |
| < 50 | `low` | Baixa similaridade |

Consequência direta das fórmulas: um vizinho de outra categoria tem score ≤ 100/(1+√2) ≈ 41,4, sempre `low`. Um vizinho da mesma categoria tem score ≥ 50 e só chega a `high` com diferença de preço normalizada ≤ 0,25.

### Exemplo numérico (ilustrativo)

Catálogo de brinquedo com 6 produtos, **só para ilustrar**. Não é o catálogo do seed nem uma medição de qualidade. As distâncias foram calculadas rodando o `RubixNeighborFinder` real sobre estes dados, com consulta pelo "Fone":

| Produto | Categoria | Preço | Distância ao Fone | Score | Nível |
|---|---|---|---|---|---|
| Fone (consulta) | Eletrônicos | 200 | 0,0000 | (descartado) | — |
| Monitor | Eletrônicos | 1.200 | 0,2564 | 79,59 | medium |
| Notebook | Eletrônicos | 4.000 | 0,9744 | 50,65 | medium |
| Luminária | Casa | 250 | 1,4143 | 41,42 | low |
| Bola | Esportes | 100 | 1,4144 | 41,42 | low |
| Tênis | Esportes | 400 | 1,4151 | 41,41 | low |

Preços entre 100 e 4.000 normalizam o Fone para ≈ 0,026 e o Monitor para ≈ 0,282, o que dá a distância de 0,2564. O próprio produto aparece como vizinho de distância 0, e é por isso que o `KNNService` pede `limit + 1` vizinhos e descarta o alvo:

```php
$neighbors = $this->neighborFinder->nearest($targetProduct, $limit + 1);
```

A explicação de cada item ML vem do `ExplanationGenerator`, com o template `Recomendado com base em %s que você visualizou (%d%% de similaridade)`, e o campo `reasons` recebe `similarity` e, se a categoria for a mesma, `category`.

### Treino lazy

Não existe passo de treino separado. No caminho HTTP, `GenerateRecommendations::ensureModelTrained()` treina o índice antes da primeira consulta, com o catálogo carregado por `findAll(1000, 0)`; com menos de 2 produtos ele lança `RuntimeException`, que cai no fallback `ml_error` (só alcançável se `min_products_for_ml` for configurado abaixo de 2). Usado direto, o `KNNService` faz o mesmo sozinho: `KNNService::ensureModelIsTrained()` treina na primeira chamada a `recommend()`. As chamadas seguintes **no mesmo processo** reutilizam o índice (o `KnnBenchmarkTest` confirma que 100 `recommend()` resultam em um único `train()`). Veja em [Limitações](#10-limitações-conhecidas) o que isso significa com `php -S`.

## 4. ML ou fallback: quem responde

A decisão fica em [`GenerateRecommendations::execute()`](../app/Application/Recommendation/GenerateRecommendations.php):

| Situação | O que acontece | Log (`Fallback activated: …`) | `source` dos itens |
|---|---|---|---|
| `product_id` não existe no catálogo | `RuleBasedFallback::getPopularRecommendations()` (cold start) | `cold_start_unknown_product` | `popular` |
| Catálogo com menos de `min_products_for_ml` produtos (padrão 5) | fallback com a estratégia configurada | `insufficient_catalog_data` | `rules` / `popular` |
| Parâmetro `insufficientData = true` do caso de uso (o controller HTTP sempre passa `false`) | fallback com a estratégia configurada | `insufficient_session_data` | `rules` / `popular` |
| Caminho normal | `RecommendationStrategy::recommend()` (KNN ou CF, conforme `RECOMMENDATION_ALGORITHM`) | — (log `Recommendation algorithm used`) | `ml` |
| KNN devolve menos que `limit` (só acontece quando o índice tem `limit` produtos ou menos; como o índice é treinado com no máximo 1.000 produtos, na prática isso é um catálogo pequeno, em que o índice é o catálogo inteiro) | tenta completar com fallback, descartando duplicatas (`mergeRecommendations`) e o próprio produto. Como o fallback busca no mesmo catálogo que o KNN já devolveu inteiro, na prática nada é acrescentado e a resposta fica com menos de `limit` itens | (log do `RuleBasedFallback`) | `ml` |
| Exceção no ML (`\Exception`, ex.: índice não treina ou event store do CF indisponível; um `\Error` como `TypeError` não é capturado e sobe até a borda HTTP) | `ML failed, using fallback` (nível error, com `algorithm` no contexto) e depois fallback. O `try` envolve o método inteiro, então uma exceção do próprio fallback nos ramos acima (cold start, catálogo insuficiente) também cai aqui com a mesma mensagem. Se, nessa nova tentativa, `findById` não encontrar o produto, a resposta é uma lista vazia, sem fallback nem log `ml_error` | `ml_error` | `rules` / `popular` |

A linha "KNN devolve menos que `limit`" vale também para o CF, onde é bem mais comum: qualquer produto com poucas co-interações devolve menos itens, e aí o fallback de fato acrescenta itens.

O `source` de cada item é `ml` para itens da estratégia (KNN ou CF), `popular` para itens com `fallback_reason = popular_product` e `rules` para os demais. O `meta.source` da resposta vem do primeiro item de fallback encontrado (`popular` ou `rules`) e, se não houver nenhum, é `ml`. Como uma resposta pode misturar origens, o `source` por item é o dado mais preciso. `RecommendationException` não é convertida em fallback; ela sobe até a borda HTTP.

**Log por resposta (Story 10.4).** Todo retorno de `execute()` com lista (ML, os fallbacks, o cold start de produto desconhecido, `ml_error` e a lista vazia do `ml_error` sem produto) emite exatamente um `info('Recommendations served', …)`, depois da personalização, com a estratégia de cada item na ordem final. Os logs `Fallback activated: …` continuam.

```json
{
  "target_product_id": 12,
  "algorithm": "knn",
  "fallback_activated": true,
  "recommendations": [
    {"product_id": 7, "source": "ml", "strategy": "knn"},
    {"product_id": 3, "source": "rules", "strategy": "category"},
    {"product_id": 41, "source": "popular", "strategy": "popularity"}
  ]
}
```

`strategy` é o nome da estratégia ativa para itens `ml` e o `fallback_strategy` do item (`category` ou `popularity`) para itens de fallback, ou `rule_based` quando ele não vem. `fallback_activated` é `true` quando algum item veio do fallback (`source` diferente de `ml`); para a lista vazia é `false`. `algorithm` é o nome da estratégia configurada (`knn` ou `collaborative`) mesmo quando nenhum item de ML foi servido (fallback forçado, catálogo pequeno, produto desconhecido). Uma `RecommendationException` sobe sem gerar o log `Recommendations served`. Os logs emitidos pelo próprio `GenerateRecommendations` (`Recommendations served`, `Recommendation algorithm used`, `Recommendation model ready`, `Fallback activated: …` e `ML failed, using fallback`) passam por um helper que descarta falhas do logger, então uma falha deles não muda a resposta nem cai no ramo `ml_error`. Os avisos da personalização e os logs do `RuleBasedFallback` não têm essa proteção. O exemplo acima é ilustrativo, não um log real.

**Onde ver o log.** O container liga `LoggerInterface` ao [`StreamLogger`](../app/Infrastructure/Logging/StreamLogger.php), que grava uma linha JSON por registro (`{"ts","level","message","context"}`) no **stderr** do processo: o terminal do `php -S` ou `docker compose logs app`. O nível mínimo vem de `LOG_LEVEL` (padrão `info`; aceita qualquer nível PSR-3, e `none` desliga). Nos testes o `phpunit.xml` usa `LOG_LEVEL=none`. Para filtrar só as respostas servidas:

```bash
docker compose logs app 2>&1 | grep '"Recommendations served"'
```

## 5. Fallback baseado em regras

[`RuleBasedFallback`](../app/Domain/Recommendation/Service/RuleBasedFallback.php) tem três estratégias:

| Estratégia | Comportamento |
|---|---|
| `category_only` | produtos da mesma categoria (`findByCategory`), sem o produto de contexto |
| `popularity_only` | "populares" (ver limitação abaixo). A amostra pode incluir o próprio produto, que é removido depois sem reposição, então a lista pode vir com `limit - 1` itens |
| `hybrid` (**padrão**) | até `ceil(limit * 0.5)` itens por categoria; o restante (`limit` menos os itens de categoria obtidos, então a popularidade cobre também a falta de itens da categoria) é buscado por popularidade e os IDs repetidos (e, depois, o próprio produto) são descartados **sem reposição**, então a lista pode vir com menos de `limit` itens |

Score do fallback por posição, com teto de 70 (o ML vai até 100), com `rank` começando em 0. As faixas se sobrepõem às do ML: um item de fallback pode ter score maior que um vizinho ML de outra categoria (≤ 41,4) ou de preço distante:

```php
$score = $max - ($rank * 2);
return max($min, min($max, $score));
```

| Tipo | Faixa | Sequência |
|---|---|---|
| categoria | 60–70 | 70, 68, 66, 64, 62, 60, 60… |
| popularidade | 50–60 | 60, 58, 56, 54, 52, 50, 50… |

No `hybrid`, os scores de popularidade são atribuídos antes do descarte de repetidos, então a sequência entregue pode pular valores (por exemplo 60, 56, 54).

Explicações: `Produtos populares na categoria %s` (categoria) e `Produtos mais visualizados` (popularidade).

### Configuração

Os padrões ficam em `RecommendationSettings::fromArray()`, lidos de [`config/recommendation.php`](../config/recommendation.php):

| Chave | Padrão | Variável de ambiente |
|---|---|---|
| `algorithm` | `knn` | `RECOMMENDATION_ALGORITHM` (`knn` ou `collaborative`) |
| `ab_test` | vazio (A/B desligado) | `RECOMMENDATION_AB_TEST` (ex.: `knn,collaborative`) |
| `fallback.strategy` | `hybrid` | `RECOMMENDATION_FALLBACK_STRATEGY` |
| `fallback.min_products_for_ml` | `5` | `RECOMMENDATION_MIN_PRODUCTS_FOR_ML` |
| `fallback.scores.category_min` / `category_max` | `60.0` / `70.0` | — |
| `fallback.scores.popularity_min` / `popularity_max` | `50.0` / `60.0` | — |

Uma estratégia de fallback desconhecida cai no ramo `default` do `switch`, que é o `hybrid`. Já o algoritmo é validado: `RECOMMENDATION_ALGORITHM` é normalizado (`trim` + minúsculas, vazio ou ausente vira `knn`) e qualquer valor fora de `knn`/`collaborative` lança `InvalidArgumentException` em `RecommendationSettings::fromArray()`, com a lista dos valores aceitos. `RECOMMENDATION_AB_TEST` segue a mesma linha: cada item passa por `trim` + minúsculas, vazio desliga o A/B e, ligado, o valor precisa ter exatamente 2 algoritmos distintos; fora disso, `InvalidArgumentException` citando `RECOMMENDATION_AB_TEST` e os valores aceitos.

## 6. Personalização por histórico

Quando há sessão (cookie) ou `user_id` na query (se os dois existirem, vale o `user_id`), `GenerateRecommendations::personalize()` lê o histórico de eventos (`EventHistoryRepositoryInterface`, implementado sobre Redis) e **re-ordena** a lista já montada, seja ela do ML ou do fallback. Nenhum item novo entra na lista, e o `score` de cada item não é recalculado, então depois da re-ordenação a lista pode não estar em ordem decrescente de `score`.

- peso **3** para `cart.item_added` e **1** para os demais eventos
- cada evento soma o peso ao **produto** e à **categoria** desse produto
- score do item = peso do produto + peso da categoria; a ordenação é decrescente e os empates mantêm a ordem original (estável)
- histórico vazio ou erro de leitura: a lista volta sem alteração (o erro vira log `warning`)

```php
$weight = ($event['event'] ?? '') === 'cart.item_added' ? 3 : 1;
$productWeights[$productId] = ($productWeights[$productId] ?? 0) + $weight;
// ...
$categoryWeights[$category] = ($categoryWeights[$category] ?? 0) + $weight;
```

## 7. Testando o Domain sem Rubix

Como o `KNNService` depende só da porta, os testes do Domain usam um fake determinístico e não carregam o Rubix. O excerto abaixo é de [`KNNServiceDeterministicTest`](../tests/Unit/Domain/Recommendation/KNNServiceDeterministicTest.php):

```php
final class FakeNeighborFinder implements NeighborFinderInterface
{
    // ...
    public function nearest(Product $target, int $k): array
    {
        return array_slice($this->neighbors, 0, $k);
    }
}
```

```php
$this->neighborFinder->setNeighbors([
    ['product' => $this->product(2, 'Mouse Gamer', 'Eletrônicos', 150.0), 'distance' => 0.5],
    ['product' => $this->product(3, 'Teclado', 'Eletrônicos', 300.0), 'distance' => 1.0],
    ['product' => $this->product(4, 'Camiseta', 'Roupas', 79.9), 'distance' => 2.0],
]);
// ...
$results = $this->service->recommend($target, 3);

$this->assertSame(100 * (1 / (1 + 0.5)), $results[0]->getScore());
```

O mesmo arquivo cobre a exclusão do produto-alvo quando ele é o vizinho mais próximo, o limite de score em `[0, 100]` e o corte por `limit`. O pipeline Rubix real é exercitado em `KNNServiceTest`, nos testes de integração (por exemplo `GenerateRecommendationsIntegrationTest`) e no benchmark da próxima seção.

## 8. Benchmarks medidos

Tempo do `KNNService` real com o `RubixNeighborFinder` real, sem HTTP e sem banco ([`KnnBenchmark`](../tests/Performance/Support/KnnBenchmark.php)):

- catálogo sintético determinístico (`KnnBenchmark::syntheticCatalog`): 8 categorias em rodízio e preços de R$ 10 a ~R$ 5.000
- **treino ms**: uma chamada a `train()` sobre o catálogo inteiro
- **recommend**: 100 chamadas a `recommend(limit = 5)` com produtos-alvo espalhados pelo catálogo, reportando p50, p95 e máximo. O `train()` é chamado explicitamente antes, então esses tempos **não** incluem treino
- a linha de 5.000 produtos é sintética: em produção o treino carrega no máximo 1.000 produtos (ver [Limitações](#10-limitações-conhecidas))

| produtos | treino ms | recommend p50 | recommend p95 | recommend máx |
|---:|---:|---:|---:|---:|
| 100 | 1.83 | 0.08 | 0.09 | 0.69 |
| 1.000 | 16.60 | 0.04 | 0.07 | 0.09 |
| 5.000 | 96.70 | 0.05 | 0.08 | 0.18 |

Valores em milissegundos, copiados da saída do script (que usa ponto como separador decimal).

**Ambiente da medição:** 2026-09-23, container `app` do `docker compose` com PHP 8.4.25 (CLI, NTS, OPcache), `rubix/ml` 2.5.5, host Docker Desktop no macOS (aarch64, 14 CPUs visíveis ao container). Uma segunda execução seguida ficou na mesma ordem de grandeza (treino de 1,62 / 14,59 / 92,96 ms; p95 de 0,09 / 0,07 / 0,07 ms).

**Para reproduzir:**

```bash
make up && make setup
make benchmark-knn        # = docker compose exec -T app php bin/benchmark-knn.php
```

Os números **variam com a máquina** e com a carga do momento. O que deve se repetir é a ordem de grandeza: o treino cresce com o tamanho do catálogo, e cada consulta fica abaixo de 1 ms. O [`KnnBenchmarkTest`](../tests/Performance/KnnBenchmarkTest.php) (grupo `performance`, `make test-performance`) garante um teto, não a ordem de grandeza medida: com 1.000 produtos, um único treino e p95 de `recommend()` < 200 ms.

Leitura dos números: em uma requisição HTTP, o custo dominante do KNN é o **treino**, porque ele acontece a cada requisição (ver abaixo), e não a consulta. O catálogo do seed tem hoje 56 produtos em 6 categorias, bem abaixo da menor linha da tabela.

## 9. Avaliação offline

Mede a **qualidade** do KNN (precision@k e recall@k, cobertura de catálogo, diversidade intra-lista e concentração, k = 1, 5 e 10) e a taxa de ativação do fallback de cold-start, com o fallback medido pelas mesmas métricas, e não só o tempo. Roda local, sem Docker, MySQL nem Redis:

```bash
make eval        # = php bin/evaluate.php [--seed=42] [--catalog=...] [--output-dir=docs/evaluation]
```

- **Catálogo:** [`database/fixtures/evaluation-catalog.json`](../database/fixtures/evaluation-catalog.json), 80 produtos versionados, gerados uma vez com a distribuição do `ProductSeeder`. O seed do banco é aleatório a cada execução, então não serve para comparar medições.
- **Split:** catálogo ordenado por id, embaralhado com `Mt19937` e seed fixa (padrão 42). O holdout são os primeiros `max(1, round(n × 0.2))` produtos depois desse embaralhamento (16 dos 80 do catálogo versionado), e o resto é o treino. O `KNNService` real com o `RubixNeighborFinder` real é treinado só com o treino, e cada produto do holdout vira uma consulta de top-k direto na estratégia, sem o fallback de cold-start.
- **Relevância:** os produtos **do treino** da mesma categoria da consulta. Limitação: a categoria também é feature do modelo, então o número mede o quanto o KNN respeita a categoria, e não o gosto do usuário. A precision@k cai abaixo de 1 principalmente quando a categoria tem menos de k produtos no treino.
- **Reprodutível:** mesma seed e mesmo catálogo geram o mesmo relatório, exceto a data. O [`EvaluateScriptTest`](../tests/Integration/Tooling/EvaluateScriptTest.php) (roda no `make test`) confere que o relatório commitado bate com uma execução nova.

- **Cobertura, diversidade e concentração (Story 10.3):** medidas por k (1, 5 e 10) sobre as listas top-k de **todas** as consultas do holdout, incluindo as sem relevante no treino. Os candidatos são os produtos do treino, os únicos que o índice pode recomendar.
  - **Cobertura de catálogo@k:** produtos distintos do treino que aparecem em pelo menos uma lista@k ÷ total de candidatos. O relatório mostra também `cobertos/candidatos`.
  - **Diversidade intra-lista@k (ILD):** distância por categoria (0 se igual, 1 se diferente), pares de categorias diferentes ÷ `n·(n−1)/2`, com média sobre as listas de n ≥ 2 (n/a em k = 1). Também sai a média de categorias distintas por lista. Como a categoria domina a distância ([seção 3](#3-features-similaridade-e-score)), a ILD tende a ficar perto de 0 enquanto a categoria da consulta tem itens no treino.
  - **Concentração@k:** Gini das aparições de cada candidato (incluindo os que aparecem 0 vezes) e *top share*, a fração das aparições que fica com os `ceil(0.1 × candidatos)` mais recomendados. O relatório sinaliza **concentração excessiva** quando o top share publicado (4 casas) é ≥ 0.5 (10% do catálogo recomendável com metade das recomendações). Esse limiar foi fixado antes da medição. Um id repetido dentro da mesma lista conta uma vez na cobertura, na diversidade e na concentração.
  - **Tamanho da amostra:** com listas cheias há listas × k aparições. Quando esse número é pequeno perto do número de candidatos, o menor top share possível (cada aparição num produto diferente) é `top_products / aparições`, então um k pequeno infla o top share e o Gini. Isso não torna o sinal inevitável: ele depende de quanto os mesmos produtos se repetem.

- **Cold-start e fallback (Story 10.4):** o harness roda cada consulta do holdout pelo caso de uso real (`GenerateRecommendations`), no cenário `new_product`: o catálogo da consulta é o treino (na ordem do split) mais a própria consulta, e o modelo foi treinado só com o treino. Cada consulta roda duas vezes, sem sessão nem usuário:
  - **Taxa de ativação do fallback:** na execução servida, consultas com pelo menos um item de `source` diferente de `ml` ÷ total de consultas. O relatório mostra numerador e denominador, e também itens de fallback ÷ itens servidos.
  - **População ML (`served_ml_items`):** a lista servida de cada consulta, filtrada para os itens `ml`. Consultas sem nenhum item ML ficam fora.
  - **População fallback (`forced_fallback`):** a lista com o fallback forçado (`insufficientData: true`) para todas as consultas, com a estratégia padrão `hybrid` (`RecommendationSettings::fromArray([])`, independente de env). É **contrafactual**: mostra o que o fallback serviria, e não tráfego real. É o ramo de fallback completo (a lista inteira vem das regras), e não o completamento de uma lista ML curta.
  - **Por que a ativação espontânea é rara ou nula nesse cenário:** o KNN por conteúdo responde a qualquer produto que tenha features, mesmo sem tê-lo visto no treino. O fallback só entra com lista ML mais curta que o pedido, id desconhecido, erro de ML ou catálogo pequeno demais para o ML. Por isso a população de fallback é medida forçando o ramo.
  - As duas populações usam as mesmas métricas e fórmulas das seções acima (`OfflineEvaluation::measure()`), lado a lado. A popularidade usa `shuffle()`, então o harness chama `mt_srand(seed)` antes das execuções e `mt_srand(seed + id da consulta)` logo antes de cada execução forçada: o relatório é reproduzível, e a população forçada depende só da seed e do catálogo, e não do quanto a execução servida consumiu de aleatoriedade. As duas execuções pedem `limit` = maior k (10), publicado em `cold_start.limit`; a taxa de ativação depende desse limite.
  - **Limitações:** a relevância por categoria favorece o fallback `category` (circuito fechado), e a "popularidade" é a ordem do catálogo embaralhada ([seção 10](#10-limitações-conhecidas)).

Os números medidos ficam em [`docs/evaluation/offline-evaluation.md`](evaluation/offline-evaluation.md) (e `.json`), e não são copiados para cá.

- **Onde os números são publicados (Story 10.5):** o Nível 3 do `/metrics` ("Ver arquitetura técnica") lê o `offline-evaluation.json` commitado a cada request, sem cache e sem MySQL/Redis, e mostra precision@5, cobertura de catálogo@5 (mesmo k), a data da medição, o tamanho do holdout e a ativação offline do fallback. Se o arquivo faltar ou vier malformado, as linhas mostram "indisponível (rode make eval)". A seção "Qualidade da recomendação (medida)" do [README](../README.md) repete os mesmos números, e o `ReadmeQualityMetricsTest` falha se ela divergir do JSON.
- **Taxa de cold-start da sessão (ao vivo):** o `RecommendationController` mantém na sessão o campo `recommendation.cold_start` = `{requests, fallback_activated}`. Cada resposta servida a uma sessão soma 1 em `requests` e, se algum item tiver `source` diferente de `ml` (a mesma definição de "ativada" do harness), soma 1 em `fallback_activated`. Uma lista vazia conta só como request. O Nível 3 mostra "N de M recomendações (P%)". Essa taxa é de tráfego real da sessão e não se compara diretamente com a offline, que é o cenário `new_product` do holdout.

## 10. Limitações conhecidas

- **O índice é treinado a cada requisição HTTP.** O servidor é o `php -S`, que não mantém estado entre requisições, e não há cache do modelo. Toda chamada a `/api/recommendations` que chega ao KNN refaz `findAll(1000, 0)` + `train()`. O "treina uma vez" vale dentro de um mesmo processo (testes, benchmark).
- **Catálogo limitado a 1.000 produtos** no treino (`findAll(1000, 0)`, ordenado por nome). Produtos além disso não entram no índice. Se o produto consultado ficar fora do índice, o preço dele pode normalizar fora de `[0, 1]` e, se a categoria dele não existir no índice, o `OneHotEncoder` gera um vetor só de zeros; nesses casos as faixas de distância e score da [seção 3](#3-features-similaridade-e-score) deixam de valer.
- **"Popularidade" não é popularidade.** `getByPopularity()` pega os primeiros N produtos de `findAll($limit, 0)` (ordem alfabética) e os embaralha com `shuffle()`. Ainda não usa contagem de visualizações, embora o texto exibido seja "Produtos mais visualizados".
- **Só duas features** (categoria e preço). Por construção, recomendações de outra categoria só aparecem quando a categoria do produto não tem vizinhos suficientes, e sempre com score baixo.
- **`BallTree::nearest()` é `@internal`** no Rubix e pode mudar sem aviso em versões menores. Esse risco foi aceito no [ADR-003](architecture.md#adr-003--rubix-ml-real-mantido-fora-do-domain) e fica contido em `RubixNeighborFinder`.
- **O CF lê o event store inteiro a cada requisição.** Pelo mesmo motivo do KNN (sem cache de modelo), cada requisição com `collaborative` faz três `getByEvent()` que trazem **todos** os eventos de interação já gravados, e recalcula a co-ocorrência. O custo cresce com o volume de eventos, sem janela de tempo nem limite, e não foi medido.
- **CF herda o limite de 1.000 produtos.** O CF é treinado com o mesmo `findAll(1000, 0)` do KNN: eventos de produtos fora desse recorte são ignorados, e um produto-alvo fora dele sempre cai no fallback.
- **CF depende de histórico.** Sem interações registradas (base nova, Redis limpo) o CF não recomenda nada e toda resposta vem do fallback. Os eventos também não expiram por idade: interesse antigo pesa igual a recente.
- **O A/B compara operação, não resultado.** As métricas por algoritmo são volume, % de itens `ml`, latência e score médio. Não há CTR nem conversão (os eventos de clique e carrinho não são ligados à recomendação que os originou) e não há teste de significância estatística: a diferença entre os braços é só descritiva. O score médio mistura escalas diferentes (KNN × CF, ver seção 1), então não serve para dizer qual algoritmo acerta mais.
- **O sujeito do A/B não é autenticado.** O `user_id` é um parâmetro de query sem autenticação: um cliente que o envia escolhe o próprio braço (basta testar valores até cair na variante desejada) e pode inflar `unique_subjects` mandando um `user_id` diferente a cada requisição. Um mesmo visitante que às vezes manda `user_id` e às vezes não é atribuído ora pelo `user_id`, ora pelo `session_id`, e pode cair nos dois braços. Trate os números do A/B como indicativos, não como prova.
- **Métricas do A/B sem janela de tempo.** Os contadores no Redis não expiram e acumulam desde a última limpeza manual (`DEL` das chaves `ec-hub:ab-metrics:*`). Trocar as variantes sem zerar mistura os períodos. Sujeitos únicos vêm de um HyperLogLog, uma contagem aproximada.
- **Qualidade medida só offline e só por categoria.** precision@k e recall@k são medidos pelo harness offline ([seção 9](#9-avaliação-offline)) num catálogo versionado, com a categoria como rótulo de relevância. A cobertura de catálogo, a diversidade intra-lista e a concentração saem do mesmo harness e do mesmo holdout pequeno (uma lista por produto do holdout). O fallback rule-based é medido pelo mesmo harness e com as mesmas métricas, mas a população dele é contrafactual (fallback forçado), não tráfego real. Não há medição de qualidade com comportamento real de usuário: a única métrica ao vivo é a taxa de ativação do fallback por sessão no `/metrics`, que conta respostas servidas e não diz nada sobre acerto.

## Roadmap (não implementado)

Nada nesta seção existe no código hoje. Não há números para estes itens porque eles ainda não foram medidos.

- **Cache do modelo serializado (Story 10.1):** persistir o índice treinado para não retreinar a cada requisição.
- **Export Prometheus das métricas por algoritmo (Story 8.4):** pode reaproveitar `RecommendationExperiment::results()`.

---

Veja também: [README](../README.md) · [architecture.md (ADRs)](architecture.md) · [STRUCTURE.md](STRUCTURE.md)
