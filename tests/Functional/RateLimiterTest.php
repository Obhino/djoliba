<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class RateLimiterTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();

        // Réinitialiser le pool de cache du rate limiter pour garantir un test propre
        static::getContainer()->get('cache.global_clearer')->clearPool('cache.rate_limiter');

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test-rate-limiter@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('test-rate-limiter@djoliba.com');
            $user->setPassword(
                static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password123')
            );
            $user->setIsVerified(true);
            $user->setIsActive(true);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
        $this->user = $user;
    }

    public function testIaRateLimiter(): void
    {
        $this->client->loginUser($this->user);
        $headers = ['HTTP_X-Enable-Rate-Limit' => '1'];

        // Envoyer 20 requêtes POST sur un endpoint IA (ex: /api/writing/check)
        // Les 20 premières requêtes doivent renvoyer 400 Bad Request (car sans paramètres requis)
        // et non pas 429
        for ($i = 0; $i < 20; $i++) {
            $this->client->request('POST', '/api/writing/check', [], [], $headers);
            $responseCode = $this->client->getResponse()->getStatusCode();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $responseCode);
        }

        // La 21ème requête doit être bloquée avec un code 429 Too Many Requests
        $this->client->request('POST', '/api/writing/check', [], [], $headers);
        $response = $this->client->getResponse();
        
        $this->assertEquals(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertEquals(429, $data['error']['code']);
        $this->assertEquals('Trop de requêtes. Veuillez réessayer plus tard.', $data['error']['message']);
    }
}
