<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Event\EventStoreInterface;
use InvalidArgumentException;
use JsonException;
use Predis\Client;
use RuntimeException;

final class RedisEventSubscriber
{
    /** @param array{host: string, port: int} $config */
    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly array $config,
    ) {
    }

    /**
     * Consome uma mensagem de um canal e retorna após a persistência.
     *
     * @param callable(): void|null $onReady
     */
    public function consumeOnce(string $event, ?callable $onReady = null): void
    {
        RedisEventBus::assertEventName($event);
        $client = new Client(['scheme' => 'tcp', ...$this->config]);
        $pubSub = null;
        $channel = "events:{$event}";

        try {
            $pubSub = $client->pubSubLoop();
            $pubSub->subscribe($channel);

            foreach ($pubSub as $message) {
                if ($message->kind === 'subscribe') {
                    if ($onReady !== null) {
                        call_user_func($onReady);
                    }

                    continue;
                }

                if ($message->kind !== 'message' || $message->channel !== $channel) {
                    continue;
                }

                try {
                    $envelope = json_decode($message->payload, true, 100, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('Received event envelope is not valid JSON.', 0, $exception);
                }

                if (! is_array($envelope)) {
                    throw new InvalidArgumentException('Received event envelope must be an object.');
                }

                if (($envelope['event'] ?? null) !== $event) {
                    throw new InvalidArgumentException('Received event does not match the subscribed channel.');
                }

                $this->store->append($envelope);

                return;
            }

            throw new RuntimeException('Subscription ended before receiving an event.');
        } finally {
            if ($pubSub !== null) {
                try {
                    $pubSub->unsubscribe();
                } catch (\Throwable) {
                    // Preserva a exceção principal de consumo ou persistência.
                }
            }

            try {
                $client->disconnect();
            } catch (\Throwable) {
                // Preserva a exceção principal de consumo ou persistência.
            }
        }
    }
}
