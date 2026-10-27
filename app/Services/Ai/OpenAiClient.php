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

        $response = Http::withToken($this->apiKey)
            ->timeout((int) config('services.openai.timeout', 60))
            ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
            ->post($this->baseUrl . '/chat/completions', $payload);

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new RuntimeException('OpenAI API error (' . $response->status() . '): ' . mb_substr((string) $error, 0, 300));
        }

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new RuntimeException('OpenAI API returned an unexpected response');
        }
        $message['usage'] = $response->json('usage', []);

        return $message;
    }
}
