#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\Messaging\RedisEventStore;
use App\Infrastructure\Messaging\RedisEventSubscriber;
use Predis\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? null) !== '--once' || ! isset($argv[2]) || isset($argv[3])) {
    fwrite(STDERR, "Uso: php bin/consume-events.php --once noun.verb\n");
    exit(1);
}

/** @var array{host: string, port: int} $config */
$config = require dirname(__DIR__) . '/config/redis.php';
$store = new RedisEventStore(new Client(['scheme' => 'tcp', ...$config]));
$subscriber = new RedisEventSubscriber($store, $config);

try {
    $subscriber->consumeOnce($argv[2], static function (): void {
        fwrite(STDOUT, "READY\n");
    });
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
