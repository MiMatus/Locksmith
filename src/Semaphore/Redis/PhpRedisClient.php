<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore\Redis;

use RuntimeException;

class PhpRedisClient implements RedisClientInterface
{
    public function __construct(
        private \Redis $redis,
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
            $result = $this->redis->eval($script, [...$keys, ...$args], count($keys));
        } catch (\RedisException $e) {
            throw new RuntimeException('Redis eval failed: ' . $e->getMessage(), 0, $e);
        }

        if ($result === false) {
            $errorMessage = $this->redis->getLastError() ?? 'Unknown error';
            throw new RuntimeException('Redis eval failed: ' . $errorMessage);
        }
        return $result;
    }

    /**
     * @throws RuntimeException
     */
    #[\Override]
    public function exists(string $key): bool
    {
        try {
            return $this->redis->exists($key) > 0;
        } catch (\RedisException $e) {
            throw new RuntimeException('Redis exists check failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
