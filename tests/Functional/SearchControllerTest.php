<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SearchControllerTest extends WebTestCase
{
    public function testHubSearchExecution(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'search-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('search-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('Searcher');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        // Création d'un projet requis pour la recherche
        $project = new \App\Entity\Project();
        $project->setName('Projet Recherche');
        $project->setType('literature_review');
        $project->setUser($user);
        $em->persist($project);
        $em->flush();
        
        $client->request('POST', '/api/literature/review', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'query' => 'La recherche scientifique en Afrique de l\'Ouest',
            'project_id' => $project->getId()
        ]));

        // Sans vraie clé API, on s'attend à 503 (Service Unavailable) géré par le contrôleur
        $this->assertEquals(503, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testContextualizedPrompt(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'search-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('search-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('Searcher');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        // 1. Créer un projet de recherche global et un projet hérité lié
        $rp = new \App\Entity\ResearchProject();
        $rp->setUser($user);
        $rp->setTitle('Projet de test IA');
        $rp->setDescription('Description du projet de test');
        $em->persist($rp);

        $project = new \App\Entity\Project();
        $project->setName('Projet Recherche');
        $project->setType('literature_review');
        $project->setUser($user);
        $project->setResearchProject($rp);
        $em->persist($project);
        
        $em->flush();

        // 2. Mocker DeepSeekService pour capturer les messages
        $capturedMessages = null;
        $deepSeekMock = $this->createMock(\App\Service\IA\DeepSeekService::class);
        $deepSeekMock->method('isApiKeyPlaceholder')->willReturn(false);
        $deepSeekMock->method('streamWithHistory')->willReturnCallback(function($messages, $callback) use (&$capturedMessages) {
            $capturedMessages = $messages;
            $callback("Contenu généré de test");
        });
        $container->set(\App\Service\IA\DeepSeekService::class, $deepSeekMock);

        // 3. Exécuter la requête
        $client->request('POST', '/api/literature/review', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'query' => 'Mon sujet de recherche',
            'project_id' => $project->getId()
        ]));

        $this->assertResponseIsSuccessful();

        // 4. Valider le prompt contextualisé
        $this->assertNotNull($capturedMessages);
        $lastMessage = end($capturedMessages);
        $this->assertEquals('user', $lastMessage['role']);
        $this->assertStringContainsString('dans le cadre du projet de recherche "Projet de test IA" (Description du projet de test)', $lastMessage['content']);
        $this->assertStringContainsString('Mon sujet de recherche', $lastMessage['content']);
    }

    public function testLiteraturePreFillsQueryFromUrl(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'search-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('search-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('Searcher');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        // Créer un projet
        $project = new \App\Entity\Project();
        $project->setName('Projet A');
        $project->setType('literature_review');
        $project->setUser($user);
        $em->persist($project);
        $em->flush();

        // Demander la page de revue avec un paramètre query
        $client->request('GET', "/project/{$project->getId()}/literature?query=" . urlencode("Sujet Spécial"));

        $this->assertResponseIsSuccessful();
        // Vérifier que l'input a bien la valeur "Sujet Spécial"
        $this->assertSelectorExists('input[data-literature-target="input"][value="Sujet Spécial"]');
    }
}
