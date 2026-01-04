<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use MiMatus\Locksmith\Semaphore;
use MiMatus\Locksmith\Semaphore\InMemorySemaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;

class InMemorySemaphoreTest extends SemaphoreTestCase
{
    protected function createSemaphore(TimeProvider $timeProvider, int $maxConcurrentLocks = 1): Semaphore
    {
        return new InMemorySemaphore(timeProvider: $timeProvider, maxConcurrentLocks: $maxConcurrentLocks);
    }
}
