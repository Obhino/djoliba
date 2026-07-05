<?php

namespace App\Tests\Service\Bibliography;

use App\Entity\BibliographicReference;
use App\Entity\ResearchProject;
use App\Entity\User;
use App\Service\Bibliography\BibliographicReferenceManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BibliographicReferenceManagerTest extends KernelTestCase
{
    private ?BibliographicReferenceManager $manager = null;
    private $entityManager = null;
    private ?User $user = null;
    private ?ResearchProject $project = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->manager = $container->get(BibliographicReferenceManager::class);
        $this->entityManager = $container->get('doctrine')->getManager();

        // Créer un utilisateur et un projet pour le test
        $this->user = new User();
        $this->user->setEmail('manager-test@djoliba.com');
        $this->user->setPassword('password');
        $this->user->setFirstName('Manager');
        $this->entityManager->persist($this->user);

        $this->project = new ResearchProject();
        $this->project->setUser($this->user);
        $this->project->setTitle('Manager Test Project');
        $this->entityManager->persist($this->project);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->user) {
            $freshUser = $this->entityManager->getRepository(User::class)->find($this->user->getId());
            if ($freshUser) {
                // Nettoyage en cascade
                $this->entityManager->remove($freshUser);
            }
        }
        $this->entityManager->flush();
        parent::tearDown();
    }

    public function testBibliographyManagerWorkflow(): void
    {
        // 1. Créer une référence
        $data = [
            'citeKey' => 'key1',
            'entryType' => 'book',
            'title' => 'First Book',
            'authors' => 'Author A, Author B',
            'year' => '2025',
            'journal' => 'Publisher X',
            'doi' => '10.1001/book',
            'source' => 'bib_file',
            'rawData' => ['pages' => '100']
        ];
        $ref = $this->manager->createReference($this->user, $data);

        $this->assertNotNull($ref->getId());
        $this->assertEquals('key1', $ref->getCiteKey());
        $this->assertEquals('book', $ref->getEntryType());
        $this->assertEquals('First Book', $ref->getTitle());

        // 2. Récupérer pour l'utilisateur
        $refs = $this->manager->getReferencesForUser($this->user);
        $this->assertCount(1, $refs);
        $this->assertEquals('key1', $refs[0]->getCiteKey());

        // 3. Mettre à jour la référence
        $updatedData = [
            'title' => 'First Book - Second Edition',
            'year' => '2026'
        ];
        $updatedRef = $this->manager->updateReference($ref, $updatedData);
        $this->assertEquals('First Book - Second Edition', $updatedRef->getTitle());
        $this->assertEquals('2026', $updatedRef->getYear());

        // 4. Recherche
        // Recherche sans filtre
        $searchResults = $this->manager->searchReferences($this->user, 'Edition');
        $this->assertCount(1, $searchResults);

        // Recherche avec filtre correspondant
        $searchResultsFiltered = $this->manager->searchReferences($this->user, 'Edition', ['entryType' => 'book']);
        $this->assertCount(1, $searchResultsFiltered);

        // Recherche avec filtre non correspondant
        $searchResultsNoMatch = $this->manager->searchReferences($this->user, 'Edition', ['entryType' => 'article']);
        $this->assertCount(0, $searchResultsNoMatch);

        // 5. Association au projet
        $this->assertCount(0, $this->manager->getReferencesForProject($this->project));
        
        $this->manager->addToProject($ref, $this->project);
        
        $projectRefs = $this->manager->getReferencesForProject($this->project);
        $this->assertCount(1, $projectRefs);
        $this->assertEquals('key1', $projectRefs[0]->getCiteKey());

        // 6. Retrait du projet
        $this->manager->removeFromProject($ref, $this->project);
        $this->assertCount(0, $this->manager->getReferencesForProject($this->project));

        // 7. Suppression de la référence
        $refId = $ref->getId();
        $this->manager->deleteReference($ref);
        $this->assertCount(0, $this->manager->getReferencesForUser($this->user));
        
        $deletedRef = $this->entityManager->getRepository(BibliographicReference::class)->find($refId);
        $this->assertNull($deletedRef);
    }
}
