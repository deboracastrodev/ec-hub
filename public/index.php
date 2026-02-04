<?php

declare(strict_types=1);

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

// Simple router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Static files serving - serve assets before routing
$staticFile = __DIR__ . $uri;
if (file_exists($staticFile) && is_file($staticFile)) {
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

// Pattern routes (with parameters)
$patternRoutes = [
    'GET /products/(\d+)' => ['controller' => 'ProductController', 'action' => 'show'],
];

// Match exact routes first
$routeKey = "$method $uri";
$matchedRoute = $routes[$routeKey] ?? null;
$params = [];

// If no exact match, try pattern routes
if (!$matchedRoute) {
    foreach ($patternRoutes as $pattern => $route) {
        if (preg_match('#^' . $pattern . '$#', $uri, $matches)) {
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

// Create controller with dependencies
$controllerClass = "App\\Controller\\{$matchedRoute['controller']}";
$action = $matchedRoute['action'];
$controller = new $controllerClass($productRepository, $twig);

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
