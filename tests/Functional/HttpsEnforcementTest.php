<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HttpsEnforcementTest extends WebTestCase
{
    public function testHttpsIsEnforcedWhenConfigured(): void
    {
        // On simule l'activation de HTTPS forcé
        $_ENV['IS_HTTPS_ENFORCED'] = 'https';

        $client = static::createClient();
        
        // On fait une requête HTTP non sécurisée
        $client->request('GET', 'http://localhost/login');
        
        // On s'attend à être redirigé vers l'équivalent HTTPS
        $this->assertResponseRedirects('https://localhost/login');
        
        // Rétablir la valeur
        unset($_ENV['IS_HTTPS_ENFORCED']);
    }

    public function testHttpsIsNotEnforcedByDefault(): void
    {
        // On s'assure que IS_HTTPS_ENFORCED est vide ou non défini (cas par défaut en dev/test)
        $_ENV['IS_HTTPS_ENFORCED'] = '';

        $client = static::createClient();
        
        // On fait une requête HTTP non sécurisée sur login (qui ne nécessite pas d'authentification)
        $client->request('GET', 'http://localhost/login');
        
        // On ne doit pas être redirigé vers https
        $response = $client->getResponse();
        $this->assertNotEquals(301, $response->getStatusCode());
        $this->assertNotEquals(302, $response->getStatusCode());
        $this->assertResponseIsSuccessful();
    }
}
