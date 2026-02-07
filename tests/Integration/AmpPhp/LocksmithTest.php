<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Integration\AmpPhp;

use Closure;
use Exception;
use MiMatus\Locksmith\Locksmith;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore\Redis\RedisSemaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use MiMatus\Locksmith\SemaphoreInterface;
use Override;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await;
use function Amp\Redis\createRedisClient;

class LocksmithTest extends TestCase
{
    private SemaphoreInterface $semaphore;
    private TimeProvider $timeProvider;

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        $this->semaphore = new RedisSemaphore(
            redisClient: new \MiMatus\Locksmith\Semaphore\Redis\AmPhpRedisClient(redis: createRedisClient(
                'tcp://redis:6379',
            )),
        );

        $this->timeProvider = new TimeProvider();
    }

    public function testConcurrentTask(): void
    {
        $sharedCounter = 0;

        $task = static function (Closure $suspension) use (&$sharedCounter) {
            $suspension(); // Suspend! This allows the other fiber to run.
            $sharedCounter += 1;
        };

        $task2 = static function (Closure $suspension) use (&$sharedCounter) {
            self::assertSame(0, $sharedCounter, 'Task 2 should not see the incremented counter value');
            $suspension(); // Suspend! This allows the other fiber to run.
            $sharedCounter += 1;
        };

        $locksmith = new Locksmith(semaphore: $this->semaphore, timeProvider: $this->timeProvider);

        $locked = $locksmith->locked(
            resource: new Resource(namespace: 'test-lock key'),
            lockTTLNs: 5_000_000_000,
            maxLockWaitNs: 1_000_000_000,
            minSuspensionDelayNs: 10_000,
        );
        $locked2 = $locksmith->locked(
            resource: new Resource(namespace: 'test-lock key2'),
            lockTTLNs: 5_000_000_000,
            maxLockWaitNs: 1_000_000_000,
            minSuspensionDelayNs: 10_000,
        );

        $future1 = async(static fn() => $locked($task));
        $future2 = async(static fn() => $locked2($task2));

        await([$future2, $future1]);

        self::assertSame(2, $sharedCounter);
    }
}
