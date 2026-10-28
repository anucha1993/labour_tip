<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Google Cloud Translation v2 (API key). Used to produce the English
 * company name / street line shown in the Worker App. Results are cached per text.
 */
class GoogleTranslate
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    public function isConfigured(): bool
    {
        return trim((string) config('services.google.translate_key')) !== '';
    }

    /** Translate Thai (or anything) to English. Returns '' for blank input. */
    public function toEnglish(string $text): string
    {
        return $this->translate([$text], 'en')[0] ?? '';
    }

    /**
     * @param  string[]  $texts
     * @return string[] same order as input; '' when a line could not be translated
     *
     * @throws RuntimeException when the API key is missing or Google answers with an error
     */
    public function translate(array $texts, string $target = 'en'): array
    {
        $texts = array_values(array_map(fn ($t) => trim((string) $t), $texts));
        if (! array_filter($texts, fn ($t) => $t !== '')) {
            return array_fill(0, count($texts), '');
        }
        if (! $this->isConfigured()) {
            throw new RuntimeException('GOOGLE_TRANSLATE_API_KEY is not set');
        }

        $out = array_fill(0, count($texts), '');
        foreach (array_chunk(array_keys($texts), 100) as $keys) {
            $q = array_map(fn ($k) => $texts[$k] === '' ? ' ' : $texts[$k], $keys);
            $res = Http::asJson()->timeout(30)->post(self::ENDPOINT . '?key=' . urlencode(config('services.google.translate_key')), [
                'q' => $q,
                'target' => $target,
                'format' => 'text',
            ]);
            if (! $res->successful()) {
                throw new RuntimeException('Google Translate ' . $res->status() . ': ' . ($res->json('error.message') ?? mb_substr($res->body(), 0, 200)));
            }
            $rows = $res->json('data.translations') ?? [];
            foreach ($keys as $i => $k) {
                $out[$k] = $texts[$k] === '' ? '' : trim((string) ($rows[$i]['translatedText'] ?? ''));
            }
        }

        return $out;
    }
}
