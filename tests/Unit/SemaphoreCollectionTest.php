<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Unit;

use MiMatus\Locksmith\Semaphore\SemaphoreCollection;
use MiMatus\Locksmith\SemaphoreInterface;
use PHPUnit\Framework\TestCase;

class SemaphoreCollectionTest extends TestCase
{
    public function testGetIterator(): void
    {
        $semaphore1 = $this->createStub(SemaphoreInterface::class);
        $semaphore2 = $this->createStub(SemaphoreInterface::class);
        $semaphore3 = $this->createStub(SemaphoreInterface::class);

        $collection = new SemaphoreCollection([
            $semaphore1,
            $semaphore2,
            $semaphore3,
        ]);

        $semaphores = [];
        foreach ($collection as $semaphore) {
            $semaphores[] = $semaphore;
        }

        self::assertSame([$semaphore1, $semaphore2, $semaphore3], $semaphores);
    }

    public function testCount(): void
    {
        $semaphore1 = $this->createStub(SemaphoreInterface::class);
        $semaphore2 = $this->createStub(SemaphoreInterface::class);

        $collection = new SemaphoreCollection([
            $semaphore1,
            $semaphore2,
        ]);

        self::assertSame(2, $collection->count());
    }

    public function testWithout(): void
    {
        $semaphore1 = $this->createStub(SemaphoreInterface::class);
        $semaphore2 = $this->createStub(SemaphoreInterface::class);
        $semaphore3 = $this->createStub(SemaphoreInterface::class);

        $collection = new SemaphoreCollection([
            $semaphore1,
            $semaphore2,
            $semaphore3,
        ]);

        $newCollection = $collection->without($semaphore2);

        self::assertSame(3, $collection->count());
        self::assertSame(2, $newCollection->count());

        $semaphores = [];
        foreach ($newCollection as $semaphore) {
            $semaphores[] = $semaphore;
        }

        self::assertSame([$semaphore1, $semaphore3], $semaphores);
    }
}
