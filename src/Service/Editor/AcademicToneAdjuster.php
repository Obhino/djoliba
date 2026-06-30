<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class AcademicToneAdjuster
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    public function adjust(string $text, string $register): string
    {
        return "Academic Tone Adjuster draft";
    }

    public function streamAdjust(string $text, string $register, callable $callback): void
    {
        $callback("Academic Tone Adjuster draft");
    }
}
