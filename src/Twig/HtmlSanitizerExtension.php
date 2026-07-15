<?php

namespace App\Twig;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class HtmlSanitizerExtension extends AbstractExtension
{
    public function __construct(
        private HtmlSanitizerInterface $defaultSanitizer
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('sanitize_html', [$this, 'sanitizeHtml']),
        ];
    }

    public function sanitizeHtml(string $html): string
    {
        return $this->defaultSanitizer->sanitize($html);
    }
}
