# Avaliação offline do recomendador

<!-- Gerado por bin/evaluate.php (make eval). Não edite à mão: rode o harness de novo. -->

- **Algoritmo:** `knn`
- **Medido em:** 2026-09-24
- **Catálogo:** `database/fixtures/evaluation-catalog.json` (80 produtos, sha256 `6735c6350a9f`)
- **Split:** seed 42, holdout de 20% → 64 produtos no treino e 16 no holdout
- **Consultas:** 16 avaliadas, 0 sem nenhum relevante no treino (fora das médias)

## Resultado

| k | precision@k | recall@k |
|---:|---:|---:|
| 1 | 1.0000 | 0.1155 |
| 5 | 0.9500 | 0.4939 |
| 10 | 0.8125 | 0.7083 |

Macro-média sobre as consultas avaliadas. precision@k = acertos no top-k / k, sempre dividido por k, mesmo quando a lista vem menor. recall@k = acertos no top-k / total de relevantes da consulta.

## Cobertura, diversidade e concentração

Medido sobre as 16 listas do holdout (uma por consulta, com ou sem relevante no treino). Candidatos: os 64 produtos do treino, os únicos que o índice pode recomendar.

| k | cobertura | cobertos/candidatos | ILD | categorias distintas | Gini | top share | concentração |
|---:|---:|---:|---:|---:|---:|---:|:---|
| 1 | 0.1875 | 12/64 | n/a | 1.0000 | 0.8496 | 0.6875 | excessiva |
| 5 | 0.6875 | 44/64 | 0.0875 | 1.2500 | 0.5199 | 0.3625 | ok |
| 10 | 0.9219 | 59/64 | 0.2736 | 2.3750 | 0.3879 | 0.2938 | ok |

**Sinal de concentração:** excessiva em k = 1.

Mais recomendados (k = 10):

- produto 11: 8 aparições
- produto 37: 8 aparições
- produto 3: 7 aparições
- produto 22: 7 aparições
- produto 25: 6 aparições

### Definições

- **Cobertura de catálogo@k:** produtos distintos do treino que aparecem em pelo menos uma lista@k, divididos pelo total de candidatos. A coluna cobertos/candidatos traz a contagem e o denominador, para quem quiser dividir pelo catálogo inteiro.
- **Diversidade intra-lista@k (ILD):** distância por categoria (0 se igual, 1 se diferente). Numa lista com n ≥ 2 itens, ILD = pares de categorias diferentes / (n·(n−1)/2). O valor é a média sobre as listas com n ≥ 2, e fica n/a quando não há nenhuma (sempre em k = 1). Categorias distintas = média de categorias diferentes por lista, com lista vazia contando 0. Para cobertura, diversidade e concentração, um id repetido dentro da mesma lista conta uma vez (a primeira ocorrência).
- **Leitura esperada da ILD:** a categoria domina a distância do KNN, então a ILD tende a ficar perto de 0 enquanto a categoria da consulta tem itens suficientes no treino, e sobe quando eles acabam. É consequência do modelo, e o número não foi ajustado.
- **Gini@k:** Σᵢ(2i − n − 1)·cᵢ / (n·Σc) sobre as aparições de cada candidato nas listas@k, incluindo os que aparecem 0 vezes (contagens em ordem crescente). 0 = recomendações espalhadas por igual; perto de 1 = poucas recomendações dominam.
- **Top share@k:** fração das aparições que fica com os `ceil(0.1 × candidatos)` candidatos mais recomendados (os 10% do catálogo recomendável mais recomendados): aqui, os 7 candidatos mais recomendados (`top_products`).
- **Concentração excessiva:** top share ≥ 0.5, ou seja, 10% do catálogo recomendável fica com 50% ou mais das recomendações. O limiar foi fixado antes da medição.

## Como reproduzir

```bash
make eval                                   # = php bin/evaluate.php (sem Docker e sem banco)
php bin/evaluate.php --seed=42 --catalog=database/fixtures/evaluation-catalog.json
```

Mesma seed e mesmo catálogo produzem o mesmo split e as mesmas métricas; só a data muda.

## Como o split é feito

O catálogo é ordenado por id e embaralhado com `Random\Randomizer(new Random\Engine\Mt19937($seed))`. Os primeiros `max(1, round(n × proporção))` produtos formam o holdout, e o resto é o treino. O KNN (`KNNService` + `RubixNeighborFinder`) é treinado só com o treino. Cada produto do holdout é uma consulta: o harness pede o top-k direto à estratégia, sem passar pelo fallback de cold-start.

## Definição de relevância

Para uma consulta `q` do holdout, os relevantes são os produtos **do treino** com a mesma categoria de `q` (`same_category`). Não há rótulo de preferência de usuário no catálogo, então a categoria é o único rótulo disponível sem banco e sem eventos.

## Limitações

- **Circuito fechado:** a categoria também é feature do KNN, e pela distância a mesma categoria sempre fica mais perto. O número mede o quanto o modelo respeita a categoria, e não o gosto do usuário.
- **precision@k cai por falta de relevantes, não por erro:** quando a categoria da consulta tem menos de k produtos no treino, os acertos não chegam a k e a precisão fica abaixo de 1 mesmo com o ranking perfeito.
- **Catálogo fixo:** o catálogo versionado (`database/fixtures/evaluation-catalog.json`) foi gerado uma vez com a distribuição do `ProductSeeder`. Não é o catálogo do banco, que muda a cada seed.
- **Top share sensível ao tamanho da amostra:** com listas cheias há listas × k aparições. Quando esse número é pequeno perto do número de candidatos, o menor top share possível (cada aparição num produto diferente) é `top_products / aparições`, então um k pequeno infla o top share e o Gini. Aqui, em k = 1: 7/16 = 0.4375. Isso não torna o sinal inevitável: ele depende de quanto os mesmos produtos se repetem. O limiar não muda por causa disso: ele foi fixado antes da medição.
- **Fora deste relatório:** cold-start/fallback (Story 10.4).
