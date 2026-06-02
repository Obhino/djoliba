<?php

namespace App\Command;

use App\Service\SuggestionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-suggestions',
    description: 'Teste le service de suggestion d\'articles scientifiques à partir d\'un sujet ou d\'une requête.'
)]
class TestSuggestionsCommand extends Command
{
    public function __construct(
        private SuggestionService $suggestionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Le sujet de recherche ou la requête (ex: "La transition énergétique")')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre maximum d\'articles à suggérer', 5)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $query = (string) $input->getArgument('query');
        $limit = (int) $input->getOption('limit');

        $io->title('📚 Test de Suggestions d\'Articles Académiques (Djoliba)');

        $io->definitionList(
            ['Sujet fourni' => $query],
            ['Limite demandée' => $limit]
        );

        $io->text('Appel du service de suggestion...');
        $startTime = microtime(true);

        try {
            $articles = $this->suggestionService->suggest($query, $limit);
            $durationMs = (microtime(true) - $startTime) * 1000;

            if (empty($articles)) {
                $io->warning('Aucune suggestion d\'article n\'a été trouvée.');
                return Command::SUCCESS;
            }

            $io->success(sprintf('Trouvé %d suggestion(s) d\'articles en %.2f ms.', count($articles), $durationMs));

            $table = new Table($output);
            $table->setHeaders(['#', 'Badge / Titre', 'Auteurs / Année', 'Journal / DOI / URL']);

            $index = 1;
            foreach ($articles as $article) {
                $verifBadge = $article['verified']
                    ? '<fg=green>[Vérifié]</>'
                    : '<fg=yellow>[Non vérifié]</>';

                $doiStr = $article['doi'] ? sprintf('DOI: %s', $article['doi']) : 'Pas de DOI';
                $urlStr = $article['url'] ? sprintf('<fg=cyan>%s</>', $article['url']) : 'Pas d\'URL';

                $table->addRow([
                    $index++,
                    sprintf("%s\n<info>%s</info>", $verifBadge, $this->truncate($article['title'], 60)),
                    sprintf("<comment>%s</comment>\nAnnée: %s", $this->truncate($article['authors'], 50), $article['year'] ?: 'N/A'),
                    sprintf("Journal: %s\n%s\n%s", $article['journal'], $doiStr, $urlStr)
                ]);

                // Ajouter un résumé condensé en dessous de chaque article pour plus de détails
                $table->addRow([
                    '',
                    sprintf('<fg=gray;italic>%s</>', $this->truncate($article['abstract'], 120)),
                    '',
                    ''
                ]);

                if ($index <= count($articles)) {
                    $table->addRow(['', '', '', '']);
                }
            }

            $table->render();

        } catch (\Exception $e) {
            $io->error('Erreur lors de l\'exécution du service de suggestion : ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length - 3) . '...';
    }
}
