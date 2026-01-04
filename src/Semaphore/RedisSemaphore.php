<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use Closure;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore;
use RedisException;
use RuntimeException;

readonly class RedisSemaphore implements Semaphore
{
    /**
     * @param positive-int $maxConcurrentLocks
     */
    public function __construct(
        public \Redis $redisClient,
        private int $maxConcurrentLocks = 1,
        private string $keyPrefix = '',
    ) {}

    /**
     * @param Closure(): void $suspension
     * @throws RuntimeException
     */
    #[\Override]
    public function lock(Resource $resource, #[\SensitiveParameter] string $token, Closure $suspension): void
    {
        $luaScript = <<<LUA
            local field_count = redis.call('HLEN', KEYS[1])
            local locked_version = redis.call('HGET', KEYS[1], 'version')
            local max_concurrent_locks = redis.call('HGET', KEYS[1], 'max_locks')
            local token = ARGV[1]
            local field_ttl = tonumber(ARGV[2])
            local version = tonumber(ARGV[3])
            local max_concurrent_locks_arg = tonumber(ARGV[4])

            if max_concurrent_locks == false or max_concurrent_locks == nil then
                max_concurrent_locks = max_concurrent_locks_arg
            else
                max_concurrent_locks = tonumber(max_concurrent_locks)
                field_count = field_count - 1
            end

            if locked_version ~= nil and locked_version ~= false then
                locked_version = tonumber(locked_version)
                field_count = field_count - 1

                if locked_version > version then
                    return redis.error_reply("MiMatus_VERSION_MISMATCH")
                end
                if locked_version < version then
                    return redis.error_reply("MiMatus_VERSION_LOCKED")
                end
            end

            if field_count < max_concurrent_locks then
                local token_key = "TOKEN_" .. token
                -- 1. Set the hash field with the value
                redis.call('HSET', KEYS[1], 
                    token_key, token,
                    "version", version,
                    "max_locks", max_concurrent_locks
                )

                -- 2. Set expiration for the specific hash field
                -- We use 'GT' so it only updates if the new TTL is further in the future
                -- field_ttl is treated as milliseconds for HPEXPIRE
                redis.call('HPEXPIRE', KEYS[1], field_ttl, 'FIELDS', 1, token_key)

                -- 4. Try to set the TTL only if there is no existing TTL 'NX'
                local set_nx = redis.call('PEXPIRE', KEYS[1], field_ttl, 'NX')

                -- 5. Update Key TTL if there already is one and only if the new value is Greater Than current 'GT'
                if set_nx == 0 then
                    redis.call('PEXPIRE', KEYS[1], field_ttl, 'GT')
                end
                return 1 -- Success
            else
                return redis.error_reply("MiMatus_MAX_LOCKS_REACHED"..field_count)
            end
        LUA;

        $milisecondsTTL = (int) ($resource->ttlNanoseconds / 1_000_000);

        do {
            try {
                /** @var bool|int */
                $result = $this->redisClient->eval(
                    $luaScript,
                    [
                        $this->keyPrefix . $resource->namespace,
                        $token,
                        $milisecondsTTL,
                        $resource->version,
                        $this->maxConcurrentLocks,
                    ],
                    1,
                );
                if ($result !== false) {
                    break;
                }

                $errorMessage = $this->redisClient->getLastError() ?? 'Unknown error';
                if (str_contains($errorMessage, 'MiMatus_VERSION_MISMATCH')) {
                    throw new RuntimeException('Lock version mismatch');
                }

                if (str_contains($errorMessage, 'MiMatus_VERSION_LOCKED')) {
                    $suspension();
                    continue;
                }

                if (str_contains($errorMessage, 'MiMatus_MAX_LOCKS_REACHED')) {
                    $suspension();
                    continue;
                }

                throw new RuntimeException(message: 'Redis Error: ' . $errorMessage);
            } catch (RedisException $e) {
                throw new RuntimeException(message: 'Redis Error: ' . $e->getMessage(), previous: $e);
            }
        } while (true);
    }

    /**
     * @throws RuntimeException
     */
    #[\Override]
    public function unlock(Resource $resource, #[\SensitiveParameter] string $token): void
    {
        $luaScript = <<<LUA
            local namespace = KEYS[1]
            local token = ARGV[1]

            local exists = redis.call('HEXISTS', namespace, "TOKEN_"..token)

            if exists == 1 then
                -- 1. Remove the specific field
                redis.call('HDEL', namespace, "TOKEN_"..token)
                
                -- 2. Check if the hash is now empty
                local remaining_fields = redis.call('HLEN', namespace)
                
                if remaining_fields <= 2 then
                    redis.call('DEL', namespace)
                end
                
                return 1
            else
                return 0
            end
        LUA;

        try {
            /** @var bool|int */
            $result = $this->redisClient->eval($luaScript, [$this->keyPrefix . $resource->namespace, $token], 1);
            if ($result === false) {
                throw new RuntimeException('Redis Error: ' . ($this->redisClient->getLastError() ?? 'Unknown error'));
            }
        } catch (RedisException $e) {
            throw new RuntimeException(message: 'Redis Error: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws RuntimeException
     */
    #[\Override]
    public function isLocked(Resource $resource): bool
    {
        try {
            /** @var bool|int */
            $exists = $this->redisClient->exists($resource->namespace);
        } catch (RedisException $e) {
            throw new RuntimeException(message: 'Redis Error: ' . $e->getMessage(), previous: $e);
        }
        if ($exists === false) {
            throw new RuntimeException('Redis Error: ' . ($this->redisClient->getLastError() ?? 'Unknown error'));
        }
        return $exists === 1;
    }
}
