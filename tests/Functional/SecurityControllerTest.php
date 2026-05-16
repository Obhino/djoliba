<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginDisplay(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Bon retour');
    }

    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        
        // On récupère l'EntityManager pour créer un utilisateur de test
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'test-functional@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('test-functional@djoliba.com');
            $user->setPassword(
                $container->get('security.user_password_hasher')->hashPassword($user, 'password123')
            );
            $user->setFirstName('Tester');
            $em->persist($user);
            $em->flush();
        }

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'test-functional@djoliba.com',
            '_password' => 'password123',
        ]);
        
        $client->submit($form);

        $this->assertResponseRedirects('/hub');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Tester');
    }
}
