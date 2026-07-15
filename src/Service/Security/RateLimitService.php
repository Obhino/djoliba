<?php

namespace App\Service\Security;

use Symfony\Component\RateLimiter\RateLimiterFactory;

class RateLimitService
{
    public function __construct(
        private RateLimiterFactory $apiDefaultLimiter,
        private RateLimiterFactory $apiIaLimiter,
        private RateLimiterFactory $apiLoginLimiter
    ) {}

    public function getLimiterFactory(string $name): RateLimiterFactory
    {
        return match ($name) {
            'api_default' => $this->apiDefaultLimiter,
            'api_ia' => $this->apiIaLimiter,
            'api_login' => $this->apiLoginLimiter,
            default => throw new \InvalidArgumentException(sprintf('Rate limiter "%s" not found.', $name)),
        };
    }
}
