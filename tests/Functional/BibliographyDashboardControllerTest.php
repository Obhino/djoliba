<?php

namespace App\Tests\Functional;

use App\Entity\BibliographicReference;
use App\Entity\ResearchProject;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BibliographyDashboardControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $user;
    private $manager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $this->manager = $this->client->getContainer()->get(\App\Service\Bibliography\BibliographicReferenceManager::class);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test_bib@example.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('test_bib@example.com');
            $user->setPassword('password123');
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
        $this->user = $user;
    }

    protected function tearDown(): void
    {
        // Nettoyer les entités créées
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test_bib@example.com']);
        if ($user) {
            $refs = $this->entityManager->getRepository(BibliographicReference::class)->findBy(['user' => $user]);
            foreach ($refs as $ref) {
                $this->entityManager->remove($ref);
            }
            $projects = $this->entityManager->getRepository(ResearchProject::class)->findBy(['user' => $user]);
            foreach ($projects as $project) {
                if ($project->getProjectBibliography()) {
                    $this->entityManager->remove($project->getProjectBibliography());
                }
                $this->entityManager->remove($project);
            }
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();
        parent::tearDown();
    }

    public function testIndexPage(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/bibliography');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Ma Bibliothèque');
    }

    public function testAddManualReference(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/bibliography/add', [
            'citeKey' => 'Einstein1905',
            'entryType' => 'article',
            'title' => 'On the Electrodynamics of Moving Bodies',
            'authors' => 'Einstein, Albert',
            'year' => '1905',
            'journal' => 'Annalen der Physik',
            'doi' => '10.1002/andp.19053221004',
        ]);

        $this->assertResponseRedirects();
        
        $ref = $this->entityManager->getRepository(BibliographicReference::class)->findOneBy([
            'user' => $this->user,
            'citeKey' => 'Einstein1905'
        ]);

        $this->assertNotNull($ref);
        $this->assertEquals('On the Electrodynamics of Moving Bodies', $ref->getTitle());
    }

    public function testResolveDoiEndpoint(): void
    {
        $this->client->loginUser($this->user);
        
        // On requête un DOI non résoluble pour valider le retour JSON 404 propre
        $this->client->request('GET', '/bibliography/resolve-doi?doi=invalid-doi-test');
        $this->assertResponseStatusCodeSame(404);
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($response['success']);
    }

    public function testImportBibtex(): void
    {
        $this->client->loginUser($this->user);
        $bibContent = <<<BIBTEX
@article{Newton1687,
    title={Philosophiae Naturalis Principia Mathematica},
    author={Newton, Isaac},
    year={1687}
}
BIBTEX;

        $this->client->request('POST', '/bibliography/import', [
            'bib_content' => $bibContent
        ]);

        $this->assertResponseRedirects();

        $ref = $this->entityManager->getRepository(BibliographicReference::class)->findOneBy([
            'user' => $this->user,
            'citeKey' => 'Newton1687'
        ]);

        $this->assertNotNull($ref);
        $this->assertEquals('Philosophiae Naturalis Principia Mathematica', $ref->getTitle());
    }

    public function testLinkAndUnlinkFromProject(): void
    {
        $this->client->loginUser($this->user);

        // 1. Créer une référence
        $ref = new BibliographicReference();
        $ref->setUser($this->user);
        $ref->setCiteKey('Hawking1974');
        $ref->setEntryType('article');
        $ref->setTitle('Black hole explosions?');
        $ref->setAuthors('Hawking, Stephen');
        $ref->setYear('1974');
        $ref->setSource('manual');
        $this->entityManager->persist($ref);

        // 2. Créer un projet
        $project = new ResearchProject();
        $project->setUser($this->user);
        $project->setTitle('Projet Hawking de test');
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        // 3. Associer au projet
        $this->client->request('POST', sprintf('/bibliography/project/%d/add', $project->getId()), [
            'reference_ids' => [$ref->getId()]
        ]);

        $this->assertResponseRedirects();

        // Recharger le projet et s'assurer que Hawking1974 est lié
        $this->entityManager->clear();
        $projectReloaded = $this->entityManager->getRepository(ResearchProject::class)->find($project->getId());
        $this->assertNotNull($projectReloaded->getProjectBibliography());
        $this->assertCount(1, $projectReloaded->getProjectBibliography()->getReferences());

        // 4. Dissocier du projet
        $this->client->request('POST', sprintf('/bibliography/project/%d/remove/%d', $project->getId(), $ref->getId()));
        $this->assertResponseRedirects();

        // S'assurer qu'il n'y a plus de référence liée
        $this->entityManager->clear();
        $projectReloaded = $this->entityManager->getRepository(ResearchProject::class)->find($project->getId());
        $this->assertCount(0, $projectReloaded->getProjectBibliography()->getReferences());
    }

    public function testListUserReferences(): void
    {
        $this->client->loginUser($this->user);

        // Créer une référence spécifique
        $ref = new BibliographicReference();
        $ref->setUser($this->user);
        $ref->setCiteKey('Curie1911');
        $ref->setEntryType('book');
        $ref->setTitle('Traité de radioactivité');
        $ref->setAuthors('Curie, Marie');
        $ref->setYear('1911');
        $ref->setSource('manual');
        $this->entityManager->persist($ref);
        $this->entityManager->flush();

        // Requêter la liste des références de l'utilisateur
        $this->client->request('GET', '/api/user/bibliographic-references');
        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        
        $entries = $response['data']['entries'];
        $found = false;
        foreach ($entries as $entry) {
            if ($entry['citeKey'] === 'Curie1911') {
                $found = true;
                $this->assertEquals('Traité de radioactivité', $entry['title']);
                $this->assertEquals('Curie, Marie', $entry['authors']);
                break;
            }
        }
        $this->assertTrue($found, 'La référence Curie1911 devrait être présente dans la liste.');

        // Tester la recherche
        $this->client->request('GET', '/api/user/bibliographic-references?q=Curie');
        $responseSearch = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $responseSearch['data']['entries']);
        $this->assertEquals('Curie1911', $responseSearch['data']['entries'][0]['citeKey']);
    }

    public function testAddProjectReferencesApi(): void
    {
        $this->client->loginUser($this->user);

        // 1. Créer une référence
        $ref = new BibliographicReference();
        $ref->setUser($this->user);
        $ref->setCiteKey('Planck1900');
        $ref->setEntryType('article');
        $ref->setTitle('Ueber eine Verbesserung der Wien’schen Spectralgleichung');
        $ref->setAuthors('Planck, Max');
        $ref->setYear('1900');
        $ref->setSource('manual');
        $this->entityManager->persist($ref);

        // 2. Créer un projet
        $project = new ResearchProject();
        $project->setUser($this->user);
        $project->setTitle('Projet Planck Quantum');
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        // 3. Associer via l'API JSON
        $this->client->request(
            'POST',
            sprintf('/api/research-project/%d/bibliography/add', $project->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['reference_ids' => [$ref->getId()]])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // S'assurer de la liaison en base
        $this->entityManager->clear();
        $projectReloaded = $this->entityManager->getRepository(ResearchProject::class)->find($project->getId());
        $this->assertNotNull($projectReloaded->getProjectBibliography());
        $this->assertCount(1, $projectReloaded->getProjectBibliography()->getReferences());
        $this->assertEquals('Planck1900', $projectReloaded->getProjectBibliography()->getReferences()->first()->getCiteKey());
    }

    public function testRenderBibliographyHtml(): void
    {
        $this->client->loginUser($this->user);

        // 1. Créer deux références
        $ref1 = new BibliographicReference();
        $ref1->setUser($this->user);
        $ref1->setCiteKey('Watson1953');
        $ref1->setEntryType('article');
        $ref1->setTitle('Molecular Structure of Nucleic Acids');
        $ref1->setAuthors('Watson, James and Crick, Francis');
        $ref1->setYear('1953');
        $ref1->setSource('manual');
        $this->entityManager->persist($ref1);

        $ref2 = new BibliographicReference();
        $ref2->setUser($this->user);
        $ref2->setCiteKey('Franklin1953');
        $ref2->setEntryType('article');
        $ref2->setTitle('Molecular Configuration in Sodium Thymonucleate');
        $ref2->setAuthors('Franklin, Rosalind');
        $ref2->setYear('1953');
        $ref2->setSource('manual');
        $this->entityManager->persist($ref2);

        $this->entityManager->flush();

        // 2. Requêter le rendu HTML
        $this->client->request('GET', '/api/user/bibliographic-references/render?keys=Watson1953,Franklin1953');
        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        
        $html = $response['html'];
        $this->assertStringContainsString('Watson, James', $html);
        $this->assertStringContainsString('Franklin, Rosalind', $html);
        $this->assertStringContainsString('Molecular Structure of Nucleic Acids', $html);
    }

    public function testExportGlobal(): void
    {
        $this->client->loginUser($this->user);

        // Créer une référence
        $ref = new BibliographicReference();
        $ref->setUser($this->user);
        $ref->setCiteKey('Dirac1928');
        $ref->setEntryType('article');
        $ref->setTitle('The Quantum Theory of the Electron');
        $ref->setAuthors('Dirac, Paul');
        $ref->setYear('1928');
        $ref->setSource('manual');
        $this->entityManager->persist($ref);
        $this->entityManager->flush();

        // Requêter le téléchargement global
        $this->client->request('GET', '/bibliography/export');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/x-bibtex');
        $this->assertResponseHeaderSame('Content-Disposition', 'attachment; filename="ma_bibliotheque.bib"');
        
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('@article{Dirac1928,', $content);
        $this->assertStringContainsString('title = {The Quantum Theory of the Electron}', $content);
    }

    public function testExportProject(): void
    {
        $this->client->loginUser($this->user);

        // 1. Créer référence
        $ref = new BibliographicReference();
        $ref->setUser($this->user);
        $ref->setCiteKey('Schrodinger1926');
        $ref->setEntryType('article');
        $ref->setTitle('Quantisierung als Eigenwertproblem');
        $ref->setAuthors('Schrodinger, Erwin');
        $ref->setYear('1926');
        $ref->setSource('manual');
        $this->entityManager->persist($ref);

        // 2. Créer projet
        $project = new ResearchProject();
        $project->setUser($this->user);
        $project->setTitle('Projet Wave Mechanics');
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        // 3. Associer
        $this->manager->addToProject($ref, $project);
        $this->entityManager->flush();

        // 4. Requêter le téléchargement du projet
        $this->client->request('GET', sprintf('/bibliography/project/%d/export', $project->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/x-bibtex');
        $this->assertResponseHeaderSame('Content-Disposition', sprintf('attachment; filename="bibliographie_projet_%d.bib"', $project->getId()));
        
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('@article{Schrodinger1926,', $content);
    }

    public function testImportApiJson(): void
    {
        $this->client->loginUser($this->user);

        $bibContent = <<<BIB
@article{Einstein1905,
    author = {Einstein, Albert},
    title = {Ist die Trägheit eines Körpers von seinem Energieinhalt abhängig?},
    journal = {Annalen der Physik},
    volume = {18},
    pages = {639--641},
    year = {1905}
}
BIB;

        $this->client->request(
            'POST',
            '/api/bibliography/import',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['bib_content' => $bibContent])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(1, $response['data']['imported']);

        // Vérifier que la référence a bien été créée dans la base
        $ref = $this->entityManager->getRepository(BibliographicReference::class)->findOneBy([
            'user' => $this->user,
            'citeKey' => 'Einstein1905'
        ]);
        $this->assertNotNull($ref);
        $this->assertEquals('Einstein1905', $ref->getCiteKey());
        $this->assertEquals('article', $ref->getEntryType());
    }

    public function testDeleteApiJson(): void
    {
        $this->client->loginUser($this->user);

        // 1. Créer une référence
        $ref = new BibliographicReference();
        $ref->setUser($this->user);
        $ref->setCiteKey('Bohr1913');
        $ref->setEntryType('article');
        $ref->setTitle('On the Constitution of Atoms and Molecules');
        $ref->setAuthors('Bohr, Niels');
        $ref->setYear('1913');
        $ref->setSource('manual');
        $this->entityManager->persist($ref);
        $this->entityManager->flush();

        $refId = $ref->getId();

        // 2. Supprimer via l'API JSON
        $this->client->request('DELETE', sprintf('/api/bibliography/%d', $refId));
        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // 3. S'assurer de la suppression en base
        $this->entityManager->clear();
        $deletedRef = $this->entityManager->getRepository(BibliographicReference::class)->find($refId);
        $this->assertNull($deletedRef);
    }
}


