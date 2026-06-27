<?php

namespace App\Tests\Service\Project;

use App\Entity\ResearchProject;
use App\Entity\User;
use App\Repository\ResearchProjectRepository;
use App\Service\Project\ProjectSwitcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class ProjectSwitcherTest extends TestCase
{
    private $requestStack;
    private $session;
    private $rpRepository;
    private $switcher;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->requestStack->method('getSession')->willReturn($this->session);

        $this->rpRepository = $this->createMock(ResearchProjectRepository::class);
        $this->switcher = new ProjectSwitcher($this->requestStack, $this->rpRepository);
    }

    public function testSetActiveProject(): void
    {
        $user = new User();
        $rp = $this->createMock(ResearchProject::class);
        $rp->method('getId')->willReturn(42);

        $this->session->expects($this->once())
            ->method('set')
            ->with('active_research_project_id', 42);

        $this->switcher->setActiveProject($user, $rp);
    }

    public function testClearActiveProject(): void
    {
        $user = new User();

        $this->session->expects($this->once())
            ->method('remove')
            ->with('active_research_project_id');

        $this->switcher->clearActiveProject($user);
    }

    public function testGetActiveProject(): void
    {
        $user = new User();
        $rp = new ResearchProject();

        $this->session->expects($this->once())
            ->method('get')
            ->with('active_research_project_id')
            ->willReturn(42);

        $this->rpRepository->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($rp);

        $active = $this->switcher->getActiveProject($user);
        $this->assertSame($rp, $active);
    }
}
