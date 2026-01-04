<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use Closure;
use MiMatus\Locksmith\Lock;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore;
use RuntimeException;

class InMemorySemaphore implements Semaphore
{
    /**
     * @var array<string, array{version: int, expirations: array<string|int, float|int>}>
     */
    private array $locks = [];

    /**
     * @param positive-int $maxConcurrentLocks
     */
    public function __construct(
        private readonly TimeProvider $timeProvider = new TimeProvider(),
        private readonly int $maxConcurrentLocks = 1,
    ) {}

    /**
     * @param Closure(): void $suspension
     * @throws RuntimeException
     */
    #[\Override]
    public function lock(Resource $resource, #[\SensitiveParameter] string $token, Closure $suspension): void
    {
        if (
            isset($this->locks[$resource->namespace]['version'])
            && $this->locks[$resource->namespace]['version'] > $resource->version
        ) {
            throw new RuntimeException('Lock version mismatch');
        }

        do {
            $currentTime = $this->timeProvider->getCurrentTimeNanoseconds();
            $storedResource = $this->locks[$resource->namespace] ?? null;
            if ($storedResource === null) {
                break;
            }

            $activeLocks = 0;
            foreach ($storedResource['expirations'] as $expirationToken => $expiration) {
                if ($expiration > $currentTime) {
                    $activeLocks++;
                } else {
                    $this->unlock($resource, (string) $expirationToken);
                }
            }

            $higherVersion = $resource->version > $storedResource['version'];
            $maxLocksReached = $activeLocks >= $this->maxConcurrentLocks;

            if ($maxLocksReached || $higherVersion) {
                $suspension();
            } else {
                break;
            }
        } while (true);

        $resourceExpiration = $currentTime + $resource->ttlNanoseconds;
        $this->locks[$resource->namespace]['expirations'][$token] = $resourceExpiration;
        $this->locks[$resource->namespace]['version'] = $resource->version;
    }

    #[\Override]
    public function unlock(Resource $resource, #[\SensitiveParameter] string $token): void
    {
        if (!isset($this->locks[$resource->namespace])) {
            return;
        }

        unset($this->locks[$resource->namespace]['expirations'][$token]);
        if ($this->locks[$resource->namespace]['expirations'] === []) {
            unset($this->locks[$resource->namespace]);
        }
    }

    /**
     * @throws RuntimeException
     */
    #[\Override]
    public function isLocked(Resource $resource): bool
    {
        if (!isset($this->locks[$resource->namespace])) {
            return false;
        }

        foreach ($this->locks[$resource->namespace]['expirations'] as $token => $expiration) {
            if ($expiration > $this->timeProvider->getCurrentTimeNanoseconds()) {
                return true;
            }
            $this->unlock($resource, (string) $token);
        }
        return false;
    }
}
