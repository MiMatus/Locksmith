<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Integration\Predis;

use Exception;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore\Redis\PredisRedisClient;
use MiMatus\Locksmith\Semaphore\Redis\RedisSemaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use MiMatus\Locksmith\SemaphoreInterface;
use MiMatus\Locksmith\Tests\SemaphoreTestCase;
use Override;
use Predis\Client;

class PredisSemaphoreTest extends SemaphoreTestCase
{
    private Client $redis;

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new Client('tcp://redis:6379');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushAll();
        $this->redis->disconnect();
    }

    protected function advanceTime(int $nanoseconds): void
    {
        usleep((int) $nanoseconds / 1000);
        parent::advanceTime($nanoseconds);
    }

    protected function createSemaphore(TimeProvider $timeProvider, $maxConcurrentLocks = 1): SemaphoreInterface
    {
        $rediClient = new PredisRedisClient($this->redis);
        return new RedisSemaphore(redisClient: $rediClient, maxConcurrentLocks: $maxConcurrentLocks);
    }

    public function testLockingOccupiedKey(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $this->redis->set('test-lock-key', 'occupied');

        $resource = new Resource(namespace: 'test-lock-key');
        $semaphore->lock(
            resource: $resource,
            token: 'test-lock-token', // @mago-ignore lint:no-literal-password
            lockTTLNs: 5_000_000_000,
            suspension: static function (): void {
                self::fail('Lock should not have been acquired, suspension should not be called');
            },
        );

        self::assertTrue($semaphore->isLocked(resource: $resource), 'Resource should be locked');

        $this->redis->del('test-lock-key'); // Deleting same key does not affect lock

        self::assertTrue($semaphore->isLocked(resource: $resource), 'Resource should be locked');

        $semaphore->unlock(resource: $resource, token: 'test-lock-token'); // @mago-ignore lint:no-literal-password

        self::assertFalse($semaphore->isLocked(resource: $resource), 'Resource should be unlocked');
    }
}
