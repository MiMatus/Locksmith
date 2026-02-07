<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

use Closure;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use Random\Engine;
use Random\Engine\Xoshiro256StarStar;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

readonly class Locksmith
{
    public function __construct(
        private SemaphoreInterface $semaphore,
        private TimeProvider $timeProvider = new TimeProvider(),
        private Engine $randomEngine = new Xoshiro256StarStar(),
    ) {}

    /**
     * @template T
     * @param non-negative-int $maxLockWaitNs
     * @param non-negative-int $minSuspensionDelayNs
     * @param non-negative-int $lockTTLNs
     * @throws Throwable
     * @return Closure(Closure(): void): T
     */
    public function locked(
        ResourceInterface $resource,
        int $lockTTLNs,
        int $maxLockWaitNs,
        int $minSuspensionDelayNs,
    ): Closure {
        return function (Closure $callback) use ($resource, $lockTTLNs, $maxLockWaitNs, $minSuspensionDelayNs): mixed {
            $token = bin2hex($this->randomEngine->generate());

            $startTimeNs = $this->timeProvider->getCurrentTimeNanoseconds();

            $suspender = function () use ($startTimeNs, $lockTTLNs, $minSuspensionDelayNs) {
                $remainingLockTTLNs = (int) (
                    $lockTTLNs
                    - ($this->timeProvider->getCurrentTimeNanoseconds() - $startTimeNs)
                );
                if ($remainingLockTTLNs <= 0) {
                    throw new RuntimeException('Unable to get result under TTL');
                }
                /** @var non-negative-int $remainingLockTTLNs */

                $this->getResultUnderTTL(static fn() => null, $remainingLockTTLNs, $minSuspensionDelayNs);
            };
            $this->getResultUnderTTL(
                function () use ($token, $resource, $lockTTLNs, $suspender): void {
                    $this->semaphore->lock($resource, $token, $lockTTLNs, $suspender);
                },
                $maxLockWaitNs,
                $minSuspensionDelayNs,
            );

            try {
                /** @var T */
                return $this->getResultUnderTTL(
                    function () use ($callback, $resource, $suspender): mixed {
                        if (!$this->semaphore->isLocked($resource)) {
                            throw new RuntimeException('Lock has been lost during process');
                        }

                        return $callback($suspender);
                    },
                    $lockTTLNs,
                    $minSuspensionDelayNs,
                );
            } finally {
                $this->semaphore->unlock($resource, $token);
            }
        };
    }

    /**
     * @template T
     * @throws Throwable
     * @param Closure(): T $task
     * @param non-negative-int $ttlNanoseconds
     * @return T
     */
    private function getResultUnderTTL(Closure $task, int $ttlNanoseconds, int $minSuspensionDelayNs): mixed
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
