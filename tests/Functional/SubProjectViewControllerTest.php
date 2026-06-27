<?php

namespace App\Tests\Functional;

use App\Entity\ResearchProject;
use App\Entity\SubProject;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SubProjectViewControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('test@example.com');
            $user->setPassword('password123');
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
        $this->user = $user;
    }

    public function testSubProjectShowRedirects(): void
    {
        $this->client->loginUser($this->user);

        // 1. Création d'un sous-projet
        $sp = new SubProject();
        $sp->setUser($this->user);
        $sp->setName('Lecture Sahel');
        $sp->setType('reading');
        $sp->setCreatedAt(new \DateTime());
        $this->entityManager->persist($sp);
        $this->entityManager->flush();

        // 2. Accès à /sub-project/{id} -> vérifie que cela génère un projet compagnon et redirige
        $this->client->request('GET', '/sub-project/' . $sp->getId());
        
        // La route redirige vers /project/{legacy_id}/reading
        $this->assertResponseRedirects();
        $redirectUrl = $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/reading', $redirectUrl);
    }

    public function testListOrphanSubprojects(): void
    {
        $this->client->loginUser($this->user);

        $sp = new SubProject();
        $sp->setUser($this->user);
        $sp->setName('Synthese Sahel');
        $sp->setType('literature');
        $sp->setCreatedAt(new \DateTime());
        $this->entityManager->persist($sp);
        $this->entityManager->flush();

        $this->client->request('GET', '/sub-projects/type/literature');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Projets de synthèse');
        $this->assertSelectorTextContains('h3', 'Synthese Sahel');
    }

    public function testListProjectSubprojects(): void
    {
        $this->client->loginUser($this->user);

        $rp = new ResearchProject();
        $rp->setUser($this->user);
        $rp->setTitle('Projet Geo');
        $this->entityManager->persist($rp);

        $sp = new SubProject();
        $sp->setUser($this->user);
        $sp->setName('Ecriture Geo');
        $sp->setType('writing');
        $sp->setResearchProject($rp);
        $sp->setCreatedAt(new \DateTime());
        $this->entityManager->persist($sp);
        
        $this->entityManager->flush();

        $this->client->request('GET', "/research-project/{$rp->getId()}/sub-projects/writing");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', "Projets d'écriture");
        $this->assertSelectorTextContains('h3', 'Ecriture Geo');
    }

    public function testCreateOrphanSubproject(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/sub-project/create', [
            'name' => 'Nouveau sous-projet test',
            'type' => 'reading'
        ]);

        // Redirige vers /sub-project/{id} de l'activité créée
        $this->assertResponseRedirects();
        $this->client->followRedirect(); // suit vers la page de lecture finale
        $this->assertResponseRedirects(); // redirige encore vers /project/{id}/reading
    }

    public function testNewSubProjectView(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/sub-project/new?type=writing');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Créer une nouvelle activité');
        $this->assertSelectorTextContains('option[selected]', "Projet d'écriture (Rédaction d'article)");
    }

    public function testCentralSearchAssociation(): void
    {
        $this->client->loginUser($this->user);

        // 1. Créer et activer un projet de recherche
        $rp = new ResearchProject();
        $rp->setUser($this->user);
        $rp->setTitle('Projet de Recherche Actif');
        $this->entityManager->persist($rp);
        $this->entityManager->flush();

        // Rendre actif en session
        $this->client->request('POST', "/api/research-projects/{$rp->getId()}/select");
        $this->assertResponseIsSuccessful();

        // 2. Simuler la recherche synthétique du hub (POST /api/projects)
        $this->client->request('POST', '/api/projects', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'name' => 'Ma recherche IA Sahel',
            'type' => 'literature_review'
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        
        $projectId = $data['id'] ?? $data['data']['id'] ?? null;
        $this->assertNotNull($projectId);

        // 3. Vérifier l'association en BDD
        $project = $this->entityManager->getRepository(\App\Entity\Project::class)->find($projectId);
        $this->assertNotNull($project);
        
        // Le projet hérité doit être rattaché au projet de recherche actif
        $this->assertEquals($rp->getId(), $project->getResearchProject()->getId());

        // Le sous-projet compagnon créé doit aussi être rattaché au projet de recherche actif
        $subProject = $project->getSubProject();
        $this->assertNotNull($subProject);
        $this->assertEquals($rp->getId(), $subProject->getResearchProject()->getId());
        $this->assertEquals('literature', $subProject->getType());
    }

    public function testSubProjectDeletionCascadesToProject(): void
    {
        $this->client->loginUser($this->user);

        // 1. Créer un sous-projet et un projet hérité lié
        $subProject = new SubProject();
        $subProject->setUser($this->user);
        $subProject->setName('Sous-projet à supprimer');
        $subProject->setType('literature');
        $subProject->setStatus('active');
        $this->entityManager->persist($subProject);

        $project = new \App\Entity\Project();
        $project->setUser($this->user);
        $project->setName('Projet compagnon');
        $project->setType('literature_review');
        $project->setStatus('active');
        $subProject->addProject($project);
        $this->entityManager->persist($project);

        $this->entityManager->flush();

        $subProjectId = $subProject->getId();
        $projectId = $project->getId();

        // 2. Supprimer le sous-projet via le manager
        $subProjectManager = static::getContainer()->get(\App\Service\Project\SubProjectManager::class);
        $subProjectManager->deleteSubProject($subProject);

        // Vider l'EntityManager pour s'assurer que les requêtes suivantes interrogent la base
        $this->entityManager->clear();

        // 3. Vérifier que les deux entités sont bien supprimées de la base
        $deletedSubProject = $this->entityManager->getRepository(SubProject::class)->find($subProjectId);
        $this->assertNull($deletedSubProject);

        $deletedProject = $this->entityManager->getRepository(\App\Entity\Project::class)->find($projectId);
        $this->assertNull($deletedProject);
    }

    public function testReadingListHasDragDropZone(): void
    {
        $this->client->loginUser($this->user);

        // 1. Accéder à la liste des sous-projets de type lecture
        $this->client->request('GET', '/sub-projects/type/reading');
        $this->assertResponseIsSuccessful();

        // 2. Vérifier que la zone de drag & drop existe bien dans le DOM
        $this->assertSelectorExists('div[data-controller="reading-list-drag-drop"]');
        $this->assertSelectorExists('input[type="file"][accept="application/pdf"]');

        // 3. Accéder à la liste des sous-projets d'un autre type (ex: literature) et s'assurer qu'elle n'y est pas
        $this->client->request('GET', '/sub-projects/type/literature');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('div[data-controller="reading-list-drag-drop"]');
    }

    public function testPaginationAndJsonApiResponse(): void
    {
        // Utiliser un utilisateur unique pour éviter toute pollution de base de données par d'autres tests
        $uniqueEmail = 'pagination-test@djoliba.com';
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $uniqueEmail]);
        if (!$user) {
            $user = new User();
            $user->setEmail($uniqueEmail);
            $user->setPassword(
                static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password123')
            );
            $user->setIsVerified(true);
            $user->setIsActive(true);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } else {
            // Nettoyer les sous-projets existants pour cet utilisateur de test pour éviter l'accumulation entre les exécutions de tests
            $existing = $this->entityManager->getRepository(SubProject::class)->findBy(['user' => $user]);
            foreach ($existing as $sp) {
                $this->entityManager->remove($sp);
            }
            $this->entityManager->flush();
        }

        $this->client->loginUser($user);

        // 1. Créer 25 sous-projets de type thesis
        for ($i = 1; $i <= 25; $i++) {
            $sp = new SubProject();
            $sp->setUser($user);
            $sp->setName('These Partie ' . $i);
            $sp->setType('thesis');
            $sp->setCreatedAt(new \DateTime());
            $this->entityManager->persist($sp);
        }
        $this->entityManager->flush();

        // 2. Requête HTML avec pagination (limit 10)
        $crawler = $this->client->request('GET', '/sub-projects/type/thesis?page=1&limit=10');
        $this->assertResponseIsSuccessful();

        // On vérifie qu'on a bien nos contrôles de pagination dans le DOM
        $this->assertSelectorExists('nav[aria-label="Pagination"]');

        // 3. Requête JSON (API) avec pagination
        $this->client->request('GET', '/sub-projects/type/thesis?page=1&limit=10', [], [], [
            'HTTP_ACCEPT' => 'application/json'
        ]);
        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(10, $data['data']['items']);
        $this->assertEquals(25, $data['data']['pagination']['total']);
        $this->assertEquals(1, $data['data']['pagination']['page']);
        $this->assertEquals(10, $data['data']['pagination']['limit']);
        $this->assertEquals(3, $data['data']['pagination']['pages']);
    }
}
