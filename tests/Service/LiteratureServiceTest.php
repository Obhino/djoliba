<?php

namespace App\Tests\Service;

use App\Entity\Interaction;
use App\Entity\Project;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use App\Service\LiteratureService;
use App\Service\Search\OpenSerpSearchService;
use App\Service\ReferenceInterceptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LiteratureServiceTest extends TestCase
{
    private $deepSeekService;
    private $cacheService;
    private $entityManager;
    private $openSerpSearchService;
    private $referenceInterceptor;
    private $service;

    protected function setUp(): void
    {
        $this->deepSeekService = $this->createMock(DeepSeekService::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->openSerpSearchService = $this->createMock(OpenSerpSearchService::class);
        $this->referenceInterceptor = $this->createMock(ReferenceInterceptor::class);

        $this->service = new LiteratureService(
            $this->deepSeekService,
            $this->cacheService,
            $this->entityManager,
            $this->openSerpSearchService,
            $this->referenceInterceptor
        );
    }

    public function testReviewCallsDeepSeekAndPersistsInteraction(): void
    {
        $project = new Project();
        $query = "IA en Afrique";
        $mockResponse = "Analyse de l'IA...";

        // Simulation du cache qui appelle le callback
        $this->cacheService->method('remember')->willReturnCallback(function($key, $callback) {
            return $callback();
        });

        $this->deepSeekService->expects($this->once())
            ->method('call')
            ->willReturn($mockResponse);

        // Simulation de la recherche web
        $this->openSerpSearchService->method('search')->willReturn([
            [
                'title' => 'Article de test',
                'url' => 'https://arxiv.org/abs/1234.5678',
                'description' => 'Description de test...',
                'source' => 'google'
            ]
        ]);

        // Simulation de l'interception de références
        $this->referenceInterceptor->method('formatEnrichedResponse')->willReturnCallback(function($text) {
            return $text . " [Enriched]";
        });

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->review($query, $project);

        $this->assertEquals($mockResponse, $result['response']);
        $this->assertEquals($mockResponse . " [Enriched]", $result['literature_review']);
        $this->assertCount(1, $result['web_sources']);
        $this->assertEquals('Article de test', $result['web_sources'][0]['title']);
        $this->assertInstanceOf(Interaction::class, $result['interaction']);
        $this->assertEquals('literature_review', $result['interaction']->getType());
    }
}
