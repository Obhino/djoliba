<?php

namespace App\Tests\Service;

use App\Service\ReferenceExtractor;
use App\Service\ReferenceCorrector;
use App\Service\ReferenceInterceptor;
use PHPUnit\Framework\TestCase;

class ReferenceInterceptorTest extends TestCase
{
    private $extractor;
    private $corrector;
    private $interceptor;

    protected function setUp(): void
    {
        $this->extractor = $this->createMock(ReferenceExtractor::class);
        $this->corrector = $this->createMock(ReferenceCorrector::class);
        $this->interceptor = new ReferenceInterceptor($this->extractor, $this->corrector);
    }

    public function testFormatEnrichedResponseNoReferences(): void
    {
        $text = "Pas de references ici.";
        $this->extractor->expects($this->once())
            ->method('extract')
            ->willReturn([]);

        $result = $this->interceptor->formatEnrichedResponse($text);
        $this->assertEquals($text, $result);
    }

    public function testFormatEnrichedResponseVerifiedAndUnverified(): void
    {
        $text = "Voici un article: Sall (2024) et un autre: Inconnu (2025).";

        $ref1 = [
            'type' => 'text',
            'raw' => 'Sall (2024)',
            'query' => 'Sall (2024)',
            'parsed' => [
                'author' => 'Sall, M.',
                'year' => '2024',
                'title' => 'Transformers for low-resource African languages',
                'journal' => 'Arxiv'
            ]
        ];

        $ref2 = [
            'type' => 'text',
            'raw' => 'Inconnu (2025)',
            'query' => 'Inconnu (2025)',
            'parsed' => [
                'author' => 'Inconnu',
                'year' => '2025',
                'title' => 'Paper title',
                'journal' => 'Journal'
            ]
        ];

        $this->extractor->expects($this->once())
            ->method('extract')
            ->willReturn([$ref1, $ref2]);

        $this->corrector->expects($this->exactly(2))
            ->method('verify')
            ->willReturnMap([
                [$ref1, [
                    'verified' => true,
                    'corrected' => false,
                    'title' => 'Transformers for low-resource African languages',
                    'url' => 'https://doi.org/10.48550/arXiv.2401.12345',
                    'doi' => '10.48550/arXiv.2401.12345',
                    'score' => 95.0,
                    'alternative_matches' => [],
                    'corrected_metadata' => [
                        'author' => 'Sall, M.',
                        'year' => '2024',
                        'title' => 'Transformers for low-resource African languages',
                        'journal' => 'Arxiv'
                    ]
                ]],
                [$ref2, [
                    'verified' => false,
                    'corrected' => false,
                    'title' => null,
                    'url' => null,
                    'doi' => null,
                    'score' => 10.0,
                    'alternative_matches' => [],
                    'corrected_metadata' => [
                        'author' => 'Inconnu',
                        'year' => '2025',
                        'title' => 'Paper title',
                        'journal' => 'Journal'
                    ]
                ]]
            ]);

        $result = $this->interceptor->formatEnrichedResponse($text);

        // Vérifier la présence du bloc HTML enrichi pour Sall (2024)
        $this->assertStringContainsString('<span class="ref verified">', $result);
        $this->assertStringContainsString('<span class="ref-authors">Sall, M.</span>', $result);
        $this->assertStringContainsString('<span class="ref-year">2024</span>', $result);
        $this->assertStringContainsString('<span class="ref-title">"Transformers for low-resource African languages"</span>.', $result);
        $this->assertStringContainsString('<span class="ref-journal">Arxiv</span>', $result);
        $this->assertStringContainsString('<a href="https://doi.org/10.48550/arXiv.2401.12345" class="ref-link" target="_blank">[DOI]</a>', $result);
        $this->assertStringContainsString('<span class="ref-badge">✅ vérifié</span>', $result);

        // Vérifier la présence du bloc HTML enrichi pour Inconnu (2025)
        $this->assertStringContainsString('<span class="ref unverified">', $result);
        $this->assertStringContainsString('<span class="ref-badge">❌ non trouvé</span>', $result);
    }
}
