<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

use Closure;

interface Semaphore
{
    public function lock(Resource $resource, #[\SensitiveParameter] string $token, Closure $suspension): void;

    public function unlock(Resource $resource, #[\SensitiveParameter] string $token): void;

    public function isLocked(Resource $resource): bool;
}
