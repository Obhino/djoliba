<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class Reformulator
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text): string
    {
        return sprintf(
            "Tu es un relecteur scientifique expert. Propose 3 reformulations académiques différentes pour le texte suivant.\n" .
            "Assure-toi de :\n" .
            "- Conserver strictement le sens scientifique initial.\n" .
            "- Améliorer le style, la clarté et la concision.\n" .
            "- Fournir exactement 3 variations.\n\n" .
            "Tu dois impérativement répondre au format JSON brut suivant (sans aucun autre texte avant ou après) :\n" .
            "{\n" .
            "  \"options\": [\n" .
            "    {\n" .
            "      \"label\": \"Variation 1 (Formelle & Standard)\",\n" .
            "      \"text\": \"<Texte de la reformulation 1>\"\n" .
            "    },\n" .
            "    {\n" .
            "      \"label\": \"Variation 2 (Directe & Active)\",\n" .
            "      \"text\": \"<Texte de la reformulation 2>\"\n" .
            "    },\n" .
            "    {\n" .
            "      \"label\": \"Variation 3 (Concise & Synthétique)\",\n" .
            "      \"text\": \"<Texte de la reformulation 3>\"\n" .
            "    }\n" .
            "  ]\n" .
            "}\n\n" .
            "Texte à reformuler : \"%s\"",
            $text
        );
    }

    public function reformulate(string $text): string
    {
        return $this->deepSeekService->call($this->getPrompt($text));
    }

    public function streamReformulate(string $text, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text), $callback);
    }
}
