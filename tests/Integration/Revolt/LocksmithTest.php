<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Integration\Revolt;

use MiMatus\Locksmith\RevoltTaskExecutor;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use MiMatus\Locksmith\TaskExecutorInterface;
use MiMatus\Locksmith\Tests\LocksmithTestCase;

class LocksmithTest extends LocksmithTestCase
{
    protected function createTaskExecutor(TimeProvider $timeProvider): TaskExecutorInterface
    {
        return new RevoltTaskExecutor($timeProvider);
    }
}
