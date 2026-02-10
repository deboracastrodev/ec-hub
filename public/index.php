<?php

declare(strict_types=1);

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\ProductController;
use App\Controller\RecommendationController;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\Exceptions\RecommendationException;
use App\Service\CategoryService;

/**
 * ec-hub Application Entry Point
 *
 * Minimal web-facing entry point.
 * Sensitive logic is in config/bootstrap.php (outside web root).
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Allow test harness to inject a container and bypass infrastructure bootstrapping.
$container = isset($GLOBALS['EC_HUB_TEST_CONTAINER']) && is_array($GLOBALS['EC_HUB_TEST_CONTAINER'])
    ? $GLOBALS['EC_HUB_TEST_CONTAINER']
    : (require __DIR__ . '/../config/bootstrap.php');

$twig = $container['twig'];

// Simple router
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
    // Determine MIME type
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

// Route mapping
$routes = [
    'GET /' => ['controller' => 'ProductController', 'action' => 'index'],
    'GET /products' => ['controller' => 'ProductController', 'action' => 'index'],
    'GET /api/recommendations' => ['controller' => 'RecommendationController', 'action' => 'getRecommendations', 'type' => 'api'],
];

// Pattern routes (with parameters) - pattern is URI only, method is checked separately
$patternRoutes = [
    '/products/([A-Za-z0-9-]+)' => ['method' => 'GET', 'controller' => 'ProductController', 'action' => 'show'],
];

// Match exact routes first
$routeKey = "$method $uri";
$matchedRoute = $routes[$routeKey] ?? null;
$params = [];

// If no exact match, try pattern routes
if (!$matchedRoute) {
    foreach ($patternRoutes as $pattern => $route) {
        if ($route['method'] === $method && preg_match('#^' . $pattern . '$#', $uri, $matches)) {
            $matchedRoute = $route;
            $params = array_slice($matches, 1);
            break;
        }
    }
}

// 404 if no route matched
if (!$matchedRoute) {
    http_response_code(404);
    echo $twig->render('error/404.html.twig', ['message' => 'Página não encontrada']);
    exit;
}

// Create controller with dependencies via lightweight resolver
$controllerClass = "App\\Controller\\{$matchedRoute['controller']}";
$action = $matchedRoute['action'];

switch ($controllerClass) {
    case ProductController::class:
        $productRepository = $container['repositories']['product']($container['pdo']);
        $categoryService = new CategoryService($productRepository);
        $getProductList = new GetProductList($productRepository, $categoryService);
        $getProductDetail = new GetProductDetail($productRepository);
        $controller = new ProductController($getProductList, $getProductDetail, $twig);
        break;
    case RecommendationController::class:
        $logger = $container['services']['logger']($container);
        $generateRecommendations = $container['services']['generate_recommendations']($container);
        $controller = new RecommendationController($generateRecommendations, $logger);
        break;
    default:
        throw new RuntimeException("Controller {$controllerClass} não configurado");
}

// Extract query params for index action
$queryParams = $_GET;

// Determine if this is an API route
$isApiRoute = isset($matchedRoute['type']) && $matchedRoute['type'] === 'api';

// Call controller action with exception handling for API
try {
    if ($action === 'show') {
        $identifier = (string) $params[0];
        $output = $controller->$action($identifier);
    } else {
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
        $output = $controller->$action($queryParams, $headers);
    }

    // Send response
    if ($isApiRoute) {
        // API endpoints return JSON
        $responseTimeMs = $output['meta']['response_time_ms'] ?? 0;
        $source = $output['meta']['source'] ?? 'unknown';

        header('Content-Type: application/json');
        header('X-Recommendation-Source: ' . $source);
        header('X-Response-Time: ' . round($responseTimeMs, 2) . 'ms');
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        // HTML endpoints
        header('Content-Type: text/html; charset=utf-8');
        echo $output;
    }
} catch (InvalidRequestException $e) {
    // AC4: 400 Bad Request for validation errors
    http_response_code($e->getHttpCode());
    if ($isApiRoute) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => $e->getMessage(),
            'code' => $e->getHttpCode(),
        ], JSON_PRETTY_PRINT);
    } else {
        echo $twig->render('error/400.html.twig', ['message' => $e->getMessage()]);
    }
} catch (RecommendationException $e) {
    // Internal service error
    http_response_code($e->getHttpCode());
    if ($isApiRoute) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => $e->getMessage(),
            'code' => $e->getHttpCode(),
        ], JSON_PRETTY_PRINT);
    } else {
        http_response_code(500);
        echo $twig->render('error/500.html.twig', ['message' => 'Erro interno']);
    }
} catch (\Exception $e) {
    // Generic error handler
    http_response_code(500);
    if ($isApiRoute) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Internal server error',
            'code' => 500,
        ], JSON_PRETTY_PRINT);
    } else {
        echo $twig->render('error/500.html.twig', ['message' => 'Erro interno']);
    }
}
