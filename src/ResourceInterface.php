<?php

declare(strict_types=1);

namespace MiMatus\Locksmith;

interface ResourceInterface
{
    public function getNamespace(): string;

    public function getVersion(): int;
}
