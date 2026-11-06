<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal OpenAI Chat Completions client (tool calling). No SDK needed.
 */
class OpenAiClient
{
    /** Statuses worth a second try: rate limit, and the upstream / proxy being busy or briefly down. */
    public const TRANSIENT = [408, 409, 425, 429, 500, 502, 503, 504];

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

        $response = $this->send(fn () => Http::withToken($this->apiKey)
            ->timeout((int) config('services.openai.timeout', 60))
            ->post($this->baseUrl . '/chat/completions', $payload));

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
     * @param  array  $extra  more reference images [['bytes' => …, 'mime' => …]] (e.g. a logo to put on the clothes)
     * @return array{bytes: string, mime: string, usage: array}
     */
    public function editImage(string $bytes, string $mime, string $prompt, string $size = '1024x1024', array $extra = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า OPENAI_API_KEY ใน .env');
        }

        $response = $this->send(fn () => Http::withToken($this->apiKey)
                ->timeout((int) config('services.openai.image_timeout', 240))
                ->post($this->baseUrl . '/images/edits', [
                    'model' => config('services.openai.image_model', 'gpt-image-2'),
                    'images' => array_merge(
                        [['image_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]],
                        array_map(fn ($x) => ['image_url' => 'data:' . $x['mime'] . ';base64,' . base64_encode($x['bytes'])], $extra)
                    ),
                    'prompt' => $prompt,
                    'size' => $size,
                    'quality' => 'high',
                    'output_format' => 'jpeg',
                    'n' => 1,
                ]), 1);
        $b64 = $response->json('data.0.b64_json');
        if (! is_string($b64) || $b64 === '') {
            throw new RuntimeException('OpenAI API returned no image');
        }

        return ['bytes' => base64_decode($b64), 'mime' => 'image/' . ($response->json('output_format') ?: 'jpeg'), 'usage' => $response->json('usage', [])];
    }

    /**
     * Run the request, retrying when the connection drops or the API answers with a transient status
     * (DataCorp returns 503 "AI service is busy" under load). Waits honour Retry-After, else back off
     * 1 s, 2 s, 4 s … (OPENAI_RETRY_DELAY_MS scales this; 0 in tests). The last failure is turned into an error.
     *
     * @param  callable(): Response  $request
     */
    private function send(callable $request, ?int $retries = null): Response
    {
        $retries = $retries ?? max(0, (int) config('services.openai.retries', 2));
        $delay = max(0, (int) config('services.openai.retry_delay_ms', 1000));
        $response = null;
        $lastError = null;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $response = $request();
                $lastError = null;
            } catch (RequestException $e) {
                $response = $e->response; // Laravel 8 throws on HTTP errors when using retry(); keep the body
            } catch (ConnectionException $e) {
                $response = null;
                $lastError = $e;
            }

            if ($response && ($response->successful() || ! in_array($response->status(), self::TRANSIENT, true))) {
                break;
            }
            if ($attempt < $retries) {
                $wait = $delay * (2 ** $attempt);
                if ($response && is_numeric($response->header('Retry-After'))) {
                    $wait = min(15000, max($wait, (int) $response->header('Retry-After') * 1000));
                }
                if ($wait > 0) {
                    usleep($wait * 1000);
                }
            }
        }

        if (! $response) {
            throw new RuntimeException('เชื่อมต่อบริการ AI ไม่ได้ (' . ($lastError ? $lastError->getMessage() : 'connection failed') . ') กรุณาลองใหม่อีกครั้ง');
        }
        if (! $response->successful()) {
            $this->fail($response, $retries);
        }

        return $response;
    }

    /** @return never */
    private function fail($response, int $retries = 0): void
    {
        // DataCorp: plan expired / credits used up / unpaid → error.action_url = payment page
        if ($payUrl = $response->json('error.action_url')) {
            $message = explode(' — ', (string) $response->json('error.message'))[0];
            throw new AiPaymentRequired($message, (string) $response->json('error.code'), $payUrl, $response->status());
        }
        $error = $response->json('error.message') ?? $response->body();
        if (in_array($response->status(), self::TRANSIENT, true)) {
            $what = $response->status() === 429 ? 'ถูกจำกัดจำนวนคำขอชั่วคราว' : 'มีผู้ใช้งานมากหรือขัดข้องชั่วคราว';
            throw new RuntimeException('บริการ AI ' . $what . ' (HTTP ' . $response->status() . ($retries ? ', ลองซ้ำให้แล้ว ' . $retries . ' ครั้ง' : '') . ') กรุณาลองใหม่อีกครั้งในอีกสักครู่ — ' . mb_substr((string) $error, 0, 200));
        }
        throw new RuntimeException('OpenAI API error (' . $response->status() . '): ' . mb_substr((string) $error, 0, 300));
    }
}
