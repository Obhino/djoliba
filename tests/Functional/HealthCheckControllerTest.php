<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthCheckControllerTest extends WebTestCase
{
    public function testHealthCheckReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health-check');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertEquals('OK', $data['status']);
        $this->assertArrayHasKey('checks', $data);
        $this->assertEquals('OK', $data['checks']['database']);
    }
}
