<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

use Closure;
use Fiber;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use Override;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
readonly class FiberTaskExecutor implements TaskExecutorInterface
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
        $start = $this->timeProvider->getCurrentTimeNanoseconds();

        $fiber = new Fiber($task);
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

        if ($ttlNanoseconds < ($this->timeProvider->getCurrentTimeNanoseconds() - $start)) {
            throw new RuntimeException('Unable to get result under TTL');
        }

        /** @var T */
        return $fiber->getReturn();
    }
}
