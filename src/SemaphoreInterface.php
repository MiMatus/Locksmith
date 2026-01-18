<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

use Closure;

interface SemaphoreInterface
{
    public function lock(
        ResourceInterface $resource,
        #[\SensitiveParameter] string $token,
        int $lockTTLNs,
        Closure $suspension,
    ): void;

    public function unlock(ResourceInterface $resource, #[\SensitiveParameter] string $token): void;

    public function isLocked(ResourceInterface $resource): bool;
}
