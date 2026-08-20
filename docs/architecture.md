# Architecture Decisions — ec-hub

Este documento registra as decisões arquiteturais reais do projeto, como ADRs (Architecture Decision Records): o problema, o que foi decidido, o que foi rejeitado e por quê. Não é um plano — é o histórico do que aconteceu, mantido junto do código.

> Este arquivo substitui uma versão anterior gerada na fase de planejamento (BMAD `create-architecture`), que descrevia Hyperf + Swoole + Redis como stack e nunca foi reconciliada com o que de fato foi implementado. O plano original de planejamento, se precisar dele, está em `_bmad-output/planning-artifacts/architecture.md` (fora do controle de versão) — é histórico de intenção, não estado atual.

## ADR-001 — Remoção do Hyperf em favor de PHP puro + PDO

**Status:** Aceito · **Data:** 2026-02-03

**Contexto:** durante a Story 2.1 (banco de dados de produtos), tentar instalar Hyperf Database + Router + View gerou conflitos reais de dependência:

```
Problem 1: Root composer.json requires hyperf/router, it could not be found
Problem 2: Root composer.json requires rubix/ml ^3.0, found rubix/ml[3.0.x-dev]
           but it does not match your minimum-stability
```

Hyperf Database + Router + View adicionava ~53 pacotes extras para um projeto de porte pequeno, com conflito direto de versão contra o rubix/ml.

**Decisão:** PHP puro + PDO nativo + Twig standalone + roteamento próprio, sem framework.

**Rejeitado:** manter Hyperf e forçar a resolução de dependências (route de menor esforço de curto prazo, mas carregava a complexidade e o conflito de versão para sempre).

**Consequência:** toda a stack de execução (roteamento, DB, views) é código próprio, pequeno o suficiente para caber neste repositório sem abstrações de framework por cima.

---

## ADR-002 — Migração da plataforma de PHP 7.4 para PHP 8.4

**Status:** Aceito · **Data:** 2026-08-18 (início da remediação de consistência)

**Contexto:** o projeto foi concebido como POC de "ML em PHP 7.4" — um argumento de venda deliberado ("stack legacy com arquitetura moderna"). Na prática, o `Dockerfile` fixava `php:7.4-cli`, mas o `vendor/` instalado já exigia PHP ≥ 8.1 (`twig/twig`) e ≥ 8.0 (`psr/log`), e a máquina de desenvolvimento rodava PHP 8.5 — três versões de PHP em jogo simultaneamente, nenhuma delas 7.4 de verdade.

**Decisão:** alvo passa a ser PHP 8.4, com `composer.json` pinando `config.platform.php = 8.4.0` para que a resolução de dependências seja idêntica em qualquer máquina, independente da versão local instalada.

**Rejeitado:**
- manter PHP 7.4 e forçar as dependências a uma versão compatível — teria exigido rebaixar Twig e outras libs, perdendo funcionalidade real por uma restrição que já não era respeitada na prática
- não pinar a plataforma — deixaria o mesmo tipo de drift silencioso que causou o problema original acontecer de novo

**Consequência:** o argumento de venda do projeto deixa de ser "PHP 7.4" e passa a ser "ML nativo em PHP com Clean Architecture" (ver README). `Dockerfile`, `composer.json` e os scripts de validação em `tests/docker/` foram todos atualizados juntos, na mesma leva de mudanças — não em momentos separados, para não reabrir o mesmo tipo de gap.

---

## ADR-003 — Rubix ML real, mantido fora do Domain

**Status:** Aceito · **Data:** 2026-08-20

**Contexto:** o `KNNService` original implementava one-hot encoding, normalização min-max e busca de vizinho mais próximo à mão — usando o Rubix ML só para uma chamada, `Euclidean::compute()`. O próprio docblock da classe admitia "manual KNN implementation", enquanto a proposta de valor do projeto é justamente "KNN usando Rubix ML". Ao mesmo tempo, o `Domain` importava `Rubix\ML\Kernels\Distance\Euclidean` diretamente, violando a regra que o próprio projeto propaga como diferencial ("Domain não depende de framework").

**Decisão:**
- reescrever o pipeline sobre a API real do Rubix: `Labeled` dataset (features = categoria + preço, labels = product_id) → `OneHotEncoder` → `MinMaxNormalizer` → `BallTree::nearest()` para a busca de k-NN
- criar `App\Domain\Recommendation\Service\NeighborFinderInterface` como porta do Domain, sem nenhum tipo do Rubix na assinatura
- `App\Infrastructure\ML\RubixNeighborFinder` é a única implementação e o único arquivo do projeto que importa `Rubix\ML\*`

**Rejeitado:**
- remover o Rubix e assumir o KNN manual como definitivo — mais leve (tira ~10 dependências transitivas: amphp, tensor, stemmer), mas descarta o diferencial declarado do projeto sem necessidade
- deixar como estava, só documentando "KNN manual usando um kernel do Rubix" — não resolve a violação de camada nem a lacuna entre o que o projeto promete e o que entrega

**Risco aceito:** `BallTree::nearest()` é a API correta para busca de vizinhos no Rubix (os estimadores públicos classificam, não devolvem vizinhos com distância), mas está marcada `@internal` no pacote — pode mudar entre versões menores sem aviso em SemVer. Mitigado pela própria porta: uma quebra fica confinada a `RubixNeighborFinder`, um arquivo, sem vazar para o `Domain`.

---

## ADR-004 — Contrato da API usa `product_id`, não `user_id`

**Status:** Aceito · **Data:** 2026-08-20

**Contexto:** `GET /api/recommendations` validava um parâmetro `user_id`, mas o valor sempre foi tratado como id de produto (`findById` em produtos, similaridade item-a-item) — não existe tabela de usuários nem sessão no projeto. O próprio código denunciava a inconsistência: o log de fallback para produto inexistente se chamava `cold_start_unknown_user`.

**Decisão:** renomear o parâmetro e toda a cadeia de validação/log para `product_id`, refletindo o que está de fato implementado hoje: recomendação item-a-item.

**Rejeitado:** implementar de verdade um contrato por usuário — exigiria tabela de usuários/sessões e histórico de produtos vistos, escopo do Epic 4 (roadmap), não desta correção.

**Consequência:** `user_id` volta como conceito quando (e se) a captura de eventos/sessão (Epic 4, roadmap) existir — nesse momento o contrato pode crescer para aceitar os dois, ou migrar; não antes.

---

## ADR-005 — Clean Architecture mantida; bounded contexts não implementados foram removidos, não documentados como se existissem

**Status:** Aceito · **Data:** 2026-08-20

**Contexto:** `app/Domain/` tinha `Cart/`, `User/` e `Metrics/` inteiros — cada um só com `.gitkeep` e uma interface de repositório sem nenhuma implementação nem consumidor. Total de 25 diretórios vazios em `app/`, incluindo `Infrastructure/Messaging/` e `Infrastructure/Monitoring/`, e uma camada `Shared/` inteira que virou vazia depois que o código morto dela foi removido.

**Decisão:** manter a estrutura de 4 camadas (é real e funciona), remover os andaimes vazios. A intenção de Cart/User/Metrics/Messaging/Monitoring fica registrada no Roadmap do README, não em diretórios `.gitkeep`.

**Rejeitado:** manter os diretórios vazios "para não perder a intenção arquitetural" — 25 diretórios sem um único arquivo real comunicam pior do que uma seção de texto explícita, e ainda enganam quem olha `find app -type d` achando que aquilo é código ativo.

**Consequência:** quando Cart, User ou Metrics forem implementados de verdade, a estrutura de camadas já existente (`Domain/<Contexto>/{Model,Repository,Service}`) é o padrão a seguir — só recriar quando houver código real para colocar lá.

---

## Decisões menores (mesma remediação, sem ADR dedicado)

| Decisão | Motivo | Rejeitado |
|---|---|---|
| `RecommendationSettings` (value object) injetado em vez de `require config/recommendation.php` dentro de `GenerateRecommendations`/`RuleBasedFallback` | Application/Domain não devem tocar o filesystem; achado no processo: `RuleBasedFallback` tinha os ranges de score *hardcoded*, duplicando (por coincidência) o que o config já dizia — duas fontes de verdade pro mesmo número | Continuar lendo o arquivo em cada construtor, só documentando a duplicação |
| Preço como `float` (decimal) em toda a cadeia API/DTO, formatação (`R$ x,xx`) só na view via filtro Twig `BRL` | Existiam 5 conversões entre `Money` (centavos) e a resposta HTTP, uma delas um regex tentando adivinhar se a string chegava em formato BR ou cru do banco | Manter o regex e só documentar seu comportamento |
| `ProductRepositoryInterface` devolve `Product`/`list<Product>` nos métodos de leitura, não array | A forma da tabela SQL vazava para o Domain; a entidade `Product` virava opcional, só montada ad hoc dentro dos casos de uso | Manter array e aceitar que `Product::fromArray()` seja chamado espalhado pelo código |
| `App\Shared\Container\Container` (PSR-11) substitui o array aninhado de closures que `config/bootstrap.php` devolvia | `psr/container` estava declarado no `composer.json` desde o início e nunca foi usado; o container real era acessado por chave mágica (`$container['services']['knn']($container)`), sem erro até a chave errada ser de fato acessada em runtime | Deixar como estava, só chamando isso de "resolver leve" na documentação |
| `App\Shared\Http\Router` + `ErrorHandler` extraídos de `public/index.php` | O entry point acumulava roteamento, resolução de dependências e 3 blocos de catch quase idênticos em ~190 linhas, nada testável sem HTTP real | Deixar como estava; o teste que precisava de servidor HTTP real (`RecommendationApiLiveHttpTest`) é sintoma direto disso |

---

## Convenções de nomenclatura

Ver [docs/CODING-STANDARDS.md](CODING-STANDARDS.md) para PSR-12 e as convenções específicas do projeto (onde formatar `Money`, por que o Domain não importa Rubix, por que repositório devolve entidade).

## Referências

- [ADR (Architecture Decision Records) — Michael Nygard](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
- [docs/STRUCTURE.md](STRUCTURE.md) — árvore de diretórios e fluxo de requisição, estado atual
- [docs/remediation-spec.md](remediation-spec.md) — a spec que motivou ADR-002 a ADR-005 e as decisões menores da tabela acima, com o levantamento completo de inconsistências encontradas
