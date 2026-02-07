<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore\Redis;

use RuntimeException;

interface RedisClientInterface
{
    /**
     * @param list<string> $keys
     * @param list<string> $args
     * @throws RuntimeException
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed;

    /**
     * @throws RuntimeException
     */
    public function exists(string $key): bool;
}
