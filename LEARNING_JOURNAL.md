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
