<?php

namespace App\Controller\Admin;

use App\Entity\AdminLog;
use App\Entity\EmailQueue;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Interaction;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use App\Controller\Admin\UserCrudController;
use App\Controller\Admin\ProjectCrudController;
use App\Controller\Admin\AdminLogCrudController;
use App\Controller\Admin\EmailQueueCrudController;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function index(): Response
    {
        $userRepo = $this->entityManager->getRepository(User::class);
        $projectRepo = $this->entityManager->getRepository(Project::class);
        $interactionRepo = $this->entityManager->getRepository(Interaction::class);
        $emailQueueRepo = $this->entityManager->getRepository(EmailQueue::class);

        $activeUsers = $userRepo->count(['isActive' => true]);
        $totalProjects = $projectRepo->count([]);
        $totalIaQueries = $interactionRepo->count([]);
        
        $totalEmails = $emailQueueRepo->count([]);
        $sentEmails = $emailQueueRepo->count(['status' => 'sent']);
        $emailSuccessRate = $totalEmails > 0 ? round(($sentEmails / $totalEmails) * 100, 1) : 100.0;

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'activeUsers' => $activeUsers,
                'totalProjects' => $totalProjects,
                'totalIaQueries' => $totalIaQueries,
                'emailSuccessRate' => $emailSuccessRate,
            ]
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Djoliba Admin');
    }

    public function configureAssets(): \EasyCorp\Bundle\EasyAdminBundle\Config\Assets
    {
        return \EasyCorp\Bundle\EasyAdminBundle\Config\Assets::new()
            ->addJsFile('js/admin_toast.js');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de Bord', 'fa fa-home');
        
        yield MenuItem::section('Gestion Applicative');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fas fa-users');
        yield MenuItem::linkTo(ProjectCrudController::class, 'Projets', 'fas fa-project-diagram');
        
        yield MenuItem::section('Logs & Diagnostics');
        yield MenuItem::linkTo(AdminLogCrudController::class, 'Logs Admin', 'fas fa-history');
        yield MenuItem::linkTo(EmailQueueCrudController::class, 'File d\'emails', 'fas fa-envelope-open-text');
        
        yield MenuItem::section('Actions');
        yield MenuItem::linkToRoute('Envoyer un email', 'fas fa-paper-plane', 'admin_email_sender');
    }
}
