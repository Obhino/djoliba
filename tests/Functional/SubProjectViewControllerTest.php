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
}
