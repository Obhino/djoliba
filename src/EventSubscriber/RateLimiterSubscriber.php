<?php

namespace App\EventSubscriber;

use App\Attribute\RateLimiter;
use App\Service\Security\RateLimitService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RateLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimitService $rateLimitService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (is_array($controller)) {
            $class = new \ReflectionClass($controller[0]);
            $method = $class->getMethod($controller[1]);
        } else {
            return;
        }

        $attribute = $method->getAttributes(RateLimiter::class)[0] ?? null;
        if (!$attribute) {
            $attribute = $class->getAttributes(RateLimiter::class)[0] ?? null;
        }

        if ($attribute) {
            $request = $event->getRequest();
            $env = isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'dev';
            if ($env === 'test' && !$request->headers->has('X-Enable-Rate-Limit')) {
                return;
            }

            $rateLimiterAttr = $attribute->newInstance();

            $clientIp = $request->getClientIp() ?? '127.0.0.1';
            $csrfToken = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf_token');

            $key = $csrfToken ? 'csrf_' . sha1((string) $csrfToken) : 'ip_' . sha1((string) $clientIp);
            $key .= '_' . $rateLimiterAttr->configuration;

            $limiterFactory = $this->rateLimitService->getLimiterFactory($rateLimiterAttr->configuration);
            $limiter = $limiterFactory->create($key);

            $limit = $limiter->consume($rateLimiterAttr->tokens);
            if (!$limit->isAccepted()) {
                $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
                $event->setController(function () use ($retryAfter) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => [
                            'code' => 429,
                            'message' => 'Trop de requêtes. Veuillez réessayer plus tard.',
                            'retry_after' => max(0, $retryAfter)
                        ]
                    ], Response::HTTP_TOO_MANY_REQUESTS, [
                        'Retry-After' => max(0, $retryAfter)
                    ]);
                });
            }
        }
    }
}
