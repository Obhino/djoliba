<?php

namespace App\Command;

use App\Repository\ProjectRepository;
use App\Service\Project\ProjectManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:projects:cleanup',
    description: 'Supprime les projets dont la date d\'expiration (expires_at) est dépassée.'
)]
class ProjectsCleanupCommand extends Command
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectManager $projectManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Simule la suppression sans effectuer de changement en base de données.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');

        $io->title('Nettoyage des projets expirés');

        if ($isDryRun) {
            $io->warning('Mode simulation (dry-run) : aucune suppression ne sera effectuée.');
        }

        $expiredProjects = $this->projectRepository->findExpired();
        $count = count($expiredProjects);

        if ($count === 0) {
            $io->success('Aucun projet expiré trouvé. Rien à supprimer.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('%d projet(s) expiré(s) trouvé(s) :', $count));

        $rows = [];
        foreach ($expiredProjects as $project) {
            $rows[] = [
                $project->getId(),
                $project->getName(),
                $project->getUser()->getEmail(),
                $project->getExpiresAt()->format('Y-m-d H:i:s'),
            ];
        }

        $io->table(['ID', 'Nom', 'Propriétaire', 'Expiré le'], $rows);

        if ($isDryRun) {
            $io->info(sprintf('%d projet(s) auraient été supprimé(s) (dry-run).', $count));
            return Command::SUCCESS;
        }

        $io->progressStart($count);
        $deleted = 0;

        foreach ($expiredProjects as $project) {
            try {
                $this->projectManager->deleteProject($project);
                $deleted++;
            } catch (\Exception $e) {
                $io->error(sprintf(
                    'Erreur lors de la suppression du projet #%d ("%s") : %s',
                    $project->getId(),
                    $project->getName(),
                    $e->getMessage()
                ));
            }
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf('%d projet(s) expiré(s) supprimé(s) avec succès.', $deleted));

        return Command::SUCCESS;
    }
}
