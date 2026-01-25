<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use Closure;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\ResourceInterface;
use MiMatus\Locksmith\Semaphore\DistributedSemaphore;
use MiMatus\Locksmith\Semaphore\GroupedException;
use MiMatus\Locksmith\Semaphore\SemaphoreCollection;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use MiMatus\Locksmith\SemaphoreInterface;
use PHPUnit\Framework\TestCase;

class DistributedSemaphoreTest extends TestCase
{
    public function testUnableToAcquireLockQuorumUnderTTL(): void
    {
        $resource = new Resource(namespace: 'test-resource');
        $semaphore1 = self::createMock(SemaphoreInterface::class);
        $semaphore2 = self::createMock(SemaphoreInterface::class);
        $timeProvider = self::createStub(TimeProvider::class);
        $currentTime = 0;

        $distributedSemaphore = new DistributedSemaphore(
            semaphores: new SemaphoreCollection([$semaphore1, $semaphore2]),
            quorum: 1,
            timeProvider: $timeProvider,
        );

        $lockAttempt = 0;
        $semaphore1
            ->method('lock')
            ->willReturnCallback(static function (
                ResourceInterface $r,
                #[\SensitiveParameter] string $token,
                int $lockTTLNs,
                Closure $suspension,
            ) use (&$currentTime, $resource, &$lockAttempt): void {
                self::assertEquals($resource, $r);
                self::assertEquals('test-token', $token);
                self::assertEquals(1_000_000_000 - ($lockAttempt * 500_000_001), $lockTTLNs);

                $currentTime += 500_000_001;
                $lockAttempt++;
                throw new \RuntimeException('Lock failed on semaphore 1');
            });

        $semaphore2
            ->method('lock')
            ->willReturnCallback(static function (
                ResourceInterface $r,
                #[\SensitiveParameter] string $token,
                int $lockTTLNs,
                Closure $suspension,
            ) use (&$currentTime, $resource, &$lockAttempt): void {
                self::assertEquals($resource, $r);
                self::assertEquals('test-token', $token);
                self::assertEquals(1_000_000_000 - ($lockAttempt * 500_000_001), $lockTTLNs);

                $currentTime += 500_000_001;
                $lockAttempt++;
                throw new \RuntimeException('Lock failed on semaphore 2');
            });

        $semaphore1->expects(self::atLeastOnce())->method('unlock');
        $semaphore2->expects(self::atLeastOnce())->method('unlock');

        $timeProvider
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime): int {
                return $currentTime;
            });

        $this->expectExceptionObject(new GroupedException('Failed to acquire lock quorum', []));

        $distributedSemaphore->lock(
            resource: new Resource(namespace: 'test-resource'),
            token: 'test-token',
            lockTTLNs: 1_000_000_000, // 1 second
            suspension: static function (): void {},
        );
    }

    public function testAcquiredLockWithoutErrors(): void
    {
        $resource = new Resource(namespace: 'test-resource');
        $semaphore1 = self::createStub(SemaphoreInterface::class);
        $semaphore2 = self::createStub(SemaphoreInterface::class);
        $timeProvider = self::createStub(TimeProvider::class);
        $currentTime = 0;

        $distributedSemaphore = new DistributedSemaphore(
            semaphores: new SemaphoreCollection([$semaphore1, $semaphore2]),
            quorum: 1,
            timeProvider: $timeProvider,
        );

        $lockAttempt = 0;
        $lockedSemaphore1 = false;
        $lockedSemaphore2 = false;
        $semaphore1
            ->method('lock')
            ->willReturnCallback(static function (
                ResourceInterface $r,
                #[\SensitiveParameter] string $token,
                int $lockTTLNs,
                Closure $suspension,
            ) use (&$currentTime, $resource, &$lockAttempt, &$lockedSemaphore1): void {
                self::assertEquals($resource, $r);
                self::assertEquals('test-token', $token);
                self::assertEquals(1_000_000_000 - ($lockAttempt * 500_000_001), $lockTTLNs);
                self::assertFalse($lockedSemaphore1, 'Semaphore 1 should not have been locked yet');

                $currentTime += 500_000_001;
                $lockAttempt++;
                $lockedSemaphore1 = true;
            });

        $semaphore2
            ->method('lock')
            ->willReturnCallback(static function (
                ResourceInterface $r,
                #[\SensitiveParameter] string $token,
                int $lockTTLNs,
                Closure $suspension,
            ) use (&$currentTime, $resource, &$lockAttempt, &$lockedSemaphore2): void {
                self::assertEquals($resource, $r);
                self::assertEquals('test-token', $token);
                self::assertEquals(1_000_000_000 - ($lockAttempt * 500_000_001), $lockTTLNs);
                self::assertFalse($lockedSemaphore2, 'Semaphore 2 should not have been locked yet');

                $currentTime += 500_000_001;
                $lockAttempt++;
                $lockedSemaphore2 = true;
            });

        $timeProvider
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime): int {
                return $currentTime;
            });

        $distributedSemaphore->lock(
            resource: new Resource(namespace: 'test-resource'),
            token: 'test-token',
            lockTTLNs: 1_000_000_000, // 1 second
            suspension: static function (): void {},
        );

        self::assertTrue($lockedSemaphore1 || $lockedSemaphore2, 'At least one semaphore should have been locked');
    }

    public function testAcquiredLockWithErrors(): void
    {
        $resource = new Resource(namespace: 'test-resource');
        $semaphore = self::createStub(SemaphoreInterface::class);
        $timeProvider = self::createStub(TimeProvider::class);
        $currentTime = 0;

        $distributedSemaphore = new DistributedSemaphore(
            semaphores: new SemaphoreCollection([$semaphore, $semaphore, $semaphore, $semaphore, $semaphore]),
            quorum: 3,
            timeProvider: $timeProvider,
        );

        $lockAttempt = 0;
        $locksAquired = 0;
        $semaphore
            ->method('lock')
            ->willReturnCallback(static function (
                ResourceInterface $r,
                #[\SensitiveParameter] string $token,
                int $lockTTLNs,
                Closure $suspension,
            ) use (&$currentTime, $resource, &$lockAttempt, &$locksAquired): void {
                self::assertEquals($resource, $r);
                self::assertEquals('test-token', $token);

                $currentTime += 10_000;

                if (($lockAttempt % 3) === 0) {
                    $lockAttempt++;
                    $locksAquired++;
                } else {
                    $lockAttempt++;
                    throw new \RuntimeException('Lock failed on semaphore');
                }
            });

        $timeProvider
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime): int {
                return $currentTime;
            });

        $distributedSemaphore->lock(
            resource: new Resource(namespace: 'test-resource'),
            token: 'test-token',
            lockTTLNs: 1_000_000_000, // 1 second
            suspension: static function (): void {},
        );

        self::assertEquals(3, $locksAquired, 'Exactly three semaphores should have been locked');
    }

    public function testUnlockWithErrors(): void
    {
        $resource = new Resource(namespace: 'test-resource');
        $semaphore = self::createStub(SemaphoreInterface::class);

        $distributedSemaphore = new DistributedSemaphore(semaphores: new SemaphoreCollection([
            $semaphore,
            $semaphore,
            $semaphore,
            $semaphore,
            $semaphore,
        ]), quorum: 3);

        $unlockAttempt = 0;
        $unlocksPerformed = 0;
        $semaphore
            ->method('unlock')
            ->willReturnCallback(static function (ResourceInterface $r, #[\SensitiveParameter] string $token) use (
                $resource,
                &$unlockAttempt,
                &$unlocksPerformed,
            ): void {
                self::assertEquals($resource, $r);
                self::assertEquals('test-token', $token);

                if (($unlockAttempt % 3) === 0) {
                    $unlockAttempt++;
                    throw new \RuntimeException('Unlock failed on semaphore');
                } else {
                    $unlockAttempt++;
                    $unlocksPerformed++;
                }
            });

        $distributedSemaphore->unlock(resource: new Resource(namespace: 'test-resource'), token: 'test-token');

        self::assertEquals(3, $unlocksPerformed, 'Exactly three semaphores should have been unlocked');
    }

    public function testUnableToUnlock(): void
    {
        $resource = new Resource(namespace: 'test-resource');
        $semaphore = self::createStub(SemaphoreInterface::class);

        $distributedSemaphore = new DistributedSemaphore(semaphores: new SemaphoreCollection([
            $semaphore,
            $semaphore,
            $semaphore,
            $semaphore,
            $semaphore,
        ]), quorum: 3);

        $unlockAttempt = 0;
        $unlocksPerformed = 0;
        $semaphore
            ->method('unlock')
            ->willReturnCallback(static function (ResourceInterface $r, #[\SensitiveParameter] string $token) use (
                $resource,
                &$unlockAttempt,
                &$unlocksPerformed,
            ): void {
                self::assertEquals($resource, $r);
                self::assertEquals('test-token', $token);

                if (($unlockAttempt % 3) === 0) {
                    $unlocksPerformed++;
                    $unlockAttempt++;
                } else {
                    $unlockAttempt++;
                    throw new \RuntimeException('Unlock failed on semaphore');
                }
            });

        $this->expectExceptionObject(new GroupedException('Failed to release lock quorum', []));

        $distributedSemaphore->unlock(resource: new Resource(namespace: 'test-resource'), token: 'test-token');
    }

    public function testIsLockedOnQuorum(): void
    {
        $resource = new Resource(namespace: 'test-resource');
        $semaphore = self::createStub(SemaphoreInterface::class);

        $distributedSemaphore = new DistributedSemaphore(semaphores: new SemaphoreCollection([
            $semaphore,
            $semaphore,
            $semaphore,
            $semaphore,
            $semaphore,
        ]), quorum: 3);

        $isLockedAttempt = 0;
        $semaphore
            ->method('isLocked')
            ->willReturnCallback(static function (ResourceInterface $r) use ($resource, &$isLockedAttempt): bool {
                self::assertEquals($resource, $r);

                if (($isLockedAttempt % 3) === 0) {
                    $isLockedAttempt++;
                    throw new \RuntimeException('Unlock failed on semaphore');
                } else {
                    $isLockedAttempt++;
                    return true;
                }
            });

        self::assertTrue($distributedSemaphore->isLocked(resource: new Resource(namespace: 'test-resource')));
    }
}
