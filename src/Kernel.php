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
            $requiredSecrets = ['DEEPSEEK_API_KEY', 'OPENSERP_API_KEY', 'DB_PASSWORD', 'ENCRYPTION_KEY'];
            $missing = [];

            foreach ($requiredSecrets as $secret) {
                $value = $_ENV[$secret] ?? $_SERVER[$secret] ?? getenv($secret) ?? '';
                if (
                    empty($value) || 
                    str_contains($value, 'place_your_') || 
                    $value === 'sk_prod_placeholder' || 
                    $value === '!ChangeMe!' ||
                    ($secret === 'ENCRYPTION_KEY' && $value === '2a8d54d9b4bfa2cfd1e34e56598c0d9a716c52a382c7f0d616c87a552ef3bf9d')
                ) {
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
