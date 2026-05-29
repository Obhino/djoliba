<?php

namespace App\Tests\Service;

use App\Entity\Interaction;
use App\Entity\Project;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use App\Service\WritingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class WritingServiceTest extends TestCase
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
        $this->service = new WritingService(
            $this->deepSeekService,
            $this->cacheService,
            $this->entityManager
        );
    }

    public function testCheckOriginalityWithoutProject(): void
    {
        $text = "Ceci est un texte scientifique de test.";
        $mockResponse = json_encode([
            'originality_score' => 90,
            'level' => 'élevé',
            'similar_passages' => [
                ['passage' => 'texte scientifique', 'risk' => 'faible', 'suggestion' => 'texte de recherche']
            ],
            'recommendations' => ['Ajouter des citations']
        ]);

        $this->cacheService->method('remember')->willReturnCallback(function($key, $callback) {
            return $callback();
        });

        $this->deepSeekService->expects($this->once())
            ->method('call')
            ->willReturn($mockResponse);

        $this->entityManager->expects($this->never())->method('persist');

        $result = $this->service->checkOriginality($text);

        $this->assertEquals(90, $result['originality_score']);
        $this->assertEquals('élevé', $result['level']);
        $this->assertCount(1, $result['similar_passages']);
        $this->assertEquals('texte scientifique', $result['similar_passages'][0]['passage']);
        $this->assertNull($result['interaction']);
    }

    public function testCheckOriginalityWithProject(): void
    {
        $project = new Project();
        $text = "Ceci est un texte scientifique de test.";
        $mockResponse = json_encode([
            'score' => 85,
            'niveau' => 'moyen',
            'passages' => [
                ['extrait' => 'texte scientifique', 'risque' => 'faible', 'reformulation' => 'texte de recherche']
            ],
            'conseils' => ['Ajouter des citations']
        ]);

        $this->cacheService->method('remember')->willReturnCallback(function($key, $callback) {
            return $callback();
        });

        $this->deepSeekService->expects($this->once())
            ->method('call')
            ->willReturn($mockResponse);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->checkOriginality($text, $project);

        $this->assertEquals(85, $result['originality_score']);
        $this->assertEquals('moyen', $result['level']);
        $this->assertCount(1, $result['similar_passages']);
        $this->assertEquals('texte scientifique', $result['similar_passages'][0]['passage']);
        $this->assertInstanceOf(Interaction::class, $result['interaction']);
    }

    public function testSuggestJournalWithoutProject(): void
    {
        $text = "Ceci est le résumé d'un article de recherche.";
        $mockResponse = json_encode([
            'journals' => [
                [
                    'name' => 'Nature',
                    'publisher' => 'Springer',
                    'impact_factor' => '42.5',
                    'scope' => 'Généraliste',
                    'url' => 'https://nature.com',
                    'match_reason' => 'Excellence'
                ]
            ]
        ]);

        $this->cacheService->method('remember')->willReturnCallback(function($key, $callback) {
            return $callback();
        });

        $this->deepSeekService->expects($this->once())
            ->method('call')
            ->willReturn($mockResponse);

        $this->entityManager->expects($this->never())->method('persist');

        $result = $this->service->suggestJournal($text);

        $this->assertCount(1, $result['journals']);
        $this->assertEquals('Nature', $result['journals'][0]['name']);
        $this->assertNull($result['interaction']);
    }

    public function testSuggestJournalWithProject(): void
    {
        $project = new Project();
        $text = "Ceci est le résumé d'un article de recherche.";
        $mockResponse = json_encode([
            'revues' => [
                [
                    'nom' => 'Science',
                    'editeur' => 'AAAS',
                    'facteur_impact' => '47.7',
                    'domaine' => 'Généraliste',
                    'url' => 'https://science.org',
                    'justification' => 'Excellence'
                ]
            ]
        ]);

        $this->cacheService->method('remember')->willReturnCallback(function($key, $callback) {
            return $callback();
        });

        $this->deepSeekService->expects($this->once())
            ->method('call')
            ->willReturn($mockResponse);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->suggestJournal($text, $project);

        $this->assertCount(1, $result['journals']);
        $this->assertEquals('Science', $result['journals'][0]['name']);
        $this->assertInstanceOf(Interaction::class, $result['interaction']);
    }
}
