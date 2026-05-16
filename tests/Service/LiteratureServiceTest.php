<?php

namespace App\Tests\Service;

use App\Entity\Interaction;
use App\Entity\Project;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use App\Service\LiteratureService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LiteratureServiceTest extends TestCase
{
    private $deepSeekService;
    private $cacheService;
    private $entityManager;
    private $service;

    protected function setUp(): void
    {
        $this->deepSeekService = $this->createMock(DeepSeekService::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new LiteratureService(
            $this->deepSeekService,
            $this->cacheService,
            $this->entityManager
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

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->review($query, $project);

        $this->assertEquals($mockResponse, $result['response']);
        $this->assertInstanceOf(Interaction::class, $result['interaction']);
        $this->assertEquals('literature_review', $result['interaction']->getType());
    }
}
