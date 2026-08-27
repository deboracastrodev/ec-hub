<?php

declare(strict_types=1);

use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\MemoryMonitoringController;
use App\Controller\ProductController;
use App\Controller\ProductInteractionController;
use App\Controller\MetricsController;
use App\Controller\RecommendationController;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Shared\Http\ErrorHandler;
use App\Shared\Http\Router;
use App\Shared\Http\SessionContext;
use Psr\Container\ContainerInterface;
use Twig\Environment;

/**
 * ec-hub Application Entry Point
 *
 * Minimal web-facing entry point: static assets, routing, dispatch, and
 * error mapping are the only things done here. Everything else (dependency
 * wiring) is in config/bootstrap.php (outside web root); routing and error
 * mapping are in App\Shared\Http (R5.6).
 */

// A baseline pertence exclusivamente à requisição atual. Ela é capturada
// antes do container e de qualquer dependência da rota de diagnóstico.
$GLOBALS['EC_HUB_MEMORY_BASELINE'] = memory_get_usage();

require_once __DIR__ . '/../vendor/autoload.php';

// Allow test harness to inject a container and bypass infrastructure bootstrapping.
$container = isset($GLOBALS['EC_HUB_TEST_CONTAINER']) && $GLOBALS['EC_HUB_TEST_CONTAINER'] instanceof ContainerInterface
    ? $GLOBALS['EC_HUB_TEST_CONTAINER']
    : (require __DIR__ . '/../config/bootstrap.php');

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Static files serving - serve assets before routing (protect against traversal)
$publicDir = realpath(__DIR__);
$staticFile = $publicDir && $uri !== null ? realpath($publicDir . $uri) : false;
if (
    $publicDir !== false &&
    $staticFile !== false &&
    strpos($staticFile, $publicDir . DIRECTORY_SEPARATOR) === 0 &&
    is_file($staticFile)
) {
    $extension = pathinfo($staticFile, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=31536000'); // 1 year cache
    readfile($staticFile);
    exit;
}

$router = new Router(
    [
        'GET /' => ['controller' => ProductController::class, 'action' => 'index'],
        'GET /products' => ['controller' => ProductController::class, 'action' => 'index'],
        'GET /metrics' => ['controller' => MetricsController::class, 'action' => 'index'],
        'GET /debug/memory' => ['controller' => MemoryMonitoringController::class, 'action' => 'index', 'api' => true],
        'GET /api/recommendations' => ['controller' => RecommendationController::class, 'action' => 'getRecommendations', 'api' => true],
        'POST /api/events' => ['controller' => ProductInteractionController::class, 'action' => 'event', 'api' => true],
        'POST /api/cart/items' => ['controller' => ProductInteractionController::class, 'action' => 'addCartItem', 'api' => true],
    ],
    [
        '/products/([A-Za-z0-9-]+)' => ['method' => 'GET', 'controller' => ProductController::class, 'action' => 'show'],
    ]
);

$matchedRoute = $router->match($method, $uri);

if ($matchedRoute === null) {
    http_response_code(404);
    echo $container->get(Environment::class)->render('error/404.html.twig', ['message' => 'Página não encontrada']);
    exit;
}

$controller = $container->get($matchedRoute->controller);
$action = $matchedRoute->action;
$isApiRoute = $matchedRoute->isApi;
$errorHandler = new ErrorHandler($container->get(Environment::class));

try {
    if ($matchedRoute->params !== []) {
        $output = $controller->$action((string) $matchedRoute->params[0], $_GET);
    } elseif ($isApiRoute && $method === 'POST') {
        $rawBody = isset($GLOBALS['EC_HUB_TEST_JSON_BODY']) && is_string($GLOBALS['EC_HUB_TEST_JSON_BODY'])
            ? $GLOBALS['EC_HUB_TEST_JSON_BODY']
            : (file_get_contents('php://input') ?: '');
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new InvalidRequestException('JSON body is required');
        }
        $output = $controller->$action($payload);
    } elseif ($matchedRoute->controller === MemoryMonitoringController::class) {
        $output = $controller->$action();
    } else {
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
        $sessionId = $container->has(SessionContext::class) ? $container->get(SessionContext::class)->id() : null;
        $output = $controller->$action($_GET, $headers, $sessionId);
    }

    if ($isApiRoute) {
        header('Content-Type: application/json');
        if ($matchedRoute->controller === RecommendationController::class) {
            $responseTimeMs = $output['meta']['response_time_ms'] ?? 0;
            $source = $output['meta']['source'] ?? 'unknown';
            header('X-Recommendation-Source: ' . $source);
            header('X-Response-Time: ' . round($responseTimeMs, 2) . 'ms');
        }
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo $output;
    }
} catch (InvalidRequestException $e) {
    $errorHandler->handleInvalidRequest($e, $isApiRoute);
} catch (RecommendationException $e) {
    $errorHandler->handleRecommendationFailure($e, $isApiRoute);
} catch (\Throwable $e) {
    $errorHandler->handleUnexpected($e, $isApiRoute);
}
