<?php declare(strict_types=1);

namespace App\AIAssistant;

use RuntimeException;

class ClaudeClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODELS_URL = 'https://api.anthropic.com/v1/models';
    private const API_VERSION = '2023-06-01';
    private const MAX_TOKENS = 4096;
    private const TIMEOUT_SECONDS = 60;

    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-20250514')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    /**
     * Apply SSL CA bundle to a cURL handle (needed on Windows where no system CA store exists).
     */
    private function applySslConfig(\CurlHandle $ch): void
    {
        $caBundle = dirname(__DIR__, 2) . '/storage/cacert.pem';
        if (file_exists($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
    }

    /**
     * Send a message to the Claude Messages API.
     *
     * @param array  $messages     Array of message objects: [['role'=>'user','content'=>'...'], ...]
     * @param string $systemPrompt System prompt providing context
     * @param int    $maxTokens    Maximum tokens in response
     *
     * @return array{content: string, usage: array, model: string, stop_reason: string}
     *
     * @throws RuntimeException on network error, API error, or invalid response
     */
    public function sendMessage(array $messages, string $systemPrompt = '', int $maxTokens = self::MAX_TOKENS): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Claude API key is not configured.');
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::API_URL);
        $this->applySslConfig($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RuntimeException('Failed to connect to Claude API: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? 'Unknown API error';
            $errorType = $data['error']['type'] ?? 'unknown';
            throw new RuntimeException(
                "Claude API error ({$errorType}): {$errorMsg}",
                $httpCode
            );
        }

        if (!isset($data['content'][0]['text'])) {
            throw new RuntimeException('Unexpected Claude API response format.');
        }

        return [
            'content'     => $data['content'][0]['text'],
            'usage'       => $data['usage'] ?? [],
            'model'       => $data['model'] ?? $this->model,
            'stop_reason' => $data['stop_reason'] ?? '',
        ];
    }

    /**
     * Fetch available models from the Anthropic Models API.
     *
     * @return array<int, array{id: string, display_name: string}>
     * @throws RuntimeException on network or API error
     */
    public function listModels(): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Claude API key is not configured.');
        }

        $models = [];
        $afterId = null;

        do {
            $url = self::MODELS_URL . '?limit=1000';
            if ($afterId !== null) {
                $url .= '&after_id=' . urlencode($afterId);
            }

            $ch = curl_init($url);
            $this->applySslConfig($ch);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET        => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $curlError !== '') {
                throw new RuntimeException('Failed to connect to Claude API: ' . $curlError);
            }

            $data = json_decode($response, true);

            if ($httpCode !== 200) {
                $errorMsg = $data['error']['message'] ?? 'Unknown API error';
                $errorType = $data['error']['type'] ?? 'unknown';
                throw new RuntimeException(
                    "Claude API error ({$errorType}): {$errorMsg}",
                    $httpCode
                );
            }

            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new RuntimeException('Unexpected Claude API response format.');
            }

            foreach ($data['data'] as $model) {
                $models[] = [
                    'id'           => $model['id'] ?? '',
                    'display_name' => $model['display_name'] ?? $model['id'] ?? '',
                ];
            }

            $hasMore = $data['has_more'] ?? false;
            $afterId = $data['last_id'] ?? null;
        } while ($hasMore && $afterId !== null);

        usort($models, fn(array $a, array $b) => strcasecmp($a['display_name'], $b['display_name']));

        return $models;
    }
}
