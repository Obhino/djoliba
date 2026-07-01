<?php

namespace App\Service\IA;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class DeepSeekService
{
    private const TIMEOUT_SECONDS  = 60;
    private const MAX_RETRIES      = 3;
    private const RETRY_DELAY_MS   = 500; // délai initial en millisecondes, doublé à chaque tentative

    private const DEFAULT_MODEL    = 'deepseek-chat';

    private ?array $lastUsage = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        #[Autowire('%env(DEEPSEEK_API_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(DEEPSEEK_API_URL)%')]
        private string $apiUrl,
        private ?\App\Service\Project\ProjectSwitcher $projectSwitcher = null,
    ) {
    }

    public function getLastUsage(): ?array
    {
        return $this->lastUsage;
    }

    /**
     * Appel API standard AVEC CACHE.
     */
    public function call(string $prompt, array $options = []): string
    {
        $cacheKey = $this->buildCacheKey($prompt, $options);

        return $this->cache->get($cacheKey, function ($item) use ($prompt, $options) {
            $item->expiresAfter(86400); // 24 heures par défaut
            return $this->callApi($prompt, $options);
        });
    }

    /**
     * Appel API avec historique complet (bénéficie du cache contextuel DeepSeek côté API).
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function chatWithHistory(array $messages, array $options = []): string
    {
        $cacheKey = $this->buildHistoryCacheKey($messages, $options);

        return $this->cache->get($cacheKey, function ($item) use ($messages, $options) {
            $item->expiresAfter(86400); // 24 heures par défaut
            return $this->callApiWithHistory($messages, $options);
        });
    }

    /**
     * Construit une clé de cache unique basée sur les messages de l'historique et les options.
     */
    private function buildHistoryCacheKey(array $messages, array $options): string
    {
        $keyData = [
            'messages' => $messages,
            'model' => $options['model'] ?? self::DEFAULT_MODEL,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system_prompt' => $options['system_prompt'] ?? null,
        ];

        return 'deepseek_history_' . md5(json_encode($keyData));
    }

    /**
     * Construit une clé de cache unique basée sur le prompt et les options.
     */
    private function buildCacheKey(string $prompt, array $options): string
    {
        $keyData = [
            'prompt' => $prompt,
            'model' => $options['model'] ?? self::DEFAULT_MODEL,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system_prompt' => $options['system_prompt'] ?? null,
        ];

        return 'deepseek_call_' . md5(json_encode($keyData));
    }

    /**
     * Appel API réel (ancienne logique de call).
     */
    private function callApi(string $prompt, array $options = []): string
    {
        try {
            $payload = $this->buildPayload($prompt, stream: false, options: $options);
            $attempt = 0;

            while ($attempt < self::MAX_RETRIES) {
                $attempt++;
                try {
                    $this->logger->info('[DeepSeek] Tentative d\'appel #{attempt}', ['attempt' => $attempt]);

                    $response = $this->httpClient->request('POST', $this->apiUrl . '/chat/completions', [
                        'headers' => $this->buildHeaders(),
                        'json'    => $payload,
                        'timeout' => self::TIMEOUT_SECONDS,
                    ]);

                    $data = $response->toArray();
                    $this->lastUsage = $data['usage'] ?? null;

                    $content = $data['choices'][0]['message']['content'] ?? null;

                    if ($content === null) {
                        throw new \UnexpectedValueException('La réponse DeepSeek ne contient pas de contenu.');
                    }

                    $this->logger->info('[DeepSeek] Appel réussi', [
                        'tokens_used' => $data['usage']['total_tokens'] ?? 0,
                    ]);

                    return $content;

                } catch (TransportExceptionInterface | \UnexpectedValueException $e) {
                    $this->logger->warning('[DeepSeek] Échec tentative #{attempt}: {error}', [
                        'attempt' => $attempt,
                        'error'   => $e->getMessage(),
                    ]);

                    if ($attempt >= self::MAX_RETRIES) {
                        throw new \RuntimeException(
                            sprintf('DeepSeek API indisponible après %d tentatives : %s', self::MAX_RETRIES, $e->getMessage()),
                            0,
                            $e
                        );
                    }

                    usleep(self::RETRY_DELAY_MS * (2 ** ($attempt - 1)) * 1000);
                }
            }

            throw new \RuntimeException('DeepSeek API : nombre de tentatives dépassé.');
        } catch (\Exception $e) {
            $this->logger->error('[DeepSeek Error] : ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Appel API réel avec historique (ancienne logique de call).
     */
    private function callApiWithHistory(array $messages, array $options = []): string
    {
        try {
            $payload = $this->buildHistoryPayload($messages, stream: false, options: $options);
            $attempt = 0;

            while ($attempt < self::MAX_RETRIES) {
                $attempt++;
                try {
                    $this->logger->info('[DeepSeek] Tentative d\'appel historique #{attempt}', ['attempt' => $attempt]);

                    $response = $this->httpClient->request('POST', $this->apiUrl . '/chat/completions', [
                        'headers' => $this->buildHeaders(),
                        'json'    => $payload,
                        'timeout' => self::TIMEOUT_SECONDS,
                    ]);

                    $data = $response->toArray();
                    $this->lastUsage = $data['usage'] ?? null;

                    $content = $data['choices'][0]['message']['content'] ?? null;

                    if ($content === null) {
                        throw new \UnexpectedValueException('La réponse DeepSeek ne contient pas de contenu.');
                    }

                    $this->logger->info('[DeepSeek] Appel historique réussi', [
                        'tokens_used' => $data['usage']['total_tokens'] ?? 0,
                    ]);

                    return $content;

                } catch (TransportExceptionInterface | \UnexpectedValueException $e) {
                    $this->logger->warning('[DeepSeek] Échec tentative historique #{attempt}: {error}', [
                        'attempt' => $attempt,
                        'error'   => $e->getMessage(),
                    ]);

                    if ($attempt >= self::MAX_RETRIES) {
                        throw new \RuntimeException(
                            sprintf('DeepSeek (historique) indisponible après %d tentatives : %s', self::MAX_RETRIES, $e->getMessage()),
                            0,
                            $e
                        );
                    }

                    usleep(self::RETRY_DELAY_MS * (2 ** ($attempt - 1)) * 1000);
                }
            }

            throw new \RuntimeException('DeepSeek (historique) : nombre de tentatives dépassé.');
        } catch (\Exception $e) {
            $this->logger->error('[DeepSeek History Error] : ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Appel en streaming SSE.
     * Appelle le callback pour chaque fragment de texte reçu.
     */
    public function stream(string $prompt, callable $callback, array $options = []): void
    {
        try {
            $payload = $this->buildPayload($prompt, stream: true, options: $options);
            $attempt = 0;

            while ($attempt < self::MAX_RETRIES) {
                $attempt++;
                try {
                    $this->logger->info('[DeepSeek] Tentative streaming #{attempt}', ['attempt' => $attempt]);

                    $response = $this->httpClient->request('POST', $this->apiUrl . '/chat/completions', [
                        'headers' => $this->buildHeaders(),
                        'json'    => $payload,
                        'timeout' => self::TIMEOUT_SECONDS,
                        'buffer'  => false, // important pour le streaming
                    ]);

                    // Lecture du flux SSE avec bufferisation des lignes
                    $buffer = '';
                    foreach ($this->httpClient->stream($response) as $chunk) {
                        if ($chunk->isLast()) {
                            break;
                        }

                        $buffer .= $chunk->getContent();

                        while (($pos = strpos($buffer, "\n")) !== false) {
                            $line = substr($buffer, 0, $pos);
                            $buffer = substr($buffer, $pos + 1);

                            $line = trim($line);
                            if ($line === '') {
                                continue;
                            }

                            // Format SSE : "data: {...}" ou "data: [DONE]"
                            if (!str_starts_with($line, 'data: ')) {
                                continue;
                            }

                            $jsonStr = substr($line, 6);

                            if ($jsonStr === '[DONE]') {
                                break 2; // Sort de la boucle while et foreach
                            }

                            $data = json_decode($jsonStr, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $content = $data['choices'][0]['delta']['content'] ?? null;
                                if ($content !== null) {
                                    $callback($content);
                                }
                            }
                        }
                    }

                    $this->logger->info('[DeepSeek] Streaming terminé.');
                    return;

                } catch (TransportExceptionInterface $e) {
                    $this->logger->warning('[DeepSeek] Échec streaming tentative #{attempt}: {error}', [
                        'attempt' => $attempt,
                        'error'   => $e->getMessage(),
                    ]);

                    if ($attempt >= self::MAX_RETRIES) {
                        throw new \RuntimeException(
                            sprintf('DeepSeek streaming indisponible après %d tentatives : %s', self::MAX_RETRIES, $e->getMessage()),
                            0,
                            $e
                        );
                    }

                    usleep(self::RETRY_DELAY_MS * (2 ** ($attempt - 1)) * 1000);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('[DeepSeek Stream Error] : ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Appel en streaming SSE avec historique complet (bénéficie du cache contextuel).
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function streamWithHistory(array $messages, callable $callback, array $options = []): void
    {
        try {
            $payload = $this->buildHistoryPayload($messages, stream: true, options: $options);
            $attempt = 0;

            while ($attempt < self::MAX_RETRIES) {
                $attempt++;
                try {
                    $this->logger->info('[DeepSeek] Tentative streaming historique #{attempt}', ['attempt' => $attempt]);

                    $response = $this->httpClient->request('POST', $this->apiUrl . '/chat/completions', [
                        'headers' => $this->buildHeaders(),
                        'json'    => $payload,
                        'timeout' => self::TIMEOUT_SECONDS,
                        'buffer'  => false, // important pour le streaming
                    ]);

                    // Lecture du flux SSE avec bufferisation des lignes
                    $buffer = '';
                    foreach ($this->httpClient->stream($response) as $chunk) {
                        if ($chunk->isLast()) {
                            break;
                        }

                        $buffer .= $chunk->getContent();

                        while (($pos = strpos($buffer, "\n")) !== false) {
                            $line = substr($buffer, 0, $pos);
                            $buffer = substr($buffer, $pos + 1);

                            $line = trim($line);
                            if ($line === '') {
                                continue;
                            }

                            // Format SSE : "data: {...}" ou "data: [DONE]"
                            if (!str_starts_with($line, 'data: ')) {
                                continue;
                            }

                            $jsonStr = substr($line, 6);

                            if ($jsonStr === '[DONE]') {
                                break 2; // Sort de la boucle while et foreach
                            }

                            $data = json_decode($jsonStr, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $content = $data['choices'][0]['delta']['content'] ?? null;
                                if ($content !== null) {
                                    $callback($content);
                                }
                            }
                        }
                    }

                    $this->logger->info('[DeepSeek] Streaming historique terminé.');
                    return;

                } catch (TransportExceptionInterface $e) {
                    $this->logger->warning('[DeepSeek] Échec streaming historique tentative #{attempt}: {error}', [
                        'attempt' => $attempt,
                        'error'   => $e->getMessage(),
                    ]);

                    if ($attempt >= self::MAX_RETRIES) {
                        throw new \RuntimeException(
                            sprintf('DeepSeek streaming historique indisponible après %d tentatives : %s', self::MAX_RETRIES, $e->getMessage()),
                            0,
                            $e
                        );
                    }

                    usleep(self::RETRY_DELAY_MS * (2 ** ($attempt - 1)) * 1000);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('[DeepSeek Stream History Error] : ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Construit le payload commun pour les appels API.
     */
    private function buildPayload(string $prompt, bool $stream, array $options = []): array
    {
        $messages = [];
        
        $systemPrompt = $options['system_prompt'] ?? 'Tu es un assistant IA spécialisé dans la recherche académique, scientifique et technique pour la plateforme Djoliba. Réponds toujours de manière structurée, précise et rigoureuse. Utilise un formatage Markdown riche, clair et très lisible. Si l\'on te demande du JSON, ne renvoie STRICTEMENT QUE le code JSON valide, sans balises markdown ni texte d\'introduction ou de conclusion.';
        $systemPrompt .= $this->getActiveProjectContext();

        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return [
            'model'       => $options['model'] ?? self::DEFAULT_MODEL,
            'messages'    => $messages,
            'stream'      => $stream,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens'] ?? 4096,
        ];
    }

    /**
     * Construit le payload commun pour les appels API avec historique complet.
     */
    private function buildHistoryPayload(array $messages, bool $stream, array $options = []): array
    {
        $payloadMessages = [];
        
        // Vérifier si un message 'system' est déjà présent dans l'historique fourni
        $hasSystem = false;
        foreach ($messages as $msg) {
            if (isset($msg['role']) && $msg['role'] === 'system') {
                $hasSystem = true;
                break;
            }
        }
        
        if (!$hasSystem) {
            $systemPrompt = $options['system_prompt'] ?? 'Tu es un assistant IA spécialisé dans la recherche académique, scientifique et technique pour la plateforme Djoliba. Réponds toujours de manière structurée, précise et rigoureuse. Utilise un formatage Markdown riche, clair et très lisible. Si l\'on te demande du JSON, ne renvoie STRICTEMENT QUE le code JSON valide, sans balises markdown ni texte d\'introduction ou de conclusion.';
            $systemPrompt .= $this->getActiveProjectContext();
            $payloadMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            $role = $message['role'] === 'ai' ? 'assistant' : $message['role'];
            $payloadMessages[] = [
                'role' => $role,
                'content' => $message['content']
            ];
        }

        return [
            'model'       => $options['model'] ?? self::DEFAULT_MODEL,
            'messages'    => $payloadMessages,
            'stream'      => $stream,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens'] ?? 4096,
        ];
    }

    /**
     * Retourne les en-têtes HTTP communs (auth Bearer).
     */
    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Vérifie si la clé API est un simple placeholder de test.
     */
    public function isApiKeyPlaceholder(): bool
    {
        return $this->apiKey === 'test_key' || empty($this->apiKey);
    }

    private function getActiveProjectContext(): string
    {
        if ($this->projectSwitcher) {
            $activeProject = $this->projectSwitcher->getActiveProject();
            if ($activeProject) {
                return sprintf(
                    "\n\nIMPORTANT : Tu es dans le contexte du projet de recherche suivant de l'utilisateur :\n- Titre : %s\n- Description : %s\nOriente tes réponses, ton vocabulaire, tes suggestions, ta reformulation et ton analyse pour être pertinents vis-à-vis de ce sujet de recherche.",
                    $activeProject->getTitle(),
                    $activeProject->getDescription() ?? 'Non renseignée'
                );
            }
        }
        return '';
    }
}