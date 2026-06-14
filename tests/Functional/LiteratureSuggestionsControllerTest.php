<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LiteratureSuggestionsControllerTest extends WebTestCase
{
    public function testSuggestionsRouteRequiresAuthentication(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/api/literature/suggestions', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'query' => 'Machine Learning'
        ]));

        // Sans être connecté et hors mode test, on s'attend à 401 Unauthorized
        $this->assertEquals(401, $client->getResponse()->getStatusCode());
    }

    public function testSuggestionsRouteReturnsValidResponse(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Mocking SuggestionService to avoid external API calls (DeepSeek and OpenSERP) in functional tests
        $suggestionServiceMock = $this->createMock(\App\Service\SuggestionService::class);
        $suggestionServiceMock->method('suggest')
            ->willReturn([
                [
                    'title' => 'Quantum Cryptography for Beginners',
                    'authors' => 'Alice, Bob',
                    'year' => 2024,
                    'abstract' => 'An introduction to quantum key distribution.',
                    'doi' => '10.1000/xyz123',
                    'verified' => true,
                    'url' => 'https://example.org/qc',
                    'journal' => 'Journal of Cryptology'
                ]
            ]);
        $container->set(\App\Service\SuggestionService::class, $suggestionServiceMock);

        $em = $container->get('doctrine')->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'suggestion-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('suggestion-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('Suggester');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        // Faire la requête de suggestions
        $client->request('POST', '/api/literature/suggestions', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'query' => 'Quantum Cryptography',
            'limit' => 3
        ]));

        $statusCode = $client->getResponse()->getStatusCode();
        
        // La route doit retourner un succès direct 200
        $this->assertEquals(200, $statusCode);
        
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals('Quantum Cryptography for Beginners', $data['data'][0]['title']);
    }

    public function testDeepSearchRouteRequiresAuthentication(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/api/literature/deep-search', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'query' => 'Machine Learning'
        ]));

        $this->assertEquals(401, $client->getResponse()->getStatusCode());
    }

    public function testDeepSearchRouteReturnsValidResponse(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $suggestionServiceMock = $this->createMock(\App\Service\SuggestionService::class);
        $suggestionServiceMock->method('suggest')
            ->willReturn([
                [
                    'title' => 'Quantum Cryptography for Beginners',
                    'authors' => 'Alice, Bob',
                    'year' => 2024,
                    'abstract' => 'An introduction to quantum key distribution.',
                    'doi' => '10.1000/xyz123',
                    'verified' => true,
                    'url' => 'https://example.org/qc',
                    'journal' => 'Journal of Cryptology'
                ]
            ]);
        $container->set(\App\Service\SuggestionService::class, $suggestionServiceMock);

        $em = $container->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'suggestion-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('suggestion-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('Suggester');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        $client->request('POST', '/api/literature/deep-search', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'query' => 'Quantum Cryptography',
            'limit' => 3
        ]));

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertEquals(200, $statusCode);
        
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals('Quantum Cryptography for Beginners', $data['data'][0]['title']);
    }
}
