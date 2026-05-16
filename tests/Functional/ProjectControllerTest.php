<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProjectControllerTest extends WebTestCase
{
    private $client;
    private $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        // Nettoyage et création d'un utilisateur
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'project-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('project-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('ProjetTester');
            $em->persist($user);
            $em->flush();
        }
        $this->user = $user;
    }

    public function testHubAccessIsRestricted(): void
    {
        $this->client->request('GET', '/hub');
        $this->assertResponseRedirects('/login');
    }

    public function testProjectCreationViaApi(): void
    {
        $this->client->loginUser($this->user);
        
        $url = static::getContainer()->get('router')->generate('api_projects_create');
        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'name' => 'Nouveau Projet Test',
            'type' => 'thesis'
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Nouveau Projet Test', $data['data']['name']);
    }
}
