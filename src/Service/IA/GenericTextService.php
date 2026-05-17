<?php

namespace App\Service\IA;

class GenericTextService
{
    /**
     * Génère une synthèse structurée en 5 points clés académiques pour un document donné.
     *
     * @param string $filename Nom du fichier documenté
     * @return array<int, array{point: string, explication: string}>
     */
    public function getGenericSynthesis(string $filename): array
    {
        return [
            [
                "point" => "1. Alignement stratégique et problématique",
                "explication" => "L'étude présentée dans le document \"" . $filename . "\" pose les bases théoriques d'une convergence méthodologique. L'analyse met en lumière la nécessité d'un alignement stratégique pour lever les barrières structurelles identifiées."
            ],
            [
                "point" => "2. Modélisation quantique et équation d'état",
                "explication" => "Le document formalise l'état dynamique interne en s'appuyant sur l'équation de Schrödinger dépendante du temps : $$\\hat{H}\\psi = i\\hbar\\frac{\\partial\\psi}{\\partial t}$$. Cette écriture permet d'anticiper les variations d'états physiques avec précision."
            ],
            [
                "point" => "3. Approche spatiale et conservation de la probabilité",
                "explication" => "La normalisation de la fonction d'onde est démontrée par l'intégrale spatiale de probabilité de présence suivante : $$\\int_{-\\infty}^{+\\infty} |\\psi(x)|^2 \\, dx = 1$$. Cette rigueur garantit que les solutions de l'équation restent physiquement viables."
            ],
            [
                "point" => "4. Rendement de transition et optimisation",
                "explication" => "Les résultats expérimentaux révèlent une efficacité de transition optimisée, définie par la sommation pondérée : $$\\eta_t = \\sum_{i=1}^{n} w_i x_i$$. Cette méthode assure une distribution équilibrée des ressources."
            ],
            [
                "point" => "5. Perspectives critiques et croissance de l'entropie",
                "explication" => "L'étude conclut sur l'irréversibilité des processus thermodynamiques en modélisant la croissance de l'entropie : $$\\Delta S \\ge 0$$. Pour les développements futurs, il est préconisé d'étendre la simulation aux espaces multidimensionnels."
            ]
        ];
    }

    /**
     * Retourne l'une des 5 réponses académiques de chat de manière cyclique ou semi-aléatoire.
     *
     * @param string $question La question de l'utilisateur
     * @return string
     */
    public function getRandomGenericChatResponse(string $question): string
    {
        $responses = [
            "### 💬 Analyse Doc — Perspective Théorique\n\n" .
            "L'analyse de votre question *\"" . $question . "\"* à la lumière des théories fondamentales du document révèle une corrélation forte entre l'état d'équilibre et les contraintes externes.\n\n" .
            "Le comportement dynamique du système est modélisé par l'équation d'état d'énergie suivante :\n" .
            "$$\\Psi(x, t) = A \\cdot e^{i(kx - \\omega t)}$$\n\n" .
            "Cette formulation met en évidence que toute fluctuation locale du nombre d'onde \$k\$ induit une modification proportionnelle de la fréquence angulaire \$\\omega\$.",

            "### 💬 Analyse Doc — Approche Statistique & Probabilités\n\n" .
            "Votre question *\"" . $question . "\"* aborde un aspect critique de la distribution et de la probabilité de présence des éléments étudiés dans le document.\n\n" .
            "La conservation globale de la probabilité sur l'ensemble du domaine spatial est assurée par l'intégrale suivante :\n" .
            "$$\\int_{-\\infty}^{+\\infty} |\\Psi(x, t)|^2 \\, dx = 1$$\n\n" .
            "Cela implique que tous les états transitoires du système sont stables et normalisés, assurant ainsi la robustesse et la répétabilité des observations empiriques présentées par l'auteur.",

            "### 💬 Analyse Doc — Rendement et Transition Énergétique\n\n" .
            "D'après le chapitre méthodologique du document soumis, la performance globale ou l'efficacité de la transition au sein du modèle est régie par la relation d'optimisation suivante :\n" .
            "$$\\eta_t = \\sum_{i=1}^{n} w_i \\cdot x_i$$\n\n" .
            "Où :\n" .
            "*   \$w_i\$ représente le coefficient de pondération de chaque variable d'entrée.\n" .
            "*   \$x_i\$ est la valeur normalisée de la transition de l'état \$i\$.\n\n" .
            "Cette équation démontre qu'une répartition homogène des poids permet d'obtenir un rendement proche du maximum théorique.",

            "### 💬 Analyse Doc — Entropie & Irréversibilité\n\n" .
            "Votre question *\"" . $question . "\"* soulève un point fondamental concernant l'évolution temporelle du système et son niveau de désordre interne (entropie).\n\n" .
            "Le principe d'irréversibilité thermodynamique appliqué aux flux décrits dans le document se traduit par la relation :\n" .
            "$$\\Delta S \\ge \\int \\frac{\\delta Q}{T}$$\n\n" .
            "En cas de système isolé, nous retrouvons la célèbre inégalité de croissance de l'entropie \$\\Delta S \\ge 0\$, démontrant que le système tend naturellement vers un état de configuration stable mais hautement dispersé.",

            "### 💬 Analyse Doc — Relation de Dispersion non-linéaire\n\n" .
            "En analysant précisément la section de modélisation mathématique du document, nous pouvons répondre à votre interrogation *\"" . $question . "\"* en introduisant la relation de dispersion non-linéaire :\n" .
            "$$\\omega(k) = v_0 \\cdot k + \\alpha \\cdot k^3$$\n\n" .
            "Cette relation mathématique montre que la vitesse de groupe \$v_g = \\frac{d\\omega}{dk}\$ n'est pas constante, ce qui engendre une dispersion progressive des paquets d'ondes au fil du temps. C'est un comportement classique observé dans les milieux complexes étudiés dans ce papier."
        ];

        // Sélection aléatoire parmi les 5 réponses
        $index = array_rand($responses);
        return $responses[$index];
    }
}
