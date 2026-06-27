<?php

namespace App\Service\IA;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * CacheService — Couche de cache Redis pour les appels DeepSeek.
 *
 * Évite les appels API redondants et coûteux pour des requêtes identiques.
 * La clé de cache est un hash SHA-256 du prompt pour garantir l'unicité.
 */
class CacheService
{
    private const DEFAULT_TTL = 3600; // 1 heure

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Retourne une valeur depuis le cache, ou exécute le callback et la met en cache.
     *
     * @template T
     * @param string   $key      Clé logique (sera hashée en interne)
     * @param callable $callback Fonction qui génère la valeur si absente du cache
     * @param int      $ttl      Durée de vie en secondes (défaut: 3600s / 1h)
     * @return T
     */
    public function remember(string $key, callable $callback, int $ttl = self::DEFAULT_TTL): mixed
    {
        $cacheKey = $this->buildKey($key);
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback();

        $item->set($value);
        $item->expiresAfter($ttl);
        $this->cache->save($item);

        return $value;
    }

    /**
     * Récupère une valeur depuis le cache par sa clé logique.
     */
    public function get(string $key): mixed
    {
        $cacheKey = $this->buildKey($key);
        $item = $this->cache->getItem($cacheKey);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Stocke une valeur en cache avec une clé logique et un TTL.
     */
    public function set(string $key, mixed $value, int $ttl = self::DEFAULT_TTL): void
    {
        $cacheKey = $this->buildKey($key);
        $item = $this->cache->getItem($cacheKey);
        $item->set($value);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }

    /**
     * Invalide une entrée du cache par sa clé logique.
     */
    public function invalidate(string $key): void
    {
        $this->cache->deleteItem($this->buildKey($key));
    }

    /**
     * Invalide toutes les entrées dont la clé commence par un préfixe.
     * Utile pour invalider toutes les reviews d'un projet.
     *
     * @param string[] $keys
     */
    public function invalidateMultiple(array $keys): void
    {
        $cacheKeys = array_map(fn(string $k) => $this->buildKey($k), $keys);
        $this->cache->deleteItems($cacheKeys);
    }

    /**
     * Génère une clé de cache sûre depuis une clé logique (SHA-256 tronqué).
     * Les PSR-6 pools ne supportent pas les espaces ou caractères spéciaux dans les clés.
     */
    private function buildKey(string $key): string
    {
        return 'djoliba_' . hash('sha256', $key);
    }
}
