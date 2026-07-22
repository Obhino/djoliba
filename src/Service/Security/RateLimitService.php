<?php
 
namespace App\Service\Security;
 
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
 
class RateLimitService
{
    public function __construct(
        private RateLimiterFactoryInterface $apiDefaultLimiter,
        private RateLimiterFactoryInterface $apiIaLimiter,
        private RateLimiterFactoryInterface $apiLoginLimiter
    ) {}
 
    public function getLimiterFactory(string $name): RateLimiterFactoryInterface
    {
        return match ($name) {
            'api_default' => $this->apiDefaultLimiter,
            'api_ia' => $this->apiIaLimiter,
            'api_login' => $this->apiLoginLimiter,
            default => throw new \InvalidArgumentException(sprintf('Rate limiter "%s" not found.', $name)),
        };
    }
}
