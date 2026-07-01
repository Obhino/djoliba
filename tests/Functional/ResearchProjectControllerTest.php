<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\ResearchProject;
use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ResearchProjectControllerTest extends WebTestCase
{
    private $client;
    private $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        // Nettoyage et création d'un utilisateur de test
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'rp-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('rp-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('RpTester');
            $em->persist($user);
            $em->flush();
        }
        $this->user = $user;
    }

    public function testResearchProjectCycleAndSelection(): void
    {
        $this->client->loginUser($this->user);
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        // 1. Création d'un ResearchProject
        $createUrl = $container->get('router')->generate('api_research_projects_create');
        $this->client->request('POST', $createUrl, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'name' => 'Projet de Recherche Web',
            'description' => 'Description du projet de recherche web',
            'select' => true // Devrait l'activer en session
        ]));

        $this->assertResponseIsSuccessful();
        $res = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($res['success']);
        $rpId = $res['data']['id'];
        $this->assertEquals('Projet de Recherche Web', $res['data']['name']);

        // Vérifier que la synthèse initiale et le plan de réalisation ont été générés
        $rp = $em->getRepository(ResearchProject::class)->find($rpId);
        $this->assertNotNull($rp->getSynthesis());
        $this->assertStringContainsString('Plan de réalisation', $rp->getSynthesis());

        // 2. Vérification que la session contient l'ID actif
        $session = $this->client->getRequest()->getSession();
        $this->assertEquals($rpId, $session->get('active_research_project_id'));

        // 3. Création d'un Project régulier (devrait être lié au ResearchProject actif automatiquement)
        $projectUrl = $container->get('router')->generate('api_projects_create');
        $this->client->request('POST', $projectUrl, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'name' => 'Sous-projet d\'analyse',
            'type' => 'literature_review'
        ]));

        $this->assertResponseIsSuccessful();
        $projRes = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($projRes['success']);
        $projectId = $projRes['data']['id'];

        // Rafraîchir depuis la base pour vérifier la liaison
        $em->clear();
        $project = $em->getRepository(Project::class)->find($projectId);
        $this->assertNotNull($project);
        $this->assertNotNull($project->getResearchProject());
        $this->assertEquals($rpId, $project->getResearchProject()->getId());

        // 4. Désélection du projet actif
        $deselectUrl = $container->get('router')->generate('api_research_projects_deselect');
        $this->client->request('POST', $deselectUrl);
        $this->assertResponseIsSuccessful();
        $deselectRes = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($deselectRes['success']);
        $this->assertNull($deselectRes['active_research_project_id']);

        // 5. Création d'un Project indépendant (sans projet de recherche actif)
        $this->client->request('POST', $projectUrl, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'name' => 'Projet indépendant',
            'type' => 'reading'
        ]));

        $this->assertResponseIsSuccessful();
        $indepRes = json_decode($this->client->getResponse()->getContent(), true);
        $indepProj = $em->getRepository(Project::class)->find($indepRes['data']['id']);
        $this->assertNotNull($indepProj);
        $this->assertNull($indepProj->getResearchProject());
    }
}
