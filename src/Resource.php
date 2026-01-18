<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

readonly class Resource implements ResourceInterface
{
    public function __construct(
        public string $namespace = '',
        public int $version = 1,
    ) {}

    #[\Override]
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    #[\Override]
    public function getVersion(): int
    {
        return $this->version;
    }
}
