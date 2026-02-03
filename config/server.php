<?php

declare(strict_types=1);

/**
 * Swoole HTTP Server Configuration for ec-hub
 */
use Swoole\Constant;
use Swoole\Server;

return [
    'mode' => SWOOLE_PROCESS,
    'servers' => [
        [
            'name' => 'http',
            'type' => Server::TYPE_HTTP,
            'host' => env('SWOOLE_HTTP_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('SWOOLE_HTTP_SERVER_PORT', 9501),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
            'options' => [
                // Process Configuration
                'worker_num' => (int) env('SWOOLE_WORKER_NUM', 4),
                'task_worker_num' => (int) env('SWOOLE_TASK_WORKER_NUM', 2),

                // Server Configuration
                'daemonize' => false,
                'enable_coroutine' => true,

                // Request Handling
                'max_request' => (int) env('SWOOLE_MAX_REQUEST', 5000),
                'reload_async' => true,

                // Logging
                'log_file' => BASE_PATH . '/runtime/logs/swoole.log',
                'log_level' => SWOOLE_LOG_INFO,

                // Performance
                'enable_preemptive_scheduler' => true,
                'hook_flags' => SWOOLE_HOOK_ALL,

                // Buffer Configuration
                'socket_buffer_size' => 2 * 1024 * 1024,
                'buffer_output_size' => 2 * 1024 * 1024,
                'buffer_input_size' => 2 * 1024 * 1024,

                // Keep Alive
                'heartbeat_check_interval' => 60,
                'heartbeat_idle_time' => 120,
            ],
        ],
    ],
    'settings' => [
        // Enable coroutine hooks for better compatibility
        'enable_coroutine' => true,
        'hook_flags' => SWOOLE_HOOK_ALL,
    ],
];
