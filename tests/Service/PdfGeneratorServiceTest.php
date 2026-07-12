<?php

namespace App\Tests\Service;

use App\Service\Converter\PdfGeneratorService;
use PHPUnit\Framework\TestCase;

class PdfGeneratorServiceTest extends TestCase
{
    private PdfGeneratorService $pdfGenerator;

    protected function setUp(): void
    {
        // On instancie le service avec un chemin de projet bidon pour le test unitaire
        $this->pdfGenerator = new PdfGeneratorService('/fake/project/dir');
    }

    public function testProcessHtmlFormatsDoiLinks(): void
    {
        $inputHtml = '<p>Voir la référence doi:10.1000/xyz123 pour plus de détails.</p>';
        $metadata = [];

        $outputHtml = $this->pdfGenerator->processHtml($inputHtml, $metadata);

        $this->assertStringContainsString(
            '<a href="https://doi.org/10.1000/xyz123" class="doi-link" target="_blank">https://doi.org/10.1000/xyz123</a>',
            $outputHtml
        );
    }

    public function testProcessHtmlInjectsMetadataTags(): void
    {
        $inputHtml = '<html><head><title>Test Title</title></head><body>Contenu</body></html>';
        $metadata = [
            'title' => 'Mon Titre Académique',
            'author' => 'Marie-Grace',
            'keywords' => 'Djoliba, PHPUnit, Test',
            'description' => 'Description de test'
        ];

        $outputHtml = $this->pdfGenerator->processHtml($inputHtml, $metadata);

        $this->assertStringContainsString('<meta name="author" content="Marie-Grace">', $outputHtml);
        $this->assertStringContainsString('<meta name="keywords" content="Djoliba, PHPUnit, Test">', $outputHtml);
        $this->assertStringContainsString('<meta name="description" content="Description de test">', $outputHtml);
    }

    public function testProcessHtmlGeneratesInteractiveTocAndAddsAnchors(): void
    {
        // Un document avec 4 titres h1/h2 déclenche la génération automatique de la TOC
        $inputHtml = '
            <div class="content">
                <h1>Introduction générale</h1>
                <p>Texte intro.</p>
                <h2>Méthodologie</h2>
                <p>Texte meth.</p>
                <h1>Résultats obtenus</h1>
                <p>Texte res.</p>
                <h2>Discussion</h2>
                <p>Texte disc.</p>
            </div>
        ';
        $metadata = [];

        $outputHtml = $this->pdfGenerator->processHtml($inputHtml, $metadata);

        // Vérifier que des IDs uniques ont été attribués aux titres
        $this->assertStringContainsString('<h1 id="section-1">Introduction générale</h1>', $outputHtml);
        $this->assertStringContainsString('<h2 id="section-2">Méthodologie</h2>', $outputHtml);
        $this->assertStringContainsString('<h1 id="section-3">Résultats obtenus</h1>', $outputHtml);
        $this->assertStringContainsString('<h2 id="section-4">Discussion</h2>', $outputHtml);

        // Vérifier que la table des matières a été générée et injectée
        $this->assertStringContainsString('<div class="toc-container">', $outputHtml);
        $this->assertStringContainsString('<div class="toc-title">Table des Matières</div>', $outputHtml);
        $this->assertStringContainsString('<ul class="toc-list">', $outputHtml);
        $this->assertStringContainsString('<li class="toc-item level-1"><a href="#section-1">Introduction générale</a></li>', $outputHtml);
        $this->assertStringContainsString('<li class="toc-item level-2"><a href="#section-2">Méthodologie</a></li>', $outputHtml);
    }

    public function testProcessHtmlDoesNotGenerateTocForShortDocuments(): void
    {
        // Document avec seulement 2 titres (seuil de génération automatique non atteint)
        $inputHtml = '
            <div class="content">
                <h1>Introduction</h1>
                <p>Texte intro.</p>
                <h2>Méthodologie</h2>
                <p>Texte meth.</p>
            </div>
        ';
        $metadata = [];

        $outputHtml = $this->pdfGenerator->processHtml($inputHtml, $metadata);

        // Les IDs doivent tout de même être ajoutés pour les ancres
        $this->assertStringContainsString('<h1 id="section-1">Introduction</h1>', $outputHtml);
        
        // Mais pas de table des matières automatique injectée
        $this->assertStringNotContainsString('<div class="toc-container">', $outputHtml);
    }
}
