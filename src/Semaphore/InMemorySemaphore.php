<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use Closure;
use MiMatus\Locksmith\Lock;
use MiMatus\Locksmith\ResourceInterface;
use MiMatus\Locksmith\SemaphoreInterface;
use RuntimeException;

class InMemorySemaphore implements SemaphoreInterface
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
    public function lock(
        ResourceInterface $resource,
        #[\SensitiveParameter] string $token,
        int $lockTTLNs,
        Closure $suspension,
    ): void {
        if (
            isset($this->locks[$resource->getNamespace()]['version'])
            && $this->locks[$resource->getNamespace()]['version'] > $resource->getVersion()
        ) {
            throw new RuntimeException('Lock version mismatch');
        }

        do {
            $currentTime = $this->timeProvider->getCurrentTimeNanoseconds();
            $storedResource = $this->locks[$resource->getNamespace()] ?? null;
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

            $higherVersion = $resource->getVersion() > $storedResource['version'];
            $maxLocksReached = $activeLocks >= $this->maxConcurrentLocks;

            if (!$maxLocksReached && !$higherVersion) {
                break;
            }
            $suspension();
        } while (true);

        $resourceExpiration = $currentTime + $lockTTLNs;
        $this->locks[$resource->getNamespace()]['expirations'][$token] = $resourceExpiration;
        $this->locks[$resource->getNamespace()]['version'] = $resource->getVersion();
    }

    #[\Override]
    public function unlock(ResourceInterface $resource, #[\SensitiveParameter] string $token): void
    {
        if (!isset($this->locks[$resource->getNamespace()])) {
            return;
        }

        unset($this->locks[$resource->getNamespace()]['expirations'][$token]);
        if ($this->locks[$resource->getNamespace()]['expirations'] === []) {
            unset($this->locks[$resource->getNamespace()]);
        }
    }

    /**
     * @throws RuntimeException
     */
    #[\Override]
    public function isLocked(ResourceInterface $resource): bool
    {
        if (!isset($this->locks[$resource->getNamespace()])) {
            return false;
        }

        foreach ($this->locks[$resource->getNamespace()]['expirations'] as $token => $expiration) {
            if ($expiration > $this->timeProvider->getCurrentTimeNanoseconds()) {
                return true;
            }
            $this->unlock($resource, (string) $token);
        }
        return false;
    }
}
