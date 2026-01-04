<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use Closure;
use Exception;
use MiMatus\Locksmith\Locksmith;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Random\Engine;
use RuntimeException;

class LocksmithTest extends TestCase
{
    private Semaphore&MockObject $semaphore;
    private TimeProvider&MockObject $timeProvider;
    private Engine&MockObject $randomEngine;

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        $this->semaphore = $this->createMock(Semaphore::class);
        $this->timeProvider = $this->createMock(TimeProvider::class);
        $this->randomEngine = $this->createMock(Engine::class);
    }

    public function testUnableToAcquireLockTimeout(): void
    {
        $currentTime = 0;
        $this->timeProvider
            ->expects($this->atLeastOnce())
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $this->semaphore
            ->expects($this->atLeastOnce())
            ->method('lock')
            ->willReturnCallback(static function (
                Resource $resource,
                #[\SensitiveParameter] string $token,
                Closure $suspension,
            ) use (&$currentTime): void {
                $suspension();
                $currentTime += 500_000_001;
                $suspension();
            });
        $this->randomEngine
            ->expects($this->once())
            ->method('generate')
            ->willReturn('token');

        $locksmith = new Locksmith(
            semaphore: $this->semaphore,
            timeProvider: $this->timeProvider,
            randomEngine: $this->randomEngine,
        );

        $locked = $locksmith->locked(
            resource: new Resource(namespace: 'test-resource', version: 1, ttlNanoseconds: 1_000_000_000),
            maxLockWaitNs: 500_000_000,
            minSuspensionDelayNs: 10_000,
        );

        $this->expectExceptionObject(new RuntimeException('Unable to get result under TTL'));
        $locked(static function (): void {
            self::fail('Lock should not be acquired');
        });
    }

    public function testUnableToAcquireLock(): void
    {
        $currentTime = 0;
        $this->timeProvider
            ->expects($this->atLeastOnce())
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $this->semaphore
            ->expects($this->atLeastOnce())
            ->method('lock')
            ->willReturnCallback(static function (
                Resource $resource,
                #[\SensitiveParameter] string $token,
                Closure $suspension,
            ) use (&$currentTime): void {
                throw new RuntimeException('error');
            });
        $this->randomEngine
            ->expects($this->once())
            ->method('generate')
            ->willReturn('token');

        $locksmith = new Locksmith(
            semaphore: $this->semaphore,
            timeProvider: $this->timeProvider,
            randomEngine: $this->randomEngine,
        );

        $locked = $locksmith->locked(
            resource: new Resource(namespace: 'test-resource', version: 1, ttlNanoseconds: 1_000_000_000),
            maxLockWaitNs: 500_000_000,
            minSuspensionDelayNs: 10_000,
        );

        $this->expectExceptionObject(new RuntimeException('error'));
        $locked(static function (): void {
            self::fail('Lock should not be acquired');
        });
    }

    public function testLostDuringExecution(): void
    {
        $resource = new Resource(namespace: 'test-resource', version: 1, ttlNanoseconds: 1_000_000_000);

        $currentTime = 0;
        $this->timeProvider
            ->expects($this->atLeastOnce())
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $this->semaphore
            ->expects($this->once())
            ->method('lock')
            ->willReturnCallback(static function (
                Resource $resource,
                #[\SensitiveParameter] string $token,
                Closure $suspension,
            ) use (&$currentTime): void {
                // Lock acquired immediately
            });

        $this->semaphore
            ->expects($this->once())
            ->method('isLocked')
            ->with(self::equalTo($resource))
            ->willReturn(false);

        $this->randomEngine
            ->expects($this->once())
            ->method('generate')
            ->willReturn('token');

        $locksmith = new Locksmith(
            semaphore: $this->semaphore,
            timeProvider: $this->timeProvider,
            randomEngine: $this->randomEngine,
        );

        $locked = $locksmith->locked(resource: $resource, maxLockWaitNs: 500_000_000, minSuspensionDelayNs: 10_000);

        $this->expectExceptionObject(new RuntimeException('Lock has been lost during process'));
        $locked(static function (Closure $suspension): void {
            $suspension(); // Simulate some processing and force lock check
        });
    }

    public function testUnableToUnlock(): void
    {
        $resource = new Resource(namespace: 'test-resource', version: 1, ttlNanoseconds: 1_000_000_000);

        $currentTime = 0;
        $this->timeProvider
            ->expects($this->atLeastOnce())
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $this->semaphore
            ->expects($this->once())
            ->method('lock')
            ->willReturnCallback(static function (
                Resource $resource,
                #[\SensitiveParameter] string $token,
                Closure $suspension,
            ) use (&$currentTime): void {
                // Lock acquired immediately
            });

        $this->semaphore
            ->expects($this->once())
            ->method('unlock')
            ->willReturnCallback(static function (Resource $resource, #[\SensitiveParameter] string $token) use (
                &$currentTime,
            ): void {
                throw new RuntimeException('error during unlock');
            });

        $this->semaphore
            ->expects($this->once())
            ->method('isLocked')
            ->with(self::equalTo($resource))
            ->willReturn(false);

        $this->randomEngine
            ->expects($this->once())
            ->method('generate')
            ->willReturn('token');

        $locksmith = new Locksmith(
            semaphore: $this->semaphore,
            timeProvider: $this->timeProvider,
            randomEngine: $this->randomEngine,
        );

        $locked = $locksmith->locked(resource: $resource, maxLockWaitNs: 500_000_000, minSuspensionDelayNs: 10_000);

        $this->expectExceptionObject(new RuntimeException('error during unlock'));
        $locked(static function (Closure $suspension): void {
            // Simulate some processing
        });
    }

    public function testLocked(): void
    {
        $resource = new Resource(namespace: 'test-resource', version: 1, ttlNanoseconds: 1_000_000_000);

        $currentTime = 0;
        $this->timeProvider
            ->expects($this->atLeastOnce())
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $this->semaphore
            ->expects($this->once())
            ->method('lock')
            ->willReturnCallback(static function (
                Resource $resource,
                #[\SensitiveParameter] string $token,
                Closure $suspension,
            ) use (&$currentTime): void {
                // Lock acquired immediately
            });

        $this->semaphore
            ->expects($this->once())
            ->method('unlock')
            ->willReturnCallback(static function (Resource $resource, #[\SensitiveParameter] string $token) use (
                &$currentTime,
            ): void {
                // Unlock successful
            });

        $this->semaphore
            ->expects($this->once())
            ->method('isLocked')
            ->with(self::equalTo($resource))
            ->willReturn(true);

        $this->randomEngine
            ->expects($this->once())
            ->method('generate')
            ->willReturn('token');

        $locksmith = new Locksmith(
            semaphore: $this->semaphore,
            timeProvider: $this->timeProvider,
            randomEngine: $this->randomEngine,
        );

        $locked = $locksmith->locked(resource: $resource, maxLockWaitNs: 500_000_000, minSuspensionDelayNs: 10_000);

        $called = false;
        $locked(static function (Closure $suspension) use (&$called): void {
            // Simulate some processing
            $called = true;
        });

        self::assertTrue($called, 'Locked callback executed');
    }
}
