<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use Exception;
use Fiber;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use MiMatus\Locksmith\SemaphoreInterface;
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

    abstract protected function createSemaphore(
        TimeProvider $timeProvider,
        int $maxConcurrentLocks = 1,
    ): SemaphoreInterface;

    /**
     * @throws Throwable
     */
    public function testBasicLock(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource = new Resource(namespace: 'test-lock-key');

        $semaphore->lock(
            resource: $resource,
            token: 'test-lock-token', // @mago-ignore lint:no-literal-password
            lockTTLNs: 5_000_000_000,
            suspension: static function (): void {
                self::fail('Suspension should not be called when lock is available');
            },
        );

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

        $resource1 = new Resource(namespace: 'test-lock-key', version: 1);

        $resource2 = new Resource(namespace: 'test-lock-key', version: 0);

        $semaphore->lock($resource1, 'test-lock-token-1', 5_000_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });
        self::assertTrue($semaphore->isLocked($resource1));

        $this->expectExceptionObject(new RuntimeException('Lock version mismatch'));

        $semaphore->lock($resource2, 'test-lock-token-2', 5_000_000_000, static function () {
            self::fail('Suspension should not be called');
        });
    }

    /**
     * @throws Throwable
     */
    public function testLockingAlreadyLockedKey(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource = new Resource(namespace: 'test-lock-key');

        $semaphore->lock($resource, 'test-lock-token', 5_000_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $called = false;
        $fiber = new Fiber(static function () use ($semaphore, $resource, &$called): void {
            $semaphore->lock($resource, 'test-lock-token-2', 5_000_000_000, static function () use (&$called): void {
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

        $resource1 = new Resource(namespace: 'test-lock-key', version: 0);

        $resource2 = new Resource(namespace: 'test-lock-key', version: 1);

        $semaphore->lock($resource1, 'test-lock-token', 5_000_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $called = false;
        $fiber = new Fiber(static function () use ($semaphore, $resource2, &$called): void {
            $semaphore->lock($resource2, 'test-lock-token-2', 5_000_000_000, static function () use (&$called): void {
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

        $resource = new Resource(namespace: 'test-lock-key');

        $semaphore->lock($resource, 'test-lock-token-1', 5_000_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $semaphore->lock($resource, 'test-lock-token-2', 5_000_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $called = false;
        $fiber = new Fiber(static function () use ($semaphore, $resource, &$called): void {
            $semaphore->lock($resource, 'test-lock-token-3', 5_000_000_000, static function () use (&$called): void {
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
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider, maxConcurrentLocks: 2);

        $resource = new Resource(namespace: 'test-lock-key');

        $semaphore->lock($resource, '1', 500_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        $semaphore->lock($resource, '2', 2_000_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });

        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');

        // Move time forward to just after key1 expiration
        $this->advanceTime(1_000_000_000);

        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');

        $semaphore->lock($resource, '3', 2_000_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available - token 1 expired');
        });

        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');

        $this->advanceTime(3_000_000_000);

        self::assertFalse($semaphore->isLocked($resource), 'Lock should be released after all expirations');
    }

    /**
     * @throws Throwable
     */
    public function testUnlockingWithInvalidToken(): void
    {
        $semaphore = $this->createSemaphore(timeProvider: $this->timeProvider);

        $resource = new Resource(namespace: 'test-lock-key');

        $semaphore->lock($resource, '1', 500_000_000, static function (): void {
            self::fail('Suspension should not be called when lock is available');
        });
        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');

        $semaphore->unlock($resource, 'invalid-token');

        self::assertTrue($semaphore->isLocked($resource), 'Lock should be held');

        $semaphore->unlock($resource, '1');

        self::assertFalse($semaphore->isLocked($resource), 'Lock should be released after unlock');
    }
}
