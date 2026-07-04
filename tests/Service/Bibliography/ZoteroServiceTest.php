<?php

namespace App\Tests\Service\Bibliography;

use App\Entity\BibliographyEntry;
use App\Entity\SubProject;
use App\Repository\BibliographyEntryRepository;
use App\Service\Bibliography\ZoteroService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ZoteroServiceTest extends TestCase
{
    private $httpClient;
    private $entityManager;
    private $repository;
    private ZoteroService $zoteroService;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(BibliographyEntryRepository::class);

        $this->zoteroService = new ZoteroService(
            $this->httpClient,
            $this->entityManager,
            $this->repository
        );
    }

    public function testValidateCredentialsSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('/collections'), $this->callback(function($options) {
                return $options['headers']['Zotero-API-Key'] === 'my-api-key';
            }))
            ->willReturn($response);

        $result = $this->zoteroService->validateCredentials('my-user-id', 'my-api-key');
        $this->assertTrue($result);
    }

    public function testValidateCredentialsFailure(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(403);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->zoteroService->validateCredentials('my-user-id', 'wrong-key');
        $this->assertFalse($result);
    }

    public function testFetchCollections(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            [
                'key' => 'col1',
                'data' => [
                    'name' => 'Machine Learning',
                    'parentCollection' => null
                ]
            ],
            [
                'key' => 'col2',
                'data' => [
                    'name' => 'Deep Learning',
                    'parentCollection' => 'col1'
                ]
            ]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $cols = $this->zoteroService->fetchCollections('user', 'key');
        $this->assertCount(2, $cols);
        $this->assertEquals('col1', $cols[0]['key']);
        $this->assertEquals('Machine Learning', $cols[0]['name']);
        $this->assertNull($cols[0]['parentCollection']);

        $this->assertEquals('col2', $cols[1]['key']);
        $this->assertEquals('col1', $cols[1]['parentCollection']);
    }

    public function testFetchItems(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            [
                'key' => 'item1',
                'data' => [
                    'key' => 'item1',
                    'itemType' => 'journalArticle',
                    'title' => 'Attention Is All You Need',
                    'creators' => [
                        ['creatorType' => 'author', 'firstName' => 'Ashish', 'lastName' => 'Vaswani'],
                        ['creatorType' => 'author', 'firstName' => 'Noam', 'lastName' => 'Shazeer']
                    ],
                    'publicationTitle' => 'arXiv',
                    'date' => '2017-06-12',
                    'DOI' => '10.48550/arXiv.1706.03762',
                    'pages' => '11-15'
                ]
            ]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $items = $this->zoteroService->fetchItems('user', 'key');
        $this->assertCount(1, $items);
        
        $item = $items[0];
        $this->assertEquals('item1', $item['zoteroKey']);
        $this->assertEquals('vaswani2017', $item['citeKey']);
        $this->assertEquals('article', $item['entryType']);
        $this->assertEquals('Attention Is All You Need', $item['title']);
        $this->assertEquals('Vaswani, Ashish and Shazeer, Noam', $item['authors']);
        $this->assertEquals('2017', $item['year']);
        $this->assertEquals('10.48550/arXiv.1706.03762', $item['doi']);
        $this->assertEquals('11-15', $item['rawData']['pages']);
    }
}
