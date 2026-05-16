<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Cache\CacheItemPoolInterface;

class HealthCheckController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CacheItemPoolInterface $cache
    ) {
    }

    #[Route('/health-check', name: 'app_health_check', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $status = [
            'status' => 'OK',
            'timestamp' => date('c'),
            'checks' => [
                'database' => 'OK',
                'cache' => 'OK',
            ]
        ];

        // Vérification de la base de données
        try {
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Exception $e) {
            $status['status'] = 'ERROR';
            $status['checks']['database'] = 'FAIL: ' . $e->getMessage();
        }

        // Vérification du Cache (Redis)
        try {
            $item = $this->cache->getItem('health_check_ping');
            $item->set(true);
            $this->cache->save($item);
        } catch (\Exception $e) {
            // Le cache n'est pas critique au point de mettre le status global à ERROR,
            // mais on le signale.
            $status['checks']['cache'] = 'FAIL: ' . $e->getMessage();
        }

        $statusCode = ($status['status'] === 'OK') ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return $this->json($status, $statusCode);
    }
}
