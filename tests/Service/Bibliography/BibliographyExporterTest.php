<?php

namespace App\Tests\Service\Bibliography;

use App\Entity\BibliographicReference;
use App\Entity\User;
use App\Service\Bibliography\BibliographyExporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BibliographyExporterTest extends KernelTestCase
{
    private ?BibliographyExporter $exporter = null;
    private $entityManager = null;
    private ?User $user = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->exporter = $container->get(BibliographyExporter::class);
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->user = new User();
        $this->user->setEmail('exporter-test@djoliba.com');
        $this->user->setPassword('password');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->user) {
            $freshUser = $this->entityManager->getRepository(User::class)->find($this->user->getId());
            if ($freshUser) {
                $this->entityManager->remove($freshUser);
            }
        }
        $this->entityManager->flush();
        parent::tearDown();
    }

    public function testExtractKeysFromHtmlAndLatex(): void
    {
        // 1. Contenu HTML (WYSIWYG) avec clés uniques et combinées
        $htmlContent = '
            <p>Voici une citation simple <cite class="djoliba-citation" data-cite-key="Einstein1916" data-display="(Einstein, 1916)" data-style="apa">(Einstein, 1916)</cite> et une multiple <cite class="djoliba-citation" data-cite-key="Einstein1905,Curie1911" data-display="(Einstein, 1905; Curie, 1911)">(Einstein, 1905; Curie, 1911)</cite>.</p>
        ';

        $keysFromHtml = $this->exporter->extractKeys($htmlContent);
        
        $this->assertCount(3, $keysFromHtml);
        $this->assertContains('Einstein1916', $keysFromHtml);
        $this->assertContains('Einstein1905', $keysFromHtml);
        $this->assertContains('Curie1911', $keysFromHtml);

        // 2. Contenu LaTeX avec clés uniques et combinées
        $latexContent = '
            La relativité générale \cite{Einstein1916} a révolutionné la physique. Voir aussi \cite{Einstein1905,Curie1911}.
        ';

        $keysFromLatex = $this->exporter->extractKeys($latexContent);

        $this->assertCount(3, $keysFromLatex);
        $this->assertContains('Einstein1916', $keysFromLatex);
        $this->assertContains('Einstein1905', $keysFromLatex);
        $this->assertContains('Curie1911', $keysFromLatex);
    }

    public function testGetReferencesByKeys(): void
    {
        // Créer des références
        $ref1 = new BibliographicReference();
        $ref1->setUser($this->user);
        $ref1->setCiteKey('Einstein1916');
        $ref1->setEntryType('article');
        $ref1->setTitle('Die Grundlage der allgemeinen Relativitätstheorie');
        $ref1->setAuthors('Einstein, Albert');
        $ref1->setYear('1916');
        $ref1->setSource('manual');
        $this->entityManager->persist($ref1);

        $ref2 = new BibliographicReference();
        $ref2->setUser($this->user);
        $ref2->setCiteKey('Curie1911');
        $ref2->setEntryType('book');
        $ref2->setTitle('Traité de radioactivité');
        $ref2->setAuthors('Curie, Marie');
        $ref2->setYear('1911');
        $ref2->setSource('manual');
        $this->entityManager->persist($ref2);

        $this->entityManager->flush();

        // Récupérer les références par clés
        $refs = $this->exporter->getReferencesByKeys($this->user, ['Einstein1916', 'Curie1911', 'NonExistentKey']);

        $this->assertCount(2, $refs);
        
        $keys = array_map(fn($r) => $r->getCiteKey(), $refs);
        $this->assertContains('Einstein1916', $keys);
        $this->assertContains('Curie1911', $keys);
    }

    public function testGenerateHtmlAndLatex(): void
    {
        $ref1 = new BibliographicReference();
        $ref1->setUser($this->user);
        $ref1->setCiteKey('Einstein1916');
        $ref1->setEntryType('article');
        $ref1->setTitle('Die Grundlage');
        $ref1->setAuthors('Einstein, Albert');
        $ref1->setYear('1916');
        $ref1->setJournal('Annalen der Physik');
        $ref1->setDoi('10.1002/andp.19163540702');
        $ref1->setSource('manual');

        $ref2 = new BibliographicReference();
        $ref2->setUser($this->user);
        $ref2->setCiteKey('Curie1911');
        $ref2->setEntryType('book');
        $ref2->setTitle('Traité');
        $ref2->setAuthors('Curie, Marie');
        $ref2->setYear('1911');
        $ref2->setJournal('Gauthier-Villars');
        $ref2->setSource('manual');

        $references = [$ref1, $ref2];

        // 1. HTML
        $html = $this->exporter->generateHtml($references);
        $this->assertStringContainsString('Bibliographie</h2>', $html);
        $this->assertStringContainsString('Curie, Marie', $html);
        $this->assertStringContainsString('Einstein, Albert', $html);
        $this->assertStringContainsString('10.1002/andp.19163540702', $html);

        // 2. LaTeX
        $latex = $this->exporter->generateLatex($references);
        $this->assertStringContainsString('\begin{thebibliography}{2}', $latex);
        $this->assertStringContainsString('\bibitem{Curie1911}', $latex);
        $this->assertStringContainsString('\bibitem{Einstein1916}', $latex);
        $this->assertStringContainsString('\end{thebibliography}', $latex);
    }

    public function testExportToBibtex(): void
    {
        $ref = new BibliographicReference();
        $ref->setUser($this->user);
        $ref->setCiteKey('Watson1953');
        $ref->setEntryType('article');
        $ref->setTitle('Molecular Structure of Nucleic Acids');
        $ref->setAuthors('Watson, James and Crick, Francis');
        $ref->setYear('1953');
        $ref->setJournal('Nature');
        $ref->setDoi('10.1038/171737a0');
        $ref->setRawData(['volume' => '171', 'pages' => '737-738', 'custom_tag' => 'customValue']);
        $ref->setSource('manual');

        $bibtex = $this->exporter->exportToBibtex([$ref]);

        $this->assertStringContainsString('@article{Watson1953,', $bibtex);
        $this->assertStringContainsString('title = {Molecular Structure of Nucleic Acids}', $bibtex);
        $this->assertStringContainsString('author = {Watson, James and Crick, Francis}', $bibtex);
        $this->assertStringContainsString('year = {1953}', $bibtex);
        $this->assertStringContainsString('journal = {Nature}', $bibtex);
        $this->assertStringContainsString('doi = {10.1038/171737a0}', $bibtex);
        $this->assertStringContainsString('volume = {171}', $bibtex);
        $this->assertStringContainsString('pages = {737-738}', $bibtex);
        $this->assertStringContainsString('custom_tag = {customValue}', $bibtex);
    }
}
