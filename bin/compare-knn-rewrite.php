<?php

declare(strict_types=1);

/**
 * Before/after da reescrita do KNN (R4.1-R4.3, commit 4a3f37d): roda a
 * implementação manual (lida de 4a3f37d^ via git) e a atual (KNNService +
 * RubixNeighborFinder) sobre o mesmo catálogo sintético determinístico e
 * compara os resultados. Substitui as fixtures pré-reescrita que o plano
 * previa (docs/remediation-spec.md, seção 6) e que nunca foram versionadas.
 *
 * Comparação de conjunto/ordem/score com limit=4, o máximo que a versão
 * manual conseguia devolver (k=5 fixo, menos o próprio alvo). A última
 * coluna mostra o teto de itens ML de cada versão com limit=10.
 *
 * Uso: php bin/compare-knn-rewrite.php   (precisa do histórico completo do git
 * e do vendor/ com as dependências de dev)
 *
 * Saída: 0 quando toda divergência é empate de distância; 1 em erro de
 * ambiente (sem git, clone raso, sem dependências de dev); 2 quando alguma divergência não é empate.
 */

use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\KNNService;
use App\Infrastructure\ML\RubixNeighborFinder;
use Tests\Performance\Support\KnnBenchmark;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\KnnTieCheck;

// O Rubix 2.5 dispara deprecations de SplObjectStorage no PHP 8.5; só ruído aqui.
error_reporting(E_ALL & ~E_DEPRECATED);

const LEGACY_REVISION = '4a3f37d39dcd03f23abe56a9c275d01d4a24f76d^';
const LEGACY_PATH = 'app/Domain/Recommendation/Service/KNNService.php';
const COMPARE_LIMIT = 4;
const CAP_LIMIT = 10;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (! class_exists(KnnBenchmark::class) || ! class_exists(InMemoryProductRepository::class)
    || ! class_exists(KnnTieCheck::class)) {
    fwrite(STDERR, "Classes de teste ausentes no autoload; rode `composer install` com as dependências de dev.\n");
    exit(1);
}

$spec = escapeshellarg(LEGACY_REVISION . ':' . LEGACY_PATH);
$git = 'git -C ' . escapeshellarg($root);

exec('command -v git >/dev/null 2>&1', $ignored, $hasGit);
if ($hasGit !== 0) {
    fwrite(STDERR, "git não encontrado no PATH; o script lê a versão antiga do histórico.\n");
    exit(1);
}

exec("{$git} cat-file -e {$spec} 2>/dev/null", $ignored, $status);
if ($status !== 0) {
    fwrite(STDERR, 'Revisão ' . LEGACY_REVISION . " não encontrada no histórico local.\n");
    fwrite(STDERR, "Clone raso? Rode `git fetch --unshallow` e tente de novo.\n");
    exit(1);
}

$source = shell_exec("{$git} show {$spec}");
if (! is_string($source) || $source === '') {
    fwrite(STDERR, 'Falha ao ler ' . LEGACY_PATH . ' de ' . LEGACY_REVISION . ".\n");
    exit(1);
}

$source = str_replace(
    ['namespace App\Domain\Recommendation\Service;', 'class KNNService'],
    ['namespace Legacy;', 'class ManualKNNService'],
    $source,
    $replacements
);
if ($replacements !== 2) {
    fwrite(STDERR, "Fonte legada inesperada: namespace/classe não encontrados.\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'knn-legacy-');
if ($tmp === false || file_put_contents($tmp, $source) === false) {
    fwrite(STDERR, "Falha ao gravar o arquivo temporário.\n");
    exit(1);
}
// Remove o arquivo mesmo se o require do código legado der erro fatal.
register_shutdown_function(static function () use ($tmp): void {
    if (is_file($tmp)) {
        unlink($tmp);
    }
});
require $tmp;

/**
 * Distância recuperada do score do próprio KNNService (score = 100 / (1 + d)).
 *
 * @param RecommendationResult[] $results
 * @return list<float>
 */
function distances(array $results): array
{
    return array_map(static fn (RecommendationResult $r): float => 100 / $r->getScore() - 1, $results);
}

/**
 * @param RecommendationResult[] $results
 * @return list<int>
 */
function productIds(array $results): array
{
    return array_map(static fn (RecommendationResult $r): int => $r->getProductId(), $results);
}

/**
 * @param RecommendationResult[] $results
 * @return array<int, float>
 */
function scoresById(array $results): array
{
    $scores = [];
    foreach ($results as $r) {
        $scores[$r->getProductId()] = $r->getScore();
    }

    return $scores;
}

$rows = [];
$divergences = [];

foreach ([20, 100] as $productCount) {
    $repository = new InMemoryProductRepository(KnnBenchmark::syntheticCatalog($productCount));
    $catalog = $repository->findAll($productCount, 0);

    $manual = new \Legacy\ManualKNNService($repository);
    $manual->train($catalog);

    $finder = new RubixNeighborFinder();
    $rubix = new KNNService($repository, $finder);
    $rubix->train($catalog);

    $sameSet = 0;
    $sameOrder = 0;
    $maxScoreDiff = 0.0;
    $manualCap = 0;
    $rubixCap = 0;

    foreach ($catalog as $target) {
        $before = $manual->recommend($target, COMPARE_LIMIT);
        $after = $rubix->recommend($target, COMPARE_LIMIT);

        $beforeIds = productIds($before);
        $afterIds = productIds($after);
        $sortedBefore = $beforeIds;
        $sortedAfter = $afterIds;
        sort($sortedBefore);
        sort($sortedAfter);

        if ($sortedBefore === $sortedAfter) {
            $sameSet++;
        }
        if ($beforeIds === $afterIds) {
            $sameOrder++;
        }

        $beforeScores = scoresById($before);
        $afterScores = scoresById($after);
        foreach (array_intersect_key($beforeScores, $afterScores) as $id => $score) {
            $maxScoreDiff = max($maxScoreDiff, abs($score - $afterScores[$id]));
        }

        if ($beforeIds !== $afterIds) {
            // Só para leitura humana: os vizinhos até 3 posições além do corte,
            // pela versão atual (+1 porque o próprio alvo volta com distância 0).
            $window = [];
            foreach ($finder->nearest($target, COMPARE_LIMIT + 1 + 3) as $neighbor) {
                $id = (int) $neighbor['product']->getId();
                if ($id !== $target->getId()) {
                    $window[] = [$id, $neighbor['distance']];
                }
            }
            $divergences[] = [
                'products' => $productCount,
                'target' => (int) $target->getId(),
                'kind' => $sortedBefore === $sortedAfter ? 'ordem' : 'conjunto',
                'before' => $beforeIds,
                'after' => $afterIds,
                'window' => $window,
                'tie' => KnnTieCheck::explained(distances($before), distances($after)),
            ];
        }

        $manualCap = max($manualCap, count($manual->recommend($target, CAP_LIMIT)));
        $rubixCap = max($rubixCap, count($rubix->recommend($target, CAP_LIMIT)));
    }

    $targets = count($catalog);
    $rows[] = [
        $productCount,
        $targets,
        "{$sameSet}/{$targets}",
        "{$sameOrder}/{$targets}",
        sprintf('%.3g', $maxScoreDiff),
        "{$manualCap} → {$rubixCap}",
    ];
}

echo '| Produtos | Alvos | Mesmo conjunto (limit=' . COMPARE_LIMIT . ') | Mesma ordem (limit='
    . COMPARE_LIMIT . ') | Maior diferença de score | Máx. itens ML com limit=' . CAP_LIMIT . " (antes → depois) |\n";
echo "|---|---|---|---|---|---|\n";
foreach ($rows as $row) {
    echo '| ' . implode(' | ', array_map('strval', $row)) . " |\n";
}

echo "\nDivergências (ids na ordem devolvida; distâncias da versão atual, alvo excluído):\n\n";
if ($divergences === []) {
    echo "(nenhuma)\n";
}
foreach ($divergences as $d) {
    $window = implode(', ', array_map(
        static fn (array $n): string => sprintf('%d@%.9f', $n[0], $n[1]),
        $d['window']
    ));
    printf(
        "- %d produtos, alvo %d, divergência de %s: antes [%s], depois [%s]; vizinhos: %s; explicada por empate de distância: %s\n",
        $d['products'],
        $d['target'],
        $d['kind'],
        implode(', ', $d['before']),
        implode(', ', $d['after']),
        $window,
        $d['tie'] ? 'sim' : 'não'
    );
}

// Divergência que não é empate quer dizer que a reescrita mudou o resultado.
$unexplained = count(array_filter($divergences, static fn (array $d): bool => ! $d['tie']));
if ($unexplained > 0) {
    fwrite(STDERR, "\n{$unexplained} divergência(s) sem empate de distância.\n");
    exit(2);
}

exit(0);
