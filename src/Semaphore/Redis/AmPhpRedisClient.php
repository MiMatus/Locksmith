<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore\Redis;

use Amp\Redis\RedisClient;
use RuntimeException;

class AmPhpRedisClient implements RedisClientInterface
{
    public function __construct(
        private RedisClient $redis,
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
            return $this->redis->eval($script, $keys, $args);
        } catch (\RedisException $e) {
            throw new RuntimeException('Redis eval failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws RuntimeException
     */
    #[\Override]
    public function exists(string $key): bool
    {
        try {
            return $this->redis->has($key);
        } catch (\RedisException $e) {
            throw new RuntimeException('Redis exists check failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
