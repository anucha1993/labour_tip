<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal OpenAI Chat Completions client (tool calling). No SDK needed.
 */
class OpenAiClient
{
    public function __construct(
        private ?string $apiKey = null,
        private ?string $model = null,
        private ?string $baseUrl = null,
    ) {
        $this->apiKey = $apiKey ?? config('services.openai.key');
        $this->model = $model ?? config('services.openai.model', 'gpt-4o-mini');
        $this->baseUrl = rtrim($baseUrl ?? config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param  array  $messages  OpenAI-format messages
     * @param  array  $tools  OpenAI-format tool definitions
     * @return array  The assistant message (role, content, tool_calls) plus 'usage'
     */
    public function chat(array $messages, array $tools = [], float $temperature = 0.2): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า OPENAI_API_KEY ใน .env');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout((int) config('services.openai.timeout', 60))
                ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
                ->post($this->baseUrl . '/chat/completions', $payload);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $response = $e->response; // Laravel 8: retry() throws on any HTTP error — read the error body below
        }

        if (! $response->successful()) {
            $this->fail($response);
        }

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new RuntimeException('OpenAI API returned an unexpected response');
        }
        $message['usage'] = $response->json('usage', []);

        return $message;
    }

    /**
     * Edit a picture with GPT Image (POST /images/edits, JSON body — works directly and through DataCorp).
     *
     * @param  string  $bytes  source image (JPEG/PNG/WEBP)
     * @return array{bytes: string, mime: string, usage: array}
     */
    public function editImage(string $bytes, string $mime, string $prompt, string $size = '1024x1024'): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า OPENAI_API_KEY ใน .env');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout((int) config('services.openai.image_timeout', 240))
                ->retry(1, 1000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
                ->post($this->baseUrl . '/images/edits', [
                    'model' => config('services.openai.image_model', 'gpt-image-2'),
                    'images' => [['image_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]],
                    'prompt' => $prompt,
                    'size' => $size,
                    'quality' => 'high',
                    'output_format' => 'jpeg',
                    'n' => 1,
                ]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $response = $e->response;
        }

        if (! $response->successful()) {
            $this->fail($response);
        }
        $b64 = $response->json('data.0.b64_json');
        if (! is_string($b64) || $b64 === '') {
            throw new RuntimeException('OpenAI API returned no image');
        }

        return ['bytes' => base64_decode($b64), 'mime' => 'image/' . ($response->json('output_format') ?: 'jpeg'), 'usage' => $response->json('usage', [])];
    }

    /** @return never */
    private function fail($response): void
    {
        // DataCorp: plan expired / credits used up / unpaid → error.action_url = payment page
        if ($payUrl = $response->json('error.action_url')) {
            $message = explode(' — ', (string) $response->json('error.message'))[0];
            throw new AiPaymentRequired($message, (string) $response->json('error.code'), $payUrl, $response->status());
        }
        $error = $response->json('error.message') ?? $response->body();
        throw new RuntimeException('OpenAI API error (' . $response->status() . '): ' . mb_substr((string) $error, 0, 300));
    }
}
