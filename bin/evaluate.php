<?php

declare(strict_types=1);

/**
 * Story 10.2: harness de avaliação offline do recomendador KNN.
 *
 * Carrega um catálogo versionado, faz o split treino/holdout com seed fixa,
 * treina o KNN real (KNNService + RubixNeighborFinder) só com o treino e mede
 * precision@k e recall@k para k = 1, 5 e 10. Grava o relatório em Markdown e
 * JSON. Não precisa de Docker, MySQL nem Redis.
 *
 * Uso: php bin/evaluate.php [--catalog=CAMINHO] [--seed=N] [--output-dir=DIR]
 *      (ou make eval)
 *
 * Saída: 0 em sucesso; 1 em opção inválida ou catálogo inválido (nada é gravado).
 */

use App\Application\Recommendation\Evaluation\EvaluationReportWriter;
use App\Application\Recommendation\Evaluation\OfflineEvaluation;
use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Evaluation\HoldoutSplit;
use App\Domain\Recommendation\Service\KNNService;
use App\Infrastructure\ML\RubixNeighborFinder;
use Tests\Support\InMemoryProductRepository;

// O Rubix 2.5 dispara deprecations de SplObjectStorage no PHP 8.5; só ruído aqui.
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

const USAGE = <<<'TXT'
Uso: php bin/evaluate.php [--catalog=CAMINHO] [--seed=N] [--output-dir=DIR]

  --catalog=CAMINHO   catálogo JSON (padrão: database/fixtures/evaluation-catalog.json)
  --seed=N            seed inteira do split treino/holdout (padrão: 42)
  --output-dir=DIR    diretório do relatório (padrão: docs/evaluation)

TXT;

function fail(string $message, bool $withUsage = false): never
{
    fwrite(STDERR, 'Erro: ' . $message . "\n");
    if ($withUsage) {
        fwrite(STDERR, "\n" . USAGE);
    }
    exit(1);
}

function resolvePath(string $path, string $base): string
{
    return str_starts_with($path, '/') ? $path : $base . '/' . $path;
}

function displayPath(string $absolute, string $root): string
{
    $real = realpath($absolute);
    $path = $real !== false ? $real : $absolute;
    $rootReal = realpath($root);

    if ($rootReal !== false && str_starts_with($path, $rootReal . '/')) {
        return substr($path, strlen($rootReal) + 1);
    }

    return $path;
}

// --- opções --------------------------------------------------------------
$options = [
    'catalog' => 'database/fixtures/evaluation-catalog.json',
    'seed' => '42',
    'output-dir' => 'docs/evaluation',
];
$given = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, USAGE);
        exit(0);
    }
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $match) !== 1 || ! array_key_exists($match[1], $options)) {
        fail(sprintf('opção desconhecida: %s', $argument), true);
    }
    if (($match[2] ?? '') === '') {
        fail(sprintf('a opção --%s precisa de um valor.', $match[1]), true);
    }
    $options[$match[1]] = $match[2];
    $given[$match[1]] = true;
}

if (preg_match('/^\d{1,10}$/', $options['seed']) !== 1 || (int) $options['seed'] > 4294967295) {
    fail(sprintf('--seed precisa ser um inteiro entre 0 e 4294967295; recebido "%s".', $options['seed']), true);
}
$seed = (int) $options['seed'];

$cwd = (string) getcwd();
// Os padrões são relativos à raiz do projeto; caminhos passados, ao diretório atual.
$catalogPath = resolvePath($options['catalog'], isset($given['catalog']) ? $cwd : $root);
$outputDir = resolvePath($options['output-dir'], isset($given['output-dir']) ? $cwd : $root);

if (! class_exists(InMemoryProductRepository::class)) {
    fail('classes de teste ausentes no autoload; rode `composer install` com as dependências de dev.');
}

// --- catálogo ------------------------------------------------------------
if (! is_file($catalogPath) || ! is_readable($catalogPath)) {
    fail(sprintf('catálogo não encontrado ou ilegível: %s', $options['catalog']));
}

$raw = @file_get_contents($catalogPath);
if ($raw === false) {
    fail(sprintf('não foi possível ler o catálogo: %s', $options['catalog']));
}
try {
    $rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fail(sprintf('o catálogo não é um JSON válido (%s): %s', $e->getMessage(), $options['catalog']));
}

if (! is_array($rows) || ! array_is_list($rows)) {
    fail('o catálogo precisa ser uma lista JSON de produtos.');
}
if (count($rows) < HoldoutSplit::MIN_PRODUCTS) {
    fail(sprintf('o catálogo precisa de pelo menos %d produtos; tem %d.', HoldoutSplit::MIN_PRODUCTS, count($rows)));
}

$seenIds = [];
foreach ($rows as $index => $row) {
    $position = $index + 1;
    if (! is_array($row)) {
        fail(sprintf('o item %d do catálogo não é um objeto de produto.', $position));
    }
    if (! isset($row['id']) || ! is_int($row['id']) || $row['id'] < 1) {
        fail(sprintf('o item %d do catálogo precisa de "id" inteiro positivo.', $position));
    }
    if (isset($seenIds[$row['id']])) {
        fail(sprintf('id duplicado no catálogo: %d.', $row['id']));
    }
    $seenIds[$row['id']] = true;
    if (! isset($row['name']) || ! is_string($row['name']) || $row['name'] === '') {
        fail(sprintf('o produto %d precisa de "name".', $row['id']));
    }
    if (! isset($row['category']) || ! is_string($row['category']) || $row['category'] === '') {
        fail(sprintf('o produto %d precisa de "category".', $row['id']));
    }
    if (! isset($row['price']) || (! is_int($row['price']) && ! is_float($row['price'])) || $row['price'] < 0) {
        fail(sprintf('o produto %d precisa de "price" numérico não negativo.', $row['id']));
    }
}

try {
    $products = array_map(static fn (array $row): Product => Product::fromArray($row), $rows);
} catch (Throwable $e) {
    fail(sprintf('produto inválido no catálogo: %s', $e->getMessage()));
}

// --- avaliação -----------------------------------------------------------
// O repositório exigido pelo KNNService só enxerga o treino: nem um retreino
// lazy a partir dele conseguiria vazar o holdout para o índice.
$evaluation = new OfflineEvaluation(
    static fn (array $train): KNNService => new KNNService(
        new InMemoryProductRepository(array_map(static fn (Product $p): array => $p->toArray(), $train)),
        new RubixNeighborFinder()
    ),
    [1, 5, 10]
);

try {
    $result = $evaluation->run($products, $seed);
} catch (Throwable $e) {
    fail(sprintf('a avaliação falhou: %s', $e->getMessage()));
}

$catalogInfo = [
    'path' => displayPath($catalogPath, $root),
    'products' => count($products),
    'sha256' => (string) hash_file('sha256', $catalogPath),
];

try {
    $paths = (new EvaluationReportWriter())->write($result, date('Y-m-d'), $catalogInfo, $outputDir);
} catch (Throwable $e) {
    fail(sprintf('não foi possível gravar o relatório: %s', $e->getMessage()));
}

// --- resumo --------------------------------------------------------------
$split = $result['split'];
printf("Avaliação offline: algoritmo %s\n", $result['algorithm']);
printf("Catálogo: %s (%d produtos)\n", $catalogInfo['path'], $catalogInfo['products']);
printf(
    "Split: seed %d, %d no treino, %d no holdout\n",
    $split['seed'],
    $split['train_size'],
    $split['holdout_size']
);
printf(
    "Consultas: %d avaliadas, %d sem relevante no treino\n\n",
    $result['queries']['evaluated'],
    $result['queries']['without_relevant']
);
printf("| %3s | %11s | %8s |\n", 'k', 'precision@k', 'recall@k');
printf("|%s:|%s:|%s:|\n", str_repeat('-', 4), str_repeat('-', 12), str_repeat('-', 9));
foreach ($result['metrics']['precision_at_k'] as $k => $precision) {
    $recall = $result['metrics']['recall_at_k'][$k];
    printf(
        "| %3d | %11s | %8s |\n",
        $k,
        $precision === null ? 'n/a' : sprintf('%.4f', $precision),
        $recall === null ? 'n/a' : sprintf('%.4f', $recall)
    );
}
printf("\nRelatório gravado em:\n  %s\n  %s\n", displayPath($paths['markdown'], $root), displayPath($paths['json'], $root));

exit(0);
