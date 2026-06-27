<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminAccessTest extends WebTestCase
{
    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');
        
        $this->assertResponseRedirects('/login');
    }

    public function testUserWithoutAdminRoleGetsForbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'user-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('user-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('User');
            $user->setRoles(['ROLE_USER']);
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/admin');
        
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminAccessWithout2fa(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin-test@djoliba.com']);
        if (!$admin) {
            $admin = new User();
            $admin->setEmail('admin-test@djoliba.com');
            $admin->setPassword('password');
            $admin->setFirstName('Admin');
            $admin->setRoles(['ROLE_ADMIN']);
            $admin->setTotpSecret(null); // No 2FA
            $em->persist($admin);
            $em->flush();
        } else {
            $admin->setRoles(['ROLE_ADMIN']);
            $admin->setTotpSecret(null);
            $em->flush();
        }

        $client->loginUser($admin);
        $client->request('GET', '/admin');
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Tableau de Bord - Statistiques Globales');
    }

    public function testAdminAccessWith2faRedirectsTo2faPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin-2fa-test@djoliba.com']);
        if (!$admin) {
            $admin = new User();
            $admin->setEmail('admin-2fa-test@djoliba.com');
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $admin->setPassword($hasher->hashPassword($admin, 'password'));
            $admin->setFirstName('Admin2FA');
            $admin->setRoles(['ROLE_ADMIN']);
            $admin->setTotpSecret('MYTOTPSECRETKEY'); // Enabled 2FA
            $admin->setIsVerified(true);
            $em->persist($admin);
            $em->flush();
        } else {
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $admin->setPassword($hasher->hashPassword($admin, 'password'));
            $admin->setRoles(['ROLE_ADMIN']);
            $admin->setTotpSecret('MYTOTPSECRETKEY');
            $admin->setIsVerified(true);
            $em->flush();
        }

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'admin-2fa-test@djoliba.com',
            '_password' => 'password',
        ]);
        $client->submit($form);
        
        // Follow the redirect (which goes to /hub)
        $client->followRedirect();
        
        // Check if the user is then redirected to /2fa
        $this->assertResponseRedirects('/2fa');
    }
}
