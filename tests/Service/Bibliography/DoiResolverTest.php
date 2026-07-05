<?php

namespace App\Tests\Service\Bibliography;

use App\Service\Bibliography\DoiResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DoiResolverTest extends KernelTestCase
{
    public function testResolveValidDoi(): void
    {
        $mockResponseBody = [
            'status' => 'ok',
            'message' => [
                'title' => ['Test Article Title'],
                'author' => [
                    ['family' => 'Smith', 'given' => 'John'],
                    ['family' => 'Doe', 'given' => 'Jane']
                ],
                'published-print' => [
                    'date-parts' => [[2026, 12, 15]]
                ],
                'container-title' => ['Journal of Science'],
                'volume' => '42',
                'page' => '10-25',
                'publisher' => 'Science Publishing'
            ]
        ];

        // Créer un mock du client HTTP
        $mockResponse = new MockResponse(json_encode($mockResponseBody), [
            'status_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json']
        ]);
        $mockHttpClient = new MockHttpClient($mockResponse);

        $resolver = new DoiResolver($mockHttpClient);
        $metadata = $resolver->resolve('https://doi.org/10.1000/xyz123');

        $this->assertNotNull($metadata);
        $this->assertEquals('Test Article Title', $metadata['title']);
        $this->assertEquals('Smith, John and Doe, Jane', $metadata['authors']);
        $this->assertEquals('2026', $metadata['year']);
        $this->assertEquals('Journal of Science', $metadata['journal']);
        $this->assertEquals('42', $metadata['volume']);
        $this->assertEquals('10-25', $metadata['pages']);
        $this->assertEquals('Science Publishing', $metadata['publisher']);
        $this->assertEquals('10.1000/xyz123', $metadata['doi']);
    }

    public function testResolveInvalidDoiNotFound(): void
    {
        $mockResponse = new MockResponse('', [
            'status_code' => 404
        ]);
        $mockHttpClient = new MockHttpClient($mockResponse);

        $resolver = new DoiResolver($mockHttpClient);
        $metadata = $resolver->resolve('10.1000/notfound');

        $this->assertNull($metadata);
    }

    public function testResolveNetworkException(): void
    {
        $mockResponse = new MockResponse('', [
            'error' => 'Network error'
        ]);
        $mockHttpClient = new MockHttpClient($mockResponse);

        $resolver = new DoiResolver($mockHttpClient);
        $metadata = $resolver->resolve('10.1000/networkerror');

        $this->assertNull($metadata);
    }
}
