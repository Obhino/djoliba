<?php

namespace App\Tests\Service;

use App\Service\IA\CacheService;
use App\Service\Search\OpenSerpSearchService;
use App\Service\ReferenceCorrector;
use App\Service\SuggestionService;
use PHPUnit\Framework\TestCase;

class SuggestionServiceTest extends TestCase
{
    public function testSuggestFetchesRealArticlesAndFormatsCorrectly(): void
    {
        $openSerpMock = $this->createMock(OpenSerpSearchService::class);
        $cacheServiceMock = $this->createMock(CacheService::class);
        $referenceCorrectorMock = $this->createMock(ReferenceCorrector::class);
        $deepSeekMock = $this->createMock(\App\Service\IA\DeepSeekService::class);

        $query = 'Quantum Computing';
        $englishKeywords = 'quantum supremacy superconducting processor';
        $limit = 2;

        // Configuration du Mock de DeepSeek
        $deepSeekMock->expects($this->once())
            ->method('call')
            ->willReturn($englishKeywords);

        // Configuration du Mock de recherche OpenSerp avec les mots-clés en anglais
        $openSerpMock->expects($this->once())
            ->method('search')
            ->with($englishKeywords, null, 15, 'duck', false)
            ->willReturn([
                [
                    'title' => 'Quantum supremacy using a programmable superconducting processor - Nature',
                    'url' => 'https://www.nature.com/articles/s41586-019-1666-5',
                    'description' => 'F Arute, K Arya, R Babbush - Nature, 2019 - nature.com ... We report quantum supremacy using a superconducting processor...',
                    'source' => 'google'
                ],
                [
                    'title' => '[PDF] A Short Introduction to Quantum Computing - arXiv',
                    'url' => 'https://arxiv.org/abs/2101.12345',
                    'description' => 'M Nielsen - arXiv preprint arXiv:2101.12345, 2021 - arxiv.org ... This is an introductory guide to quantum computing.',
                    'source' => 'google'
                ]
            ]);

        // Simuler le fonctionnement de CacheService->remember
        $cacheServiceMock->method('remember')
            ->willReturnCallback(function ($key, $callback) {
                return $callback();
            });

        // Configurer ReferenceCorrector pour extraire les métadonnées
        $referenceCorrectorMock->method('extractDoiFromString')
            ->willReturnCallback(function ($str) {
                if (str_contains($str, 's41586-019-1666-5')) {
                    return '10.1038/s41586-019-1666-5';
                }
                return null;
            });

        $referenceCorrectorMock->method('parseSnippet')
            ->willReturnCallback(function ($desc, $title, $url) {
                if (str_contains($url, 'nature.com')) {
                    return [
                        'author' => 'F Arute, K Arya, R Babbush',
                        'year' => '2019',
                        'journal' => 'Nature'
                    ];
                }
                return [
                    'author' => 'M Nielsen',
                    'year' => '2021',
                    'journal' => 'Arxiv'
                ];
            });

        $referenceCorrectorMock->method('extractYearFromString')
            ->willReturn(null);

        $referenceCorrectorMock->method('resolveDoiMetadata')
            ->willReturnCallback(function ($doi) {
                if ($doi === '10.1038/s41586-019-1666-5') {
                    return [
                        'authors' => 'F Arute, K Arya, R Babbush',
                        'year' => 2019,
                        'journal' => 'Nature',
                        'title' => 'Quantum supremacy using a programmable superconducting processor'
                    ];
                }
                return null;
            });

        $service = new SuggestionService($openSerpMock, $cacheServiceMock, $referenceCorrectorMock, $deepSeekMock);
        $articles = $service->suggest($query, $limit);

        $this->assertCount(2, $articles);

        // Validation de l'article 1 (Nature)
        $this->assertEquals('Quantum supremacy using a programmable superconducting processor', $articles[0]['title']);
        $this->assertEquals('F Arute, K Arya, R Babbush', $articles[0]['authors']);
        $this->assertEquals(2019, $articles[0]['year']);
        $this->assertEquals('10.1038/s41586-019-1666-5', $articles[0]['doi']);
        $this->assertTrue($articles[0]['verified']);
        $this->assertEquals('https://www.nature.com/articles/s41586-019-1666-5', $articles[0]['url']);
        $this->assertEquals('Nature', $articles[0]['journal']);

        // Validation de l'article 2 (arXiv)
        $this->assertEquals('A Short Introduction to Quantum Computing', $articles[1]['title']);
        $this->assertEquals('M Nielsen', $articles[1]['authors']);
        $this->assertEquals(2021, $articles[1]['year']);
        $this->assertEquals('', $articles[1]['doi']);
        $this->assertTrue($articles[1]['verified']);
        $this->assertEquals('https://arxiv.org/abs/2101.12345', $articles[1]['url']);
        $this->assertEquals('Arxiv', $articles[1]['journal']);
    }
}
