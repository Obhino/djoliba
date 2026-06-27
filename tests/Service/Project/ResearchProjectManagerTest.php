<?php

namespace App\Tests\Service\Project;

use App\Entity\ResearchProject;
use App\Entity\User;
use App\Repository\ResearchProjectRepository;
use App\Service\Project\ResearchProjectManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ResearchProjectManagerTest extends TestCase
{
    private $entityManager;
    private $rpRepository;
    private $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->rpRepository = $this->createMock(ResearchProjectRepository::class);
        $this->manager = new ResearchProjectManager($this->entityManager, $this->rpRepository);
    }

    public function testCreateResearchProject(): void
    {
        $user = new User();
        $user->setEmail('researcher@example.com');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $rp = $this->manager->createResearchProject($user, 'Projet de Recherche Global', 'Description détaillée');

        $this->assertInstanceOf(ResearchProject::class, $rp);
        $this->assertEquals('Projet de Recherche Global', $rp->getName());
        $this->assertEquals('Description détaillée', $rp->getDescription());
        $this->assertEquals('active', $rp->getStatus());
        $this->assertEquals($user, $rp->getUser());
    }

    public function testUpdateResearchProject(): void
    {
        $rp = new ResearchProject();
        $rp->setName('Ancien Nom');
        $rp->setDescription('Ancienne Description');

        $this->entityManager->expects($this->once())->method('flush');

        $updated = $this->manager->updateResearchProject($rp, [
            'name' => 'Nouveau Nom',
            'description' => 'Nouvelle Description'
        ]);

        $this->assertEquals('Nouveau Nom', $updated->getName());
        $this->assertEquals('Nouvelle Description', $updated->getDescription());
    }

    public function testArchiveResearchProject(): void
    {
        $rp = new ResearchProject();
        $rp->setStatus('active');

        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->archiveResearchProject($rp);

        $this->assertEquals('archived', $rp->getStatus());
    }

    public function testDeleteResearchProject(): void
    {
        $rp = new ResearchProject();
        $rp->setStatus('active');

        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->deleteResearchProject($rp);

        $this->assertEquals('deleted', $rp->getStatus());
    }

    public function testGetUserResearchProjects(): void
    {
        $user = new User();
        $user->setEmail('researcher@example.com');

        $this->rpRepository->expects($this->once())
            ->method('findBy')
            ->with(
                [
                    'user' => $user,
                    'status' => ['active', 'archived']
                ],
                ['createdAt' => 'DESC']
            )
            ->willReturn([]);

        $projects = $this->manager->getUserResearchProjects($user);
        $this->assertIsArray($projects);
    }
}
