<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore\Redis;

use Redis;
use RedisCluster;
use RedisSentinel;
use RuntimeException;

class PhpRedisClient implements RedisClientInterface
{
    public function __construct(
        private Redis|RedisCluster $redis,
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
            $result = $this->redis->eval($script, [...$keys, ...$args], count($keys)); // @mago-ignore analysis:invalid-method-access RedisCluster
        } catch (\RedisException $e) {
            throw new RuntimeException('Redis eval failed: ' . $e->getMessage(), 0, $e);
        }

        if ($result === false) {
            /** @var string */
            $errorMessage = $this->redis->getLastError() ?? 'Unknown error'; // @mago-ignore analysis:invalid-method-access RedisCluster
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
            return (bool) $this->redis->exists($key); // @mago-ignore analysis:invalid-method-access RedisCluster
        } catch (\RedisException $e) {
            throw new RuntimeException('Redis exists check failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
