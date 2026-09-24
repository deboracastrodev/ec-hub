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
9. [Limitações conhecidas](#9-limitações-conhecidas)
10. [Roadmap (não implementado)](#roadmap-não-implementado)

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
Domain          KNNService ──► NeighborFinderInterface (porta, sem tipos Rubix)
                RuleBasedFallback, ConfidenceCalculator, ExplanationGenerator
                                   ▲
                                   │ implementa
Infrastructure  RubixNeighborFinder             único arquivo que importa Rubix\ML\*
```

| Peça | Arquivo | Responsabilidade |
|---|---|---|
| Porta | [`NeighborFinderInterface`](../app/Domain/Recommendation/Service/NeighborFinderInterface.php) | `train(array $products)`, `isTrained()`, `nearest(Product $target, int $k)`, que devolve `list<array{product: Product, distance: float}>` |
| Implementação | [`RubixNeighborFinder`](../app/Infrastructure/ML/RubixNeighborFinder.php) | pipeline Rubix: encoding, normalização e índice `BallTree` |
| Regra de negócio | [`KNNService`](../app/Domain/Recommendation/Service/KNNService.php) | pede vizinhos, descarta o próprio produto, calcula score e rank |
| Caso de uso | [`GenerateRecommendations`](../app/Application/Recommendation/GenerateRecommendations.php) | escolhe entre ML e fallback, completa resultados, re-ordena por histórico |
| Fallback | [`RuleBasedFallback`](../app/Domain/Recommendation/Service/RuleBasedFallback.php) | recomendações por categoria e popularidade |
| Confiança | [`ConfidenceCalculator`](../app/Domain/Recommendation/Utility/ConfidenceCalculator.php) | score → `high`/`medium`/`low` e rótulo em português |
| Explicação | [`ExplanationGenerator`](../app/Domain/Recommendation/Service/ExplanationGenerator.php) | textos de `explanation` e `reasons` |
| Configuração | [`RecommendationSettings`](../app/Domain/Recommendation/ValueObject/RecommendationSettings.php) + [`config/recommendation.php`](../config/recommendation.php) | estratégia de fallback, mínimo de produtos, faixas de score |
| HTTP | [`RecommendationController`](../app/Controller/RecommendationController.php) | `DEFAULT_LIMIT = 10`, `MAX_LIMIT = 50` (acima disso o valor é truncado para 50, sem erro; `limit` que não seja inteiro positivo, `product_id` ausente ou inválido e `user_id` presente mas vazio ou não-string respondem 400), log `Slow recommendation` acima de 200 ms |

A troca de implementação acontece em um único ponto, o container em [`config/bootstrap.php`](../config/bootstrap.php):

```php
NeighborFinderInterface::class => fn () => new RubixNeighborFinder(),
```

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

Não existe passo de treino separado. No caminho HTTP, `GenerateRecommendations::ensureModelTrained()` treina o índice antes da primeira consulta, com o catálogo carregado por `findAll(1000, 0)`; com menos de 2 produtos ele lança `RuntimeException`, que cai no fallback `ml_error` (só alcançável se `min_products_for_ml` for configurado abaixo de 2). Usado direto, o `KNNService` faz o mesmo sozinho: `KNNService::ensureModelIsTrained()` treina na primeira chamada a `recommend()`. As chamadas seguintes **no mesmo processo** reutilizam o índice (o `KnnBenchmarkTest` confirma que 100 `recommend()` resultam em um único `train()`). Veja em [Limitações](#9-limitações-conhecidas) o que isso significa com `php -S`.

## 4. ML ou fallback: quem responde

A decisão fica em [`GenerateRecommendations::execute()`](../app/Application/Recommendation/GenerateRecommendations.php):

| Situação | O que acontece | Log (`Fallback activated: …`) | `source` dos itens |
|---|---|---|---|
| `product_id` não existe no catálogo | `RuleBasedFallback::getPopularRecommendations()` (cold start) | `cold_start_unknown_product` | `popular` |
| Catálogo com menos de `min_products_for_ml` produtos (padrão 5) | fallback com a estratégia configurada | `insufficient_catalog_data` | `rules` / `popular` |
| Parâmetro `insufficientData = true` do caso de uso (o controller HTTP sempre passa `false`) | fallback com a estratégia configurada | `insufficient_session_data` | `rules` / `popular` |
| Caminho normal | `KNNService::recommend()` | — | `ml` |
| KNN devolve menos que `limit` (só acontece quando o índice tem `limit` produtos ou menos; como o índice é treinado com no máximo 1.000 produtos, na prática isso é um catálogo pequeno, em que o índice é o catálogo inteiro) | tenta completar com fallback, descartando duplicatas (`mergeRecommendations`) e o próprio produto. Como o fallback busca no mesmo catálogo que o KNN já devolveu inteiro, na prática nada é acrescentado e a resposta fica com menos de `limit` itens | (log do `RuleBasedFallback`) | `ml` |
| Exceção no ML (`\Exception`, ex.: índice não treina; um `\Error` como `TypeError` não é capturado e sobe até a borda HTTP) | `ML failed, using fallback` (nível error) e depois fallback. O `try` envolve o método inteiro, então uma exceção do próprio fallback nos ramos acima (cold start, catálogo insuficiente) também cai aqui com a mesma mensagem. Se, nessa nova tentativa, `findById` não encontrar o produto, a resposta é uma lista vazia, sem fallback nem log `ml_error` | `ml_error` | `rules` / `popular` |

O `source` de cada item é `ml` para itens do KNN, `popular` para itens com `fallback_reason = popular_product` e `rules` para os demais. O `meta.source` da resposta vem do primeiro item de fallback encontrado (`popular` ou `rules`) e, se não houver nenhum, é `ml`. Como uma resposta pode misturar origens, o `source` por item é o dado mais preciso. `RecommendationException` não é convertida em fallback; ela sobe até a borda HTTP.

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
| `fallback.strategy` | `hybrid` | `RECOMMENDATION_FALLBACK_STRATEGY` |
| `fallback.min_products_for_ml` | `5` | `RECOMMENDATION_MIN_PRODUCTS_FOR_ML` |
| `fallback.scores.category_min` / `category_max` | `60.0` / `70.0` | — |
| `fallback.scores.popularity_min` / `popularity_max` | `50.0` / `60.0` | — |

Uma estratégia desconhecida cai no ramo `default` do `switch`, que é o `hybrid`.

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
- a linha de 5.000 produtos é sintética: em produção o treino carrega no máximo 1.000 produtos (ver [Limitações](#9-limitações-conhecidas))

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

## 9. Limitações conhecidas

- **O índice é treinado a cada requisição HTTP.** O servidor é o `php -S`, que não mantém estado entre requisições, e não há cache do modelo. Toda chamada a `/api/recommendations` que chega ao KNN refaz `findAll(1000, 0)` + `train()`. O "treina uma vez" vale dentro de um mesmo processo (testes, benchmark).
- **Catálogo limitado a 1.000 produtos** no treino (`findAll(1000, 0)`, ordenado por nome). Produtos além disso não entram no índice. Se o produto consultado ficar fora do índice, o preço dele pode normalizar fora de `[0, 1]` e, se a categoria dele não existir no índice, o `OneHotEncoder` gera um vetor só de zeros; nesses casos as faixas de distância e score da [seção 3](#3-features-similaridade-e-score) deixam de valer.
- **"Popularidade" não é popularidade.** `getByPopularity()` pega os primeiros N produtos de `findAll($limit, 0)` (ordem alfabética) e os embaralha com `shuffle()`. Ainda não usa contagem de visualizações, embora o texto exibido seja "Produtos mais visualizados".
- **Só duas features** (categoria e preço). Por construção, recomendações de outra categoria só aparecem quando a categoria do produto não tem vizinhos suficientes, e sempre com score baixo.
- **`BallTree::nearest()` é `@internal`** no Rubix e pode mudar sem aviso em versões menores. Esse risco foi aceito no [ADR-003](architecture.md#adr-003--rubix-ml-real-mantido-fora-do-domain) e fica contido em `RubixNeighborFinder`.
- **Sem métrica de qualidade de recomendação.** Os números acima são de desempenho (tempo), não de acerto. Não há precision@k nem cobertura de catálogo medidos.

## Roadmap (não implementado)

Nada nesta seção existe no código hoje. Não há números para estes itens porque eles ainda não foram medidos.

- **Cache do modelo serializado (Story 10.1):** persistir o índice treinado para não retreinar a cada requisição.
- **Métricas de qualidade (Epic 10):** precision@k e cobertura de catálogo das recomendações.

---

Veja também: [README](../README.md) · [architecture.md (ADRs)](architecture.md) · [STRUCTURE.md](STRUCTURE.md)
