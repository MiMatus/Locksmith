<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use ArrayIterator;
use MiMatus\Locksmith\SemaphoreInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RedisCluster;

/**
 * @template T of SemaphoreInterface
 * @implements SemaphoreCollectionInterface<T>
 */
class PhpRedisClusterCollection
{
    /**
     * @param list<T> $semaphores
     */
    public function __construct(
        private RedisCluster $redisCluster,
    ) {}

    #[\Override]
    public function getMininumNodeCount(): int
    {
        $minNodesPerSlot = $redisShards[0]
        $this->redisCluster->_masters();
        $this->redisCluster->cluster('slots');
    }

    #[\Override]
    public function without(SemaphoreInterface $semaphore): static
    {
        $newSemaphores = array_filter($this->semaphores, static fn(SemaphoreInterface $s) => $s !== $semaphore);

        return new self(array_values($newSemaphores), $this->randomizer);
    }

    /**
     * @return T
     */
    #[\Override]
    public function getRandom(): SemaphoreInterface
    {
        /** @var int */
        $key = $this->randomizer->pickArrayKeys($this->semaphores, 1)[0];
        return $this->semaphores[$key];
    }

    /**
     * @return \Traversable<T>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        return new ArrayIterator($this->semaphores);
    }

    public function getSemaphores(string $key): array
    {
        $semaphores = $this->redisCluster->getNodeForKey($key);
        return $semaphores;
    }


}


