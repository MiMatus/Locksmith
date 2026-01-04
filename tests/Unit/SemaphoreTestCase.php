<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use Exception;
use Fiber;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore;
use MiMatus\Locksmith\Semaphore\RedisSemaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use Override;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

abstract class SemaphoreTestCase extends TestCase
{
    private TimeProvider&Stub $timeProvider;

    private int $currentTime = 0;

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        $this->timeProvider = self::createStub(TimeProvider::class);
        $this->timeProvider
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(function (): int {
                return $this->currentTime;
            });
    }

    protected function advanceTime(int $nanoseconds): void
    {
        $this->currentTime += $nanoseconds;
    }

    abstract protected function createSemaphore(TimeProvider $timeProvider, int $maxConcurrentLocks = 1): Semaphore;

    /**
     * @throws Throwable
     */
    public function testBasicLock(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource = new Resource(ttlNanoseconds: 5_000_000_000, namespace: 'test-lock-key');

        $semaphore->lock($resource, 'test-lock-token', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        self::assertTrue($semaphore->isLocked($resource));

        $semaphore->unlock($resource, 'test-lock-token');

        self::assertFalse($semaphore->isLocked($resource));
    }

    /**
     * @throws Throwable
     */
    public function testLockingWithLowerVersion(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource1 = new Resource(ttlNanoseconds: 5_000_000_000, namespace: 'test-lock-key', version: 1);

        $resource2 = new Resource(ttlNanoseconds: 5_000_000_000, namespace: 'test-lock-key', version: 0);

        $semaphore->lock($resource1, 'test-lock-token-1', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });
        self::assertTrue($semaphore->isLocked($resource1));

        $this->expectExceptionObject(new RuntimeException('Lock version mismatch'));

        $semaphore->lock($resource2, 'test-lock-token-2', static function () {
            self::fail('Suspension should not be called');
        });
    }

    /**
     * @throws Throwable
     */
    public function testLockingAlreadyLockedKey(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource = new Resource(
            ttlNanoseconds: 5_000_000_000, // 5s
            namespace: 'test-lock-key',
        );

        $semaphore->lock($resource, 'test-lock-token', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $called = false;
        $fiber = new Fiber(static function () use ($semaphore, $resource, &$called): void {
            $semaphore->lock($resource, 'test-lock-token-2', static function () use (&$called): void {
                $called = true;
                Fiber::suspend();
            });
        });

        $fiber->start();

        self::assertTrue($called);
        self::assertTrue($fiber->isSuspended());
        self::assertFalse($fiber->isTerminated());

        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');

        $semaphore->unlock($resource, 'test-lock-token');

        self::assertFalse($semaphore->isLocked($resource), 'Lock should be released after unlock');

        $fiber->resume();

        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');
        $semaphore->unlock($resource, 'test-lock-token-2');
        self::assertFalse($semaphore->isLocked($resource), 'Lock should be released after unlock');
    }

    /**
     * @throws Throwable
     */
    public function testLockingHigherVersion(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource1 = new Resource(
            ttlNanoseconds: 5_000_000_000, // 5s
            namespace: 'test-lock-key',
            version: 0,
        );

        $resource2 = new Resource(
            ttlNanoseconds: 5_000_000_000, // 5s
            namespace: 'test-lock-key',
            version: 1,
        );

        $semaphore->lock($resource1, 'test-lock-token', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $called = false;
        $fiber = new Fiber(static function () use ($semaphore, $resource2, &$called): void {
            $semaphore->lock($resource2, 'test-lock-token-2', static function () use (&$called): void {
                $called = true;
                Fiber::suspend();
            });
        });

        $fiber->start();

        self::assertTrue($called);
        self::assertTrue($fiber->isSuspended());
        self::assertFalse($fiber->isTerminated());

        self::assertTrue($semaphore->isLocked($resource1), 'Lock should be held');

        $semaphore->unlock($resource1, 'test-lock-token');

        self::assertFalse($semaphore->isLocked($resource1), 'Lock should be released after unlock');

        $fiber->resume();

        self::assertTrue($semaphore->isLocked($resource2), 'Lock should be held');
        $semaphore->unlock($resource2, 'test-lock-token-2');

        self::assertFalse($semaphore->isLocked($resource2), 'Lock should be released after unlock');
    }

    /**
     * @throws Throwable
     */
    public function testSemaphoreWithCapacity(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider, maxConcurrentLocks: 2);

        $resource = new Resource(
            ttlNanoseconds: 5_000_000_000, // 5s
            namespace: 'test-lock-key',
        );

        $semaphore->lock($resource, 'test-lock-token-1', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $semaphore->lock($resource, 'test-lock-token-2', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $called = false;
        $fiber = new Fiber(static function () use ($semaphore, $resource, &$called): void {
            $semaphore->lock($resource, 'test-lock-token-3', static function () use (&$called): void {
                $called = true;
                Fiber::suspend();
            });
        });

        $fiber->start();

        self::assertTrue($called);
        self::assertTrue($fiber->isSuspended());
        self::assertFalse($fiber->isTerminated());

        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');

        $semaphore->unlock($resource, 'test-lock-token-1');
        self::assertTrue($semaphore->isLocked($resource), 'Lock should still be held');

        $semaphore->unlock($resource, 'test-lock-token-2');
        self::assertFalse($semaphore->isLocked($resource), 'Lock should be released after unlock');
    }

    /**
     * @throws Throwable
     */
    public function testLockExpiration(): void
    {
        /**
         * @var RedisSemaphore
         */
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider, maxConcurrentLocks: 2);

        $resource1 = new Resource(
            ttlNanoseconds: 500_000_000, // 0.5 second
            namespace: 'test-lock-key',
        );

        $resource2 = new Resource(
            ttlNanoseconds: 2_000_000_000, // 2 seconds
            namespace: 'test-lock-key',
        );

        $resource3 = new Resource(
            ttlNanoseconds: 2_000_000_000, // 2 seconds
            namespace: 'test-lock-key',
        );

        $semaphore->lock($resource1, '1', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $semaphore->lock($resource2, '2', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        self::assertTrue($semaphore->isLocked($resource1), 'Lock should be held');

        // Move time forward to just after key1 expiration
        $this->advanceTime(1_000_000_000);

        self::assertTrue($semaphore->isLocked($resource1), 'Lock should be held');

        $semaphore->lock($resource3, '3', static function (): void {
            self::fail('Suspension should not be called when lock is available - token 1 expired');
        });

        self::assertTrue($semaphore->isLocked($resource1), 'Lock should be held');

        $this->advanceTime(3_000_000_000);

        self::assertFalse($semaphore->isLocked($resource1), 'Lock should be released after all expirations');
    }

    /**
     * @throws Throwable
     */
    public function testUnlockingWithInvalidToken(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource1 = new Resource(
            ttlNanoseconds: 500_000_000, // 0.5 second
            namespace: 'test-lock-key',
        );

        $semaphore->lock($resource1, '1', static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });
        self::assertTrue($semaphore->isLocked($resource1), 'Lock should be held');

        $semaphore->unlock($resource1, 'invalid-token');

        self::assertTrue($semaphore->isLocked($resource1), 'Lock should be held');

        $semaphore->unlock($resource1, '1');

        self::assertFalse($semaphore->isLocked($resource1), 'Lock should be released after unlock');
    }
}
