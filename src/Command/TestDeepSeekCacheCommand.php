<?php

namespace App\Command;

use App\Service\IA\DeepSeekService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-deepseek-cache',
    description: 'Teste et compare la performance et le coût du cache contextuel sur l\'API DeepSeek.'
)]
class TestDeepSeekCacheCommand extends Command
{
    // Prix réels de DeepSeek (par million de tokens)
    private const COST_INPUT_UNCACHED = 0.55; // $0.55 / M tokens
    private const COST_INPUT_CACHED   = 0.14; // $0.14 / M tokens
    private const COST_OUTPUT         = 2.19; // $2.19 / M tokens

    public function __construct(
        private DeepSeekService $deepSeekService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🚀 Test du Cache Contextuel DeepSeek');

        if ($this->deepSeekService->isApiKeyPlaceholder()) {
            $io->error('La clé API DeepSeek est absente ou est un placeholder ("test_key"). Activez une clé réelle dans .env.local pour ce test.');
            return Command::FAILURE;
        }

        $io->text('Génération d\'un document volumineux (~10 000 tokens)...');

        // Créer un contexte lourd (environ 40 000 caractères, ~10 000 tokens de charabia académique répétitif)
        $heavyText = $this->generateHeavyAcademicText();
        $io->success(sprintf('Document généré avec succès : %d caractères.', strlen($heavyText)));

        // 1. Initialisation de l'historique
        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => 'Tu es un assistant IA expert. Réponds en te basant précisément sur le document fourni.'
        ];
        $messages[] = [
            'role' => 'user',
            'content' => "Voici le document de recherche :\n\n" . $heavyText
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => "J'ai bien reçu le document. Je l'ai lu et mémorisé dans mon contexte de travail. Prêt pour vos questions."
        ];

        // --- PREMIER APPEL ---
        $io->section('💬 Premier Appel : Première question sur le document');
        $question1 = 'Fais une liste des 3 thèmes majeurs abordés dans ce document.';
        $messages[] = ['role' => 'user', 'content' => $question1];

        $io->text(sprintf('Question : "%s"', $question1));
        $io->text('Envoi de la requête à DeepSeek (calcul initial)...');

        $timeStart = microtime(true);
        $response1 = $this->deepSeekService->chatWithHistory($messages, [
            'temperature' => 0.2
        ]);
        $time1 = microtime(true) - $timeStart;

        $usage1 = $this->deepSeekService->getLastUsage();
        if (!$usage1) {
            $io->error('Impossible de récupérer l\'usage des tokens pour le premier appel.');
            return Command::FAILURE;
        }

        $io->success('Réponse reçue !');
        $io->note(mb_substr($response1, 0, 300) . '...');

        // Sauvegarder la réponse dans l'historique
        $messages[] = ['role' => 'assistant', 'content' => $response1];

        // --- SECOND APPEL ---
        $io->section('💬 Second Appel : Question complémentaire (historique enrichi)');
        $question2 = 'Merci. Quel est le rôle de la formule d\'entropie mentionnée dans le document ?';
        $messages[] = ['role' => 'user', 'content' => $question2];

        $io->text(sprintf('Question : "%s"', $question2));
        $io->text('Envoi de la requête à DeepSeek (vérification du cache contextuel)...');

        $timeStart = microtime(true);
        $response2 = $this->deepSeekService->chatWithHistory($messages, [
            'temperature' => 0.2
        ]);
        $time2 = microtime(true) - $timeStart;

        $usage2 = $this->deepSeekService->getLastUsage();
        if (!$usage2) {
            $io->error('Impossible de récupérer l\'usage des tokens pour le second appel.');
            return Command::FAILURE;
        }

        $io->success('Réponse reçue !');
        $io->note(mb_substr($response2, 0, 300) . '...');


        // --- RAPPORT & ANALYSE ---
        $io->section('📊 Rapport d\'Analyse & Optimisation des Coûts');

        // Extraction des statistiques
        $pTokens1 = $usage1['prompt_tokens'] ?? 0;
        $cTokens1 = $usage1['prompt_tokens_details']['cached_tokens'] ?? 0;
        $oTokens1 = $usage1['completion_tokens'] ?? 0;

        $pTokens2 = $usage2['prompt_tokens'] ?? 0;
        $cTokens2 = $usage2['prompt_tokens_details']['cached_tokens'] ?? 0;
        $oTokens2 = $usage2['completion_tokens'] ?? 0;

        // Calcul des coûts individuels
        $cost1 = (($pTokens1 - $cTokens1) * self::COST_INPUT_UNCACHED + $cTokens1 * self::COST_INPUT_CACHED + $oTokens1 * self::COST_OUTPUT) / 1000000;
        $cost2 = (($pTokens2 - $cTokens2) * self::COST_INPUT_UNCACHED + $cTokens2 * self::COST_INPUT_CACHED + $oTokens2 * self::COST_OUTPUT) / 1000000;

        // Si tout s'était fait hors cache contextuel (simulation coût brut)
        $cost2NoCache = ($pTokens2 * self::COST_INPUT_UNCACHED + $oTokens2 * self::COST_OUTPUT) / 1000000;

        $table = new Table($output);
        $table->setHeaders(['Métrique', 'Appel 1 (Initial)', 'Appel 2 (Cache Contextuel)']);
        $table->setRows([
            ['Temps de réponse', sprintf('%.2fs', $time1), sprintf('%.2fs', $time2)],
            ['Tokens d\'Entrée (Prompt)', $pTokens1, $pTokens2],
            ['Tokens lus du cache', sprintf('<info>%d</info> (%.1f%%)', $cTokens1, $pTokens1 > 0 ? ($cTokens1 / $pTokens1) * 100 : 0), sprintf('<info>%d</info> (%.1f%%)', $cTokens2, $pTokens2 > 0 ? ($cTokens2 / $pTokens2) * 100 : 0)],
            ['Tokens de Sortie (Completion)', $oTokens1, $oTokens2],
            ['Coût réel estimé (USD)', sprintf('$%.6f', $cost1), sprintf('$%.6f', $cost2)],
            ['Coût sans cache estimé (USD)', sprintf('$%.6f', $cost1), sprintf('$%.6f', $cost2NoCache)],
        ]);
        $table->render();

        $io->newLine();

        if ($cTokens2 > 0) {
            $savingPct = ($cost2NoCache - $cost2) / $cost2NoCache * 100;
            $io->success(sprintf(
                "Le cache contextuel DeepSeek a fonctionné à la perfection !\n" .
                "- %d tokens d'entrée ont été récupérés du cache interne sur le second appel.\n" .
                "- Vous avez économisé %.1f%% sur le coût du second appel ($%.6f économisés).\n" .
                "- Le temps d'analyse a été réduit (appel 1: %.2fs, appel 2: %.2fs).",
                $cTokens2,
                $savingPct,
                $cost2NoCache - $cost2,
                $time1,
                $time2
            ));
        } else {
            $io->warning(
                "Le cache contextuel DeepSeek n'a pas détecté de hit (cached_tokens = 0).\n" .
                "Rappels : Le cache contextuel s'active automatiquement sur l'API DeepSeek pour les préfixes identiques d'au moins 1024 tokens.\n" .
                "Assurez-vous que le document et les premiers messages soient strictement identiques d'un appel à l'autre."
            );
        }

        return Command::SUCCESS;
    }

    private function generateHeavyAcademicText(): string
    {
        $baseText = "
Modélisation de l'Entropie et de l'Équation d'État en Physique Quantique des Systèmes Ouverts.
Auteurs: Dr. Sophia Vance, Prof. Marc Sterling.
Résumé: Ce document formalise la dynamique thermodynamique et la décohérence quantique dans des espaces complexes de dimension non-linéaire.
En physique moderne, l'étude d'un système quantique couplé à un environnement macroscopique exige d'étudier la conservation globale de la fonction d'onde.
L'équation de Schrödinger dépendant du temps s'écrit de la façon suivante:
$$\\hat{H}\\psi(x,t) = i\\hbar\\frac{\\partial\\psi(x,t)}{\\partial t}$$
Dans cette formule fondamentale de transition énergétique quantique, H représente l'opérateur hamiltonien, h_barre est la constante de Planck réduite, et psi décrit l'état ondulatoire.
La probabilité totale de présence de l'entité particulaire sur l'ensemble du domaine tridimensionnel continu spatial répond à la condition stricte de normalisation intégrale:
$$\\int_{-\\infty}^{+\\infty} |\\psi(x,t)|^2 \\, dx = 1$$
Si cette intégrale converge, le système est fermé. Dans le cas d'un système ouvert, des flux exogènes perturbent cette intégrité.
Le rendement optimal de transition thermodynamique s'exprime par le vecteur pondéré suivant:
$$\\eta_t = \\sum_{i=1}^{n} w_i \\cdot x_i - \\lambda \\cdot \\sigma^2$$
Où w_i représente le coefficient d'importance relative de chaque sous-composant structurel, et sigma quantifie les pertes entropiques.
L'irréversibilité fondamentale de ces transformations internes obéit à la seconde loi thermodynamique classique sur la croissance continue de l'entropie globale:
$$\\Delta S \\ge \\int \\frac{\\delta Q}{T} \\ge 0$$
Toute augmentation du désordre moléculaire ou de la dispersion thermique empêche le retour à l'état initial, provoquant une asymétrie temporelle.
Pour les futurs chercheurs de cette discipline, il est impératif de résoudre l'équation de dispersion non-linéaire complexe suivante:
$$\\omega(k) = v_0 \\cdot k + \\alpha \\cdot k^3$$
Ce modèle prédictif met en relief l'apparition de distorsions de phase au sein des paquets d'ondes de matière traversant un milieu dense et dispersif.
";

        $output = '';
        // Répéter le texte pour atteindre ~40 000 caractères, ce qui correspond à ~10 000 tokens
        for ($i = 0; $i < 40; $i++) {
            $output .= "\n--- Section " . ($i + 1) . " ---\n" . $baseText;
        }

        return $output;
    }
}
