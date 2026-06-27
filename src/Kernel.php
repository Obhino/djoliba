<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        if ($this->getEnvironment() === 'prod') {
            $requiredSecrets = ['DEEPSEEK_API_KEY', 'OPENSERP_API_KEY', 'DB_PASSWORD'];
            $missing = [];

            foreach ($requiredSecrets as $secret) {
                $value = $_ENV[$secret] ?? $_SERVER[$secret] ?? getenv($secret) ?? '';
                if (empty($value) || str_contains($value, 'place_your_') || $value === 'sk_prod_placeholder' || $value === '!ChangeMe!') {
                    $missing[] = $secret;
                }
            }

            if (!empty($missing)) {
                throw new \RuntimeException(sprintf(
                    'Djoliba Boot Error: Les secrets requis suivants sont manquants ou non configurés en production : %s. ' .
                    'Veuillez les définir dans vos variables d\'environnement ou via .env.prod.',
                    implode(', ', $missing)
                ));
            }
        }
    }
}
