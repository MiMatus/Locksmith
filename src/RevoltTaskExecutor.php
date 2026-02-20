<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

use Closure;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use Override;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
readonly class RevoltTaskExecutor implements TaskExecutorInterface
{
    public function __construct(
        private TimeProvider $timeProvider = new TimeProvider(),
    ) {}

    /**
     * @template T
     * @param Closure(): T $task
     * @param non-negative-int $ttlNanoseconds
     * @param non-negative-int $minSuspensionDelayNs
     * @throws Throwable
     * @return T
     */
    #[Override]
    public function getResultUnderTTL(Closure $task, int $ttlNanoseconds, int $minSuspensionDelayNs): mixed
    {
        $suspension = EventLoop::getSuspension();

        $startTime = $this->timeProvider->getCurrentTimeNanoseconds();

        $deferId = EventLoop::delay($minSuspensionDelayNs / 1_000_000, function () use (
            $task,
            $suspension,
            $startTime,
            $ttlNanoseconds,
        ): void {
            try {
                $result = $task();
            } catch (Throwable $e) {
                $suspension->throw($e);
                return;
            }

            // Check if TTL has been exceeded before resuming the fiber - there might have been a blocking operation in the task that caused us to exceed the TTL
            if (($this->timeProvider->getCurrentTimeNanoseconds() - $startTime) >= $ttlNanoseconds) {
                $suspension->throw(new RuntimeException('Unable to get result under TTL'));
                return;
            }
            $suspension->resume($result);
        });

        EventLoop::delay($ttlNanoseconds / 1_000_000, static function () use ($deferId, $suspension) {
            EventLoop::cancel($deferId);

            $suspension->throw(new RuntimeException('Unable to get result under TTL'));
        });

        /** @var T */
        return $suspension->suspend();
    }
}
