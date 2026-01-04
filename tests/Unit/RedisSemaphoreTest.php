<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use Exception;
use MiMatus\Locksmith\Semaphore;
use MiMatus\Locksmith\Semaphore\RedisSemaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use Override;
use Redis;

class RedisSemaphoreTest extends SemaphoreTestCase
{
    private Redis $redis;

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new Redis();
        $this->redis->connect('redis');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushAll();
        $this->redis->close();
    }

    protected function advanceTime(int $nanoseconds): void
    {
        usleep((int) $nanoseconds / 1000);
        parent::advanceTime($nanoseconds);
    }

    protected function createSemaphore(TimeProvider $timeProvider, $maxConcurrentLocks = 1): Semaphore
    {
        return new RedisSemaphore(redisClient: $this->redis, maxConcurrentLocks: $maxConcurrentLocks);
    }
}
