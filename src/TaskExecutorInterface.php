<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

use Closure;
use Throwable;

/**
 * @internal
 */
interface TaskExecutorInterface
{
    /**
     * @template T
     * @param Closure(): T $task
     * @param non-negative-int $ttlNanoseconds
     * @param non-negative-int $minSuspensionDelayNs
     * @throws Throwable
     * @return T
     */
    public function getResultUnderTTL(Closure $task, int $ttlNanoseconds, int $minSuspensionDelayNs): mixed;
}
