<?php
require 'vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(dirname(__FILE__).'/.env');

$kernel = new App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

try {
    echo "=== TEST DE LA SYNTHÈSE ===\n";
    $em = $container->get('doctrine')->getManager();
    $document = $em->getRepository(App\Entity\Document::class)->findOneBy([]);
    if (!$document) {
        throw new \Exception("Aucun document en BDD.");
    }
    echo sprintf("Document ciblé : ID %d - %s\n", $document->getId(), $document->getFilename());
    
    // Rendre temporairement ReadingService accessible si besoin ou obtenir directement
    $readingService = $container->get(App\Service\ReadingService::class);
    
    $start = microtime(true);
    $result = $readingService->synthesize($document, $document->getProject());
    $time = microtime(true) - $start;
    
    echo sprintf("Synthèse réussie en %.2f secondes !\n", $time);
    echo "Points clés générés :\n";
    print_r($result['points']);
} catch (\Exception $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    echo "TRACE : " . $e->getTraceAsString() . "\n";
}
