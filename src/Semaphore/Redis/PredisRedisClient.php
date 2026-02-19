<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore\Redis;

use Predis\ClientInterface;
use Predis\PredisException;
use Predis\Response\ErrorInterface as ErrorResponseInterface;
use RuntimeException;

class PredisRedisClient implements RedisClientInterface
{
    public function __construct(
        private ClientInterface $redis,
    ) {}

    /**
     * @param list<string> $keys
     * @param list<string> $args
     * @throws RuntimeException
     */
    #[\Override]
    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        try {
            /** @var mixed */
            $response = $this->redis->eval($script, count($keys), ...[...$keys, ...$args]);
        } catch (PredisException $e) {
            throw new RuntimeException('Redis eval failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response instanceof ErrorResponseInterface && !$this->redis->getOptions()->exceptions) {
            throw new RuntimeException($response->getMessage());
        }

        return $response;
    }

    /**
     * @throws RuntimeException
     */
    #[\Override]
    public function exists(string $key): bool
    {
        try {
            return $this->redis->exists($key) > 0;
        } catch (PredisException $e) {
            throw new RuntimeException('Redis exists check failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
