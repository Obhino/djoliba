<?php

namespace App\Tests\Service\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Service\Project\ProjectManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ProjectManagerTest extends TestCase
{
    private $entityManager;
    private $projectRepository;
    private $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->projectRepository = $this->createMock(ProjectRepository::class);
        $this->manager = new ProjectManager($this->entityManager, $this->projectRepository);
    }

    public function testCreateProject(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->exactly(2))->method('flush');

        $project = $this->manager->createProject($user, 'thesis', 'Ma Thèse');

        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals('thesis', $project->getType());
        $this->assertEquals('Ma Thèse', $project->getName());
        $this->assertEquals($user, $project->getUser());
    }

    public function testUpdateProject(): void
    {
        $project = new Project();
        $project->setName('Ancien Nom');

        $this->entityManager->expects($this->once())->method('flush');

        $updatedProject = $this->manager->updateProject($project, ['name' => 'Nouveau Nom']);

        $this->assertEquals('Nouveau Nom', $updatedProject->getName());
    }

    public function testArchiveProject(): void
    {
        $project = new Project();
        $project->setStatus('active');

        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->archiveProject($project);

        $this->assertEquals('archived', $project->getStatus());
    }

    public function testGetUserProjectsWithFiltering(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $this->projectRepository->expects($this->once())
            ->method('findBy')
            ->with(
                ['user' => $user, 'type' => 'reading'],
                ['updatedAt' => 'DESC', 'createdAt' => 'DESC']
            )
            ->willReturn([]);

        $projects = $this->manager->getUserProjects($user, 'reading');
        $this->assertIsArray($projects);
    }

    public function testCreateProjectWithLongNameTruncation(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->exactly(2))->method('flush');

        // Name is 60 characters long
        $longName = str_repeat('A', 60);
        $project = $this->manager->createProject($user, 'thesis', $longName);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals(50, strlen($project->getName()));
        $this->assertEquals(str_repeat('A', 47) . '...', $project->getName());
    }
}
