<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class AcademicTranslator
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    public function translate(string $text, string $targetLanguage): string
    {
        return "Academic Translation draft";
    }

    public function streamTranslate(string $text, string $targetLanguage, callable $callback): void
    {
        $callback("Academic Translation draft");
    }
}
