<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use ArrayIterator;
use MiMatus\Locksmith\SemaphoreInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * @template T of SemaphoreInterface
 * @implements SemaphoreCollectionInterface<T>
 */
class SemaphoreCollection implements SemaphoreCollectionInterface
{
    /**
     * @param list<T> $semaphores
     */
    public function __construct(
        private array $semaphores,
        private Randomizer $randomizer = new Randomizer(new Xoshiro256StarStar()),
    ) {}

    #[\Override]
    public function count(): int
    {
        return count($this->semaphores);
    }

    #[\Override]
    public function without(SemaphoreInterface $semaphore): static
    {
        $newSemaphores = array_filter($this->semaphores, static fn(SemaphoreInterface $s) => $s !== $semaphore);

        return new self(array_values($newSemaphores), $this->randomizer);
    }

    /**
     * @return ?T
     */
    #[\Override]
    public function getRandom(): ?SemaphoreInterface
    {
        if ($this->semaphores === []) {
            return null;
        }

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
}
