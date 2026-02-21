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
    private TaskExecutorInterface $taskExecutor;

    public function __construct(
        private SemaphoreInterface $semaphore,
        private TimeProvider $timeProvider = new TimeProvider(),
        private Engine $randomEngine = new Xoshiro256StarStar(),
        ?TaskExecutorInterface $taskExecutor = null,
    ) {
        $this->taskExecutor =
            $taskExecutor
            ?? (
                class_exists(EventLoop::class)
                    ? new RevoltTaskExecutor($timeProvider)
                    : new FiberTaskExecutor($timeProvider)
            );
    }

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

                $this->taskExecutor->getResultUnderTTL(static fn() => null, $remainingLockTTLNs, $minSuspensionDelayNs);
            };
            $this->taskExecutor->getResultUnderTTL(
                function () use ($token, $resource, $lockTTLNs, $suspender): void {
                    $this->semaphore->lock($resource, $token, $lockTTLNs, $suspender);
                },
                $maxLockWaitNs,
                $minSuspensionDelayNs,
            );

            try {
                /** @var T */
                return $this->taskExecutor->getResultUnderTTL(
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
}
