<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use Fiber;
use MiMatus\Locksmith\FiberTaskExecutor;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FiberTaskExecutorTest extends TestCase
{
    public function testRetrieveResult(): void
    {
        $timeProvider = new TimeProvider();
        $taskExecutor = new FiberTaskExecutor($timeProvider);

        $result = $taskExecutor->getResultUnderTTL(
            static function () use ($timeProvider) {
                return 'result';
            },
            ttlNanoseconds: 1_000_000_000, // Set TTL to 1 second
            minSuspensionDelayNs: 100_000_000, // Set minimum suspension delay to 0.1 seconds
        );

        $this->assertSame('result', $result);
    }

    public function testResultRetrievalTookTooLongBlocking(): void
    {
        $currentTime = 0;
        $timeProvider = self::createStub(TimeProvider::class);
        $timeProvider
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $taskExecutor = new FiberTaskExecutor($timeProvider);

        $this->expectExceptionObject(new RuntimeException('Unable to get result under TTL'));

        $taskExecutor->getResultUnderTTL(
            static function () use ($timeProvider, &$currentTime) {
                $currentTime += 1_500_000_000; // Simulate a long-running task (1.5 seconds)
                return 'result';
            },
            ttlNanoseconds: 1_000_000_000, // Set TTL to 1 second
            minSuspensionDelayNs: 100_000_000, // Set minimum suspension delay to 0.1 seconds
        );
    }

    public function testResultRetrievalNonBlocking(): void
    {
        $currentTime = 0;
        $timeProvider = self::createStub(TimeProvider::class);
        $timeProvider
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $taskExecutor = new FiberTaskExecutor($timeProvider);

        $fiber = new Fiber(static function () use ($taskExecutor, $timeProvider, &$currentTime) {
            return $taskExecutor->getResultUnderTTL(
                static function () use ($timeProvider, &$currentTime) {
                    $currentTime += 100_000_000; // Simulate a short task (0.1 seconds)
                    Fiber::suspend(); // Suspend to trigger the TTL check in the executor
                    $currentTime += 100_000_000; // Simulate a short task (0.1 seconds)
                    return 'result';
                },
                ttlNanoseconds: 1_000_000_000, // Set TTL to 1 second
                minSuspensionDelayNs: 100_000_000, // Set minimum suspension delay to 0.1 seconds
            );
        });

        $fiber->start();
        $this->assertSame(100_000_000, $currentTime);

        $fiber->resume();

        $this->assertSame(200_000_000, $currentTime);
        $this->assertTrue($fiber->isTerminated());
        $this->assertSame('result', $fiber->getReturn());
    }

    public function testResultRetrievalTookTooLongNonBlocking(): void
    {
        $currentTime = 0;
        $timeProvider = self::createStub(TimeProvider::class);
        $timeProvider
            ->method('getCurrentTimeNanoseconds')
            ->willReturnCallback(static function () use (&$currentTime) {
                return $currentTime;
            });

        $taskExecutor = new FiberTaskExecutor($timeProvider);

        $fiber = new Fiber(static function () use ($taskExecutor, $timeProvider, &$currentTime) {
            return $taskExecutor->getResultUnderTTL(
                static function () use ($timeProvider, &$currentTime) {
                    $currentTime += 1_500_000_000; // Simulate a long-running task (1.5 seconds)
                    Fiber::suspend(); // Suspend to trigger the TTL check in the executor
                    return 'result';
                },
                ttlNanoseconds: 1_000_000_000, // Set TTL to 1 second
                minSuspensionDelayNs: 100_000_000, // Set minimum suspension delay to 0.1 seconds
            );
        });

        $this->expectExceptionObject(new RuntimeException('Unable to get result under TTL'));

        $fiber->start();
        $this->assertSame(1_500_000_000, $currentTime);
    }
}
