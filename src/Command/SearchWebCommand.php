<?php

namespace App\Command;

use App\Service\Search\OpenSerpSearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search-web',
    description: 'Recherche des articles scientifiques ou du contenu web via l\'API OpenSERP avec cache Redis.'
)]
class SearchWebCommand extends Command
{
    public function __construct(
        private OpenSerpSearchService $searchService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Le terme de recherche ou les mots-clés')
            ->addOption('domain', 'd', InputOption::VALUE_OPTIONAL, 'Filtrer par domaine (arxiv, hal, pubmed, scholar, ou un domaine spécifique comme arxiv.org)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre maximum de résultats', 10)
            ->addOption('engine', null, InputOption::VALUE_OPTIONAL, 'Moteur de recherche à utiliser (google, duck, mega)', 'google')
            ->addOption('strict', 's', InputOption::VALUE_OPTIONAL, 'Recherche stricte sur les domaines scientifiques (true/false)', 'true')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $query = (string) $input->getArgument('query');
        $domain = $input->getOption('domain') ? (string) $input->getOption('domain') : null;
        $limit = (int) $input->getOption('limit');
        $engine = (string) $input->getOption('engine');
        $strict = filter_var($input->getOption('strict'), FILTER_VALIDATE_BOOLEAN);

        $io->title('🔍 Recherche Web Académique avec OpenSERP');

        $io->definitionList(
            ['Requête' => $query],
            ['Domaine' => $domain ?? 'Aucun (recherche globale)'],
            ['Limite' => $limit],
            ['Moteur' => $engine],
            ['Mode Strict Scientifique' => $strict ? 'Oui (domaines académiques sélectionnés)' : 'Non (web large)']
        );

        $io->text('Recherche en cours...');
        $startTime = microtime(true);
        
        $results = $this->searchService->search($query, $domain, $limit, $engine, $strict);
        
        $durationMs = (microtime(true) - $startTime) * 1000;

        if (empty($results)) {
            $io->warning('Aucun résultat trouvé ou erreur lors de la recherche.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Trouvé %d résultat(s) en %.2f ms.', count($results), $durationMs));

        // Détection de cache hit basé sur la durée d'exécution (la requête réseau prend en moyenne >150ms)
        if ($durationMs < 15) {
            $io->info('[⚡ CACHE HIT] Réponse instantanée servie par le cache local Redis.');
        } else {
            $io->info('[🌐 NETWORK MISS] Requête effectuée en direct sur l\'API OpenSERP.');
        }

        $table = new Table($output);
        $table->setHeaders(['#', 'Titre', 'Source', 'URL / Description']);

        $index = 1;
        foreach ($results as $item) {
            $table->addRow([
                $index++,
                sprintf('<info>%s</info>', $this->truncate($item['title'], 60)),
                sprintf('<comment>%s</comment>', $item['source']),
                sprintf("<fg=cyan>%s</>\n%s", $item['url'], $this->truncate($item['description'], 120))
            ]);
            // Séparateur entre les lignes pour plus de lisibilité
            if ($index <= count($results)) {
                $table->addRow(['', '', '', '']);
            }
        }

        $table->render();

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
