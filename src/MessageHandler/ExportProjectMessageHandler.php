<?php

namespace App\MessageHandler;

use App\Message\ExportProjectMessage;
use App\Service\Project\ProjectExporterService;
use App\Service\Project\ProjectManager;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
class ExportProjectMessageHandler
{
    public function __construct(
        private ProjectManager $projectManager,
        private ProjectExporterService $exporterService,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $router
    ) {
    }

    public function __invoke(ExportProjectMessage $message)
    {
        $project = $this->projectManager->getProject($message->getProjectId());
        
        if (!$project || !$project->getUser()) {
            return;
        }

        // Génération du ZIP
        $zipPath = $this->exporterService->exportToZip($project);
        $filename = basename($zipPath);
        
        // Construction de l'URL de téléchargement
        $downloadUrl = $this->router->generate('app_hub', [], UrlGeneratorInterface::ABSOLUTE_URL) . 'uploads/exports/' . $filename;

        // Envoi de l'email
        $email = (new Email())
            ->from('noreply@djoliba-search.com')
            ->to($project->getUser()->getEmail())
            ->subject('Votre export Djoliba est prêt !')
            ->html(sprintf(
                '<div style="font-family: sans-serif; color: #1E293B;">
                    <h1 style="color: #0B2545;">Djoliba Search</h1>
                    <p>Bonjour %s,</p>
                    <p>L\'exportation de votre projet <strong>"%s"</strong> est terminée.</p>
                    <p>Vous pouvez télécharger votre archive ZIP en cliquant sur le bouton ci-dessous :</p>
                    <div style="margin: 30px 0;">
                        <a href="%s" style="background-color: #10B981; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;">Télécharger l\'archive</a>
                    </div>
                    <p style="font-size: 12px; color: #64748B;">Ce lien sera disponible pendant 24 heures.</p>
                </div>',
                $project->getUser()->getUsername(),
                $project->getName(),
                $downloadUrl
            ));

        $this->mailer->send($email);
    }
}
