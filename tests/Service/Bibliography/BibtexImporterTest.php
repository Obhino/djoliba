<?php

namespace App\Tests\Service\Bibliography;

use App\Entity\BibliographicReference;
use App\Entity\User;
use App\Service\Bibliography\BibtexImporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BibtexImporterTest extends KernelTestCase
{
    private ?BibtexImporter $importer = null;
    private $entityManager = null;
    private ?User $user = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->importer = $container->get(BibtexImporter::class);
        $this->entityManager = $container->get('doctrine')->getManager();

        // Créer un utilisateur pour le test
        $this->user = new User();
        $this->user->setEmail('importer-test@djoliba.com');
        $this->user->setPassword('password');
        $this->user->setFirstName('Importer');
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

    public function testImportBibtexContent(): void
    {
        $bibContent = <<<BIBTEX
@article{einstein1916relativity,
    title={Relativity: The Special and General Theory},
    author={Einstein, Albert},
    year={1916},
    journal={Annalen der Physik},
    volume={49},
    pages={769-822},
    doi={10.1002/andp.19163540702},
    abstract={A beautiful explanation of space and time.},
    keywords={physics, relativity}
}

@book{title={Directly Missing Citekey Book},
    author={Smith, John and Doe, Jane},
    year={2023},
    publisher={Test Publisher}
}

@book{Smith2023,
    title={Another Book Colliding with Generated Key},
    author={Smith, John},
    year={2023}
}
BIBTEX;

        $stats = $this->importer->import($this->user, $bibContent);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(3, $stats['imported']);

        // Vérifier les entités en base
        $repo = $this->entityManager->getRepository(BibliographicReference::class);
        $refs = $repo->findBy(['user' => $this->user], ['id' => 'ASC']);
        $this->assertCount(3, $refs);

        // 1. Première référence (Relativity)
        $ref1 = $refs[0];
        $this->assertEquals('einstein1916relativity', $ref1->getCiteKey());
        $this->assertEquals('article', $ref1->getEntryType());
        $this->assertEquals('Relativity: The Special and General Theory', $ref1->getTitle());
        $this->assertEquals('Einstein, Albert', $ref1->getAuthors());
        $this->assertEquals('1916', $ref1->getYear());
        $this->assertEquals('Annalen der Physik', $ref1->getJournal());
        $this->assertEquals('10.1002/andp.19163540702', $ref1->getDoi());
        $this->assertEquals('bib_file', $ref1->getSource());
        $this->assertEquals('49', $ref1->getRawData()['volume'] ?? null);
        $this->assertEquals('physics, relativity', $ref1->getRawData()['keywords'] ?? null);

        // 2. Deuxième référence (sans CiteKey -> doit générer Smith2023)
        $ref2 = $refs[1];
        $this->assertEquals('Smith2023', $ref2->getCiteKey());
        $this->assertEquals('book', $ref2->getEntryType());
        $this->assertEquals('Directly Missing Citekey Book', $ref2->getTitle());

        // 3. Troisième référence (CiteKey Smith2023 entre en collision -> doit générer Smith2023a)
        $ref3 = $refs[2];
        $this->assertEquals('Smith2023a', $ref3->getCiteKey());
        $this->assertEquals('Another Book Colliding with Generated Key', $ref3->getTitle());
    }

    public function testLatexUnicodeDecoding(): void
    {
        $bibContent = <<<BIBTEX
@article{accentTest,
    title={M{\'e}lange {\'e}l{\'e}gant {\^e}tre},
    author={Ren{\'{e}} Desqu{\^{e}}nes},
    year={2026}
}
BIBTEX;

        $this->importer->import($this->user, $bibContent);

        $repo = $this->entityManager->getRepository(BibliographicReference::class);
        $ref = $repo->findOneBy(['user' => $this->user, 'citeKey' => 'accentTest']);

        $this->assertNotNull($ref);
        // renanbr/bibtex-parser avec LatexToUnicodeProcessor traduit les accents LaTeX :
        // \'e -> é, \^e -> ê, etc.
        $this->assertEquals('Mélange élégant être', $ref->getTitle());
        $this->assertEquals('René Desquênes', $ref->getAuthors());
    }
}
