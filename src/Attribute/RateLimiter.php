<?php

namespace App\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class RateLimiter
{
    public function __construct(
        public string $configuration,
        public int $tokens = 1
    ) {}
}
