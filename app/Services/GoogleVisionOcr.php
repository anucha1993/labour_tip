<?php

namespace App\Services;

use App\Support\PdfInfo;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Cloud Vision OCR (DOCUMENT_TEXT_DETECTION) for PDFs and images.
 * PDFs are read page by page (the sync endpoint takes at most 5 pages per request).
 */
class GoogleVisionOcr
{
    private const FILES = 'https://vision.googleapis.com/v1/files:annotate';
    private const IMAGES = 'https://vision.googleapis.com/v1/images:annotate';
    private const PAGES_PER_REQUEST = 5;
    public const MAX_PAGES = 30;

    public function isConfigured(): bool
    {
        return $this->key() !== '';
    }

    /**
     * @return array{pages:int, texts:array<int,string>} page number => text ('' when the page has no readable text)
     */
    public function pdf(string $bytes): array
    {
        $total = PdfInfo::pageCount($bytes) ?: 1;
        $total = min($total, self::MAX_PAGES);
        $content = base64_encode($bytes);
        $texts = [];

        for ($first = 1; $first <= $total; $first += self::PAGES_PER_REQUEST) {
            $pages = range($first, min($first + self::PAGES_PER_REQUEST - 1, $total));
            $res = $this->post(self::FILES, ['requests' => [[
                'inputConfig' => ['content' => $content, 'mimeType' => 'application/pdf'],
                'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                'pages' => $pages,
            ]]]);

            $fileResponse = $res['responses'][0] ?? [];
            if (! empty($fileResponse['error']['message'])) {
                throw new RuntimeException('Google Vision: ' . $fileResponse['error']['message']);
            }
            if (! empty($fileResponse['totalPages'])) {
                $total = min((int) $fileResponse['totalPages'], self::MAX_PAGES);
            }
            foreach ($fileResponse['responses'] ?? [] as $i => $page) {
                $n = (int) ($page['context']['pageNumber'] ?? ($pages[$i] ?? 0));
                if ($n > 0) {
                    $texts[$n] = trim((string) ($page['fullTextAnnotation']['text'] ?? ''));
                }
            }
            if (empty($fileResponse['responses'])) {
                break;
            }
        }

        for ($n = 1; $n <= $total; $n++) {
            $texts[$n] = $texts[$n] ?? '';
        }
        ksort($texts);

        return ['pages' => $total, 'texts' => $texts];
    }

    /** @return array{pages:int, texts:array<int,string>} */
    public function image(string $bytes): array
    {
        $res = $this->post(self::IMAGES, ['requests' => [[
            'image' => ['content' => base64_encode($bytes)],
            'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
        ]]]);
        $r = $res['responses'][0] ?? [];
        if (! empty($r['error']['message'])) {
            throw new RuntimeException('Google Vision: ' . $r['error']['message']);
        }

        return ['pages' => 1, 'texts' => [1 => trim((string) ($r['fullTextAnnotation']['text'] ?? ''))]];
    }

    private function post(string $url, array $body): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('GOOGLE_VISION_API_KEY is not set');
        }
        $res = Http::asJson()->timeout(90)->post($url . '?key=' . urlencode($this->key()), $body);
        if (! $res->successful()) {
            throw new RuntimeException('Google Vision ' . $res->status() . ': ' . ($res->json('error.message') ?? mb_substr($res->body(), 0, 200)));
        }

        return (array) $res->json();
    }

    private function key(): string
    {
        return trim((string) (config('services.google.vision_key') ?: config('services.google.translate_key')));
    }
}
