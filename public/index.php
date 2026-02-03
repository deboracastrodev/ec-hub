<?php

declare(strict_types=1);

/**
 * ec-hub Application Entry Point
 *
 * Clean Architecture 4-Layer PHP Application
 * Layer 1 (Controller) -> Layer 2 (Application) -> Layer 3 (Domain) -> Layer 4 (Infrastructure)
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Define application constants
define('BASE_PATH', __DIR__ . '/..');
define('APP_START_TIME', microtime(true));

// Initialize Swoole HTTP Server
$server = new Swoole\Http\Server(
    getenv('SWOOLE_HTTP_SERVER_HOST') ?: '0.0.0.0',
    (int) (getenv('SWOOLE_HTTP_SERVER_PORT') ?: 9501)
);

$server->set([
    'worker_num' => (int) (getenv('SWOOLE_WORKER_NUM') ?: 4),
    'task_worker_num' => (int) (getenv('SWOOLE_TASK_WORKER_NUM') ?: 2),
    'enable_coroutine' => true,
    'max_request' => (int) (getenv('SWOOLE_MAX_REQUEST') ?: 5000),
    'hook_flags' => SWOOLE_HOOK_ALL,
]);

// Request handler
$server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
    try {
        // Create PSR-7 request from Swoole request
        $psrRequest = App\Shared\Helper\RequestFactory::createFromSwoole($request);

        // Route to appropriate controller
        $router = new App\Shared\Router\SimpleRouter();
        $controllerResponse = $router->dispatch($psrRequest);

        // Send response
        $response->status($controllerResponse->getStatusCode());
        $response->end($controllerResponse->getBody());

    } catch (Throwable $e) {
        // Error handling
        $errorResponse = App\Shared\Helper\ErrorBuilder::fromException($e);

        $response->status($errorResponse['status']);
        $response->header('Content-Type', 'application/problem+json');
        $response->end(json_encode($errorResponse));
    }
});

// Server start callback
$server->on('start', function (Swoole\Http\Server $server) {
    echo "🚀 ec-hub server started on http://{$server->host}:{$server->port}\n";
    echo "📁 Clean Architecture 4-Layer Application\n";
    echo "✨ Ready to serve requests\n";
});

// Worker start callback
$server->on('workerStart', function () {
    echo "✅ Worker started\n";
});

// Start the server
$server->start();
