<?php

namespace App\Tests\Functional;

use App\Entity\ResearchProject;
use App\Entity\SubProject;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ResearchProjectViewControllerTest extends WebTestCase
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

    public function testShowProjectPage(): void
    {
        $this->client->loginUser($this->user);

        // Création d'un projet de recherche test
        $rp = new ResearchProject();
        $rp->setUser($this->user);
        $rp->setTitle('Projet de test dashboard');
        $rp->setDescription('Ma super description');
        $this->entityManager->persist($rp);
        $this->entityManager->flush();

        // 1. Accès à la page HTML de gestion
        $this->client->request('GET', '/research-project/' . $rp->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Projet de test dashboard');

        // 2. Ajout d'un sous-projet
        $this->client->request('POST', "/research-project/{$rp->getId()}/sub-projects/create", [
            'name' => 'Mon premier sous-projet',
            'type' => 'reading'
        ]);

        $this->assertResponseRedirects();
        $redirectUrl = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('/sub-project/', $redirectUrl);
        $subProjectId = (int) str_replace('/sub-project/', '', $redirectUrl);
        
        $this->client->followRedirect();

        // Récupérer le sous-projet
        $subProject = $this->entityManager->getRepository(SubProject::class)->find($subProjectId);
        $this->assertNotNull($subProject);

        // 3. Modifier le sous-projet
        $this->client->request('POST', "/sub-project/{$subProject->getId()}/edit", [
            'name' => 'Mon sous-projet renomme'
        ]);
        $this->assertResponseRedirects("/research-project/{$rp->getId()}");
        $this->client->followRedirect();
        $this->assertSelectorTextContains('h3', 'Mon sous-projet renomme');

        // 4. Archiver le sous-projet
        $this->client->request('POST', "/sub-project/{$subProject->getId()}/toggle-status");
        $this->assertResponseRedirects("/research-project/{$rp->getId()}");
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.subproject-status', 'Archivé');

        // 5. Supprimer le sous-projet
        $this->client->request('POST', "/sub-project/{$subProject->getId()}/delete");
        $this->assertResponseRedirects("/research-project/{$rp->getId()}");
        $this->client->followRedirect();
        $this->assertSelectorTextNotContains('h3', 'Mon sous-projet renomme');
    }

    public function testExportZipEndpoint(): void
    {
        $this->client->loginUser($this->user);

        $rp = new ResearchProject();
        $rp->setUser($this->user);
        $rp->setTitle('Projet Export Test');
        $this->entityManager->persist($rp);
        $this->entityManager->flush();

        $this->client->request('GET', "/research-project/{$rp->getId()}/export/zip");
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/zip');
    }
}
