<?php

declare(strict_types=1);

use App\Application\Monitoring\HttpRequestRecorder;
use App\Controller\AbTestResultsController;
use App\Controller\Admin\AdminAuthController;
use App\Controller\Admin\AdminProductController;
use App\Controller\CartController;
use App\Controller\CheckoutController;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\HealthCheckController;
use App\Controller\MemoryMonitoringController;
use App\Controller\MetricsController;
use App\Controller\MetricsExportController;
use App\Controller\ProductController;
use App\Controller\ProductInteractionController;
use App\Controller\RecommendationController;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Shared\Http\ErrorHandler;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
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

// Story 8.4: start of the request, for the HTTP duration histogram.
$requestStartedAt = hrtime(true);

require_once __DIR__ . '/../vendor/autoload.php';

// Allow test harness to inject a container and bypass infrastructure bootstrapping.
$container = isset($GLOBALS['EC_HUB_TEST_CONTAINER']) && $GLOBALS['EC_HUB_TEST_CONTAINER'] instanceof ContainerInterface
    ? $GLOBALS['EC_HUB_TEST_CONTAINER']
    : (require __DIR__ . '/../config/bootstrap.php');

/**
 * Story 8.4: records the request in the global HTTP metrics. Static assets
 * are not counted; the status is whatever was sent (200 when unknown). A
 * metrics failure never changes the response.
 */
$recordHttpRequest = static function (ContainerInterface $container, string $method, string $route, int $startedAt): void {
    try {
        if (! $container->has(HttpRequestRecorder::class)) {
            return;
        }
        $status = http_response_code();
        $container->get(HttpRequestRecorder::class)->record(
            $method,
            $route,
            is_int($status) ? $status : 200,
            (hrtime(true) - $startedAt) / 1e9
        );
    } catch (\Throwable) {
        // HttpRequestRecorder already logs repository failures.
    }
};

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
        'GET /health' => ['controller' => HealthCheckController::class, 'action' => 'index', 'api' => true],
        'GET /api/recommendations' => ['controller' => RecommendationController::class, 'action' => 'getRecommendations', 'api' => true],
        'GET /api/ab-tests/results' => ['controller' => AbTestResultsController::class, 'action' => 'results', 'api' => true],
        'GET /api/metrics' => ['controller' => MetricsExportController::class, 'action' => 'export', 'api' => true],
        'POST /api/events' => ['controller' => ProductInteractionController::class, 'action' => 'event', 'api' => true],
        'POST /api/cart/items' => ['controller' => ProductInteractionController::class, 'action' => 'addCartItem', 'api' => true],
        // Story 8.3: admin panel (Request in, Response out; see the admin branch below).
        'GET /admin/login' => ['controller' => AdminAuthController::class, 'action' => 'loginForm', 'admin' => true],
        'POST /admin/login' => ['controller' => AdminAuthController::class, 'action' => 'login', 'admin' => true],
        'POST /admin/logout' => ['controller' => AdminAuthController::class, 'action' => 'logout', 'admin' => true],
        'GET /admin/products' => ['controller' => AdminProductController::class, 'action' => 'index', 'admin' => true],
        'GET /admin/products/new' => ['controller' => AdminProductController::class, 'action' => 'newForm', 'admin' => true],
        'POST /admin/products' => ['controller' => AdminProductController::class, 'action' => 'create', 'admin' => true],
        // Story 8.6: cart page (Request in, Response out, without the admin headers).
        'GET /cart' => ['controller' => CartController::class, 'action' => 'index', 'request' => true],
        'POST /cart/items' => ['controller' => CartController::class, 'action' => 'add', 'request' => true],
        // Story 8.7: simulated checkout.
        'GET /checkout' => ['controller' => CheckoutController::class, 'action' => 'form', 'request' => true],
        'POST /checkout' => ['controller' => CheckoutController::class, 'action' => 'place', 'request' => true],
        'GET /checkout/confirmation' => ['controller' => CheckoutController::class, 'action' => 'confirmation', 'request' => true],
    ],
    [
        '/products/([A-Za-z0-9-]+)' => ['method' => 'GET', 'controller' => ProductController::class, 'action' => 'show'],
        '/admin/products/(\d+)/edit' => ['method' => 'GET', 'controller' => AdminProductController::class, 'action' => 'edit', 'admin' => true],
        '/admin/products/(\d+)' => ['method' => 'POST', 'controller' => AdminProductController::class, 'action' => 'update', 'admin' => true],
        '/admin/products/(\d+)/delete' => ['method' => 'POST', 'controller' => AdminProductController::class, 'action' => 'delete', 'admin' => true],
        '/cart/items/(\d+)' => ['method' => 'POST', 'controller' => CartController::class, 'action' => 'update', 'request' => true],
        '/cart/items/(\d+)/delete' => ['method' => 'POST', 'controller' => CartController::class, 'action' => 'remove', 'request' => true],
    ]
);

$matchedRoute = $router->match($method, $uri);

if ($matchedRoute === null) {
    http_response_code(404);
    echo $container->get(Environment::class)->render('error/404.html.twig', ['message' => 'Página não encontrada']);
    $recordHttpRequest($container, $method, 'unmatched', $requestStartedAt);
    exit;
}

$action = $matchedRoute->action;
$isApiRoute = $matchedRoute->isApi;

if ($matchedRoute->isAdmin) {
    // Set up front so an error page rendered by the catch blocks gets them too:
    // not cached, not frameable.
    foreach (Response::SECURITY_HEADERS as $name => $value) {
        header($name . ': ' . $value);
    }
}

$response = null;
$output = null;

try {
    // Inside the try (Story 8.4): a wiring failure becomes a 500 from the
    // ErrorHandler and is still counted in the HTTP metrics.
    $controller = $container->get($matchedRoute->controller);

    if ($matchedRoute->isAdmin || $matchedRoute->usesRequest) {
        $response = $controller->$action(new Request($method, $matchedRoute->params, $_GET, $_POST));
        if (! $response instanceof Response) {
            throw new \LogicException(sprintf('Action %s::%s must return a Response.', $matchedRoute->controller, $action));
        }
    } elseif ($matchedRoute->params !== []) {
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
    } elseif (in_array($matchedRoute->controller, [MemoryMonitoringController::class, HealthCheckController::class], true)) {
        $output = $controller->$action();
    } else {
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
        $sessionId = $container->has(SessionContext::class) ? $container->get(SessionContext::class)->id() : null;
        $output = $controller->$action($_GET, $headers, $sessionId);
    }

    // Story 8.4: a non-admin action may return a Response too (GET /api/metrics).
    if ($output instanceof Response) {
        $response = $output;
    }

    if ($response instanceof Response) {
        http_response_code($response->status);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($matchedRoute->isAdmin) {
            // Admin pages are private and not frameable, whatever the controller returned.
            foreach (Response::SECURITY_HEADERS as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $response->body;
    } elseif ($isApiRoute) {
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
    $errorHandler = new ErrorHandler($container->get(Environment::class));
    $errorHandler->handleInvalidRequest($e, $isApiRoute);
} catch (RecommendationException $e) {
    $errorHandler = new ErrorHandler($container->get(Environment::class));
    $errorHandler->handleRecommendationFailure($e, $isApiRoute);
} catch (\Throwable $e) {
    $errorHandler = new ErrorHandler($container->get(Environment::class));
    $errorHandler->handleUnexpected($e, $isApiRoute);
}

$recordHttpRequest($container, $method, $matchedRoute->route !== '' ? $matchedRoute->route : 'unknown', $requestStartedAt);
