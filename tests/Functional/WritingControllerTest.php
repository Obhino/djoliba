<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WritingControllerTest extends WebTestCase
{
    private function getOrCreateUser($em): User
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'writing-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('writing-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('Writer');
            $em->persist($user);
            $em->flush();
        }
        return $user;
    }

    private function createProject($em, User $user): Project
    {
        $project = new Project();
        $project->setName('Projet Ecriture Test');
        $project->setType('writing');
        $project->setUser($user);
        $em->persist($project);
        $em->flush();
        return $project;
    }

    public function testCheckOriginalityJsonEndpoint(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = $this->getOrCreateUser($em);
        $project = $this->createProject($em, $user);

        $client->loginUser($user);

        // Envoyer du texte court (devrait renvoyer 422 Unprocessable Entity)
        $client->request('POST', '/api/writing/check', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'text' => 'Texte trop court',
            'project_id' => $project->getId()
        ]));

        $this->assertEquals(422, $client->getResponse()->getStatusCode());

        // Envoyer du texte valide (attendu 503 sans clé API configurée)
        $longText = str_repeat("Ceci est un long paragraphe de recherche académique pour tester le système de détection d'originalité. ", 5);
        $client->request('POST', '/api/writing/check', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'text' => $longText,
            'project_id' => $project->getId()
        ]));

        // Le contrôleur renvoie 503 si l'API IA échoue ou si la clé est manquante
        $this->assertContains($client->getResponse()->getStatusCode(), [503, 200]);
    }

    public function testSuggestJournalJsonEndpoint(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = $this->getOrCreateUser($em);
        $project = $this->createProject($em, $user);

        $client->loginUser($user);

        $client->request('POST', '/api/writing/suggest-journal', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'text' => 'Résumé de l\'article sur les énergies renouvelables en Afrique.',
            'project_id' => $project->getId()
        ]));

        $this->assertContains($client->getResponse()->getStatusCode(), [503, 200]);
    }
}
