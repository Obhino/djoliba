<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class ReasoningVerifier
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    public function verify(string $text): string
    {
        $prompt = sprintf(
            "Tu es un relecteur scientifique rigoureux. Analyse la structure logique du paragraphe suivant.\n" .
            "Identifie clairement :\n" .
            "1. Les prémisses implicites ou explicites.\n" .
            "2. La validité des inférences ou des liens de cause à effet.\n" .
            "3. La solidité de la conclusion par rapport aux arguments présentés.\n\n" .
            "Signale toute faille logique (généralisation hâtive, faux dilemme, corrélation confondue avec causalité) et propose une formulation alternative plus rigoureuse.\n" .
            "Texte à analyser : \"%s\"",
            $text
        );

        return $this->deepSeekService->call($prompt);
    }
}
