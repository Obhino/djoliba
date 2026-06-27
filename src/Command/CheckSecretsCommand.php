<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:check-secrets',
    description: 'Vérifie la présence et la validité des secrets et clés API requis par Djoliba.'
)]
class CheckSecretsCommand extends Command
{
    private const REQUIRED_SECRETS = [
        'DEEPSEEK_API_KEY' => 'Clé API pour le service d\'IA DeepSeek',
        'OPENSERP_API_KEY' => 'Clé API / Token pour la recherche OpenSERP',
        'DB_PASSWORD' => 'Mot de passe de la base de données'
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 Vérification des Secrets de Djoliba');

        $tableRows = [];
        $hasMissing = false;

        foreach (self::REQUIRED_SECRETS as $envVar => $description) {
            $value = $_ENV[$envVar] ?? $_SERVER[$envVar] ?? getenv($envVar) ?? '';
            $status = '❌ Non défini';
            $details = 'Manquant';

            if (!empty($value)) {
                if (str_contains($value, 'place_your_') || $value === '!ChangeMe!') {
                    $status = '⚠️ Placeholder';
                    $details = 'Contient une valeur par défaut de modèle';
                    $hasMissing = true;
                } else {
                    $status = '✅ Défini';
                    // Montrer seulement les 4 premiers et 4 derniers caractères pour sécurité
                    $len = strlen($value);
                    if ($len > 8) {
                        $details = substr($value, 0, 4) . '...' . substr($value, -4);
                    } else {
                        $details = '🔑 Masqué';
                    }
                }
            } else {
                $hasMissing = true;
            }

            $tableRows[] = [$envVar, $description, $status, $details];
        }

        $io->table(
            ['Variable d\'environnement', 'Description', 'Statut', 'Valeur / Détail'],
            $tableRows
        );

        if ($hasMissing) {
            $io->error('Certains secrets sont manquants ou mal configurés.');
            return Command::FAILURE;
        }

        $io->success('Tous les secrets requis sont configurés avec succès !');
        return Command::SUCCESS;
    }
}
