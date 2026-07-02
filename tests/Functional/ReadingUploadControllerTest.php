<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\ResearchProject;
use App\Entity\SubProject;
use App\Entity\Document;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ReadingUploadControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $user;
    private $project;
    private $researchProject;
    private $dummyPdfPath;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();

        // 1. Créer un utilisateur de test
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'reading-test@example.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('reading-test@example.com');
            $user->setPassword('password123');
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
        $this->user = $user;

        // 2. Créer un projet de recherche de test
        $rp = new ResearchProject();
        $rp->setUser($user);
        $rp->setTitle('Projet de Recherche Climat');
        $this->entityManager->persist($rp);

        // 3. Créer un projet compagnon initial de type reading
        $project = new Project();
        $project->setUser($user);
        $project->setName('Lecture Initiale Climat');
        $project->setType('reading');
        $project->setResearchProject($rp);
        $this->entityManager->persist($project);

        $this->entityManager->flush();

        $this->project = $project;
        $this->researchProject = $rp;

        // 4. Créer un fichier PDF temporaire pour le test d'upload
        $this->dummyPdfPath = sys_get_temp_dir() . '/test_reading_upload_dummy.pdf';
        file_put_contents($this->dummyPdfPath, '%PDF-1.4 ... dummy content ...');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dummyPdfPath)) {
            unlink($this->dummyPdfPath);
        }
        parent::tearDown();
    }

    public function testUploadCreatesNewSubProjectAndCompanionProject(): void
    {
        $this->client->loginUser($this->user);

        // Préparer le fichier uploadé simulé
        // NOTE: Symfony WebTestCase supporte l'envoi de fichiers simulés
        $uploadedFile = new UploadedFile(
            $this->dummyPdfPath,
            'test_climate_report.pdf',
            'application/pdf',
            null,
            true // mode test (ne lance pas de vérification de déplacement d'upload standard PHP)
        );

        // Effectuer la requête POST vers /api/reading/upload
        $this->client->request(
            'POST',
            '/api/reading/upload',
            [
                'project_id' => $this->project->getId(),
            ],
            [
                'file' => $uploadedFile,
            ]
        );

        $this->assertResponseStatusCodeSame(201);
        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('redirect_url', $data['data']);
        $this->assertArrayHasKey('document_id', $data['data']);

        // Vérifier que le document est créé
        $docRepo = $this->entityManager->getRepository(Document::class);
        $document = $docRepo->find($data['data']['document_id']);
        $this->assertNotNull($document);

        // Récupérer le sous-projet lié
        $newSubProject = $document->getSubProject();
        $this->assertNotNull($newSubProject);
        $this->assertEquals($this->user->getId(), $newSubProject->getUser()->getId());
        $this->assertEquals('reading', $newSubProject->getType());
        $this->assertEquals('test_climate_report', $newSubProject->getName());
        $this->assertEquals($this->researchProject->getId(), $newSubProject->getResearchProject()->getId());

        // Vérifier le projet compagnon lié au sous-projet
        $newProject = $newSubProject->getProjects()->first();
        $this->assertNotNull($newProject);
        $this->assertEquals('reading', $newProject->getType());
        $this->assertEquals($newProject->getId(), $document->getProject()->getId());

        // Nettoyer les fichiers physiques générés par l'upload de test si existants
        if ($document && file_exists($document->getStoredPath())) {
            unlink($document->getStoredPath());
        }
    }

    public function testUploadDetectsDuplicateFileName(): void
    {
        $this->client->loginUser($this->user);

        // 1. Uploader un premier fichier
        $uploadedFile1 = new UploadedFile(
            $this->dummyPdfPath,
            'test_duplicate_report.pdf',
            'application/pdf',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/api/reading/upload',
            ['project_id' => $this->project->getId()],
            ['file' => $uploadedFile1]
        );

        $this->assertResponseStatusCodeSame(201);
        $data1 = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data1['success']);
        
        // Nettoyer le fichier créé pour ne pas polluer l'espace disque
        $docRepo = $this->entityManager->getRepository(Document::class);
        $document1 = $docRepo->find($data1['data']['document_id']);
        if ($document1 && file_exists($document1->getStoredPath())) {
            unlink($document1->getStoredPath());
        }

        // Recréer le fichier temporaire car il a été déplacé lors de l'upload précédent
        file_put_contents($this->dummyPdfPath, '%PDF-1.4 ... dummy content ...');

        // 2. Tenter d'uploader à nouveau le même fichier (même nom)
        $uploadedFile2 = new UploadedFile(
            $this->dummyPdfPath,
            'test_duplicate_report.pdf',
            'application/pdf',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/api/reading/upload',
            ['project_id' => $this->project->getId()],
            ['file' => $uploadedFile2]
        );

        // Doit renvoyer un statut 409 Conflict
        $this->assertResponseStatusCodeSame(409);
        $data2 = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data2['success']);
        $this->assertStringContainsString('existe déjà', $data2['error']['message']);
        $this->assertNotNull($data2['error']['redirect_url']);
        $this->assertStringContainsString('/project/', $data2['error']['redirect_url']);
        $this->assertStringContainsString('/reading', $data2['error']['redirect_url']);
    }
}
