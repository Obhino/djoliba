<?php

namespace App\Controller\Admin;

use App\Entity\EmailQueue;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class EmailQueueCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EmailQueue::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInPlural('File d\'emails')
            ->setEntityLabelInSingular('Email')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield TextField::new('recipient', 'Destinataire');
        yield TextField::new('subject', 'Sujet');
        yield TextField::new('status', 'Statut');
        yield BooleanField::new('isBulk', 'Envoi groupé');
        yield DateTimeField::new('createdAt', 'Créé le');
        yield DateTimeField::new('sentAt', 'Envoyé le');
        yield TextareaField::new('body', 'Contenu')->hideOnIndex();
        yield TextareaField::new('errorMessage', 'Erreur')->hideOnIndex();
    }
}
