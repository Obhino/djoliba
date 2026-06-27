<?php

namespace App\Tests\Service\Project;

use App\Entity\ResearchProject;
use App\Entity\SubProject;
use App\Entity\User;
use App\Repository\SubProjectRepository;
use App\Service\Project\SubProjectManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SubProjectManagerTest extends TestCase
{
    private $entityManager;
    private $subProjectRepository;
    private $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->subProjectRepository = $this->createMock(SubProjectRepository::class);
        $this->manager = new SubProjectManager($this->entityManager, $this->subProjectRepository);
    }

    public function testCreateForUser(): void
    {
        $user = new User();
        $rp = new ResearchProject();

        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->exactly(2))->method('flush');

        $subProject = $this->manager->createForUser(
            $user,
            'reading',
            'Lecture Sahel',
            $rp,
            '# Sahel content',
            ['key' => 'val']
        );

        $this->assertInstanceOf(SubProject::class, $subProject);
        $this->assertEquals('reading', $subProject->getType());
        $this->assertEquals('Lecture Sahel', $subProject->getName());
        $this->assertEquals($user, $subProject->getUser());
        $this->assertEquals($rp, $subProject->getResearchProject());
        $this->assertEquals('# Sahel content', $subProject->getContent());
        $this->assertEquals(['key' => 'val'], $subProject->getMetadata());
    }

    public function testUpdateSubProject(): void
    {
        $subProject = new SubProject();
        $subProject->setName('Ancien Nom');

        $this->entityManager->expects($this->once())->method('flush');

        $updated = $this->manager->updateSubProject($subProject, [
            'name' => 'Nouveau Nom',
            'content' => 'Nouveau contenu Markdown'
        ]);

        $this->assertEquals('Nouveau Nom', $updated->getName());
        $this->assertEquals('Nouveau contenu Markdown', $updated->getContent());
    }

    public function testAttachToProject(): void
    {
        $subProject = new SubProject();
        $rp = new ResearchProject();

        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->attachToProject($subProject, $rp);

        $this->assertEquals($rp, $subProject->getResearchProject());
    }

    public function testDetachFromProject(): void
    {
        $subProject = new SubProject();
        $rp = new ResearchProject();
        $subProject->setResearchProject($rp);

        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->detachFromProject($subProject);

        $this->assertNull($subProject->getResearchProject());
    }
}
