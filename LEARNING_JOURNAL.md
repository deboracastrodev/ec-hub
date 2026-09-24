# Learning Journal — ec-hub

Registro dos desafios reais deste projeto: o que estava quebrado, como foi diagnosticado, o que resolveu e o que ainda não resolveu.

**Convenção de evidência.** Todo número aqui tem fonte citada. Pode ser:

- a spec de remediação ([docs/remediation-spec.md](docs/remediation-spec.md)), medida em `main` no commit `c7fc6e4`;
- a mensagem de um commit (linkado);
- um run do GitHub Actions (linkado);
- um comando reproduzível, executado ao escrever este journal (2026-09-23, em `8a429bf`, PHP 8.5.2 local).

Alguns itens citam artefatos de planejamento do BMAD em `_bmad-output/` (`stories.yaml`, `sprint-status.yaml`, `deferred-work.md`). Essa pasta está no `.gitignore`: são arquivos locais de trabalho e não aparecem no GitHub.

Quando o estado atual é pior do que o desejado, o texto diz isso.

---

## Desafio 1: Três Camadas de Verdade Divergentes

**Resumo.** Em agosto de 2026, o repositório tinha três versões diferentes de si mesmo: a dos docs, a dos arquivos de configuração e a do código que de fato rodava. A suíte de testes não passava (33 errors + 2 failures), o linter nunca tinha analisado `app/` e não havia CI. Um diagnóstico com 37 achados virou 42 stories rastreáveis (R1.1–R7.6), planejadas em 8 fases com gates. Ao fim da remediação havia suíte verde, 0 violações PSR-12 (em `a7ce456`) e um CI que antes não existia. Mas o primeiro run desse CI, no último commit da remediação, falhou, e o primeiro run 100% verde só veio em `51b202d`, já fora da remediação. Hoje a suíte continua verde, mas o CI de `main` está vermelho desde `70af8a3` e nada impede push direto. O bloco "O estado do CI, sem maquiagem", logo após a tabela Before / After, explica por quê.

### Problema

O projeto passou por três iterações de intenção técnica sem remover as anteriores. A primeira era um stack assíncrono (histórico: Hyperf/Swoole, nunca instalados). A segunda era ML com Rubix. A terceira era PHP puro com `php -S`. Cada iteração deixou uma camada de "verdade":

| Camada | O que dizia (em `c7fc6e4`) | Realidade |
|---|---|---|
| **1. O que os docs prometiam** | README (histórico): "Swoole HTTP Server — workers long-running com coroutines", "Redis Pub/Sub", dashboard `/metrics` e `/health`, "✅ 70% test coverage", "KNN funcional usando Rubix ML". O mapa de code review apontava para `app/Infrastructure/Messaging/RedisEventBus.php`. | Nenhuma dessas rotas existia. `RedisEventBus.php` não existia. A cobertura nunca tinha sido medida. O badge "Tests: not_run", três linhas abaixo, contradizia os 70%. |
| **2. O que os configs pressupunham** | `config/server.php` (histórico: config de Swoole), `config/autoload.php` (histórico: config de Hyperf), `.env.example` com 38 variáveis, 18 delas `SWOOLE_*`/`DB_POOL_*`/`REDIS_POOL_*`, `composer.json` com `"php": "^7.4"` e `Dockerfile` com `FROM php:7.4-cli` (histórico: alvo PHP 7.4). | Carregar `config/server.php` dava fatal error: Swoole não estava instalado. As 3 variáveis que o código lia de fato (`AUTH_REQUIRED`, `RECOMMENDATION_FALLBACK_STRATEGY`, `RECOMMENDATION_MIN_PRODUCTS_FOR_ML`) não estavam no `.env.example`. |
| **3. O que o código executava** | `Dockerfile` rodava `php -S`. O `KNNService` fazia KNN à mão e usava o Rubix só para `Euclidean::compute`. O `vendor/` tinha `twig/twig 3.23`, que exige PHP `>=8.1`. | O container (histórico: PHP 7.4) não conseguia rodar o `vendor/` instalado. O `.php-cs-fixer.php` chamava `setFinder()` duas vezes, e a segunda chamada descartava `app/`. |

Fonte: [docs/remediation-spec.md](docs/remediation-spec.md), seções 1 e WS1–WS7 (R1.1, R4.2, R5.2, R6.1, R6.4, R7.1).

A causa não foi invenção. O README foi escrito no Epic 1 descrevendo a **visão final** do produto, e os épicos seguintes nunca voltaram a ele. Faltava o rótulo "estado futuro" (R6.1).

### Baseline medido

Medido em `main` no commit [`c7fc6e4`](https://github.com/deboracastrodev/ec-hub/commit/c7fc6e4f9a99ac5100441a76b856bcdd65159610).

| Métrica | Valor | Fonte |
|---|---|---|
| Suíte PHPUnit | 134 testes: **33 errors, 2 failures**, 1 skipped | remediation-spec, "Estado medido (baseline)" |
| Causa dos 33 errors | PDO instanciado de forma eager no `require` do bootstrap. Até teste de template Twig exigia MySQL | remediation-spec, R2.4 |
| Violações PSR-12 | 11 arquivos em `app/` + 14 em `tests/` = 25 | remediation-spec, baseline e R7.2 |
| Violações PSR-12 com o finder corrigido | 27 de 46 arquivos | mensagem de [`a7ce456`](https://github.com/deboracastrodev/ec-hub/commit/a7ce4564666921961a09ae008bb4fee9b43f64ed) |
| PHP: manifesto / container / exigido pelo vendor / máquina local | `^7.4` / `7.4` / `>=8.1` / `8.5.2` (histórico): quatro versões em jogo | remediation-spec, R1.1 |
| CI | **inexistente** | remediation-spec, R7.3 |
| Diretórios em `app/` (contando o próprio `app/`) sem nenhum arquivo próprio além de `.gitkeep` | **25** (34 arquivos `.gitkeep`) | comando abaixo |
| Chamadas reais ao Rubix ML | **1**: `Euclidean::compute`, em `app/Domain/Recommendation/Service/KNNService.php:140` (import em `:9`), dentro do Domain | remediation-spec, R4.2/R4.3 |
| Recomendações ML por resposta | no máximo **4**, qualquer que fosse o `limit` (bug de `k = 5` fatiado antes do `limit`) | remediation-spec, R4.1 |

A spec registra 11 + 14 = 25 arquivos com violação, e o commit que corrigiu o finder mediu 27 de 46. Os números são diferentes porque vêm de medições diferentes. Registro os dois.

Para reproduzir os 25 diretórios e os 34 `.gitkeep` (na raiz do repositório, com histórico completo):

```bash
(
  git cat-file -e 'c7fc6e4^{commit}' || { echo "c7fc6e4 ausente: faça fetch do histórico completo"; exit 1; }
  D=$(mktemp -d)
  git archive -o "$D/app.tar" c7fc6e4 app && tar -x -C "$D" -f "$D/app.tar" || exit 1
  cd "$D"
  find app -type d | while read -r d; do
    [ -z "$(find "$d" -maxdepth 1 -type f ! -name .gitkeep)" ] && echo "$d"
  done | wc -l                        # 25
  find app -type d | while read -r d; do
    [ "$(find "$d" -maxdepth 1 -type f)" = "$d/.gitkeep" ] && echo "$d"
  done | wc -l                        # 23 (único arquivo é um .gitkeep)
  find app -name .gitkeep | wc -l     # 34
  rm -rf "$D"
)
```

A contagem inclui diretórios que só tinham subdiretórios (como o próprio `app/`): nenhum deles tinha um `.php` próprio. Contando só os diretórios cujo único arquivo é um `.gitkeep` (segundo `find` do script), o número cai para 23.

### Diagnóstico: de 37 achados para 42 stories

A análise ([docs/remediation-spec.md](docs/remediation-spec.md)) catalogou **37 achados**, cada um com evidência (arquivo:linha). O apêndice de rastreabilidade liga cada achado ao item que o resolve. A organização foi esta:

1. **Cinco decisões antes de qualquer código (D1–D5).** O alvo passou a ser PHP 8.4 com resolução pinada (D1). O Rubix passou a ser usado de verdade, fora do Domain (D2). Os docs passaram a descrever só o estado atual, com Roadmap rotulado (D3). O contrato da API passou a ser `product_id` (D4). A Clean Architecture ficou, e os andaimes vazios saíram (D5). Sem essas decisões, cada story rediscutiria o escopo.
2. **7 workstreams com prioridade.** P0 quebra execução, P1 é inconsistência estrutural, P2 é coerência e higiene.
   WS1 Plataforma (6) · WS2 Correções que quebram execução (6) · WS3 Contrato e domínio (7) · WS4 Rubix de verdade (4) · WS5 Poda (8) · WS6 Documentação (5) · WS7 Ferramentas e CI (6) = **42 stories**, de R1.1 a R7.6.
3. **8 fases sequenciais com gate de saída, no plano.** A spec pedia que nenhuma fase começasse sem o gate da anterior comprovado:
   - Fase 0: linter enxergando `app/`, em commit só de formatação.
   - Fase 1: plataforma.
   - Fase 2: suíte verde sem Docker.
   - Fase 3: CI.
   - Fase 4: contrato e domínio.
   - Fase 5: Rubix.
   - Fase 6: poda.
   - Fase 7: documentação.

   A ordem tem motivo. Formatação misturada com mudança de comportamento torna a revisão impossível. Refatorar sem baseline verde é refatorar às cegas. E documentar antes da poda documentaria o que estava prestes a sumir.

   **Na execução, a ordem não foi seguida à risca** (confira com `git log --reverse c7fc6e4..99e1f1d`). A Fase 0 (R7.1/R7.2, em [`a7ce456`](https://github.com/deboracastrodev/ec-hub/commit/a7ce4564666921961a09ae008bb4fee9b43f64ed)) rodou depois das Fases 1 e 2. O gate da Fase 3 ("pipeline verde em `main`") não foi comprovado antes da Fase 4: o CI só rodou pela primeira vez no último commit, e falhou (ver "O estado do CI"). Os gates de suíte verde (Fases 2 e 4) foram cumpridos localmente, segundo as mensagens dos commits: 106 testes sem banco e 134 com MySQL, 0/0, em [`6a849f9`](https://github.com/deboracastrodev/ec-hub/commit/6a849f9a42ace8a8d0e31b395e69150ac7c202ca), e `vendor/bin/phpunit -> 134/134` ou `137/137, 0 errors/failures` em cada commit de R3 e R4. O commit de formatação ficou isolado.
4. **Uma story por item, com checkpoints.** Cada `R<n>.<m>` virou uma story em `_bmad-output/specs/remediation-consistency/stories.yaml`, executada pelo BMAD Loop. Há 10 `spec_checkpoint` (primeira story de cada fase e itens com decisão transversal) e 8 `done_checkpoint` (contrato público, bootstrap, persistência, CI, docs de entrada). Itens só foram agrupados num commit quando tinham o mesmo objetivo e o mesmo aceite, e o agrupamento está no título do commit.

### Soluções

Cada linha aponta para o commit que resolveu o item. As linhas estão agrupadas por workstream, na ordem aproximada em que cada grupo foi executado, e não no plano de fases: por isso R7.1–R7.4 aparecem depois de R2 e antes de R3. Dentro de cada grupo, a ordem é numérica, não cronológica. Exemplos: R3.6 e R3.7 saíram antes de R3.3–R3.5, R5.8 antes de R5.6/R5.7, R6.4 e R7.5 antes de R6.1, e o follow-up de R1.6 veio depois de R2.6. A ordem exata está em `git log --reverse c7fc6e4..99e1f1d`.

| Item | Workstream | O que mudou | Commit |
|---|---|---|---|
| R1.1 | WS1 Plataforma | PHP 8.4 no `Dockerfile` e no `composer.json`, `config.platform.php = 8.4.0` | [`7ceea87`](https://github.com/deboracastrodev/ec-hub/commit/7ceea87d66b0132ccadcb18dc8898b49bba93aa9) |
| R1.2 | WS1 | `psr/log` declarado (vinha carona pelo Rubix) | [`be64bd7`](https://github.com/deboracastrodev/ec-hub/commit/be64bd7d99bf3822fcc6295b86269d2534c41af6) |
| R1.3 | WS1 | `composer.lock` versionado | [`d0e3b75`](https://github.com/deboracastrodev/ec-hub/commit/d0e3b75bf61971541860dae88e1b092f324a46f9) |
| R1.4 | WS1 | Dependências de produção atualizadas | [`2277db5`](https://github.com/deboracastrodev/ec-hub/commit/2277db5b1c056937925e67ca6e6c6fd9ff65b15d) |
| R1.5 + R2.3 | WS1 / WS2 | PHPUnit 8 → 12 e php-cs-fixer atual. `SetupScriptTest` passou a asserir `php bin/seed.php` em vez de `db:seed` | [`3a40a2e`](https://github.com/deboracastrodev/ec-hub/commit/3a40a2ee95d655b8864c1534e4eb941becd93ec4) |
| R1.6 | WS1 | `.phpunit.result.cache` fora do git (follow-up: `.phpunit.cache/` do PHPUnit 12) | [`39b8c93`](https://github.com/deboracastrodev/ec-hub/commit/39b8c935fada1bfb2071734b6d9675f294db222c), [`1b2153b`](https://github.com/deboracastrodev/ec-hub/commit/1b2153bb127be9499ab5ed201d756b38aeeb5a13) |
| R2.1 | WS2 Execução | Templates `error/400` e `error/500` + fallback em HTML puro se o Twig falhar | [`dc802a4`](https://github.com/deboracastrodev/ec-hub/commit/dc802a4912527837e59af2f600d0e8c7e617f325) |
| R2.2 | WS2 | Teste de cold-start alinhado ao fallback popular | [`17d4f4b`](https://github.com/deboracastrodev/ec-hub/commit/17d4f4bc13692176ec278ae428065031c03591cb) |
| R2.4 | WS2 | PDO lazy (factory memoizada), a causa dos 33 errors | [`1b42484`](https://github.com/deboracastrodev/ec-hub/commit/1b424843f67f14197d736f11f32f646ea7d43d59) |
| R2.5 + R2.6 | WS2 | Testes com MySQL em `#[Group('db')]`, skip limpo sem banco. Sem Docker: 106 testes, 0 failures, 0 errors. Com MySQL: 134, 0/0 | [`6a849f9`](https://github.com/deboracastrodev/ec-hub/commit/6a849f9a42ace8a8d0e31b395e69150ac7c202ca) |
| R7.1 + R7.2 | WS7 Ferramentas | Um único `Finder` para `app/` e `tests/`. 27 de 46 arquivos corrigidos em commit só de formatação | [`a7ce456`](https://github.com/deboracastrodev/ec-hub/commit/a7ce4564666921961a09ae008bb4fee9b43f64ed) |
| R7.3 + R7.4 | WS7 | `.github/workflows/ci.yml`. Cobertura medida em 70.79% de linhas (744/1051), threshold fixado em 65% | [`be30048`](https://github.com/deboracastrodev/ec-hub/commit/be3004865ca32ff61f2d538be731e4a0bd0b516c) |
| R3.1 | WS3 Contrato e domínio | Contrato `?product_id=` (antes `user_id` tratado como produto) | [`0a789b2`](https://github.com/deboracastrodev/ec-hub/commit/0a789b232d3f76950c6e8ef63d85b0e3daf71819) |
| R3.2 | WS3 | Uma única `RecommendationException`, sem código HTTP no domínio | [`6ce583e`](https://github.com/deboracastrodev/ec-hub/commit/6ce583eff91848fecb90ae510e6d0c4348978c0a) |
| R3.3 | WS3 | `Money` de ponta a ponta. O `normalizePrice()` por regex saiu | [`3290501`](https://github.com/deboracastrodev/ec-hub/commit/32905016d390835085d09b6a76a670679eb07797) |
| R3.4 | WS3 | Repositório do Domain devolve entidades, não arrays | [`4bd2ca0`](https://github.com/deboracastrodev/ec-hub/commit/4bd2ca0b26726899692c41dec9b3bb5b1a49d4c5) |
| R3.5 | WS3 | `RecommendationSettings` injetado. Nenhum `require` de config em `app/` | [`307eeb9`](https://github.com/deboracastrodev/ec-hub/commit/307eeb959c98645d59849a44a36287fb3f165e74) |
| R3.6 | WS3 | `limit` em `1..50`. `?limit=1` devolve 1 (antes devolvia 5) | [`2427974`](https://github.com/deboracastrodev/ec-hub/commit/24279743af7ce5ee85a63e5df20772ff47dc125b) |
| R3.7 | WS3 | `CategoryService` movido para dentro das camadas | [`2619a99`](https://github.com/deboracastrodev/ec-hub/commit/2619a9971c78f3af08b60b29cc3d7e3c3a9d32f8) |
| R4.1 + R4.2 + R4.3 | WS4 Rubix | Teto de 4 recomendações corrigido (`k = limit + 1`). Pipeline real do Rubix (`OneHotEncoder` → `MinMaxNormalizer` → `BallTree`) em `Infrastructure/ML/RubixNeighborFinder`, atrás da porta `NeighborFinderInterface` | [`4a3f37d`](https://github.com/deboracastrodev/ec-hub/commit/4a3f37d39dcd03f23abe56a9c275d01d4a24f76d) |
| **R4.4** | WS4 | **Não resolvido.** Marcado `superseded-by-10-1` no sprint status. O modelo ainda é treinado a cada requisição | — |
| R5.1 + R5.2 + R5.3 | WS5 Poda | 4 classes mortas, 3 configs órfãs (histórico: Hyperf/Swoole) e os andaimes `Cart`/`User`/`Metrics`/`Messaging`/`Monitoring` removidos. Suíte: 137/137 | [`b65eb65`](https://github.com/deboracastrodev/ec-hub/commit/b65eb65113da0cd88a7e924e2e1412b86d495cd0) |
| R5.4 | WS5 | `MetaTagsService` com consumidor real: OG tags e JSON-LD na página de produto | [`d8261c6`](https://github.com/deboracastrodev/ec-hub/commit/d8261c67a585e89df2a50c034035f9e3a487c9be) |
| R5.5 | WS5 | Redis removido enquanto não tinha consumidor | [`bb887c0`](https://github.com/deboracastrodev/ec-hub/commit/bb887c05c8531e51dbba60d26565706fc39ad7b6) |
| R5.6 + R5.7 | WS5 | `Router` e `ErrorHandler` extraídos de `public/index.php`. Container PSR-11 por FQCN | [`59f0fcf`](https://github.com/deboracastrodev/ec-hub/commit/59f0fcfc6bb7f9834ecbf2b3bbe55f92e9f0e5db) |
| R5.8 | WS5 | `.env` carregado de fato via `phpdotenv` | [`c6574a0`](https://github.com/deboracastrodev/ec-hub/commit/c6574a0f0d240cf4d2e413eeb4cf50dc89bffd83) |
| (achado extra) | WS5 | `.gitkeep` restantes em `app/` removidos, achados no checklist final | [`5cb8821`](https://github.com/deboracastrodev/ec-hub/commit/5cb8821d6591db0cfae76e61d52c748eb66c6d12) |
| R6.1 | WS6 Documentação | README reescrito: "o que existe hoje" + Roadmap rotulado. O mesmo commit elevou o threshold de cobertura do CI de 65% para 70% | [`abe3d6f`](https://github.com/deboracastrodev/ec-hub/commit/abe3d6ff5650e7d5eb7e2511cfabd5a70afeb35b) |
| R6.2 + R6.3 + R6.5 | WS6 | `STRUCTURE.md`, ADRs D1–D5 em `architecture.md`, regras novas em `CODING-STANDARDS.md` | [`13c4380`](https://github.com/deboracastrodev/ec-hub/commit/13c4380bd9b7b33c315f4a221c408ddc3ef9fb37) |
| R6.4 | WS6 | `.env.example` com as variáveis que o código lê, e só com elas | [`ee00d18`](https://github.com/deboracastrodev/ec-hub/commit/ee00d1873ecf12ed7fa9ce8bc4edbd9708574239) |
| R7.5 | WS7 | Makefile funciona sem Docker para teste unitário e lint | [`07d5319`](https://github.com/deboracastrodev/ec-hub/commit/07d531988d3f890e3b5d21ccf5ede7997b2ba645) |
| R7.6 | WS7 | PHPStan nível 5. 18 erros na primeira rodada, todos corrigidos no código, sem arquivo de baseline nem `ignoreErrors` (`phpstan.neon`). 11 deles estavam numa migration sem consumidor que estendia uma classe inexistente (histórico: Hyperf), e ela foi deletada | [`99e1f1d`](https://github.com/deboracastrodev/ec-hub/commit/99e1f1d4619e9a6cf4a73c5c44c94fe9b4982f4b) |

### Before / After

| Métrica | Before (`c7fc6e4`) | Na remediação (commit da medição) | Hoje (2026-09-23) | Fonte do "hoje" |
|---|---|---|---|---|
| Suíte sem banco | não havia separação: a suíte inteira exigia MySQL (ver linha abaixo) | 106 testes, 0 errors, 0 failures ([`6a849f9`](https://github.com/deboracastrodev/ec-hub/commit/6a849f9a42ace8a8d0e31b395e69150ac7c202ca)) | **283 testes, 0 errors, 0 failures** (4 deprecations, 57 PHPUnit notices) | `vendor/bin/phpunit --exclude-group db --exclude-group redis`, local em `8a429bf`, PHP 8.5.2 |
| Suíte completa (MySQL) | 134 testes: 33 errors, 2 failures, 1 skipped | 137/137 ([`b65eb65`](https://github.com/deboracastrodev/ec-hub/commit/b65eb65113da0cd88a7e924e2e1412b86d495cd0)) | 311 testes, 0 errors, 0 failures, 1 skipped | CI, [run 35945753408](https://github.com/deboracastrodev/ec-hub/actions/runs/35945753408) |
| Cobertura de linhas | nunca medida ("70%" no README sem fonte) | 70.79% (744/1051), threshold 65% ([`be30048`](https://github.com/deboracastrodev/ec-hub/commit/be3004865ca32ff61f2d538be731e4a0bd0b516c)) | 80.68% (1274/1579), threshold 70% (elevado em [`abe3d6f`](https://github.com/deboracastrodev/ec-hub/commit/abe3d6ff5650e7d5eb7e2511cfabd5a70afeb35b)) | CI, [run 35945753408](https://github.com/deboracastrodev/ec-hub/actions/runs/35945753408) |
| Violações PSR-12 | 25 arquivos (spec) / 27 de 46 (finder corrigido) | **0** ([`a7ce456`](https://github.com/deboracastrodev/ec-hub/commit/a7ce4564666921961a09ae008bb4fee9b43f64ed)) | **3 de 108 arquivos**, todos em `tests/` | `vendor/bin/php-cs-fixer fix --dry-run`, local em `8a429bf` |
| PHP manifesto / container / CI | `^7.4` / `7.4` (histórico) / sem CI | `^8.4` / `8.4`, pinado em `8.4.0` / `8.4` | igual | `composer.json`, `Dockerfile`, `php-version: '8.4'` em `.github/workflows/ci.yml` |
| `.gitkeep` em `app/` | 34 (25 diretórios sem arquivo próprio além de `.gitkeep`) | 0 | 0; todo diretório de `app/` tem ao menos um `.php` na sua árvore | comandos no bloco abaixo da tabela |
| Rubix no Domain | `use Rubix\...` no `KNNService` (Domain) | 0 imports | 0 imports no Domain; `use Rubix` só em `app/Infrastructure/ML/RubixNeighborFinder.php` (3 menções em docblocks do Domain explicam a porta) | `grep -rn "use Rubix" app` (imports); `grep -rn Rubix app/Domain` (as 3 menções em docblock) |
| CI | inexistente | 3 jobs em [`be30048`](https://github.com/deboracastrodev/ec-hub/commit/be3004865ca32ff61f2d538be731e4a0bd0b516c) | 6 jobs, **vermelho** (ver abaixo) | [run 35945753408](https://github.com/deboracastrodev/ec-hub/actions/runs/35945753408) |

Comandos da linha `.gitkeep` (o primeiro imprime 0, o segundo não imprime nada):

```bash
find app -name .gitkeep | wc -l
find app -type d | while read -r d; do
  [ -n "$(find "$d" -name '*.php' -print -quit)" ] || echo "$d"
done
```

Os números de "hoje" vêm de dois pontos. As medições locais foram feitas em `8a429bf` com PHP 8.5.2, e não com o 8.4 pinado, então deprecations e notices podem variar no container. Os números de CI vêm de `6083e69`, duas stories de documentação antes. A diferença entre 283 e 311 testes é de grupo: a execução local exclui `db` e `redis`, enquanto o job com MySQL exclui só `redis`. O crescimento em relação à remediação (106 → 283, 137 → 311, cobertura 70.79% → 80.68%) vem sobretudo das features dos épicos seguintes, não da remediação em si.

**O estado do CI, sem maquiagem:**

- **O CI não rodou verde no fim da remediação.** A remediação foi enviada em lote, e o primeiro run do CI foi no último commit, [`99e1f1d`](https://github.com/deboracastrodev/ec-hub/commit/99e1f1d4619e9a6cf4a73c5c44c94fe9b4982f4b). Esse run falhou no job de estilo, no passo `composer validate --strict` ([run 32388123023](https://github.com/deboracastrodev/ec-hub/actions/runs/32388123023)). Os jobs de teste passaram.
- **O primeiro run 100% verde** foi em [`51b202d`](https://github.com/deboracastrodev/ec-hub/commit/51b202d48895a877df8e6f32694ccf8750161119) ([run 32504611752](https://github.com/deboracastrodev/ec-hub/actions/runs/32504611752)).
- **O CI de `main` está vermelho desde [`70af8a3`](https://github.com/deboracastrodev/ec-hub/commit/70af8a34d2d28a8e73b2a0e20640fc29b0d9ea4b)** ([run 32516138381](https://github.com/deboracastrodev/ec-hub/actions/runs/32516138381), que falhou no PHPStan: `is_array()` sempre verdadeiro em `GenerateRecommendations.php` e `RecommendationController.php`, um erro diferente do que o PHPStan acusa hoje). Nenhum run posterior em `main` ficou verde (`gh run list --branch main --workflow ci.yml --limit 40`).
- **Hoje** ([run 35945753408](https://github.com/deboracastrodev/ec-hub/actions/runs/35945753408), em [`6083e69`](https://github.com/deboracastrodev/ec-hub/commit/6083e69a7d1eb741e9ee9199bd507029cf6da18b)):
  - verdes: testes sem banco, testes com MySQL + cobertura, E2E (Playwright) e performance;
  - vermelho **Composer + style**: PSR-12 em `tests/Unit/Application/Event/TrackProductInteractionTest.php`, `tests/Integration/FullStack/FullStackHttpIntegrationTest.php` e `tests/Support/RequiresTestDatabase.php`;
  - vermelho **Docker Compose integration**: `required variable SESSION_COOKIE_SECRET is missing a value`.
  - escondido atrás do PSR-12: o passo de PHPStan nem roda, porque vem depois do passo que falhou. Localmente, `vendor/bin/phpstan analyse --memory-limit=512M` acusa 1 erro (`Predis\ClientInterface::transaction()` indefinido, em `app/Infrastructure/Redis/RedisEventHistoryRepository.php:36`).
- **`main` não tem branch protection.** `gh api repos/deboracastrodev/ec-hub/branches/main/protection` responde `404 Branch not protected`, consultado com um token que tem `permissions.admin = true` no repositório (o 404 não é falta de permissão). O CI **detecta** regressão, mas não **bloqueia** push.

Nota de contexto: o Redis removido em R5.5 voltou em [`51b202d`](https://github.com/deboracastrodev/ec-hub/commit/51b202d48895a877df8e6f32694ccf8750161119), agora com consumidor real (captura de eventos do Epic 4). A regra de R5.5 era "sem consumidor, sai", não "Redis nunca".

### O que aprendi

- **Documentação de estado futuro sem rótulo vira mentira com o tempo.** Ninguém inventou o Swoole do README (histórico, removido em R6.1): ele era a visão do Epic 1. O erro foi não marcar o que ainda não existia. Hoje o README separa "o que existe" de "Roadmap".
- **Uma ferramenta cega é pior do que nenhuma, porque dá falsa confiança.** O `setFinder()` duplicado fez o linter passar desde o início do projeto sem nunca olhar `app/`. Um `cs-check` sem erros não dizia nada sobre `app/`. A primeira pergunta sobre qualquer guarda passou a ser: ela está mesmo olhando para o que eu acho?
- **Medir antes de prometer.** Os "70% de cobertura" nunca tinham sido medidos. O número real (70.79%) calhou de ser parecido, mas o threshold do CI saiu do valor medido, não do desejado.
- **Uma causa pode explicar muitos sintomas.** 33 dos 35 problemas da suíte vinham de uma linha: o PDO eager no bootstrap. O diagnóstico por achado com evidência (arquivo:linha) levou a uma única correção ([`1b42484`](https://github.com/deboracastrodev/ec-hub/commit/1b424843f67f14197d736f11f32f646ea7d43d59)) em vez de 33 remendos teste a teste.
- **Ordem com gate é o que torna uma remediação grande revisável.** Formatação isolada em commit próprio, suíte verde antes de refatorar e docs por último: sem isso, as 42 stories viram um único diff impossível de revisar. O gate que falhou foi justamente o que dependia de uma máquina externa: "pipeline verde em `main`" nunca foi conferido antes de seguir, porque os commits foram enviados em lote.
- **Guarda sem enforcement vira alarme ignorado.** O CI existe e acusa as regressões. Mesmo assim, `main` ficou vermelho de 2026-08-21 (`70af8a3`) até pelo menos o último run consultado, em 2026-09-23 às 23:05 no horário de Brasília (2026-09-24 02:05 UTC), porque nada impede push com CI vermelho. Detectar não basta: o gate precisa bloquear.

### O que ainda não está resolvido

| Pendência | Estado | Evidência |
|---|---|---|
| CI de `main` vermelho: PSR-12 em 3 arquivos de teste | aberto (DW-5 em `deferred-work.md`) | `vendor/bin/php-cs-fixer fix --dry-run`, 3 de 108 |
| CI de `main` vermelho: job Docker Compose sem `SESSION_COOKIE_SECRET` | aberto | [run 35945753408](https://github.com/deboracastrodev/ec-hub/actions/runs/35945753408) |
| PHPStan nível 5 com 1 erro (mascarado no CI pela falha de PSR-12) | aberto (DW-5 em `deferred-work.md`) | `vendor/bin/phpstan analyse --memory-limit=512M` |
| Branch protection em `main` (exigir CI verde para merge) | aberto | API responde `404 Branch not protected` |
| R4.4: modelo KNN treinado a cada requisição | não feito, `superseded-by-10-1`: coberto pela story 10-1 (cache do modelo treinado), ainda em backlog | `sprint-status.yaml` |
| DoD "CI verde em `main`, barrando estilo, teste e documentação" | cumprido só em parte: o CI roda em push e PR e acusa a falha, mas sem branch protection não impede merge nem push direto | seção "O estado do CI" acima |

---

## Desafio 2: Usar o Rubix ML de Verdade

**Resumo.** O projeto se vendia como "KNN usando Rubix ML", mas o `KNNService` fazia one-hot, min-max e busca de vizinhos à mão e só chamava o Rubix para `Euclidean::compute()`. Esse único uso ainda ficava dentro do Domain, e um bug limitava o ML a 4 recomendações por resposta, qualquer que fosse o `limit`. A reescrita de [`4a3f37d`](https://github.com/deboracastrodev/ec-hub/commit/4a3f37d39dcd03f23abe56a9c275d01d4a24f76d) (R4.1 + R4.2 + R4.3) passou o pipeline para a API real do Rubix (`Labeled` → `OneHotEncoder` → `MinMaxNormalizer` → `BallTree::nearest()`), atrás de uma porta do Domain, e corrigiu o teto de 4. O plano previa guardar as saídas antigas como fixtures antes da reescrita. **Isso não foi feito**: a comparação before/after abaixo foi reconstruída depois, em 2026-09-23, com um script reproduzível. No mesmo catálogo determinístico, o conjunto de recomendações ficou igual em 118 de 120 alvos, e as 2 diferenças (e as 25 de ordem) são empates de distância (diferença menor que 1e-9). O que continua em aberto: o modelo ainda é treinado a cada requisição (R4.4) e a busca depende de um método marcado `@internal` no Rubix.

### Problema

Estado imediatamente antes da reescrita, no pai de `4a3f37d` ([`4bd2ca0`](https://github.com/deboracastrodev/ec-hub/commit/4bd2ca0b26726899692c41dec9b3bb5b1a49d4c5)). Para ler o arquivo: `git show 4a3f37d^:app/Domain/Recommendation/Service/KNNService.php`.

| Problema | Evidência |
|---|---|
| **R4.2: o ML era escrito à mão.** One-hot em `extractSingleProductFeatures()`, min-max em `normalizeFeatures()`/`normalizeSingleFeature()`, busca por força bruta em `calculateDistances()` + `asort()` + `array_slice()`. O Rubix aparecia uma vez: `Euclidean::compute()`, em `:141`. O docblock da classe dizia "Uses manual KNN implementation for PHP 7.4 compatibility" (histórico: alvo PHP 7.4, abandonado no ADR-002). | arquivo acima; mensagem de [`4a3f37d`](https://github.com/deboracastrodev/ec-hub/commit/4a3f37d39dcd03f23abe56a9c275d01d4a24f76d) |
| **O peso da dependência não se pagava.** Para uma chamada de distância euclidiana, o `rubix/ml` 2.5.5 traz **17 pacotes distintos** na árvore de dependências (7 do `amphp`, `rubix/tensor`, `wamania/php-stemmer` e o `joomla/string` que ele puxa, `andrewdalpino/okbloomer`, `psr/log`, `symfony/deprecation-contracts` e 4 polyfills da Symfony). Desses, 13 não têm outro consumidor no projeto (contagem feita lendo a saída do `composer why` de cada pacote, segundo comando abaixo); os outros 4 (`psr/log`, `symfony/deprecation-contracts`, `symfony/polyfill-mbstring`, `symfony/polyfill-php80`) também são exigidos pelo próprio app, pelo Twig, pelo phpdotenv ou por ferramentas de dev. | comandos abaixo da tabela |
| **R4.3: o Domain importava o Rubix.** `use Rubix\ML\Kernels\Distance\Euclidean;` em `:10`, e o construtor tipava `?Euclidean`. Isso violava a regra "Domain não depende de framework" que o projeto apresenta como diferencial. | `git grep -ln "use Rubix" 4a3f37d^ -- app` → só o `KNNService` do Domain |
| **R4.1: o ML nunca devolvia mais de 4 itens.** `recommend()` cortava os vizinhos em `$this->k` (fixo em 5) **antes** de aplicar o `limit` e depois tirava o próprio produto-alvo, que é vizinho de si mesmo com distância 0. Sobravam no máximo 4. O resto de uma resposta com `limit` alto vinha do fallback. | `:102` do arquivo acima; mensagem de [`4a3f37d`](https://github.com/deboracastrodev/ec-hub/commit/4a3f37d39dcd03f23abe56a9c275d01d4a24f76d) |

Contagem de dependências (executada em 2026-09-23, Composer 2.9.5):

```bash
composer show --tree rubix/ml | tail -n +2 \
  | grep -oE '[a-z0-9][a-z0-9_.-]*/[a-z0-9_.-]+' | sort -u | wc -l          # 17
for p in $(composer show --tree rubix/ml | tail -n +2 \
  | grep -oE '[a-z0-9][a-z0-9_.-]*/[a-z0-9_.-]+' | sort -u); do
  echo "$p: $(composer why "$p" | awk '{print $1}' | sort -u | tr '\n' ' ')"
done                                                                        # quem exige cada um
```

O ADR-003 fala em "~10 dependências transitivas: amphp, tensor, stemmer". Os três nomes estão na árvore. O número medido é 17 (13 exclusivos da cadeia do Rubix). O ADR não foi editado; a divergência fica registrada aqui.

### Solução

Uma reescrita só, em [`4a3f37d`](https://github.com/deboracastrodev/ec-hub/commit/4a3f37d39dcd03f23abe56a9c275d01d4a24f76d), para os três achados, com o pipeline do ADR-003 ([docs/architecture.md](docs/architecture.md)):

| Etapa | Antes (à mão, no Domain) | Depois (Rubix, na Infrastructure) |
|---|---|---|
| Dataset | arrays paralelos `$trainingSamples` / `$productsIndex` | `Labeled`: features = categoria + preço, labels = `product_id` |
| Categoria | `extractSingleProductFeatures()` | `OneHotEncoder` |
| Escala | `normalizeFeatures()` / `normalizeSingleFeature()` | `MinMaxNormalizer` |
| Busca | `calculateDistances()` + `asort()` + `array_slice()` | `BallTree(30, new Euclidean())` com `grow()` e `nearest()` |

O núcleo de [`RubixNeighborFinder::train()`](app/Infrastructure/ML/RubixNeighborFinder.php):

```php
$this->categoryEncoder = new OneHotEncoder();
$this->normalizer = new MinMaxNormalizer();

$dataset = new Labeled($samples, $labels);
$dataset->apply($this->categoryEncoder)
    ->apply($this->normalizer);

$this->tree->grow($dataset);
```

Os transformers são recriados a cada `train()`. Reaproveitar um encoder já ajustado escalaria um catálogo novo com o mínimo, o máximo e as categorias do antigo. Na consulta, o produto-alvo passa pelos mesmos transformers já ajustados, e `nearest()` devolve os labels (ids) e as distâncias.

O R4.1 foi corrigido na raiz: o [`KNNService`](app/Domain/Recommendation/Service/KNNService.php) pede `limit + 1` vizinhos, e não um `k` fixo. O `+1` abre espaço para descartar o próprio alvo e ainda entregar `limit` itens. O score `100 / (1 + d)` e o texto da explicação continuam no Domain: são regra de negócio e não têm equivalente no Rubix.

### Domain livre do Rubix

O Domain conversa com uma porta, [`NeighborFinderInterface`](app/Domain/Recommendation/Service/NeighborFinderInterface.php), com três métodos: `train(array $products)`, `isTrained()` e `nearest(Product $target, int $k)`, que devolve `list<array{product, distance}>`. Nenhum tipo do Rubix aparece na assinatura. A única implementação é `App\Infrastructure\ML\RubixNeighborFinder`, injetada em `config/bootstrap.php`.

Para conferir:

```bash
grep -rln "use Rubix" app     # app/Infrastructure/ML/RubixNeighborFinder.php (só ele)
grep -rn Rubix app/Domain     # 3 linhas, todas em docblock explicando a porta
```

A porta também permite testar o `KNNService` com um finder falso, sem Rubix (`FakeNeighborFinder`, em `tests/Unit/Domain/Recommendation/KNNServiceDeterministicTest.php`).

### Risco aceito: `@internal`

A busca usa `BallTree::nearest()`. No `rubix/ml` 2.5.5 instalado, `nearest()`, `grow()` e a interface `Spatial` que a `BallTree` implementa estão anotados `@internal` (`vendor/rubix/ml/src/Graph/Trees/Spatial.php`). Uma versão menor pode mudá-los sem aviso de SemVer. Não há alternativa pública: os estimadores do Rubix (`KNearestNeighbors`, `KDNeighbors`) classificam ou regridem, mas não devolvem os vizinhos com distância.

O risco foi aceito no ADR-003 e contido pela porta: se o método quebrar, o conserto fica em um arquivo, `RubixNeighborFinder.php`, sem tocar no Domain. O `composer.lock` versionado (R1.3) impede que uma atualização entre sem alguém rodar `composer update`. Mas a restrição no `composer.json` é `"rubix/ml": "^2.5"`: um `composer update` rotineiro pode trazer uma 2.6 que mude esses métodos. Quem atualizar precisa rodar a suíte antes de commitar o lock.

### Fallback: quando o ML não responde

O ML não responde sozinho. O caso de uso `GenerateRecommendations` decide antes quem responde, e a tabela completa está em [docs/ML.md §4](docs/ML.md#4-ml-ou-fallback-quem-responde). Em resumo:

| Quando | Quem responde |
|---|---|
| `product_id` não existe no catálogo (cold start) | populares (`source=popular`) |
| catálogo com menos de `min_products_for_ml` produtos (padrão 5) | fallback com a estratégia configurada (`hybrid` por padrão) |
| KNN devolve menos que `limit` | tenta completar com o fallback, sem duplicatas |
| exceção no ML | fallback, com log `ml_error` |
| caminho normal | `KNNService` (`source=ml`) |

Depois do R4.1, o caso "KNN devolve menos que `limit`" só acontece quando o índice tem `limit` produtos ou menos, ou seja, quando o catálogo inteiro cabe na resposta (o índice é treinado com até 1.000 produtos). Antes, ele acontecia em toda resposta com `limit` maior que 4: nas palavras da mensagem de `4a3f37d`, "a maior parte de uma resposta com limit alto era rule-based, anunciada como ML".

### Before / After

**As fixtures planejadas não existem.** A spec de remediação ([docs/remediation-spec.md](docs/remediation-spec.md), seção 6, risco "Reescrita do Rubix muda os resultados") mandava capturar as saídas do código manual como fixtures **antes** da reescrita e comparar depois. A régua era: diferença de ordem em empate é aceitável, diferença no conjunto não é. Essas fixtures não foram capturadas nem versionadas. O commit `4a3f37d` só ajusta poucas linhas de 3 testes, e não há arquivo de fixture no histórico.

A comparação abaixo substitui as fixtures. Ela foi reconstruída em 2026-09-23. O código medido (`KNNService` e `RubixNeighborFinder`) é o de `d909fc9`, que esta story não altera; o script entrou no mesmo commit desta seção. A execução foi com PHP 8.5.2 local, e deu saída idêntica no PHP 8.4.25 da imagem do container (`docker run` com o repositório montado, já que o container não enxerga o `.git`). O script [`bin/compare-knn-rewrite.php`](bin/compare-knn-rewrite.php) lê o `KNNService` manual de `4a3f37d^` via `git show`, renomeia a classe e roda as duas versões sobre o mesmo catálogo determinístico (`KnnBenchmark::syntheticCatalog()`, o mesmo do benchmark de performance), com cada produto como alvo:

```bash
php bin/compare-knn-rewrite.php   # precisa do histórico completo (senão: git fetch --unshallow) e do vendor/ com dev deps
```

| Produtos | Alvos | Mesmo conjunto (limit=4) | Mesma ordem (limit=4) | Maior diferença de score | Máx. itens ML com limit=10 (antes → depois) |
|---|---|---|---|---|---|
| 20 | 20 | 20/20 | 19/20 | 0 | 4 → 10 |
| 100 | 100 | 98/100 | 74/100 | 2.84e-14 | 4 → 10 |

Como ler:

- **Conjunto, ordem e score** são comparados com `limit=4`, o máximo que a versão manual conseguia entregar. Com um `limit` maior, a comparação mediria o bug R4.1, e não a troca de implementação.
- **Maior diferença de score** entre itens presentes nas duas respostas do mesmo alvo: 2.84e-14 é ruído de ponto flutuante. Os scores são os mesmos.
- **Máx. itens ML com limit=10:** a versão manual nunca passou de 4 (R4.1). A atual chega a 10 (a coluna mostra o máximo por catálogo, não o mínimo). A mensagem de `4a3f37d` registra o mesmo efeito no app rodando: `?limit=20` → 20 itens `source=ml`, antes ~4.

**As divergências.** O script lista cada alvo com resultado diferente e as distâncias em torno do corte. As 27 divergências (1 em 20 produtos, 26 em 100) são empates: as duas respostas têm, posição a posição, a mesma distância ao alvo (tolerância de 1e-9), cada uma medida pela própria implementação (a distância sai do score, `100 / (1 + d)`). **Não há nenhuma divergência que não seja empate.** Se houvesse, o script sairia com código 2. A regra de empate está em `tests/Support/KnnTieCheck.php`, com teste próprio (`tests/Unit/Tooling/KnnTieCheckTest.php`) para os casos que devem falhar. O teste `tests/Integration/Tooling/CompareKnnRewriteScriptTest.php` fixa os números desta tabela e confere que o journal publica as mesmas linhas que o script imprime.

O empate vem do próprio catálogo sintético. O preço é `10 + ((id × 7919) mod 499000) / 100`: até o id 63 isso é `10 + 79,19 × id`, e do 64 ao 100 é a mesma reta deslocada por uma volta do módulo. A categoria roda de 8 em 8 (`id % 8`). Então, para um alvo, os produtos da mesma categoria com id `alvo − 8` e `alvo + 8` (ou ±16), quando estão no mesmo trecho da reta, ficam à mesma diferença de preço na aritmética exata. Em ponto flutuante, depois da normalização, as duas distâncias podem diferir no último dígito (daí a tolerância de 1e-9). Cada versão desempata de um jeito: a manual pela ordem que o `asort()` dá a esses valores quase iguais, a atual pela ordem de visita da `BallTree`.

- **25 divergências de ordem:** mesmos 4 produtos, com dois vizinhos equidistantes trocados de posição. Exemplo (20 produtos, alvo 12): antes `[4, 20, 11, 13]`, depois `[20, 4, 11, 13]`. Os ids 4 e 20 estão ambos a 0,421052632. A régua aceita esse caso.
- **2 divergências de conjunto** (100 produtos, alvos 45 e 46). Alvo 45: antes `[53, 37, 93, 61]`, depois `[53, 37, 93, 29]`. Os ids 29 e 61 (alvo ∓ 16) estão ambos a 0,258010389. O 4º e o 5º vizinhos estão empatados, então "os 4 mais próximos" não é um conjunto único, e cada versão escolheu um lado. O alvo 46 repete o padrão com 30 e 62. Pela letra da régua, divergência de conjunto não é aceitável. Pela causa, é um empate exatamente no corte, e não uma mudança de resultado: não existe resposta certa entre dois produtos à mesma distância.

A comparação usa um catálogo sintético, não o catálogo do seed. Ela prova que as duas implementações calculam a mesma coisa, e não que as recomendações do app rodando ficaram iguais às de antes.

### O que aprendi

- **Mitigação planejada não é mitigação executada.** O risco "a reescrita muda os resultados" tinha plano (fixtures antes de mexer), mas o gate da Fase 5 conferia `grep Rubix app/Domain` vazio e `limit` respeitado, não o resultado. A captura das fixtures não estava em nenhum aceite, então não aconteceu. Se um passo de mitigação importa, ele precisa estar no aceite ou no gate.
- **Dá para reconstruir uma evidência que faltou, se o histórico estiver no git.** A versão antiga estava inteira em `4a3f37d^`. Um script que roda as duas implementações lado a lado prova mais do que um arquivo de fixture congelado: qualquer pessoa reproduz, e outro tamanho de catálogo é só mudar a lista `[20, 100]` no script.
- **Empate de distância é o caso normal, não o raro.** 27 de 120 alvos divergiram, todos por empate. Com features discretas (categoria) e preços regulares, vizinhos equidistantes aparecem o tempo todo. Uma régua de comparação para k-NN precisa dizer o que fazer com empate no corte, não só com empate na ordem.
- **Usar a biblioteca de verdade apareceu como correção de bug.** O teto de 4 existia porque o código manual tinha seu próprio `k` interno, desacoplado do `limit`. Ao delegar a busca ao `BallTree` e pedir `limit + 1`, o bug sumiu junto com o código manual.
- **A porta é o que torna aceitável depender de API `@internal`.** Sem ela, a escolha seria entre um método instável espalhado pelo Domain ou continuar com o KNN à mão. Com ela, o risco tem tamanho conhecido: um arquivo.

### O que ainda não está resolvido

| Pendência | Estado | Evidência |
|---|---|---|
| R4.4: modelo treinado a cada requisição | não feito; já registrado na tabela de pendências do Desafio 1 (`superseded-by-10-1`, story 10-1 em backlog) | `sprint-status.yaml` |
| `BallTree::nearest()` / `grow()` continuam `@internal` no `rubix/ml` 2.5.5 | risco aceito (ADR-003), não há teste unitário dedicado ao `RubixNeighborFinder`; uma quebra apareceria nos testes que usam o finder real, como o `KNNServiceTest` (roda no job sem banco do CI) e o `KnnBenchmarkTest` (job Performance) | `vendor/rubix/ml/src/Graph/Trees/Spatial.php` |
| Deprecations do PHP 8.5 dentro da `BallTree` | aberto, fora do nosso código: as 4 deprecations da suíte local vêm de `SplObjectStorage::attach()`/`contains()` em `vendor/rubix/ml/src/Graph/Trees/BallTree.php` (linhas 194, 205, 209, 232). No PHP 8.4 pinado do container e do CI elas não aparecem. Viram problema real se a plataforma subir para PHP 8.5+ sem uma versão nova do Rubix | `vendor/bin/phpunit --exclude-group db --exclude-group redis --display-deprecations` |
| Comparação before/after só em catálogo sintético | a equivalência no catálogo do seed não foi medida | este journal, seção Before / After |
| O CI não reexecuta a comparação | aberto: o checkout do CI é raso (`actions/checkout@v4` sem `fetch-depth`), então `4a3f37d^` não existe lá e os 3 testes do script que dependem do histórico são pulados; só o caso de clone raso roda. A verificação é local | `.github/workflows/ci.yml` |

---

## Desafio 3: Matar o Framework e Migrar a Plataforma

**Resumo.** Duas migrações, com seis meses de distância. Em 2026-02-03 o projeto largou o Hyperf (histórico: framework assíncrono sobre Swoole) e passou a PHP puro + PDO + Twig (ADR-001). Em 2026-08-18 saiu do alvo PHP 7.4 (histórico) para PHP 8.4, com a resolução do Composer pinada em `config.platform.php = 8.4.0` (ADR-002, R1.1–R1.6). A evidência conta uma história diferente da dos ADRs e do plano. **Nenhum dos dois erros do ADR-001 era conflito entre Hyperf e Rubix**: um era um nome de pacote que não existe (`hyperf/router`), o outro uma restrição sem versão estável (`rubix/ml ^3.0`). Corrigidos os dois em [`814332a`](https://github.com/deboracastrodev/ec-hub/commit/814332a41ecb18664d4713b266d591d185ff9d64), o manifesto **com** Hyperf resolve (reproduzido hoje, com a plataforma em PHP 7.4.33), e o Hyperf saiu 50 minutos depois. Nenhum registro da época diz por quê: pela sequência dos commits, foi escolha de peso e simplicidade, não impossibilidade. Do lado do PHPUnit, a migração 8 → 12 era o "maior risco" da spec de remediação, e custou 6 `assertRegExp` e o schema do `phpunit.xml`: não havia nenhum data provider para tornar `static`, e havia uma única anotação para virar atributo (um `@runInSeparateProcess`), que a migração deixou passar. O log original do erro do Hyperf não foi guardado. O que está aqui foi reproduzido hoje.

**Como ler esta seção.** Hyperf, Swoole e PHP 7.4 aparecem só como histórico, sempre rotulados. A stack atual é PHP 8.4 sem framework (ver [README](README.md) e [docs/architecture.md](docs/architecture.md)). Os comandos desta seção foram executados em **2026-09-24**, a partir de `e8224f1`, com **PHP 8.5.2** local e **Composer 2.9.5**. As saídas do Composer dependem do Packagist do dia: onde o texto de hoje difere do transcrito em fevereiro, isso está dito.

### Problema

#### A. O Hyperf não resolvia (histórico, 2026-02-03)

O ADR-001 ([docs/architecture.md](docs/architecture.md)) transcreve o erro assim:

```
Problem 1: Root composer.json requires hyperf/router, it could not be found
Problem 2: Root composer.json requires rubix/ml ^3.0, found rubix/ml[3.0.x-dev]
           but it does not match your minimum-stability
```

Esse é o único registro. **O log completo não foi guardado**: não há arquivo, issue nem mensagem de commit com a saída do Composer de fevereiro. Não dá para saber se havia mais problemas além desses dois.

Reproduzido hoje com o `composer.json` de [`0ad7f85`](https://github.com/deboracastrodev/ec-hub/commit/0ad7f8528d1a37ba3314b560643d6e93266a0afa), o primeiro manifesto com Hyperf (histórico):

```bash
D=$(mktemp -d); git show 0ad7f85:composer.json > "$D/composer.json"
composer update -d "$D" --dry-run --no-interaction --no-plugins --no-scripts --ignore-platform-reqs
# exit 2
```

```
  Problem 1
    - Root composer.json requires hyperf/router, it could not be found in any version, there may be a typo in the package name.
  Problem 2
    - Root composer.json requires rubix/ml ^3.0, found rubix/ml[3.0.0-rc1, 3.0.0-rc2, 3.0.0-rc3, 3.1.x-dev] but it does not match your minimum-stability.
```

Exatamente os mesmos dois problemas. O texto do segundo mudou: em fevereiro o Composer listou `3.0.x-dev`, hoje lista `3.0.0-rc1, 3.0.0-rc2, 3.0.0-rc3, 3.1.x-dev`, porque o Packagist ganhou versões novas do Rubix desde então.

| Problema | O que é de fato | Evidência |
|---|---|---|
| `hyperf/router` não encontrado | **Nome de pacote errado.** `hyperf/router` não existe no Packagist hoje, e o Composer de fevereiro também não o achou. O roteador do Hyperf vem dentro de `hyperf/http-server`, que já estava no mesmo manifesto | `curl -s -o /dev/null -w '%{http_code}' https://repo.packagist.org/p2/hyperf/router.json` → `404`; o mesmo para `hyperf/http-server` → `200` (2026-09-24) |
| `rubix/ml ^3.0` fora da `minimum-stability` | **Restrição sem versão estável.** A série 3.x do Rubix só tinha versões de desenvolvimento (em fevereiro) e só tem release candidates (hoje). O manifesto pedia `"minimum-stability": "stable"` | `composer show rubix/ml --all`: a maior versão estável é `2.6.0`; a 3.x para em `3.0.0-rc3` (2026-09-24) |

Nenhum dos dois é conflito entre o Hyperf e o Rubix: cada um falharia sozinho, num manifesto sem o outro. O ADR-001 fala em "conflitos reais de dependência" e em "conflito direto de versão contra o rubix/ml". A evidência não sustenta nenhuma das duas frases. **O ADR não foi editado**: a divergência fica registrada aqui, como nos Desafios 1 e 2.

Sem `--ignore-platform-reqs`, o mesmo comando mostra 5 problemas (exit 2): os dois acima, mais `php ^7.4` contra o PHP 8.5.2 local, `hyperf/redis` exigindo `ext-redis` e `laminas/laminas-mime` (dependência do `hyperf/http-server`) sem versão que aceite PHP 8.5. Esses três são da minha máquina de hoje, e não do container da época (histórico: `php:7.4-fpm` com `pecl install redis-5.3.7`, em [`2b14dd7`](https://github.com/deboracastrodev/ec-hub/commit/2b14dd78f8e9bb69b78152455add29364b16e5b5)).

#### B. Quatro lugares, três versões (histórico, até 2026-08-18)

Em [`c7fc6e4`](https://github.com/deboracastrodev/ec-hub/commit/c7fc6e4f9a99ac5100441a76b856bcdd65159610), o baseline da remediação, a versão de PHP era declarada em quatro lugares e nenhum concordava com o outro:

| Lugar | Versão de PHP | Fonte |
|---|---|---|
| `composer.json:6` | `^7.4` (histórico) | `git show c7fc6e4:composer.json` |
| `Dockerfile:1` | `php:7.4-cli` (histórico) | `git show c7fc6e4:Dockerfile` |
| `vendor/` instalado | `>=8.1` (`twig/twig 3.23`) e `>=8.0` (`psr/log 3.0.2`) | remediation-spec, R1.1 |
| máquina local | 8.5.2 | remediation-spec, R1.1 |

A remediation-spec (R1.1) diz "quatro versões diferentes", o ADR-002 diz "três versões", e a tabela de baseline do Desafio 1 repete "quatro versões em jogo". A leitura correta é **quatro lugares, três versões**: 7.4, ≥ 8.1 e 8.5.2. O `>=8.0` do `psr/log` não conta como versão à parte, porque o Twig já obrigava `>=8.1`, que o contém. Nenhum dos três textos foi editado.

**Por que o drift não apareceu.** O `.gitignore` de `c7fc6e4` ignorava `/vendor/` e `composer.lock` (linhas 27 e 28). Sem lock versionado, ninguém via no diff qual versão do Twig tinha sido instalada, e sem CI (R7.3) ninguém instalava do zero numa máquina limpa. A remediation-spec (R1.3) aponta o lock ignorado como "exatamente o motivo de o drift de R1.1 ter passado despercebido".

**O baseline não se reconstrói.** Com o `composer.json` de `c7fc6e4`, o Composer de hoje recusa a resolução no PHP 8.5.2 (exit 2):

```
  Problem 1
    - Root composer.json requires php ^7.4 but your php version (8.5.2) does not satisfy that requirement.
```

Como o `vendor/` com `twig/twig 3.23` foi parar ali não tem registro: o lock e o `vendor/` estavam no `.gitignore`. Inferência, não fato medido: com `"php": "^7.4"` na raiz, o Composer só instala um Twig que exige `>=8.1` se o requisito de plataforma for ignorado (por exemplo, `--ignore-platform-reqs`) ou se o `composer.json` era outro no momento do install.

### Solução 1: sair do framework

Linha do tempo (histórico). Todos os commits são de 2026-02-03, exceto o último:

| Hora | Commit | O que mudou |
|---|---|---|
| 11:48 | [`2b14dd7`](https://github.com/deboracastrodev/ec-hub/commit/2b14dd78f8e9bb69b78152455add29364b16e5b5) | `Dockerfile` com `php:7.4-fpm`, `pecl install redis-5.3.7` e `pecl install swoole-4.8.12` (histórico) |
| 12:51 | [`0ad7f85`](https://github.com/deboracastrodev/ec-hub/commit/0ad7f8528d1a37ba3314b560643d6e93266a0afa) | `composer.json` com 7 pacotes `hyperf/*` 2.2, incluindo `hyperf/router`, mais `rubix/ml ^3.0`, `php ^7.4` e `phpunit ^8.0`. `public/index.php` sobe um `Swoole\Http\Server` (histórico) |
| 14:39 | [`63c4404`](https://github.com/deboracastrodev/ec-hub/commit/63c440497e293da3e25f9caf0fadf7dfb090878d) | entram `psr/http-message` e `vlucas/phpdotenv`. Os dois erros continuam no manifesto |
| 18:03 | [`814332a`](https://github.com/deboracastrodev/ec-hub/commit/814332a41ecb18664d4713b266d591d185ff9d64) | sai `hyperf/router`, `rubix/ml ^3.0` vira `^2.2`. **O Hyperf fica.** Com isso, o manifesto resolve hoje, com a plataforma em 7.4.33 e os requisitos de extensão ignorados (ver a contagem abaixo) |
| 18:53 | [`6c1cddd`](https://github.com/deboracastrodev/ec-hub/commit/6c1cddd15e35025a972125be23e4273119539002) | saem os 6 `hyperf/*` restantes, `monolog/monolog` e `psr/http-message`. Entram PDO, `bin/migrate.php` e `bin/seed.php`. Mas o mesmo commit cria `app/Infrastructure/Persistence/Migration/2025_02_03_000001_create_products_table.php`, que estende `Hyperf\Database\Migration\Migration` (histórico) |
| 23:49 | [`5b4fa4f`](https://github.com/deboracastrodev/ec-hub/commit/5b4fa4f0b4e586daec4ca42b8de75524f366ca24) | ADR-001 escrito (na versão antiga de `docs/architecture.md`). Twig standalone. `Dockerfile` passa a `php:7.4-cli` com `php -S`, sem Swoole nem Redis (histórico). `public/index.php` vira um router de arrays |
| 2026-08-20 | [`99e1f1d`](https://github.com/deboracastrodev/ec-hub/commit/99e1f1d4619e9a6cf4a73c5c44c94fe9b4982f4b) | a migration órfã sai, seis meses depois, quando o PHPStan (R7.6) acusa 11 dos seus 18 erros nela |

**A constatação honesta.** Entre o commit que faz o manifesto com Hyperf (histórico) resolver (`814332a`, 18:03) e o que remove o Hyperf (`6c1cddd`, 18:53) passaram 50 minutos. Nesse intervalo, os dois problemas que o ADR-001 transcreve já estavam corrigidos (a resolução com o Packagist de fevereiro não tem como ser refeita, e a de hoje resolve). Nenhum commit, issue ou documento da época registra o motivo da troca. Inferência, não fato medido: a queda do framework foi uma decisão de peso e simplicidade (menos pacotes, sem extensão Swoole, um projeto pequeno), e é defensável nesses termos. O que a evidência mostra é que não foi uma saída forçada por conflito.

#### O que se ganhou em pacotes

Contagem de pacotes de produção que o Composer instalaria, com a plataforma fixada no PHP da época (histórico: `7.4.33`) e os requisitos de extensão ignorados. As extensões que os 68 pacotes de `814332a` **exigem** são `ext-iconv`, `ext-json`, `ext-pcre`, `ext-redis` e `ext-tokenizer` (lidas do `require` de cada pacote no lock gerado com `--no-install`). Só `ext-redis` falta na minha máquina, e o container da época a instalava via PECL. Sem o `--ignore-platform-req='ext-*'`, o único requisito de extensão que o Composer acusa é esse. O `ext-swoole` não entra na conta: `hyperf/engine` v1.2.2 e `hyperf/utils` v2.2.34 o listam em `suggest`, não em `require`, então o Composer não o exige (o app da época precisava dele em runtime, para o `Swoole\Http\Server`, mas isso não aparece na resolução):

```bash
D=$(mktemp -d); git show 814332a:composer.json > "$D/composer.json"   # ou 6c1cddd, 5b4fa4f
composer config -d "$D" platform.php 7.4.33
composer config -d "$D" audit.block-insecure false  # só para 5b4fa4f (ver a tabela)
composer update -d "$D" --dry-run --no-interaction --no-plugins --no-scripts \
  --no-dev --ignore-platform-req='ext-*' 2>&1 | grep -c '^  - Installing'
```

| Revisão | Pacotes de produção | Observação |
|---|---|---|
| `814332a` (com Hyperf, histórico) | **68** | 17 deles são `hyperf/*`, 15 `symfony/*`, 7 `amphp/*` |
| `6c1cddd` (sem Hyperf, sem Twig) | **23** | os 23 já estavam entre os 68: nenhum pacote novo, 45 a menos |
| `5b4fa4f` (+ Twig) | **25** | só com `composer config audit.block-insecure false`. Com o padrão do Composer 2.9.5, a resolução falha (exit 2): as versões do Twig 3 que aceitam PHP 7.4 são bloqueadas por advisory de segurança, e as que sobram (`v3.27.0` a `v3.29.0`) exigem `php >=8.1.0` |
| `e8224f1`, hoje (PHP 8.4) | **26** | `composer show --locked --no-dev \| wc -l`. Medido de outro jeito: é o lock versionado, resolvido para o PHP 8.4, e não o dry-run acima com a plataforma em 7.4.33 |

O ADR-001 fala em "~53 pacotes extras", e a versão original do ADR (histórico, `git show 5b4fa4f:docs/architecture.md`, linha 265) em "73 pacotes instalados vs ~20 pacotes necessários". O medido hoje é **68 → 23, ou seja, 45 a menos**. A diferença pode vir do Packagist de fevereiro, de pacotes de dev contados junto ou de um `composer.json` intermediário. Não há como saber: o lock da época não foi versionado.

#### O que se ganhou e o que se perdeu

O "framework próprio" de hoje (em `e8224f1`), para comparar com o que o Hyperf daria:

| Aspecto | Com Hyperf (histórico, `0ad7f85`) | Hoje, sem framework | Ganho ou perda |
|---|---|---|---|
| Dependências de produção | 68 pacotes (`814332a`) | 26 pacotes | **ganho**: 42 a menos (base: `814332a` → hoje, 68 → 26). A economia do dia da troca foi 45 (base: `814332a` → `6c1cddd`, 68 → 23); desde então entraram `twig/twig` e `predis/predis`, e `psr/log` virou dependência direta |
| Extensões e runtime | Swoole 4.8.12 + Redis via PECL, servidor `Swoole\Http\Server` | `php:8.4-cli` com `php -S`, extensões `pdo`, `pdo_mysql`, `mbstring`, `zip` | **ganho**: nenhuma extensão via PECL para subir o app (o único `pecl install` do `Dockerfile` atual é o `pcov`, para cobertura) |
| Roteamento | `hyperf/http-server` (rotas, middleware) | [`app/Shared/Http/Router.php`](app/Shared/Http/Router.php), 46 linhas: rota exata e regex. Sem middleware, sem parâmetros nomeados, sem geração de URL | **perda** |
| Injeção de dependência | `hyperf/di` | [`app/Shared/Container/Container.php`](app/Shared/Container/Container.php), 56 linhas, PSR-11, e o registro manual de cada classe em `config/bootstrap.php` (233 linhas) | **perda**: todo serviço novo é uma entrada escrita à mão |
| Banco e migrations | `hyperf/database` (query builder, migrations versionadas) | PDO direto nos repositórios. `bin/migrate.php` (139 linhas) com `CREATE TABLE IF NOT EXISTS` e `ALTER` condicional: sem tabela de versões, sem rollback | **perda** |
| Tratamento de erro | do framework | [`app/Shared/Http/ErrorHandler.php`](app/Shared/Http/ErrorHandler.php), 90 linhas | neutro: pequeno e testado |
| Entry point | do framework | `public/index.php`, 144 linhas. Chegou a 210 linhas sem teste antes de o router ser extraído em [`59f0fcf`](https://github.com/deboracastrodev/ec-hub/commit/59f0fcfc6bb7f9834ecbf2b3bbe55f92e9f0e5db) (R5.6). As 210 vêm de `git show 59f0fcf^:public/index.php \| wc -l`; a mensagem do commit arredonda para ~190 | **perda real**: o custo de não ter framework apareceu como código sem teste, e foi pago seis meses depois |
| Workers long-running, corrotinas | Swoole (histórico) | não existe | perda só no papel: o código nunca usou |

Fontes das linhas: `wc -l` nos arquivos citados, em `e8224f1`.

### Solução 2: PHP 8.4 com a plataforma pinada

| Data (`git log`) | Commit | O que mudou |
|---|---|---|
| 2026-08-18 23:10 | [`7ceea87`](https://github.com/deboracastrodev/ec-hub/commit/7ceea87d66b0132ccadcb18dc8898b49bba93aa9) (R1.1) | `Dockerfile`: `FROM php:8.4-cli`. `composer.json`: `"php": "^8.4"` e `config.platform.php = "8.4.0"` |
| 2026-08-18 23:49 | [`d0e3b75`](https://github.com/deboracastrodev/ec-hub/commit/d0e3b75bf61971541860dae88e1b092f324a46f9) (R1.3) | `composer.lock` sai do `.gitignore` e passa a ser versionado |
| 2026-08-19 15:19 | [`3a40a2e`](https://github.com/deboracastrodev/ec-hub/commit/3a40a2ee95d655b8864c1534e4eb941becd93ec4) (R1.5) | PHPUnit 8 → 12 (Solução 3). Vem depois do R1.1 porque o PHPUnit 12 exige PHP `>=8.3` |
| 2026-08-20 10:08 | [`be30048`](https://github.com/deboracastrodev/ec-hub/commit/be3004865ca32ff61f2d538be731e4a0bd0b516c) (R7.3) | CI, `.github/workflows/ci.yml`: `php-version: '8.4'` (`:18`), `composer install` (`:25`) e, **depois** da instalação, `composer check-platform-reqs` (`:28`), que confere o `vendor/` instalado contra o PHP e as extensões do runner |

Os demais itens de WS1 (R1.2, R1.4 e R1.6) estão na tabela Soluções do Desafio 1.

O pin faz a máquina local **resolver** as dependências como se fosse o container. Ele alinha a resolução, não a execução: o PHP que roda a suíte localmente continua sendo o 8.5.2 (ver "O que ainda não está resolvido"). Sem o pin, quem manda no lock é o PHP de quem roda `composer update`. Para ver o efeito, a partir do `composer.json` e do `composer.lock` de `e8224f1`:

```bash
D=$(mktemp -d)
git show e8224f1:composer.json > "$D/composer.json"; git show e8224f1:composer.lock > "$D/composer.lock"
composer update -d "$D" --dry-run --no-interaction --no-plugins --no-scripts 2>&1 \
  | grep -E 'Lock file operations|Upgrading (rubix/ml|symfony/console)'
composer config -d "$D" --unset platform
composer update -d "$D" --dry-run --no-interaction --no-plugins --no-scripts 2>&1 \
  | grep -E 'Lock file operations|Upgrading (rubix/ml|symfony/console)'
```

Com o pin (saída real, 2026-09-24):

```
Lock file operations: 0 installs, 16 updates, 0 removals
  - Upgrading rubix/ml (2.5.5 => 2.6.0)
```

Sem o pin:

```
Lock file operations: 1 install, 24 updates, 0 removals
  - Upgrading rubix/ml (2.5.5 => 2.6.0)
  - Upgrading symfony/console (v8.0.15 => v8.1.7)
```

| | Resultado no PHP 8.5.2 local |
|---|---|
| Com o pin `8.4.0` | `Lock file operations: 0 installs, 16 updates, 0 removals` |
| Sem o pin | `Lock file operations: 1 install, 24 updates, 0 removals` |

**Como ler.** Os 16 updates **com** o pin não têm nada a ver com o pin: mostram só que o lock versionado está atrás do Packagist de hoje (entre eles, `rubix/ml` 2.5.5 → 2.6.0, `phpunit/phpunit` 12.5.33 → 12.5.35, `twig/twig` v3.28.0 → v3.29.0). O que isola o efeito do pin é a diferença entre as duas execuções. Os 8 updates a mais são todos `symfony/{console,event-dispatcher,filesystem,finder,options-resolver,process,stopwatch,string}`, de 8.0.x para 8.1.x, e o install a mais é `symfony/polyfill-php85`. Os 8 declaram `php >=8.4.1` na série 8.1 (por exemplo, `composer show symfony/console v8.1.7 --all`; os outros 7 conferidos em `https://repo.packagist.org/p2/symfony/<pacote>.json`, 2026-09-24), e o pin em `8.4.0` bloqueia isso.

Hoje, esses pacotes nem quebrariam o container, que roda PHP 8.4.25 (medido no Desafio 2, na imagem `php:8.4-cli`). E todos são dependências de dev (vêm pelo `friendsofphp/php-cs-fixer`; nenhum aparece em `composer show --locked --no-dev`), não de produção. Mas o ponto é outro: sem o pin, o lock passaria a ser decidido pela versão de PHP da máquina de quem roda `composer update`, que é exatamente o tipo de drift que produziu o problema B.

### Solução 3: PHPUnit 8 → 12

**O risco previsto.** A remediation-spec (R1.5 e seção 6) chamou a migração de "maior risco desta spec", com probabilidade e impacto altos: data providers passariam a ser obrigatoriamente `static`, anotações `@test`/`@dataProvider`/`@group` migrariam para atributos, `assertRegExp` e afins tinham sido removidos, e o schema do `phpunit.xml` mudou. O plano B era parar no PHPUnit 10. E havia uma pré-condição: o PHPUnit 12 exige `"php": ">=8.3"` (`vendor/phpunit/phpunit/composer.json:31`), então só podia vir depois do R1.1.

**O inventário medido** (`git grep` em `tests/`, contando linhas). A coluna de hoje **exclui** dois arquivos desta seção: o fixture `tests/Fixtures/Phpunit12/LegacyMetadataExample.php` e o teste `tests/Integration/Tooling/Phpunit12LegacyMetadataTest.php`. Eles contêm de propósito um `@dataProvider` e um `@group` em docblock, um `#[DataProvider]` com provider não-static e `assertRegExp` (no código do fixture e nas mensagens que o teste fixa). Contá-los mediria o exemplo do que não fazer, e não a suíte:

| O que a spec temia | Em `c7fc6e4` (antes) | Hoje (`e8224f1`) |
|---|---|---|
| Data providers para tornar `static` | **0** (nenhum `@dataProvider`) | 3 `#[DataProvider(...)]`, os 3 providers `public static` (em 2 arquivos; os atributos entraram em [`54b2de2`](https://github.com/deboracastrodev/ec-hub/commit/54b2de2a228bc037a1f552174be2f675aba64054) e [`83e54ef`](https://github.com/deboracastrodev/ec-hub/commit/83e54ef21e2d82f98118351f16ea80d01df11f03), depois da migração) |
| Anotações `@test` / `@group` / `@covers` para virar atributo | **0** | 0 anotações (o único casamento do padrão é um comentário em `tests/docker/MakefileTest.php:120`, "a suíte sem @group db"). 41 linhas de atributo `#[...]`: 23 `#[RunInSeparateProcess]`, 15 `#[Group(...)]`, 3 `#[DataProvider(...)]` |
| Outras anotações de metadado (`@runInSeparateProcess`, `@depends`, `@requires`, `@testWith`, `@before`, `@doesNotPerformAssertions` e afins) | **1**: `@runInSeparateProcess` em `tests/Integration/Controller/RecommendationHttpEndpointTest.php:13` | 0 |
| Asserções removidas (`assertRegExp`) | **6** | 0 |
| Schema do `phpunit.xml` | 8.0 | 12.5 |

```bash
X=(':!tests/Fixtures' ':!tests/Integration/Tooling/Phpunit12LegacyMetadataTest.php')
T='@(test|group|covers)([^[:alnum:]_]|$)'
M='@(runInSeparateProcess|runTestsInSeparateProcesses|depends|requires|testWith|before|after|beforeClass|afterClass|doesNotPerformAssertions|coversNothing|backupGlobals|preserveGlobalState|testdox|ticket|small|medium|large|uses)([^[:alnum:]_]|$)'
git grep -h '@dataProvider' c7fc6e4 -- tests | wc -l                    # 0
git grep -hE "$T" c7fc6e4 -- tests | wc -l                              # 0
git grep -n 'assertRegExp' c7fc6e4 -- tests                             # 6 linhas, em 4 arquivos
git grep -nE "$M" c7fc6e4 -- tests                                      # 1 linha: o @runInSeparateProcess
git grep -hE "$M" e8224f1 -- tests "${X[@]}" | wc -l                   # 0
git grep -h '@dataProvider' e8224f1 -- tests "${X[@]}" | wc -l             # 0
git grep -nE "$T" e8224f1 -- tests "${X[@]}"                               # 1 linha: o comentário do MakefileTest
git grep -h 'assertRegExp' e8224f1 -- tests "${X[@]}" | wc -l              # 0
git grep -h '#\[DataProvider' e8224f1 -- tests "${X[@]}" | wc -l           # 3
git grep -hE '^[[:space:]]*#\[' e8224f1 -- tests "${X[@]}" | wc -l         # 41
```

Os padrões usam classes POSIX (`[[:space:]]`, `[^[:alnum:]_]`) porque o `git grep -E` não entende `\s` nem `\b`: com `^\s*#\[`, só as 14 linhas sem indentação (atributos de classe) casariam, e os atributos de método ficariam de fora. Os números de hoje foram medidos em `e8224f1` e conferidos também na árvore de trabalho com os dois arquivos novos (`git grep --untracked`, mesma exclusão): iguais. Sem a exclusão, a árvore com os dois arquivos dá 4 `#[DataProvider]`, 4 linhas com `assertRegExp`, 1 `@dataProvider` e 42 linhas de atributo.

**O que de fato mudou em [`3a40a2e`](https://github.com/deboracastrodev/ec-hub/commit/3a40a2ee95d655b8864c1534e4eb941becd93ec4)** (R1.5 + R2.3):

- `composer.json`: `phpunit/phpunit` `^8.0` → `^12.5`, `friendsofphp/php-cs-fixer` `^3.0` → `^3.95`, `mockery/mockery` `^1.4` → `^1.6`, `fakerphp/faker` `^1.20` → `^1.24`.
- `phpunit.xml`: schema `8.0` → `12.5`; saem `verbose` e `beStrictAboutTodoAnnotatedTests`, que não existem mais; entra `cacheDirectory=".phpunit.cache"`.
- 6 × `assertRegExp` → `assertMatchesRegularExpression`: `ProductControllerTest` (2), `RecommendationApiLiveHttpTest` (1), `ResponsiveDesignTest` (1), `MakefileTest` (2).
- As mudanças em `GenerateRecommendationsIntegrationTest` e `SetupScriptTest` no mesmo commit são alinhamento de comportamento (R2.3), não PHPUnit.
- **O que ficou de fora:** o `@runInSeparateProcess` de `RecommendationHttpEndpointTest` não foi convertido (`git show 3a40a2e:tests/Integration/Controller/RecommendationHttpEndpointTest.php`, linha 13). O PHPUnit 12 não lê docblock (`vendor/phpunit/phpunit/src/Metadata/Parser/` só tem o `AttributeParser`), então, entre `3a40a2e` e a conversão, esse teste rodou no mesmo processo dos outros, sem aviso.

Os primeiros atributos do projeto entraram no dia seguinte, em [`6a849f9`](https://github.com/deboracastrodev/ec-hub/commit/6a849f9a42ace8a8d0e31b395e69150ac7c202ca) (2026-08-20 10:03, R2.5): `#[Group('db')]`, para separar os testes que exigem MySQL, e o `#[RunInSeparateProcess]` que substituiu aquele docblock. A mensagem do commit chama a anotação de "deprecado no PHPUnit 12"; na verdade, o PHPUnit 12 já não a lê. Os outros 22 `#[RunInSeparateProcess]` de hoje vieram com testes dos épicos seguintes.

O "maior risco" custou 6 linhas, um schema e uma anotação que ficou para trás. A spec descrevia o custo típico dessa migração, mas ninguém tinha contado quantos providers e anotações a suíte deste projeto tinha. Eram zero providers e uma anotação, e justamente a anotação escapou: a migração procurou o que quebra alto (`assertRegExp` dá erro), não o que o PHPUnit 12 ignora em silêncio.

**As regras que valem para código novo** (e que o fixture da próxima seção demonstra quebrando):

- metadados de teste só como atributo: `#[DataProvider('nome')]`, `#[Group('db')]`, `#[Test]`. O PHPUnit 12 ignora metadados em docblock, e o efeito depende da anotação: um `@dataProvider` quebra alto (o teste é chamado sem argumentos, `ArgumentCountError`), mas um `@group` falha em silêncio (o teste passa e escapa de `--exclude-group`);
- data provider é `public static`;
- `assertMatchesRegularExpression`, nunca `assertRegExp`.

### O que NÃO fazer

**1. Declarar um pacote sem conferir o nome** (histórico, `0ad7f85`).

```json
"hyperf/http-server": "^2.2",
"hyperf/router": "^2.2"
```

```
- Root composer.json requires hyperf/router, it could not be found in any version, there may be a typo in the package name.
```

Antes de pôr o nome no manifesto: `composer show hyperf/router --all` ou a página do pacote no Packagist.

**2. Pedir uma major que não tem versão estável** (histórico, `0ad7f85`).

```json
"rubix/ml": "^3.0",
"minimum-stability": "stable"
```

```
- Root composer.json requires rubix/ml ^3.0, found rubix/ml[3.0.0-rc1, 3.0.0-rc2, 3.0.0-rc3, 3.1.x-dev] but it does not match your minimum-stability.
```

**3. Declarar uma versão de PHP que nada aplica** (histórico, `c7fc6e4`). `"php": "^7.4"` no `composer.json` e `FROM php:7.4-cli` no `Dockerfile`, com um `vendor/` que exigia `>=8.1`. A restrição só vale se alguém rodar `composer install` do zero no PHP declarado. Ninguém rodava. Hoje o CI roda `composer check-platform-reqs` em PHP 8.4.

**4. Ignorar o lock numa aplicação** (histórico, `c7fc6e4`).

```gitignore
/vendor/
composer.lock
```

Sem lock no git, o conjunto de versões instalado não aparece em nenhum diff, e seis meses depois não há como reconstruí-lo.

**5. Não pinar a plataforma.** Sem `config.platform.php`, o mesmo `composer update` no PHP 8.5.2 faz 8 updates e 1 install a mais do que com o pin: os `symfony/*` que exigem `>=8.4.1` (demonstração na Solução 2). O lock passa a depender da máquina.

**6. Instalar o `vendor/` furando a restrição de plataforma.** `--ignore-platform-reqs` (ou equivalente) instala pacotes que o PHP declarado não roda. Foi assim, por inferência, que um `vendor/` com `twig/twig 3.23` (`>=8.1`) conviveu com `"php": "^7.4"`.

**7. Deixar código que estende uma classe do framework removido** (histórico, `6c1cddd` a `99e1f1d`).

```php
use Hyperf\Database\Migration\Migration;

class CreateProductsTable extends Migration
```

O Hyperf (histórico) saiu do `composer.json` no mesmo commit em que essa migration entrou. Ela ficou seis meses no repositório sem consumidor, e só saiu quando o PHPStan (R7.6) acusou 11 erros nela.

**8 a 11. Metadados da era PHPUnit 8 no PHPUnit 12.** O arquivo [`tests/Fixtures/Phpunit12/LegacyMetadataExample.php`](tests/Fixtures/Phpunit12/LegacyMetadataExample.php) tem os quatro erros, executáveis. Ele fica fora das suítes do `phpunit.xml` e só roda quando chamado de propósito:

```php
/** @dataProvider precos */                 // 8. docblock: ignorado
public function test_docblock_data_provider(float $preco): void

/** @group db */                            // 9. docblock: ignorado
public function test_docblock_group_db(): void

#[DataProvider('naoStatic')]                // 10. atributo certo, provider sem static
public function test_non_static_data_provider(float $preco): void
public function naoStatic(): array

$this->assertRegExp('/^\d+ms$/', '12ms');    // 11. método removido
```

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Fixtures/Phpunit12/LegacyMetadataExample.php
```

Saída real no PHPUnit 12.5.33 (PHP 8.5.2, 2026-09-24; o caminho absoluto do repositório foi trocado por `<repo>`, e as linhas de runtime, tempo e arquivo:linha foram omitidas). O `TestCase.php on line 1318` da mensagem do `ArgumentCountError` é específico do PHPUnit 12.5.33: outra versão do PHPUnit aponta outra linha, e por isso o teste de tooling só fixa o trecho até `0 passed`:

```
E.E                                                                 3 / 3 (100%)

There was 1 PHPUnit error:

1) Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_non_static_data_provider
The data provider Tests\Fixtures\Phpunit12\LegacyMetadataExample::naoStatic specified for Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_non_static_data_provider is invalid
Data Provider method Tests\Fixtures\Phpunit12\LegacyMetadataExample::naoStatic() is not static

--

There were 2 errors:

1) Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_docblock_data_provider
ArgumentCountError: Too few arguments to function Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_docblock_data_provider(), 0 passed in <repo>/vendor/phpunit/phpunit/src/Framework/TestCase.php on line 1318 and exactly 1 expected

2) Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_removed_assert_reg_exp
Error: Call to undefined method Tests\Fixtures\Phpunit12\LegacyMetadataExample::assertRegExp()

ERRORS!
Tests: 3, Assertions: 1, Errors: 3.
```

Como ler:

- **8.** O `@dataProvider` em docblock é ignorado. O PHPUnit chama o teste sem argumentos: `ArgumentCountError`.
- **9.** O `@group db` em docblock também é ignorado, mas em silêncio: o teste passa (é o `.` do meio). Com `--exclude-group db --filter test_docblock_group_db`, ele **roda**, porque o grupo `db` não existe. Saída real (mesmo ambiente e mesmas omissões):

  ```
  .                                                                   1 / 1 (100%)

  There was 1 PHPUnit error:

  1) Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_non_static_data_provider
  The data provider Tests\Fixtures\Phpunit12\LegacyMetadataExample::naoStatic specified for Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_non_static_data_provider is invalid
  Data Provider method Tests\Fixtures\Phpunit12\LegacyMetadataExample::naoStatic() is not static

  ERRORS!
  Tests: 1, Assertions: 1, Errors: 1.
  ```

  O `.` e o `Assertions: 1` são o teste `@group db` rodando. O `Errors: 1` (e o exit 2) **não** vem dele: vem do provider não-static do item 10, que o PHPUnit valida ao carregar a classe, qualquer que seja o `--filter`. Isto importa aqui: o job de testes sem banco do CI depende de `--exclude-group db` (`.github/workflows/ci.yml:58`). Um teste de MySQL marcado do jeito antigo rodaria nesse job e falharia por falta de banco.
- **10.** O provider sem `static` vira "PHPUnit error" e o teste nem entra na contagem: são 4 métodos de teste e `Tests: 3`. O erro aparece mesmo quando o `--filter` seleciona outro teste.
- **11.** `assertRegExp` não existe: `Error`.

O teste [`tests/Integration/Tooling/Phpunit12LegacyMetadataTest.php`](tests/Integration/Tooling/Phpunit12LegacyMetadataTest.php) roda o fixture e fixa essas mensagens, o grupo ignorado, a ausência do fixture em `vendor/bin/phpunit --list-tests` e a presença das mesmas mensagens neste journal.

### Before / After

| Aspecto | Before | After (hoje, `e8224f1`) | Fonte |
|---|---|---|---|
| Framework | Hyperf 2.2 sobre Swoole 4.8.12 (histórico, `0ad7f85` e `2b14dd7`) | nenhum: `Router` 46 linhas, `Container` 56, `ErrorHandler` 90 | commits linkados; `wc -l` |
| Pacotes de produção | 68 com Hyperf (`814332a`, plataforma 7.4.33, histórico) | 26 | comandos da Solução 1 |
| PHP declarado / container / vendor / local | `^7.4` / `7.4` / `>=8.1` / `8.5.2` (histórico, `c7fc6e4`) | `^8.4` / `8.4` / resolvido para `8.4.0` pelo pin / 8.5.2 | `composer.json`, `Dockerfile`, R1.1 |
| `composer.lock` | ignorado no `.gitignore` | versionado ([`d0e3b75`](https://github.com/deboracastrodev/ec-hub/commit/d0e3b75bf61971541860dae88e1b092f324a46f9)) | `git ls-files composer.lock` |
| Checagem de plataforma | nenhuma | `composer check-platform-reqs` no CI, em PHP 8.4 | `.github/workflows/ci.yml:28` |
| PHPUnit | `^8.0`, schema 8.0, 6 `assertRegExp` | `^12.5` (12.5.33 no lock), schema 12.5, 0 `assertRegExp`, 3 `#[DataProvider]` com providers `static` (fora o fixture e o teste desta seção, que ficam fora da contagem) | `3a40a2e`; `git grep` com exclusão, acima |

### O que aprendi

- **Leia o erro antes de chamar de conflito.** "Could not be found in any version" e "does not match your minimum-stability" são erros de manifesto, cada um com conserto de uma linha. Chamá-los de "conflito com o Rubix" transformou dois typos numa decisão de arquitetura. A decisão acabou sendo boa, mas pelo motivo errado, e o ADR registrou o motivo errado.
- **Guarde o log do erro que motiva uma decisão.** O ADR-001 guardou duas linhas transcritas. Seis meses depois, dá para reproduzir essas duas, mas não para saber se havia outras. Um arquivo com a saída completa, junto do ADR, teria custado nada.
- **Restrição sem enforcement é comentário.** `"php": "^7.4"` e `FROM php:7.4-cli` (histórico, até `7ceea87`) estavam escritos, e não valiam nada: nenhum processo instalava do zero naquele PHP. O pin + lock versionado + `check-platform-reqs` no CI são três partes do mesmo enforcement. Sem qualquer uma delas, o drift volta.
- **Conte antes de chamar de "maior risco".** O risco do PHPUnit estava descrito corretamente para uma suíte típica, e não para esta: 0 providers, 1 anotação, 6 asserções. Um `git grep` de 10 segundos teria dimensionado o risco, e também achado a anotação que a migração deixou passar. Um risco superestimado não é inofensivo: ele puxa atenção e plano B para o lugar errado.
- **O custo de não ter framework chega depois.** A queda do Hyperf (histórico, 2026-02-03) economizou 45 pacotes de produção no dia (`814332a` → `6c1cddd`, 68 → 23; contra o lock de hoje, 68 → 26, a economia é 42). O preço veio em parcelas: `public/index.php` com 210 linhas sem teste até o R5.6, um container escrito à mão, migrations sem versão. É um preço aceitável para este porte, mas é um preço, e o ADR-001 não o listou.
- **Remover a dependência não remove o código que dependia dela.** A migration que estendia uma classe do Hyperf (histórico) sobreviveu seis meses porque nada a carregava. Só uma ferramenta que lê o código sem executá-lo (PHPStan) a encontrou.

### O que ainda não está resolvido

| Pendência | Estado | Evidência |
|---|---|---|
| O pin `8.4.0` segura pacotes que exigem `>= 8.4.1` | aberto: hoje, 8 pacotes `symfony/*` de dev ficam em 8.0.x por causa disso. O container roda 8.4.25 (Desafio 2), então o pin é mais conservador do que o runtime. Subir o pin para a versão real da imagem resolve, mas precisa acompanhar a imagem | demonstração da Solução 2; `composer show symfony/console v8.1.7 --all` |
| O PHP local (8.5.2) difere do alvo (8.4) | aberto: o pin alinha a **resolução**, não a **execução**. A suíte local roda em 8.5.2, e as 4 deprecations do Rubix no Desafio 2 só aparecem aqui, não no container nem no CI | `php -v`; Desafio 2, "O que ainda não está resolvido" |
| O texto do ADR-001 diverge da evidência ("conflitos reais", "conflito direto de versão contra o rubix/ml", "~53 pacotes") | aberto, por decisão: o ADR não foi editado nesta story; a divergência está registrada aqui | [docs/architecture.md](docs/architecture.md), ADR-001; seções A e Solução 1 acima |
| O ADR-002 diz "três versões", a R1.1 diz "quatro" | aberto, por decisão: nenhum dos dois foi editado; são quatro lugares e três versões | seção B acima |
| A série 3.x do Rubix continua sem versão estável | aberto, fora do nosso controle: a maior é `3.0.0-rc3`. O projeto segue em `^2.5` (2.5.5 no lock). Já existe um `rubix/ml 2.6.0` estável, que o `composer update` com o pin traria; o Desafio 2 alerta que uma menor nova pode mexer nos métodos `@internal` usados | `composer show rubix/ml --all` (2026-09-24); demonstração da Solução 2 |
| As regras para código novo (metadado só como atributo) não têm checagem automática | aberto: nada no CI procura `@dataProvider`, `@group`, `@runInSeparateProcess` e afins em docblock. Um `@group db` em docblock passaria pelo review e rodaria no job sem banco. É a mesma lição de "restrição sem enforcement", ainda não aplicada aqui | `.github/workflows/ci.yml`; item 9 de "O que NÃO fazer" |
| As contagens de fevereiro (73, ~53) não se reproduzem | não resolvível: o lock da época não foi versionado | seção "O que se ganhou em pacotes" |
