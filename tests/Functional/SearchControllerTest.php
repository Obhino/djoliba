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
}
