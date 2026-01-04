<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

readonly class Resource
{
    /**
     * @param non-negative-int $ttlNanoseconds
     */
    public function __construct(
        public int $ttlNanoseconds,
        public string $namespace = '',
        public int $version = 1,
    ) {}
}
