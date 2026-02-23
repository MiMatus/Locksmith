<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use Closure;
use LogicException;
use MiMatus\Locksmith\ResourceInterface;
use MiMatus\Locksmith\SemaphoreInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;
use Throwable;

readonly class DistributedSemaphore implements SemaphoreInterface
{
    /**
     * @throws LogicException
     */
    public function __construct(
        private SemaphoreCollectionInterface $semaphores,
        private int $quorum,
        private TimeProvider $timeProvider = new TimeProvider(),
        private Randomizer $randomizer = new Randomizer(new Xoshiro256StarStar()),
    ) {
        if ($this->quorum > count($this->semaphores)) {
            throw new LogicException('Acquire quorum cannot be greater than number of semaphores');
        }
    }

    /**
     * @param Closure(): void $suspension
     * @throws GroupedException
     * @throws RuntimeException
     */
    #[\Override]
    public function lock(
        ResourceInterface $resource,
        #[\SensitiveParameter] string $token,
        int $lockTTLNs,
        Closure $suspension,
    ): void {
        $successfulLocks = 0;
        $startTime = $this->timeProvider->getCurrentTimeNanoseconds();
        $exceptions = [];
        $semaphores = clone $this->semaphores;

        do {
            $semaphore = $semaphores->getRandom();
            $lockTTLNs -= $this->timeProvider->getCurrentTimeNanoseconds() - $startTime;
            if ($lockTTLNs <= 0 || $semaphore === null) {
                break;
            }

            try {
                $semaphore->lock($resource, $token, (int) $lockTTLNs, $suspension);
                $successfulLocks++;
                $semaphores = $semaphores->without($semaphore);
            } catch (Throwable $e) {
                $exceptions[] = $e;
            }

            if ($successfulLocks >= $this->quorum) {
                return;
            }

            $suspension();
        } while ($lockTTLNs > 0);

        // Rollback successful locks
        try {
            $this->unlock($resource, $token);
        } catch (Throwable $e) {
            $exceptions[] = $e;
        }

        throw new GroupedException('Failed to acquire lock quorum', $exceptions);
    }

    /**
     * @throws GroupedException
     */
    #[\Override]
    public function unlock(ResourceInterface $resource, #[\SensitiveParameter] string $token): void
    {
        $successfulUnlocks = 0;
        $exceptions = [];

        foreach ($this->semaphores as $semaphore) {
            /** @var SemaphoreInterface $semaphore */
            try {
                $semaphore->unlock($resource, $token);
                $successfulUnlocks++;
            } catch (Throwable $e) {
                $exceptions[] = $e;
            }
        }

        if ($successfulUnlocks < $this->quorum) {
            throw new GroupedException('Failed to release lock quorum', $exceptions);
        }
    }

    /**
     * @throws GroupedException
     */
    #[\Override]
    public function isLocked(ResourceInterface $resource): bool
    {
        $lockedCount = 0;
        $exceptions = [];

        foreach ($this->semaphores as $semaphore) {
            /** @var SemaphoreInterface $semaphore */
            try {
                if ($semaphore->isLocked($resource)) {
                    $lockedCount++;
                }
            } catch (Throwable $e) {
                $exceptions[] = $e;
            }

            if ($lockedCount >= $this->quorum) {
                return true;
            }
        }

        if (count($exceptions) >= $this->quorum) {
            throw new GroupedException('Failed to determine lock status quorum', $exceptions);
        }

        return false;
    }
}
