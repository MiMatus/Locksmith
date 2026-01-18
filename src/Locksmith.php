<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

use Closure;
use Fiber;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use Random\Engine;
use Random\Engine\Xoshiro256StarStar;
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

            $this->getResultUnderTTL(
                new Fiber(function () use ($token, $resource, $lockTTLNs): void {
                    $this->semaphore->lock($resource, $token, $lockTTLNs, Fiber::suspend(...));
                }),
                $maxLockWaitNs,
                $minSuspensionDelayNs,
            );

            try {
                /** @var T */
                return $this->getResultUnderTTL(
                    new Fiber(function () use ($callback, $resource): mixed {
                        if (!$this->semaphore->isLocked($resource)) {
                            throw new RuntimeException('Lock has been lost during process');
                        }

                        return $callback(Fiber::suspend(...));
                    }),
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
     * @param Fiber<T> $fiber
     * @param non-negative-int $ttlNanoseconds
     * @return T
     */
    private function getResultUnderTTL(Fiber $fiber, int $ttlNanoseconds, int $minSuspensionDelayNs): mixed
    {
        $start = $this->timeProvider->getCurrentTimeNanoseconds();

        $fiber->start();

        while (!$fiber->isTerminated()) {
            if ($ttlNanoseconds < ($this->timeProvider->getCurrentTimeNanoseconds() - $start)) {
                throw new RuntimeException('Unable to get result under TTL');
            }

            if (Fiber::getCurrent() !== null) {
                Fiber::suspend();
            } elseif ($minSuspensionDelayNs > 0) {
                /** @var positive-int $delay */
                $delay = $minSuspensionDelayNs / 1000;
                usleep($delay);
            }

            if (!$fiber->isSuspended()) {
                throw new RuntimeException('Fiber error, fiber is not suspended nor terminated');
            }

            $fiber->resume();
        }

        /** @var T */
        return $fiber->getReturn();
    }
}
