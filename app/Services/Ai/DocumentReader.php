<?php

namespace App\Services\Ai;

use App\Models\labour\LabourDocument;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Lets the assistant "read" an uploaded document: the file (PDF or image, local disk or R2)
 * is sent to the OpenAI vision model, which returns the text and key fields it can see.
 * Results are cached per file so a document is only read once.
 */
class DocumentReader
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    private const CACHE_DAYS = 7;

    public function __construct(private OpenAiClient $client)
    {
    }

    /** @return array{document:string,file:string,content:string,cached:bool,usage?:array} */
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

        $cacheKey = 'ai-doc-read:' . md5(($doc->disk ?: 'public') . '|' . $doc->path . '|' . $size);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['content'])) {
            return $cached + ['cached' => true];
        }

        $bytes = (string) $doc->storage()->get($doc->path);
        if ($bytes === '') {
            throw new RuntimeException('ไฟล์ว่างเปล่า');
        }
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);

        $part = $mime === 'application/pdf'
            ? ['type' => 'file', 'file' => ['filename' => $doc->fileName(), 'file_data' => $dataUrl]]
            : ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']];

        $reply = $this->client->chat([
            ['role' => 'system', 'content' => 'คุณคืออุปกรณ์ OCR สำหรับเอกสารแรงงานต่างด้าว (หนังสือเดินทาง วีซ่า ใบอนุญาตทำงาน ใบรับรอง) อ่านทุกอย่างที่มองเห็นในเอกสารอย่างซื่อสัตย์ ห้ามเดา ถ้าอ่านไม่ออกให้บอกว่าอ่านไม่ออก'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "อ่านเอกสาร \"{$doc->name}\" (ไฟล์ {$doc->fileName()}) แล้วตอบเป็นข้อความธรรมดา 2 ส่วน:\n"
                    . "1) ฟิลด์สำคัญที่พบ เป็นบรรทัด ชื่อฟิลด์: ค่า เช่น ประเภทเอกสาร, ชื่อ-สกุล, เลขที่เอกสาร, สัญชาติ, เพศ, วันเกิด, วันออก, วันหมดอายุ, สถานที่ออก, เลข MRZ (ถ้ามี) ค่าที่ไม่พบให้เขียนว่า ไม่พบ\n"
                    . '2) ข้อความทั้งหมดที่อ่านได้จากเอกสาร (ย่อหน้าเดียว ไม่ต้องจัดรูปแบบ)'],
                $part,
            ]],
        ], [], 0.0);

        $content = trim((string) ($reply['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('โมเดลไม่สามารถอ่านเนื้อหาในไฟล์ได้');
        }

        $result = [
            'document' => (string) $doc->name,
            'file' => $doc->fileName(),
            'content' => mb_substr($content, 0, 6000),
            'usage' => $reply['usage'] ?? [],
        ];
        Cache::put($cacheKey, $result, now()->addDays(self::CACHE_DAYS));

        return $result + ['cached' => false];
    }
}
