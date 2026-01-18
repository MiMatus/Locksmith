<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use Countable;
use IteratorAggregate;
use MiMatus\Locksmith\SemaphoreInterface;
use Traversable;

/**
 * @template T of SemaphoreInterface
 */
interface SemaphoreCollectionInterface extends IteratorAggregate, Countable
{
    public function without(SemaphoreInterface $semaphore): static;

    /**
     * @return T
     */
    public function getRandom(): SemaphoreInterface;

    /**
     * @return Traversable<T>
     */
    #[\Override]
    public function getIterator(): Traversable;
}
