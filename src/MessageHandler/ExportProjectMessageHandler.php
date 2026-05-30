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
        private UrlGeneratorInterface $router,
        private string $projectDir
    ) {
    }

    public function __invoke(ExportProjectMessage $message): void
    {
        $jobId     = $message->getJobId();
        $format    = $message->getFormat();
        $jobsDir   = $this->projectDir . '/var/export_jobs';

        $project = $this->projectManager->getProject($message->getProjectId());

        if (!$project || !$project->getUser()) {
            $this->writeJobStatus($jobsDir, $jobId, [
                'status' => 'error',
                'error'  => 'Projet introuvable ou utilisateur inconnu.',
            ]);
            return;
        }

        try {
            // ── Génération selon le format demandé ─────────────────────────
            if ($format === 'pdf') {
                $filePath    = $this->exporterService->exportToPdf($project);
                $downloadUrl = $this->router->generate(
                    'app_project_export_pdf',
                    ['id' => $project->getId()]
                );
            } elseif ($format === 'latex') {
                $filePath    = $this->exporterService->exportToLatex($project);
                $downloadUrl = $this->router->generate(
                    'app_project_export_latex',
                    ['id' => $project->getId()]
                );
            } else {
                $filePath    = $this->exporterService->exportToZip($project);
                $downloadUrl = $this->router->generate(
                    'app_project_export_zip',
                    ['id' => $project->getId()]
                );
            }

            $filename = basename($filePath);

            // ── Mise à jour du fichier de statut ───────────────────────────
            $this->writeJobStatus($jobsDir, $jobId, [
                'status'      => 'done',
                'downloadUrl' => $downloadUrl,
                'filename'    => $filename,
                'completedAt' => (new \DateTime())->format(\DateTime::ATOM),
            ]);

            // ── Notification par email ─────────────────────────────────────
            $absoluteDownload = $this->router->generate(
                'app_hub',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ) . 'uploads/exports/' . $filename;

            $email = (new Email())
                ->from('noreply@djoliba-search.com')
                ->to($project->getUser()->getEmail())
                ->subject('Votre export Djoliba est prêt !')
                ->html(sprintf(
                    '<div style="font-family: sans-serif; color: #1E293B;">
                        <h1 style="color: #0B2545;">Djoliba Search</h1>
                        <p>Bonjour %s,</p>
                        <p>L\'exportation <strong>%s</strong> de votre projet <strong>"%s"</strong> est terminée.</p>
                        <div style="margin: 30px 0;">
                            <a href="%s" style="background-color: #10B981; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;">
                                Télécharger l\'export
                            </a>
                        </div>
                        <p style="font-size: 12px; color: #64748B;">Ce lien sera disponible pendant 24 heures.</p>
                    </div>',
                    $project->getUser()->getUsername(),
                    strtoupper($format),
                    $project->getName(),
                    $absoluteDownload
                ));

            $this->mailer->send($email);

        } catch (\Throwable $e) {
            $this->writeJobStatus($jobsDir, $jobId, [
                'status' => 'error',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function writeJobStatus(string $jobsDir, string $jobId, array $data): void
    {
        if (!$jobId) {
            return; // Pas de jobId = export legacy sans polling
        }

        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0775, true);
        }

        $payload = array_merge($data, ['updatedAt' => (new \DateTime())->format(\DateTime::ATOM)]);
        file_put_contents(
            $jobsDir . '/' . $jobId . '.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
