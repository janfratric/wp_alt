<?php declare(strict_types=1);

namespace App\AIAssistant;

use RuntimeException;

class GeminiClient
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const DEFAULT_MODEL = 'gemini-2.5-flash';
    private const DEFAULT_TIMEOUT = 30;

    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct(string $apiKey, string $model = self::DEFAULT_MODEL, array $options = [])
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
    }

    private function applySslConfig(\CurlHandle $ch): void
    {
        $caBundle = dirname(__DIR__, 2) . '/storage/cacert.pem';
        if (file_exists($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
    }

    /**
     * Transcribe audio using Gemini's generateContent endpoint.
     *
     * @param string $audioData Raw audio bytes
     * @param string $mimeType  e.g. "audio/webm", "audio/ogg;codecs=opus"
     * @return string Transcribed text
     * @throws RuntimeException
     */
    public function transcribeAudio(string $audioData, string $mimeType): string
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Google API key is not configured.');
        }

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Transcribe this audio. Return only the transcribed text, nothing else.'],
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data' => base64_encode($audioData),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $url = self::API_URL . urlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $phpTimeLimit = $this->timeout + 15;
        if ((int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < $phpTimeLimit) {
            @set_time_limit($phpTimeLimit);
        }

        $ch = curl_init($url);
        $this->applySslConfig($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RuntimeException('Failed to connect to Google API: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? 'Unknown API error';
            throw new RuntimeException('Google API error: ' . $errorMsg, $httpCode);
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text === null) {
            throw new RuntimeException('Unexpected Google API response format.');
        }

        return trim($text);
    }
}
