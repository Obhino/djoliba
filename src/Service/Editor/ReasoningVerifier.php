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
            "Tu dois impérativement répondre au format JSON brut suivant (pas de texte avant ni après) :\n" .
            "{\n" .
            "  \"analysis\": \"<L'analyse détaillée du raisonnement, avec les prémisses et les failles éventuelles en format texte ou Markdown simple>\",\n" .
            "  \"reformulation\": \"<La formulation alternative corrigée et plus rigoureuse en français, prête à remplacer le texte initial>\"\n" .
            "}\n\n" .
            "Texte à analyser : \"%s\"",
            $text
        );

        return $this->deepSeekService->call($prompt);
    }
}
