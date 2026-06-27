<?php

namespace App\Command;

use App\Entity\SubProject;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:projects:migrate-to-subprojects',
    description: 'Migre progressivement les anciens projets vers la nouvelle structure de sous-projets (SubProject)',
)]
class MigrateProjectsCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private ProjectRepository $projectRepository;

    public function __construct(EntityManagerInterface $entityManager, ProjectRepository $projectRepository)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->projectRepository = $projectRepository;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Djoliba - Migration progressive vers SubProject');

        // Récupérer tous les anciens projets
        $projects = $this->projectRepository->findAll();
        $total = count($projects);

        if ($total === 0) {
            $io->info('Aucun ancien projet trouvé en base de données.');
            return Command::SUCCESS;
        }

        $io->progressStart($total);
        $migratedCount = 0;
        $skippedCount = 0;

        // Configuration du timeout d'exécution plus long au besoin
        set_time_limit(300);

        foreach ($projects as $project) {
            // Rendre le script idempotent : si le projet est déjà lié à un sous-projet, on le passe
            if ($project->getSubProject() !== null) {
                $skippedCount++;
                $io->progressAdvance();
                continue;
            }

            // Remappage du type
            $oldType = $project->getType();
            $newType = match ($oldType) {
                'literature_review' => 'literature',
                'reading' => 'reading',
                'writing' => 'writing',
                'thesis' => 'thesis',
                default => 'reading', // Fallback sécurisé
            };

            // Création du nouveau sous-projet
            $subProject = new SubProject();
            $subProject->setName($project->getName());
            $subProject->setType($newType);
            $subProject->setUser($project->getUser());
            $subProject->setResearchProject($project->getResearchProject());
            $subProject->setStatus($project->getStatus() ?? 'active');
            $subProject->setMetadata($project->getMetadata());
            $subProject->setCreatedAt($project->getCreatedAt() ?? new \DateTime());
            $subProject->setUpdatedAt($project->getUpdatedAt());

            // Lier l'ancien projet
            $project->setSubProject($subProject);
            $this->entityManager->persist($subProject);

            // Migrer les interactions liées
            $interactionsQuery = $this->entityManager->createQuery(
                'SELECT i FROM App\Entity\Interaction i WHERE i.project = :project'
            )->setParameter('project', $project);

            foreach ($interactionsQuery->getResult() as $interaction) {
                $interaction->setSubProject($subProject);
            }

            // Migrer les documents liés
            $documentsQuery = $this->entityManager->createQuery(
                'SELECT d FROM App\Entity\Document d WHERE d.project = :project'
            )->setParameter('project', $project);

            foreach ($documentsQuery->getResult() as $document) {
                $document->setSubProject($subProject);
            }

            // Migrer les chapitres liés
            $chaptersQuery = $this->entityManager->createQuery(
                'SELECT c FROM App\Entity\Chapter c WHERE c.project = :project'
            )->setParameter('project', $project);

            foreach ($chaptersQuery->getResult() as $chapter) {
                $chapter->setSubProject($subProject);
            }

            $migratedCount++;
            $io->progressAdvance();

            // Flusher toutes les 50 entités pour économiser la mémoire
            if ($migratedCount % 50 === 0) {
                $this->entityManager->flush();
            }
        }

        // Dernier flush
        $this->entityManager->flush();
        $io->progressFinish();

        $io->success(sprintf(
            'Migration terminée avec succès. %d projets migrés, %d projets ignorés (déjà migrés).',
            $migratedCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }
}
