<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-bdd-index',
    description: 'Vérifie que les index de la base de données sont bien utilisés par les requêtes critiques de l\'application.',
)]
class TestBddIndexCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('analyze', 'a', InputOption::VALUE_NONE, 'Utiliser EXPLAIN ANALYZE (exécute réellement les requêtes — plus lent mais plus précis)')
            ->addOption('list-indexes', 'l', InputOption::VALUE_NONE, 'Lister tous les index existants dans la base de données')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 Diagnostic des index de la base de données Djoliba');

        // ------------------------------------------------------------------
        // Option : lister tous les index existants
        // ------------------------------------------------------------------
        if ($input->getOption('list-indexes')) {
            $this->listAllIndexes($io);
            return Command::SUCCESS;
        }

        // ------------------------------------------------------------------
        // Requêtes de diagnostic avec EXPLAIN
        // ------------------------------------------------------------------
        $explainPrefix = $input->getOption('analyze') ? 'EXPLAIN ANALYZE' : 'EXPLAIN';

        $queries = $this->getTestQueries();

        $results = [];
        $passCount = 0;
        $failCount = 0;

        foreach ($queries as $label => $sql) {
            $plan = $this->getQueryPlan($explainPrefix, $sql);
            $usesIndex = $this->planUsesIndex($plan);

            $results[] = [
                $label,
                $usesIndex ? '✅ Index Scan' : '⚠️  Seq Scan',
                $this->extractScanType($plan),
            ];

            if ($usesIndex) {
                $passCount++;
            } else {
                $failCount++;
            }
        }

        // ------------------------------------------------------------------
        // Affichage du tableau récapitulatif
        // ------------------------------------------------------------------
        $io->section('Résultat de l\'analyse EXPLAIN');
        $io->table(
            ['Requête', 'Résultat', 'Type de scan détecté'],
            $results
        );

        $io->newLine();
        $io->text(sprintf(
            '📊 Bilan : <info>%d</info> requête(s) utilisent un index, <comment>%d</comment> requête(s) en scan séquentiel.',
            $passCount,
            $failCount
        ));

        if ($failCount > 0) {
            $io->warning(
                'Certaines requêtes n\'utilisent pas d\'index. '
                . 'Cela peut être normal sur une base de données avec très peu de lignes (PostgreSQL préfère un Seq Scan si la table est petite). '
                . 'Testez sur un jeu de données plus volumineux ou forcez avec SET enable_seqscan = off.'
            );
        } else {
            $io->success('Tous les index sont correctement utilisés par les requêtes critiques.');
        }

        // ------------------------------------------------------------------
        // Statistiques des index
        // ------------------------------------------------------------------
        $this->showIndexUsageStats($io);

        return Command::SUCCESS;
    }

    /**
     * Retourne les requêtes critiques de l'application à valider.
     *
     * @return array<string, string>
     */
    private function getTestQueries(): array
    {
        return [
            // sub_project
            'sub_project WHERE status' =>
                "SELECT id FROM sub_project WHERE status = 'active' LIMIT 10",
            'sub_project WHERE type' =>
                "SELECT id FROM sub_project WHERE type = 'reading' LIMIT 10",
            'sub_project WHERE user_id' =>
                'SELECT id FROM sub_project WHERE user_id = 1 LIMIT 10',
            'sub_project WHERE research_project_id' =>
                'SELECT id FROM sub_project WHERE research_project_id = 1 LIMIT 10',

            // project
            'project WHERE status' =>
                "SELECT id FROM project WHERE status = 'active' LIMIT 10",
            'project WHERE type' =>
                "SELECT id FROM project WHERE type = 'literature_review' LIMIT 10",
            'project WHERE user_id' =>
                'SELECT id FROM project WHERE user_id = 1 LIMIT 10',

            // interaction
            'interaction ORDER BY created_at' =>
                'SELECT id FROM interaction ORDER BY created_at DESC LIMIT 10',
            'interaction WHERE project_id' =>
                'SELECT id FROM interaction WHERE project_id = 1 LIMIT 10',
            'interaction WHERE sub_project_id' =>
                'SELECT id FROM interaction WHERE sub_project_id = 1 LIMIT 10',

            // document
            'document WHERE project_id' =>
                'SELECT id FROM document WHERE project_id = 1 LIMIT 10',
            'document WHERE sub_project_id' =>
                'SELECT id FROM document WHERE sub_project_id = 1 LIMIT 10',

            // project_activity
            'project_activity WHERE research_project_id' =>
                'SELECT id FROM project_activity WHERE research_project_id = 1 LIMIT 10',
            'project_activity ORDER BY created_at' =>
                'SELECT id FROM project_activity ORDER BY created_at DESC LIMIT 10',
        ];
    }

    /**
     * Exécute EXPLAIN sur une requête et retourne le plan sous forme de texte.
     */
    private function getQueryPlan(string $explainPrefix, string $sql): string
    {
        try {
            $rows = $this->connection->fetchAllAssociative("$explainPrefix $sql");

            $lines = [];
            foreach ($rows as $row) {
                // PostgreSQL retourne une colonne « QUERY PLAN »
                $lines[] = reset($row);
            }

            return implode("\n", $lines);
        } catch (\Exception $e) {
            return 'ERROR: ' . $e->getMessage();
        }
    }

    /**
     * Détecte si le plan d'exécution utilise un index.
     */
    private function planUsesIndex(string $plan): bool
    {
        return (bool) preg_match('/Index (Scan|Only Scan)|Bitmap (Index Scan|Heap Scan)/i', $plan);
    }

    /**
     * Extrait le type de scan principal du plan d'exécution.
     */
    private function extractScanType(string $plan): string
    {
        if (str_starts_with($plan, 'ERROR:')) {
            return $plan;
        }

        if (preg_match('/Index Only Scan using (\S+)/i', $plan, $m)) {
            return "Index Only Scan ({$m[1]})";
        }
        if (preg_match('/Index Scan using (\S+)/i', $plan, $m)) {
            return "Index Scan ({$m[1]})";
        }
        if (preg_match('/Bitmap Index Scan on (\S+)/i', $plan, $m)) {
            return "Bitmap Index Scan ({$m[1]})";
        }
        if (preg_match('/Seq Scan on (\S+)/i', $plan, $m)) {
            return "Seq Scan ({$m[1]})";
        }

        // Fallback : première ligne du plan
        return strtok($plan, "\n") ?: 'inconnu';
    }

    /**
     * Affiche les statistiques d'utilisation des index depuis pg_stat_user_indexes.
     */
    private function showIndexUsageStats(SymfonyStyle $io): void
    {
        $io->section('📈 Statistiques d\'utilisation des index (pg_stat_user_indexes)');

        try {
            $rows = $this->connection->fetchAllAssociative("
                SELECT
                    schemaname,
                    relname AS table_name,
                    indexrelname AS index_name,
                    idx_scan AS scans,
                    idx_tup_read AS tuples_read,
                    idx_tup_fetch AS tuples_fetched,
                    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
                FROM pg_stat_user_indexes
                WHERE schemaname = 'public'
                ORDER BY relname, indexrelname
            ");

            if (empty($rows)) {
                $io->text('Aucune donnée de statistiques disponible.');
                return;
            }

            $tableRows = [];
            foreach ($rows as $row) {
                $tableRows[] = [
                    $row['table_name'],
                    $row['index_name'],
                    number_format((int) $row['scans']),
                    number_format((int) $row['tuples_read']),
                    number_format((int) $row['tuples_fetched']),
                    $row['index_size'],
                ];
            }

            $io->table(
                ['Table', 'Index', 'Scans', 'Tuples lus', 'Tuples récupérés', 'Taille'],
                $tableRows
            );
        } catch (\Exception $e) {
            $io->warning('Impossible de lire pg_stat_user_indexes : ' . $e->getMessage());
        }
    }

    /**
     * Liste tous les index existants dans la base de données.
     */
    private function listAllIndexes(SymfonyStyle $io): void
    {
        $io->section('📋 Index existants dans la base de données');

        try {
            $rows = $this->connection->fetchAllAssociative("
                SELECT
                    t.relname AS table_name,
                    i.relname AS index_name,
                    am.amname AS index_type,
                    pg_size_pretty(pg_relation_size(i.oid)) AS index_size,
                    ix.indisunique AS is_unique,
                    ix.indisprimary AS is_primary,
                    array_to_string(ARRAY(
                        SELECT pg_get_indexdef(ix.indexrelid, k + 1, true)
                        FROM generate_subscripts(ix.indkey, 1) AS k
                    ), ', ') AS columns
                FROM pg_index ix
                JOIN pg_class t ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_am am ON am.oid = i.relam
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = 'public'
                ORDER BY t.relname, i.relname
            ");

            if (empty($rows)) {
                $io->text('Aucun index trouvé.');
                return;
            }

            $tableRows = [];
            foreach ($rows as $row) {
                $flags = [];
                if ($row['is_primary']) {
                    $flags[] = 'PK';
                }
                if ($row['is_unique'] && !$row['is_primary']) {
                    $flags[] = 'UQ';
                }

                $tableRows[] = [
                    $row['table_name'],
                    $row['index_name'],
                    $row['columns'],
                    $row['index_type'],
                    $row['index_size'],
                    implode(', ', $flags) ?: '-',
                ];
            }

            $io->table(
                ['Table', 'Index', 'Colonnes', 'Type', 'Taille', 'Flags'],
                $tableRows
            );
        } catch (\Exception $e) {
            $io->error('Erreur lors de la lecture des index : ' . $e->getMessage());
        }
    }
}
