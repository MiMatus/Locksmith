<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Semaphore;

use Exception;
use Throwable;

class GroupedException extends Exception
{
    /**
     * @param list<Throwable> $exceptions
     */
    public function __construct(
        string $message,
        public array $exceptions,
    ) {
        parent::__construct($message);
    }
}
