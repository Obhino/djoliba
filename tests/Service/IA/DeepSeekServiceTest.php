<?php

namespace App\Tests\Service\IA;

use App\Service\IA\DeepSeekService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class DeepSeekServiceTest extends TestCase
{
    private $httpClient;
    private $logger;
    private $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new DeepSeekService(
            $this->httpClient,
            $this->logger,
            'test_api_key',
            'https://api.deepseek.com'
        );
    }

    public function testCallReturnsContentOnSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'choices' => [
                ['message' => ['content' => 'Hello from DeepSeek']]
            ],
            'usage' => ['total_tokens' => 10]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->call('Hello');

        $this->assertEquals('Hello from DeepSeek', $result);
    }

    public function testCallThrowsExceptionOnMissingContent(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'choices' => [
                ['message' => []] // Content missing
            ]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('La réponse DeepSeek ne contient pas de contenu.');

        $this->service->call('Hello');
    }

    public function testCallRetriesOnFailure(): void
    {
        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willThrowException(new \UnexpectedValueException('Network error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DeepSeek API indisponible après 3 tentatives');

        $this->service->call('Hello');
    }
}
