<?php

declare(strict_types=1);

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\ProductController;
use App\Service\CategoryService;

/**
 * ec-hub Application Entry Point
 *
 * Minimal web-facing entry point.
 * Sensitive logic is in config/bootstrap.php (outside web root).
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap application - sensitive logic is outside web root
$container = require __DIR__ . '/../config/bootstrap.php';

$twig = $container['twig'];
$productRepository = $container['repositories']['product']($container['pdo']);
$categoryService = new CategoryService($productRepository);
$getProductList = new GetProductList($productRepository, $categoryService);
$getProductDetail = new GetProductDetail($productRepository);

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
];

// Pattern routes (with parameters) - pattern is URI only, method is checked separately
$patternRoutes = [
    '/products/(\d+)' => ['method' => 'GET', 'controller' => 'ProductController', 'action' => 'show'],
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
        $controller = new ProductController($getProductList, $getProductDetail, $twig);
        break;
    default:
        throw new RuntimeException("Controller {$controllerClass} não configurado");
}

// Extract query params for index action
$queryParams = $_GET;

// Call controller action
if ($action === 'show') {
    $id = (int) $params[0];
    $output = $controller->$action($id);
} else {
    $output = $controller->$action($queryParams);
}

// Send response
header('Content-Type: text/html; charset=utf-8');
echo $output;
