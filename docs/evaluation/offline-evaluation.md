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
- **Fora deste relatório:** cobertura de catálogo, diversidade e concentração (Story 10.3) e cold-start/fallback (Story 10.4).
