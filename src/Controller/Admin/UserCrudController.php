<?php

namespace App\Controller\Admin;

use App\Entity\AdminLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;

class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield TextField::new('firstName', 'Prénom');
        yield TextField::new('lastName', 'Nom');
        
        yield ChoiceField::new('roles')
            ->setChoices([
                'Utilisateur' => 'ROLE_USER',
                'Administrateur' => 'ROLE_ADMIN',
            ])
            ->allowMultipleChoices()
            ->renderAsBadges();

        yield BooleanField::new('isActive', 'Actif');
        yield BooleanField::new('isVerified', 'Vérifié');
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        $suspendAction = Action::new('suspend', 'Suspendre', 'fa fa-ban')
            ->linkToCrudAction('suspendUser')
            ->displayIf(static function (User $user) {
                return $user->isActive();
            })
            ->addCssClass('text-danger');

        $activateAction = Action::new('activate', 'Activer', 'fa fa-check-circle')
            ->linkToCrudAction('activateUser')
            ->displayIf(static function (User $user) {
                return !$user->isActive();
            })
            ->addCssClass('text-success');

        return $actions
            ->add(Crud::PAGE_INDEX, $suspendAction)
            ->add(Crud::PAGE_INDEX, $activateAction);
    }

    #[AdminRoute(path: '/{entityId}/suspend', name: 'suspend')]
    public function suspendUser(AdminContext $context, EntityManagerInterface $entityManager, AdminUrlGenerator $adminUrlGenerator): Response
    {
        /** @var User $user */
        $user = $context->getEntity()->getInstance();
        $user->setIsActive(false);
        
        // Log manually
        $adminLog = new AdminLog();
        $adminLog->setAdmin($this->getUser());
        $adminLog->setAction('SUSPEND');
        $adminLog->setTarget(sprintf('User #%d (%s)', $user->getId(), $user->getEmail()));
        $adminLog->setIpAddress($context->getRequest()->getClientIp());
        
        $entityManager->persist($adminLog);
        $entityManager->flush();

        $this->addFlash('success', sprintf('L\'utilisateur %s a été suspendu.', $user->getEmail()));

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }

    #[AdminRoute(path: '/{entityId}/activate', name: 'activate')]
    public function activateUser(AdminContext $context, EntityManagerInterface $entityManager, AdminUrlGenerator $adminUrlGenerator): Response
    {
        /** @var User $user */
        $user = $context->getEntity()->getInstance();
        $user->setIsActive(true);
        
        // Log manually
        $adminLog = new AdminLog();
        $adminLog->setAdmin($this->getUser());
        $adminLog->setAction('ACTIVATE');
        $adminLog->setTarget(sprintf('User #%d (%s)', $user->getId(), $user->getEmail()));
        $adminLog->setIpAddress($context->getRequest()->getClientIp());
        
        $entityManager->persist($adminLog);
        $entityManager->flush();

        $this->addFlash('success', sprintf('L\'utilisateur %s a été activé.', $user->getEmail()));

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }
}
