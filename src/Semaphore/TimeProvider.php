<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use MiMatus\Locksmith\Semaphore;
use RuntimeException;

class TimeProvider
{
    /**
     * @throws RuntimeException
     */
    public function getCurrentTimeNanoseconds(): float|int
    {
        $time = hrtime(true);
        if ($time === false) {
            throw new RuntimeException('Unable to retrieve high resolution time');
        }
        return $time;
    }
}
