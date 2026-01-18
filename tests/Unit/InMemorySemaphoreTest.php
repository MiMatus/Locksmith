<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use MiMatus\Locksmith\Semaphore\InMemorySemaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use MiMatus\Locksmith\SemaphoreInterface;

class InMemorySemaphoreTest extends SemaphoreTestCase
{
    protected function createSemaphore(TimeProvider $timeProvider, int $maxConcurrentLocks = 1): SemaphoreInterface
    {
        return new InMemorySemaphore(timeProvider: $timeProvider, maxConcurrentLocks: $maxConcurrentLocks);
    }
}
