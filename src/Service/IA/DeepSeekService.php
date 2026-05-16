<?php

namespace App\Service\IA;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class DeepSeekService
{
    private const TIMEOUT_SECONDS  = 60;
    private const MAX_RETRIES      = 3;
    private const RETRY_DELAY_MS   = 500; // délai initial en millisecondes, doublé à chaque tentative

    private const DEFAULT_MODEL    = 'deepseek-chat';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(DEEPSEEK_API_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(DEEPSEEK_API_URL)%')]
        private string $apiUrl,
    ) {
    }

    /**
     * Appel API standard (non streamé).
     * Retourne la réponse texte complète de DeepSeek.
     *
     * @throws \RuntimeException Si toutes les tentatives échouent.
     */
    public function call(string $prompt, array $options = []): string
    {
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

                $data = $response->toArray(); // Décode le JSON et lance une exception si erreur HTTP

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

                // Backoff exponentiel : 500ms, 1000ms, 2000ms…
                usleep(self::RETRY_DELAY_MS * (2 ** ($attempt - 1)) * 1000);
            }
        }

        throw new \RuntimeException('DeepSeek API : nombre de tentatives dépassé.');
    }

    /**
     * Appel en streaming SSE.
     * Appelle le callback pour chaque fragment de texte reçu.
     *
     * @param callable $callback Fonction appelée à chaque chunk : function(string $chunk): void
     * @throws \RuntimeException Si toutes les tentatives échouent.
     */
    public function stream(string $prompt, callable $callback, array $options = []): void
    {
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

                // Lecture ligne par ligne du flux SSE (Server-Sent Events)
                foreach ($this->httpClient->stream($response) as $chunk) {
                    if ($chunk->isLast()) {
                        break;
                    }

                    $line = trim($chunk->getContent());

                    // Format SSE : "data: {...}" ou "data: [DONE]"
                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $jsonStr = substr($line, 6);

                    if ($jsonStr === '[DONE]') {
                        break;
                    }

                    $data = json_decode($jsonStr, true);
                    $content = $data['choices'][0]['delta']['content'] ?? null;

                    if ($content !== null) {
                        $callback($content);
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
    }

    /**
     * Construit le payload commun pour les appels API.
     */
    private function buildPayload(string $prompt, bool $stream, array $options = []): array
    {
        return [
            'model'       => $options['model'] ?? self::DEFAULT_MODEL,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
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
}
