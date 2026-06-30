<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class PeerReviewSimulator
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    public function simulate(string $text): string
    {
        return "Peer Review Simulation draft";
    }

    public function streamSimulate(string $text, callable $callback): void
    {
        $callback("Peer Review Simulation draft");
    }
}
