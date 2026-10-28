<?php

namespace App\Services\Ai;

use App\Exports\AiLabourExport;
use App\Services\ExpiryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Orchestrates one chat turn: system prompt + history + user message -> OpenAI (tool calling loop) -> answer.
 */
class LabourAssistant
{
    private const MAX_TOOL_ROUNDS = 6;
    private const MAX_HISTORY = 20;

    public function __construct(private OpenAiClient $client, private LabourQueryService $query, private DocumentReader $reader)
    {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @param  array  $history  [{role: user|assistant, content: string}, ...] (older turns)
     * @return array{answer: string, reports: array, usage: array, tools_used: array}
     */
    public function ask(string $userMessage, array $history, string $userName): array
    {
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($userName)]];
        foreach (array_slice($history, -self::MAX_HISTORY) as $m) {
            if (in_array($m['role'] ?? '', ['user', 'assistant'], true) && is_string($m['content'] ?? null) && $m['content'] !== '') {
                $messages[] = ['role' => $m['role'], 'content' => mb_substr($m['content'], 0, 4000)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, 4000)];

        $reports = [];
        $toolsUsed = [];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $reply = $this->client->chat($messages, $this->tools());
            $usage['prompt_tokens'] += (int) ($reply['usage']['prompt_tokens'] ?? 0);
            $usage['completion_tokens'] += (int) ($reply['usage']['completion_tokens'] ?? 0);

            $toolCalls = $reply['tool_calls'] ?? [];
            if (! $toolCalls) {
                return [
                    'answer' => trim((string) ($reply['content'] ?? '')) ?: 'ขออภัย ไม่สามารถสร้างคำตอบได้',
                    'reports' => $reports,
                    'usage' => $usage,
                    'tools_used' => $toolsUsed,
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $reply['content'] ?? null, 'tool_calls' => $toolCalls];

            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
                $toolsUsed[] = $name;
                try {
                    $result = $this->runTool($name, $args, $reports);
                } catch (\Throwable $e) {
                    Log::warning('AI tool failed', ['tool' => $name, 'args' => $args, 'error' => $e->getMessage()]);
                    $result = ['error' => 'เครื่องมือทำงานไม่สำเร็จ: ' . $e->getMessage()];
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                ];
            }
        }

        return [
            'answer' => 'คำถามนี้ซับซ้อนเกินไป กรุณาแบ่งเป็นคำถามย่อยหรือระบุเงื่อนไขให้ชัดขึ้น',
            'reports' => $reports,
            'usage' => $usage,
            'tools_used' => $toolsUsed,
        ];
    }

    // ------------------------------------------------------------------ tools

    private function runTool(string $name, array $args, array &$reports): array
    {
        return match ($name) {
            'get_overview' => $this->query->overview(),
            'get_expiry_summary' => $this->query->expirySummary(),
            'search_labours' => $this->query->search($args['filters'] ?? []),
            'count_labours' => $this->query->count(($args['filters'] ?? []) + ['group_by' => $args['group_by'] ?? null]),
            'get_labour_detail' => $this->query->detail((string) ($args['query'] ?? '')),
            'list_companies' => $this->query->listCompanies($args['keyword'] ?? null),
            'list_agencies' => $this->query->listAgencies(),
            'create_excel_report' => $this->createReport($args, $reports),
            'read_labour_document' => $this->readDocument((string) ($args['query'] ?? ''), (string) ($args['document'] ?? '')),
            default => ['error' => 'unknown tool'],
        };
    }

    /** Send the file to the vision model and hand its reading back to the chat model. */
    private function readDocument(string $query, string $document): array
    {
        [$labour, $doc, $candidates] = $this->query->findDocument($query, $document);
        if (! $labour) {
            return ['error' => 'ไม่พบแรงงานตามคำค้น "' . $query . '"'];
        }
        if (! $doc) {
            return [
                'error' => $candidates ? 'ระบุเอกสารไม่ชัดเจน กรุณาเลือกจาก document_key ด้านล่าง' : 'แรงงานคนนี้ยังไม่มีไฟล์เอกสารในระบบ',
                'labour' => trim($labour->labour_prefix . '. ' . $labour->labour_fullname),
                'documents' => $candidates,
            ];
        }

        return ['labour' => trim($labour->labour_prefix . '. ' . $labour->labour_fullname), 'passport_number' => $labour->labour_passport_number]
            + $this->reader->read($doc);
    }

    private function createReport(array $args, array &$reports): array
    {
        $filters = $args['filters'] ?? [];
        $columns = is_array($args['columns'] ?? null) && $args['columns'] ? $args['columns'] : LabourQueryService::DEFAULT_COLUMNS;
        $title = mb_substr(trim((string) ($args['title'] ?? 'รายงานแรงงาน')), 0, 80) ?: 'รายงานแรงงาน';

        $rows = $this->query->exportRows($filters);
        if ($rows->isEmpty()) {
            return ['created' => false, 'rows' => 0, 'message' => 'ไม่พบข้อมูลตามเงื่อนไข จึงไม่ได้สร้างไฟล์'];
        }

        $export = new AiLabourExport($rows, $columns, $title);
        $file = 'ai-reports/' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(8)) . '.xlsx';
        Excel::store($export, $file, 'local');
        $this->cleanupOldReports();

        $report = [
            'title' => $title,
            'rows' => $rows->count(),
            'truncated' => $rows->count() >= LabourQueryService::MAX_EXPORT_ROWS,
            'columns' => $export->columnKeys(),
            'download_url' => route('ai.report', ['file' => basename($file)]),
            'file' => basename($file),
        ];
        $reports[] = $report;

        return ['created' => true] + $report;
    }

    private function cleanupOldReports(): void
    {
        try {
            $disk = Storage::disk('local');
            foreach ($disk->files('ai-reports') as $path) {
                if ($disk->lastModified($path) < now()->subDay()->getTimestamp()) {
                    $disk->delete($path);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function tools(): array
    {
        $filters = [
            'type' => 'object',
            'description' => 'เงื่อนไขกรองข้อมูลแรงงาน ใส่เฉพาะที่จำเป็น',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => 'ค้นหาจากชื่อ เลขพาสปอร์ต เลขวีซ่า เลขใบอนุญาต รหัสพนักงาน'],
                'company' => ['type' => 'string', 'description' => 'ชื่อบริษัท/นายจ้าง (ค้นหาแบบบางส่วน)'],
                'agency' => ['type' => 'string', 'description' => 'ชื่อเอเจนซี่'],
                'contact_sale' => ['type' => 'string', 'description' => 'ชื่อผู้ประสานงานการตลาด'],
                'nationality' => ['type' => 'string', 'description' => 'สัญชาติ: myanmar/พม่า, cambodia/กัมพูชา, lao/ลาว, vietnam/เวียดนาม'],
                'status_job' => ['type' => 'string', 'enum' => ['job', 'resign', 'escape'], 'description' => 'job=ทำงาน resign=ลาออก escape=หลบหนี'],
                'status' => ['type' => 'string', 'enum' => ['enable', 'disable'], 'description' => 'สถานะในระบบ'],
                'sex' => ['type' => 'string', 'enum' => ['male', 'female']],
                'date_field' => ['type' => 'string', 'enum' => array_keys(LabourQueryService::DATE_FIELDS), 'description' => 'ฟิลด์วันที่สำหรับ date_from/date_to'],
                'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'expiring' => [
                    'type' => 'object',
                    'description' => 'เอกสารที่จะหมดอายุภายใน N วันนับจากวันนี้ (ค่าเริ่มต้น: ตามการตั้งค่าแจ้งเตือนของระบบ) นับเฉพาะแรงงานที่ทำงานอยู่',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => array_keys(ExpiryService::TYPES), 'description' => 'visa=วีซ่า passport=หนังสือเดินทาง work=ใบอนุญาตทำงาน ninety=รายงานตัว 90 วัน'],
                        'within_days' => ['type' => 'integer'],
                        'include_expired' => ['type' => 'boolean', 'description' => 'รวมที่หมดอายุไปแล้วด้วย'],
                    ],
                    'required' => ['type'],
                ],
                'expired' => [
                    'type' => 'object',
                    'description' => 'เอกสารที่หมดอายุไปแล้ว (ก่อนวันนี้) นับเฉพาะแรงงานที่ทำงานอยู่',
                    'properties' => ['type' => ['type' => 'string', 'enum' => array_keys(ExpiryService::TYPES)]],
                    'required' => ['type'],
                ],
                'order_by' => ['type' => 'string', 'enum' => array_merge(array_keys(LabourQueryService::DATE_FIELDS), ['fullname', 'company'])],
                'order_dir' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit' => ['type' => 'integer', 'description' => 'จำนวนแถวสูงสุดที่ต้องการดู (ไม่เกิน ' . LabourQueryService::MAX_ROWS . ')'],
            ],
        ];

        $fn = fn (string $name, string $desc, array $params) => [
            'type' => 'function',
            'function' => ['name' => $name, 'description' => $desc, 'parameters' => $params],
        ];
        $empty = ['type' => 'object', 'properties' => new \stdClass()];

        return [
            $fn('get_overview', 'ภาพรวมระบบ: จำนวนแรงงานตามสถานะ จำนวนบริษัท เอเจนซี่ และรายชื่อสัญชาติ', $empty),
            $fn('get_expiry_summary', 'สรุปจำนวนเอกสารใกล้หมดอายุแต่ละประเภทตามค่าแจ้งเตือนของระบบ', $empty),
            $fn('search_labours', 'ค้นหารายชื่อแรงงานตามเงื่อนไข คืนค่า total และรายการ (จำกัดจำนวนแถว) ใช้เมื่อต้องการรายชื่อ', ['type' => 'object', 'properties' => ['filters' => $filters]]),
            $fn('count_labours', 'นับจำนวนแรงงานตามเงื่อนไข เลือก group_by เพื่อสรุปยอดแยกกลุ่มได้', ['type' => 'object', 'properties' => ['filters' => $filters, 'group_by' => ['type' => 'string', 'enum' => array_keys(LabourQueryService::GROUP_FIELDS)]]]),
            $fn('get_labour_detail', 'ดูข้อมูลแรงงานรายคนแบบละเอียดจากชื่อหรือเลขพาสปอร์ต รวมรายการไฟล์เอกสารที่อัปโหลดไว้ (พาสปอร์ต วีซ่า ใบอนุญาตทำงาน ฯลฯ) พร้อม open_url สำหรับเปิดไฟล์', ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]),
            $fn('read_labour_document', 'อ่านเนื้อหาในไฟล์เอกสารที่อัปโหลดไว้ (PDF หรือรูป) ด้วย OCR/vision แล้วคืนข้อความและฟิลด์สำคัญที่อ่านได้ ใช้เมื่อผู้ใช้ขอให้ดึงเนื้อหา อ่าน ตรวจสอบ หรือเทียบข้อมูลในไฟล์กับฐานข้อมูล', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'ชื่อหรือเลขพาสปอร์ตของแรงงาน'],
                    'document' => ['type' => 'string', 'description' => 'document_key จาก get_labour_detail หรือชื่อเอกสาร เช่น Passport, Visa, Work Permit'],
                ],
                'required' => ['query', 'document'],
            ]),
            $fn('list_companies', 'รายชื่อบริษัท/นายจ้าง พร้อมจำนวนแรงงาน', ['type' => 'object', 'properties' => ['keyword' => ['type' => 'string']]]),
            $fn('list_agencies', 'รายชื่อเอเจนซี่ พร้อมสัญชาติและจำนวนแรงงาน', $empty),
            $fn('create_excel_report', 'สร้างไฟล์ Excel รายงานแรงงานตามเงื่อนไข (สูงสุด ' . LabourQueryService::MAX_EXPORT_ROWS . ' แถว) คืน download_url ให้ผู้ใช้ ใช้เมื่อผู้ใช้ขอไฟล์/รายงาน/Excel/ดาวน์โหลด', [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'ชื่อรายงานภาษาไทยสั้นๆ'],
                    'filters' => $filters,
                    'columns' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(LabourQueryService::columns())], 'description' => 'คอลัมน์ที่ต้องการ (ไม่ระบุ = ชุดมาตรฐาน)'],
                ],
                'required' => ['title'],
            ]),
        ];
    }

    private function systemPrompt(string $userName): string
    {
        $columns = implode(', ', array_map(fn ($k, $v) => "$k=$v[0]", array_keys(LabourQueryService::columns()), LabourQueryService::columns()));

        return <<<PROMPT
คุณคือ "ผู้ช่วย AI" ของระบบ Labour App บริษัท The Importer 168 (บริษัทจัดหาแรงงานต่างด้าว) กำลังคุยกับผู้ใช้ชื่อ {$userName}
วันนี้คือ {$this->today()} (เวลาไทย)

หน้าที่: ตอบคำถามเกี่ยวกับข้อมูลแรงงานต่างด้าว นายจ้าง เอเจนซี่ และเอกสาร (หนังสือเดินทาง วีซ่า ใบอนุญาตทำงาน รายงานตัว 90 วัน) จากฐานข้อมูลจริงผ่านเครื่องมือที่ให้ไว้ และสร้างรายงาน Excel ตามคำขอ

กฎสำคัญ:
1. ทุกคำถามที่เกี่ยวกับข้อมูล ต้องเรียกเครื่องมือก่อนเสมอ ห้ามเดาหรือแต่งตัวเลข/รายชื่อ ถ้าเครื่องมือคืนค่าว่างให้บอกตรงๆ ว่าไม่พบข้อมูล
2. ตอบเป็นภาษาไทย กระชับ ชัดเจน ใช้ Markdown ได้ (ตาราง, bullet) เมื่อแสดงรายชื่อให้ใส่ตารางสั้นๆ และบอกจำนวนรวม (total) เสมอ ถ้าแสดงไม่ครบให้บอกว่าแสดงบางส่วนและเสนอสร้าง Excel
3. เมื่อผู้ใช้ขอไฟล์ รายงาน Excel หรือดาวน์โหลด ให้เรียก create_excel_report แล้วตอบพร้อมลิงก์ในรูปแบบ [ดาวน์โหลด Excel](download_url) และสรุปเงื่อนไข/จำนวนแถว
4. "ใกล้หมดอายุ" ให้ใช้ filters.expiring (ถ้าผู้ใช้ไม่ระบุจำนวนวัน ให้ใช้ค่าแจ้งเตือนของระบบ) "หมดอายุแล้ว" ใช้ filters.expired ช่วงวันที่เฉพาะเจาะจงใช้ date_field + date_from/date_to
5. ถ้าคำถามกำกวม (เช่น ไม่รู้ว่าหมายถึงเอกสารประเภทไหน) ให้ถามกลับสั้นๆ ก่อน
6. ไม่ทำรายการแก้ไข/ลบข้อมูล และไม่เปิดเผยข้อมูลที่ไม่ได้มาจากเครื่องมือ
7. สถานะการทำงาน: job=ทำงาน, resign=ลาออก, escape=หลบหนี  สถานะในระบบ: enable=เปิดใช้งาน, disable=ปิดใช้งาน
8. เมื่อถามถึงไฟล์เอกสาร (PDF/รูป พาสปอร์ต วีซ่า ใบอนุญาตทำงาน) ให้เรียก get_labour_detail แล้วดูช่อง "เอกสารไฟล์" ตอบเป็นรายการ [ชื่อเอกสาร – ชื่อไฟล์](open_url) ถ้ารายการว่างให้บอกว่ายังไม่มีไฟล์อัปโหลดในระบบ
9. เมื่อผู้ใช้ขอให้ "ดึงเนื้อหา" "อ่าน" "ตรวจสอบ" หรือ "เทียบ" ข้อมูลในไฟล์ ให้เรียก read_labour_document (ระบุ query และ document) แล้วสรุปสิ่งที่อ่านได้ ถ้าผู้ใช้ถามว่าตรงกับข้อมูลในระบบไหม ให้เทียบทีละฟิลด์ (ชื่อ เลขที่ วันเกิด วันหมดอายุ) และชี้จุดที่ต่างกันชัดๆ ห้ามบอกว่าอ่านไฟล์ไม่ได้ก่อนลองเรียกเครื่องมือนี้

คอลัมน์ที่ใช้ใน Excel ได้: {$columns}
PROMPT;
    }

    private function today(): string
    {
        return now()->format('Y-m-d') . ' (' . now()->locale('th')->translatedFormat('l j F Y') . ')';
    }
}
