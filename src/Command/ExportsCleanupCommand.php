<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:exports:cleanup',
    description: 'Supprime les fichiers d\'exportation (ZIP, PDF) vieux de plus de 24h.',
)]
class ExportsCleanupCommand extends Command
{
    public function __construct(
        private string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer la suppression sans confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $exportDir = $this->projectDir . '/public/uploads/exports';

        if (!is_dir($exportDir)) {
            $io->warning("Le répertoire d'export n'existe pas : $exportDir");
            return Command::SUCCESS;
        }

        $finder = new Finder();
        // Fichiers créés il y a plus de 24 heures
        $finder->files()->in($exportDir)->date('before 24 hours ago');

        if (!$finder->hasResults()) {
            $io->info("Aucun fichier à supprimer.");
            return Command::SUCCESS;
        }

        $count = $finder->count();
        $io->note(sprintf("%d fichier(s) trouvé(s) pour suppression.", $count));

        foreach ($finder as $file) {
            unlink($file->getRealPath());
        }

        $io->success(sprintf("Nettoyage terminé. %d fichiers ont été supprimés.", $count));

        return Command::SUCCESS;
    }
}
