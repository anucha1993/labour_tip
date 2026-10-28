<?php

namespace App\Services\Ai;

use App\Models\labour\LabourDocument;
use App\Services\GoogleVisionOcr;
use App\Support\PdfInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Lets the assistant "read" an uploaded document (PDF or image, local disk or R2).
 *
 * 1. Google Vision OCR reads every page and returns the text per page (accurate page count, strong on MRZ).
 *    The chat model then structures that text (key fields + per-page summary).
 * 2. When Google Vision is not configured, fails, or finds no text (pure photos), the file itself is sent
 *    to the OpenAI vision model instead.
 *
 * Results are cached per file so a document is only read once.
 */
class DocumentReader
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    private const CACHE_DAYS = 7;

    public function __construct(private OpenAiClient $client, private GoogleVisionOcr $vision)
    {
    }

    /** @return array{document:string,file:string,pages:?int,engine:string,content:string,cached:bool,usage?:array} */
    public function read(LabourDocument $doc): array
    {
        if (! $doc->exists()) {
            throw new RuntimeException('ไม่พบไฟล์ ' . $doc->fileName() . ' ในที่เก็บไฟล์');
        }

        $ext = strtolower(pathinfo($doc->fileName(), PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
        if (! $mime) {
            throw new RuntimeException('อ่านได้เฉพาะไฟล์ PDF, JPG, PNG หรือ WEBP (ไฟล์นี้เป็น .' . $ext . ')');
        }

        $size = (int) ($doc->size ?: $doc->storage()->size($doc->path));
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('ไฟล์ใหญ่เกิน ' . (self::MAX_BYTES / 1024 / 1024) . ' MB จึงไม่สามารถอ่านได้');
        }

        $cacheKey = 'ai-doc-read:v2:' . md5(($doc->disk ?: 'public') . '|' . $doc->path . '|' . $size);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['content'])) {
            return $cached + ['cached' => true];
        }

        $bytes = (string) $doc->storage()->get($doc->path);
        if ($bytes === '') {
            throw new RuntimeException('ไฟล์ว่างเปล่า');
        }
        $isPdf = $mime === 'application/pdf';

        $result = null;
        if ($this->vision->isConfigured()) {
            try {
                $ocr = $isPdf ? $this->vision->pdf($bytes) : $this->vision->image($bytes);
                if (array_filter($ocr['texts'], fn ($t) => trim($t) !== '')) {
                    $result = $this->structure($doc, $ocr['pages'], $ocr['texts']);
                } else {
                    Log::info('Google Vision found no text, falling back to OpenAI vision', ['file' => $doc->fileName()]);
                }
            } catch (\Throwable $e) {
                Log::warning('Google Vision OCR failed, falling back to OpenAI vision', ['file' => $doc->fileName(), 'error' => $e->getMessage()]);
            }
        }
        if ($result === null) {
            $result = $this->readWithOpenAiVision($doc, $bytes, $mime, $isPdf ? PdfInfo::pageCount($bytes) : 1);
        }

        $result = ['document' => (string) $doc->name, 'file' => $doc->fileName()] + $result;
        Cache::put($cacheKey, $result, now()->addDays(self::CACHE_DAYS));

        return $result + ['cached' => false];
    }

    /** Google OCR text per page -> key fields + per-page summary written by the chat model. */
    private function structure(LabourDocument $doc, int $pages, array $texts): array
    {
        $pagesText = '';
        foreach ($texts as $n => $t) {
            $pagesText .= "===== หน้า {$n} =====\n" . (trim($t) !== '' ? mb_substr($t, 0, 4000) : '(ไม่พบข้อความที่อ่านได้ในหน้านี้)') . "\n\n";
        }

        $reply = $this->client->chat([
            ['role' => 'system', 'content' => 'คุณจัดระเบียบผล OCR ของเอกสารแรงงานต่างด้าว (หนังสือเดินทาง วีซ่า ใบอนุญาตทำงาน ใบรับรอง) ใช้เฉพาะข้อความที่ให้มา ห้ามเดา ถ้าไม่พบให้เขียนว่า ไม่พบ'],
            ['role' => 'user', 'content' => "เอกสาร \"{$doc->name}\" (ไฟล์ {$doc->fileName()}) มี {$pages} หน้า ผล OCR แต่ละหน้าอยู่ด้านล่าง\n"
                . "ตอบเป็นข้อความธรรมดา 3 ส่วน:\n"
                . "1) จำนวนหน้า: {$pages}\n"
                . "2) ฟิลด์สำคัญที่พบ เป็นบรรทัด ชื่อฟิลด์: ค่า เช่น ประเภทเอกสาร, ชื่อ-สกุล, เลขที่เอกสาร, สัญชาติ, เพศ, วันเกิด, วันออก, วันหมดอายุ, สถานที่ออก, เลข MRZ (ถ้ามี)\n"
                . "3) เนื้อหาแต่ละหน้า: ขึ้นต้นบรรทัดว่า หน้า 1:, หน้า 2:, ... สรุปว่าหน้านั้นคืออะไร (หน้าข้อมูลส่วนตัว หน้าวีซ่า ตราประทับเข้า-ออก หน้าว่าง ฯลฯ) และข้อความ วันที่ เลขที่สำคัญที่พบในหน้านั้น\n\n"
                . $pagesText],
        ], [], 0.0);

        $content = trim((string) ($reply['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('โมเดลไม่สามารถสรุปผล OCR ได้');
        }

        return [
            'pages' => $pages,
            'engine' => 'google-vision',
            'content' => mb_substr($content, 0, 9000),
            'ocr_text' => mb_substr(trim(implode("\n", $texts)), 0, 6000),
            'usage' => $reply['usage'] ?? [],
        ];
    }

    /** Fallback: hand the whole file to the OpenAI vision model. */
    private function readWithOpenAiVision(LabourDocument $doc, string $bytes, string $mime, ?int $pages): array
    {
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $part = $mime === 'application/pdf'
            ? ['type' => 'file', 'file' => ['filename' => $doc->fileName(), 'file_data' => $dataUrl]]
            : ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']];
        $pageHint = $pages ? "เอกสารนี้มี {$pages} หน้า" : 'เอกสารนี้อาจมีหลายหน้า';

        $reply = $this->client->chat([
            ['role' => 'system', 'content' => 'คุณคืออุปกรณ์ OCR สำหรับเอกสารแรงงานต่างด้าว (หนังสือเดินทาง วีซ่า ใบอนุญาตทำงาน ใบรับรอง) อ่านทุกอย่างที่มองเห็นในเอกสารอย่างซื่อสัตย์ ห้ามเดา ถ้าอ่านไม่ออกให้บอกว่าอ่านไม่ออก'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "อ่านเอกสาร \"{$doc->name}\" (ไฟล์ {$doc->fileName()}) {$pageHint} ให้อ่านทุกหน้า แล้วตอบเป็นข้อความธรรมดา 3 ส่วน:\n"
                    . "1) จำนวนหน้า: N\n"
                    . "2) ฟิลด์สำคัญที่พบ เป็นบรรทัด ชื่อฟิลด์: ค่า เช่น ประเภทเอกสาร, ชื่อ-สกุล, เลขที่เอกสาร, สัญชาติ, เพศ, วันเกิด, วันออก, วันหมดอายุ, สถานที่ออก, เลข MRZ (ถ้ามี) ค่าที่ไม่พบให้เขียนว่า ไม่พบ\n"
                    . '3) เนื้อหาแต่ละหน้า: ขึ้นต้นบรรทัดว่า หน้า 1:, หน้า 2:, ... แล้วตามด้วยสิ่งที่เห็นในหน้านั้น (ประเภทหน้า และข้อความ/วันที่/เลขที่ที่อ่านได้ทั้งหมด)'],
                $part,
            ]],
        ], [], 0.0);

        $content = trim((string) ($reply['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('โมเดลไม่สามารถอ่านเนื้อหาในไฟล์ได้');
        }

        return [
            'pages' => $pages,
            'engine' => 'openai-vision',
            'content' => mb_substr($content, 0, 9000),
            'usage' => $reply['usage'] ?? [],
        ];
    }
}
