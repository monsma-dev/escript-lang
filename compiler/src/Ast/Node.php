<?php

declare(strict_types=1);

namespace EScript\Compiler\Ast;

class Node
{
    public function __construct(
        public readonly string $kind,
        public readonly array $data = [],
        public readonly int $line = 0,
        public readonly int $column = 0,
    ) {}
}
