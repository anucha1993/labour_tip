<?php

namespace App\Services\Ai;

use App\Exports\AiLabourExport;
use App\Models\labour\LabourDocument;
use App\Models\User;
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

    /** Tools that change data: offered to MasterAdmin only and re-checked when called. */
    private const WRITE_TOOLS = ['propose_update', 'apply_update', 'cancel_update'];

    private ?User $actor = null;

    public function __construct(private OpenAiClient $client, private LabourQueryService $query, private DocumentReader $reader, private DataUpdateService $updates)
    {
    }

    private function canWrite(): bool
    {
        return $this->actor !== null && $this->actor->type === 'MasterAdmin';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @param  array  $history  [{role: user|assistant, content: string}, ...] (older turns)
     * @return array{answer: string, reports: array, usage: array, tools_used: array}
     */
    public function ask(string $userMessage, array $history, string|User $user, ?LabourDocument $attachment = null): array
    {
        $this->actor = $user instanceof User ? $user : null;
        $userName = $user instanceof User ? (string) $user->name : $user;
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($userName)]];
        $toolsUsed = [];
        if ($attachment) {
            $userMessage = mb_substr($userMessage, 0, 4000) . "\n\n" . $this->attachmentContext($attachment);
            $toolsUsed[] = 'read_attachment';
        }
        foreach (array_slice($history, -self::MAX_HISTORY) as $m) {
            if (in_array($m['role'] ?? '', ['user', 'assistant'], true) && is_string($m['content'] ?? null) && $m['content'] !== '') {
                $messages[] = ['role' => $m['role'], 'content' => mb_substr($m['content'], 0, 4000)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, 4000)];

        $reports = [];
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
                    'suggestions' => $this->suggestions($toolsUsed),
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

    /** Follow-up chips shown under the answer, chosen from what the assistant just did. */
    private function suggestions(array $toolsUsed): array
    {
        $used = array_unique($toolsUsed);
        $out = [];
        if (in_array('propose_update', $used, true)) {
            return ['ยืนยัน', 'ยกเลิก'];
        }
        if (in_array('apply_update', $used, true)) {
            $out = ['ขอ Excel รายการที่เพิ่งแก้', 'ดูประวัติการแก้ไขล่าสุด'];
        } elseif (in_array('read_labour_document', $used, true) || in_array('read_attachment', $used, true)) {
            $out = ['ข้อมูลในไฟล์ตรงกับระบบไหม'];
            if ($this->canWrite()) {
                $out[] = 'อัปเดตข้อมูลตามไฟล์นี้';
            }
        } elseif (in_array('get_labour_detail', $used, true)) {
            $out = ['อ่านไฟล์พาสปอร์ตของคนนี้', 'เทียบเอกสารกับข้อมูลในระบบ', 'ใครแก้ข้อมูลคนนี้ล่าสุด'];
        } elseif (in_array('check_data_quality', $used, true)) {
            $out = ['ขอ Excel รายชื่อที่มีปัญหา', 'ดูรายละเอียดปัญหาที่พบมากที่สุด'];
        } elseif (in_array('get_expiry_timeline', $used, true)) {
            $out = ['แยกตามบริษัท', 'ขอ Excel รายชื่อของเดือนหน้า'];
        } elseif (in_array('search_labours', $used, true) || in_array('count_labours', $used, true)) {
            $out = ['ขอ Excel รายชื่อนี้', 'แยกตามบริษัท', 'ตรวจคุณภาพข้อมูลกลุ่มนี้'];
        } elseif (in_array('create_excel_report', $used, true)) {
            $out = ['เพิ่มคอลัมน์เบอร์โทรนายจ้างและผู้ประสานงาน'];
        }

        return array_slice($out, 0, 3);
    }

    // ------------------------------------------------------------------ tools

    private function runTool(string $name, array $args, array &$reports): array
    {
        if (in_array($name, self::WRITE_TOOLS, true) && ! $this->canWrite()) {
            return ['error' => 'ไม่มีสิทธิ์แก้ไขข้อมูลผ่านผู้ช่วย AI (เฉพาะ MasterAdmin เท่านั้น)'];
        }

        return match ($name) {
            'propose_update' => is_array($args['items'] ?? null) && $args['items']
                ? $this->updates->proposeMany($this->actor, (string) ($args['entity'] ?? 'labour'), $args['items'])
                : (is_array($args['filters'] ?? null) && $args['filters']
                ? $this->updates->proposeByFilter($this->actor, $args['filters'], is_array($args['changes'] ?? null) ? $args['changes'] : [])
                : $this->updates->propose($this->actor, (string) ($args['entity'] ?? 'labour'), (string) ($args['query'] ?? ''), is_array($args['changes'] ?? null) ? $args['changes'] : [])),
            'apply_update' => $this->updates->apply($this->actor, isset($args['token']) ? (string) $args['token'] : null),
            'cancel_update' => $this->updates->cancel($this->actor, isset($args['token']) ? (string) $args['token'] : null),
            'get_overview' => $this->query->overview(),
            'get_expiry_summary' => $this->query->expirySummary(),
            'search_labours' => $this->query->search($args['filters'] ?? []),
            'count_labours' => $this->query->count(($args['filters'] ?? []) + ['group_by' => $args['group_by'] ?? null]),
            'get_labour_detail' => $this->query->detail((string) ($args['query'] ?? '')),
            'list_companies' => $this->query->listCompanies($args['keyword'] ?? null),
            'list_agencies' => $this->query->listAgencies(),
            'create_excel_report' => $this->createReport($args, $reports),
            'get_change_history' => $this->query->changeHistory((string) ($args['query'] ?? ''), $args['date_from'] ?? null, $args['date_to'] ?? null, (int) ($args['limit'] ?? 20)),
            'get_expiry_timeline' => $this->query->expiryTimeline((string) ($args['type'] ?? 'visa'), (int) ($args['months'] ?? 6), $args['group_by'] ?? null, is_array($args['filters'] ?? null) ? $args['filters'] : []),
            'check_data_quality' => $this->query->dataQualityCheck(is_array($args['filters'] ?? null) ? $args['filters'] : []),
            'read_labour_document' => $this->readDocument((string) ($args['query'] ?? ''), (string) ($args['document'] ?? '')),
            default => ['error' => 'unknown tool'],
        };
    }

    /** OCR text of a file attached to the question, appended to the user message (10,000 chars max). */
    private function attachmentContext(LabourDocument $file): string
    {
        try {
            if (SpreadsheetReader::supports($file->extension())) {
                $s = app(SpreadsheetReader::class)->read($file->disk ?: 'local', $file->path);
                $note = $s['truncated'] ? ' แสดงเฉพาะ ' . SpreadsheetReader::MAX_ROWS . ' แถวแรก' : '';

                return "===== ไฟล์แนบจากผู้ใช้ (ตาราง Excel/CSV): {$file->fileName()} ({$s['sheets']} ชีต, {$s['rows']} แถวข้อมูล{$note}) =====\n" . mb_substr($s['text'], 0, 24000) . "\n===== จบไฟล์แนบ =====";
            }
            $r = $this->reader->read($file);
            $pages = $r['pages'] ? $r['pages'] . ' หน้า' : 'ไม่ทราบจำนวนหน้า';

            return "===== ไฟล์แนบจากผู้ใช้: {$file->fileName()} ({$pages}, อ่านด้วย {$r['engine']}) =====\n" . mb_substr($r['content'], 0, 10000) . "\n===== จบไฟล์แนบ =====";
        } catch (\Throwable $e) {
            Log::warning('AI attachment unreadable', ['file' => $file->fileName(), 'error' => $e->getMessage()]);

            return "===== ไฟล์แนบจากผู้ใช้: {$file->fileName()} =====\nอ่านไฟล์ไม่สำเร็จ: {$e->getMessage()}\n===== จบไฟล์แนบ =====";
        }
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

        $tools = $this->canWrite() ? [
            $fn('propose_update', 'ขั้นที่ 1 ของการแก้ไขข้อมูล (MasterAdmin): ตรวจสอบค่าที่จะแก้และคืน preview ค่าเดิม→ค่าใหม่ พร้อม token ยังไม่บันทึกจริง ใช้กับ labour (แรงงาน), company (บริษัท), agency (เอเจนซี่) ระบุ query เป็นเลขพาสปอร์ต/ชื่อ/id และ changes เป็น object {ฟิลด์: ค่าใหม่} ค่าว่างหรือ "-" = ลบค่า ฟิลด์แรงงาน: ' . implode(', ', array_keys(DataUpdateService::LABOUR_FIELDS)) . ' ฟิลด์บริษัท: ' . implode(', ', array_keys(DataUpdateService::COMPANY_FIELDS)) . ' ฟิลด์เอเจนซี่: ' . implode(', ', array_keys(DataUpdateService::AGENCY_FIELDS)) . ' (company_id/labour_agency ใส่ชื่อหรือ id ได้ สัญชาติใส่ชื่อไทยหรือ code ได้ วันที่ใส่ วัน/เดือน/ปี ได้)', [
                'type' => 'object',
                'properties' => [
                    'entity' => ['type' => 'string', 'enum' => ['labour', 'company', 'agency']],
                    'query' => ['type' => 'string', 'description' => 'เลขพาสปอร์ต ชื่อ หรือ id ของรายการที่จะแก้'],
                    'changes' => ['type' => 'object', 'description' => 'ฟิลด์ที่จะแก้และค่าใหม่', 'additionalProperties' => ['type' => 'string']],
                    'filters' => ['type' => 'object', 'description' => 'แก้ทุกแรงงานที่ตรงเงื่อนไขด้วยค่าเดียวกัน (เฉพาะ entity=labour) ใช้คู่กับ changes เช่น {"company": "ประดิษฐ์ธนานุรักษ์", "status_job": "job"} เงื่อนไขเหมือน search_labours', 'properties' => $filters['properties']],
                    'items' => ['type' => 'array', 'description' => 'แก้หลายรายการพร้อมกัน (เช่น จากไฟล์ Excel) แต่ละรายการมี query และ changes ใช้แทน query/changes ด้านบน ไม่เกิน ' . DataUpdateService::MAX_ITEMS . ' รายการ', 'items' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'changes' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]], 'required' => ['query', 'changes']]],
                ],
                'required' => ['entity'],
            ]),
            $fn('apply_update', 'ขั้นที่ 2: บันทึกการแก้ไขที่เสนอไว้ลงฐานข้อมูลจริง เรียกได้เฉพาะหลังจากผู้ใช้พิมพ์ยืนยันในข้อความถัดไปเท่านั้น (token จาก propose_update ถ้าไม่ระบุจะใช้รายการล่าสุดของผู้ใช้)', [
                'type' => 'object', 'properties' => ['token' => ['type' => 'string']],
            ]),
            $fn('cancel_update', 'ยกเลิกการแก้ไขที่เสนอไว้', ['type' => 'object', 'properties' => ['token' => ['type' => 'string']]]),
        ] : [];

        return array_merge($tools, [
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
            $fn('get_change_history', 'ประวัติการแก้ไขข้อมูลแรงงาน (ใคร แก้อะไร เมื่อไร ค่าเดิม→ค่าใหม่) ใช้เมื่อถามว่าใครเปลี่ยน/แก้เมื่อไร/ประวัติ', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'ชื่อหรือเลขพาสปอร์ตของแรงงาน (เว้นว่าง = ทุกคน)'],
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'limit' => ['type' => 'integer', 'description' => 'ไม่เกิน 50'],
                ],
            ]),
            $fn('get_expiry_timeline', 'จำนวนเอกสารที่จะหมดอายุแยกรายเดือนล่วงหน้า N เดือน (และที่หมดไปแล้ว) เลือก group_by เพื่อแยกตามบริษัท/สัญชาติ ใช้เมื่อถามแนวโน้ม เดือนหน้า ไตรมาสนี้ ปีนี้ ต้องต่ออายุกี่คน', [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => array_keys(ExpiryService::TYPES), 'description' => 'visa=วีซ่า passport=หนังสือเดินทาง work=ใบอนุญาตทำงาน ninety=รายงานตัว 90 วัน'],
                    'months' => ['type' => 'integer', 'description' => 'จำนวนเดือนข้างหน้า 1-24 (ค่าเริ่มต้น 6)'],
                    'group_by' => ['type' => 'string', 'enum' => array_keys(LabourQueryService::GROUP_FIELDS)],
                    'filters' => $filters,
                ],
                'required' => ['type'],
            ]),
            $fn('check_data_quality', 'ตรวจคุณภาพข้อมูลแรงงานตามเงื่อนไข: ฟิลด์สำคัญว่าง วันที่ขัดกัน ทำงานอยู่แต่เอกสารหมดอายุ ไม่มีไฟล์ ฯลฯ คืนจำนวนและตัวอย่างแต่ละปัญหา ใช้เมื่อถามว่าข้อมูลครบไหม/ผิดปกติ/ต้องแก้อะไร', [
                'type' => 'object', 'properties' => ['filters' => $filters],
            ]),
            $fn('create_excel_report', 'สร้างไฟล์ Excel รายงานแรงงานตามเงื่อนไข (สูงสุด ' . LabourQueryService::MAX_EXPORT_ROWS . ' แถว) คืน download_url ให้ผู้ใช้ ใช้เมื่อผู้ใช้ขอไฟล์/รายงาน/Excel/ดาวน์โหลด', [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'ชื่อรายงานภาษาไทยสั้นๆ'],
                    'filters' => $filters,
                    'columns' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(LabourQueryService::columns())], 'description' => 'คอลัมน์ที่ต้องการ (ไม่ระบุ = ชุดมาตรฐาน)'],
                ],
                'required' => ['title'],
            ]),
        ]);
    }

    private function systemPrompt(string $userName): string
    {
        $role = $this->actor ? role_label((string) $this->actor->type) : 'ผู้ใช้';
        $writeRules = $this->canWrite()
            ? "10. ผู้ใช้คนนี้เป็น MasterAdmin แก้ไขข้อมูลได้ผ่าน 2 ขั้น: (ก) เมื่อถูกขอให้แก้/เปลี่ยน/อัปเดต/ปิดสถานะ/ลบค่า ให้เรียก propose_update แล้วตอบเป็นตาราง ฟิลด์ | ค่าเดิม | ค่าใหม่ พร้อมชื่อรายการ และลงท้ายว่า พิมพ์ \"ยืนยัน\" เพื่อบันทึก หรือ \"ยกเลิก\" (ข) เรียก apply_update เฉพาะเมื่อข้อความล่าสุดของผู้ใช้เป็นการยืนยันชัดเจน (ยืนยัน/ตกลง/ใช่ บันทึกเลย) ห้ามเรียก apply_update ในรอบเดียวกับ propose_update เด็ดขาด ถ้าผู้ใช้แก้รายละเอียดให้ propose_update ใหม่ หลังบันทึกให้สรุปสิ่งที่เปลี่ยนและบอกว่าดูประวัติได้ในหน้าประวัติการแก้ไข ถ้าต้องการแก้ตามไฟล์เอกสาร ให้ read_labour_document ก่อนแล้วค่อย propose_update ด้วยค่าที่อ่านได้"
            : '10. ผู้ใช้คนนี้ไม่มีสิทธิ์แก้ไขข้อมูลผ่าน AI ถ้าถูกขอให้แก้/เปลี่ยน/ลบข้อมูล ให้ตอบสั้นๆ ว่าแก้ผ่าน AI ได้เฉพาะบัญชี MasterAdmin และแนะนำให้แก้ในหน้าฟอร์มแทน แล้วเสนอช่วยค้นหา/ตรวจสอบข้อมูลที่เกี่ยวข้องแทน';
        $columns = implode(', ', array_map(fn ($k, $v) => "$k=$v[0]", array_keys(LabourQueryService::columns()), LabourQueryService::columns()));

        return <<<PROMPT
คุณคือ "ผู้ช่วย AI" ของระบบ Labour App บริษัท The Importer 168 (บริษัทนำเข้าและดูแลแรงงานต่างด้าว MOU) หน้าที่คือค้นหา สรุป ตรวจสอบ อ่านเอกสาร และสร้างรายงานจากฐานข้อมูลจริงผ่านเครื่องมือที่ให้ไว้ ตอบเป็นภาษาไทยเสมอ

## คำศัพท์ของธุรกิจนี้
- แรงงาน (labour) = คนงานต่างด้าว 1 คน ระบุตัวด้วยเลขพาสปอร์ตเป็นหลัก ชื่อ-สกุลเป็นอังกฤษตามพาสปอร์ตและอาจซ้ำกันได้ (ถ้าค้นด้วยชื่อแล้วเจอหลายคน ให้แสดงให้เลือกพร้อมเลขพาสปอร์ต)
- นายจ้าง/บริษัท (company) = สถานประกอบการที่แรงงานทำงานอยู่ปัจจุบัน แรงงาน 1 คนมีนายจ้างเดียว; เอเจนซี่ (agency) = บริษัทจัดส่งจากประเทศต้นทาง; ผู้ประสานงานการตลาด (contact_sale) = พนักงานของเราที่ดูแลบริษัทนั้น
- สถานะการทำงาน: job=ทำงานอยู่, resign=ลาออก (ต้องมีวันที่ลาออก), escape=หลบหนี (ต้องมีวันที่หลบหนี) สถานะในระบบ: enable=เปิดใช้งาน (แรงงานเข้า Worker App ได้), disable=ปิดใช้งาน "ปิดสถานะ" ของผู้ใช้มักหมายถึงทั้งสองอย่าง ให้ถามถ้าไม่ชัด
- เอกสารที่มีวันหมดอายุ 4 ประเภท: หนังสือเดินทาง (passport), วีซ่า (visa), ใบอนุญาตทำงาน/Work Permit (work), รายงานตัว 90 วัน/ตม. (ninety) แต่ละประเภทมีจำนวนวันแจ้งเตือนล่วงหน้าตั้งค่าไว้ในระบบ (ดูได้จาก get_expiry_summary)
- "ใกล้หมดอายุ" = จะหมดภายใน N วันนับจากวันนี้ (ยังไม่หมด) "หมดอายุแล้ว" = ผ่านวันหมดอายุมาแล้ว ทั้งสองนับเฉพาะแรงงานที่ทำงานอยู่
- รหัส Worker App ของแรงงาน = วันเกิดรูปแบบ ddmmyyyy แรงงานที่ไม่มีวันเกิดในระบบจะเข้าแอปไม่ได้
- ไฟล์เอกสาร = PDF/รูปที่อัปโหลดไว้ต่อคน รายการอยู่ในช่อง "เอกสารไฟล์" ของ get_labour_detail (มี document_key และ open_url)

## เลือกเครื่องมือจากคำถาม (ตัวอย่าง)
- "มีกี่คน / แยกตามสัญชาติ / บริษัทไหนมากสุด" → count_labours (+group_by) ; "ใครบ้าง / รายชื่อ" → search_labours
- "ข้อมูลของ MK204208 / ของ TIN ZAR OO / มีไฟล์อะไรบ้าง / ทำงานที่ไหน" → get_labour_detail
- "วีซ่าใกล้หมดใน 30 วัน" → filters.expiring {type: visa, within_days: 30} ; "หมดอายุแล้ว" → filters.expired {type} ; ไม่ระบุวัน → ใช้ค่าแจ้งเตือนของระบบ
- "เดือนหน้า / ไตรมาสนี้ / 6 เดือนข้างหน้า มีเอกสารหมดกี่คน แนวโน้ม" → get_expiry_timeline (group_by company เมื่อถามแยกบริษัท)
- "ใครแก้ข้อมูลคนนี้ / เปลี่ยนเมื่อไร / ประวัติการแก้ไข" → get_change_history
- "ข้อมูลครบไหม / มีอะไรผิดปกติ / ตรวจสุขภาพข้อมูล / ต้องแก้อะไรบ้าง" → check_data_quality (ใส่ filters เช่น company)
- "ขอ Excel / ส่งออก / รายงาน / ดาวน์โหลด" → create_excel_report หลังจากรู้เงื่อนไขชัดแล้ว
- "อ่าน / ดึงเนื้อหา / เทียบไฟล์พาสปอร์ต" → read_labour_document ; ถ้ามีไฟล์แนบมากับคำถาม ใช้เนื้อหานั้นได้เลย
- "แก้ / เปลี่ยน / อัปเดต / ปิดสถานะ / ย้ายบริษัท" → propose_update (ตามข้อ 10)
- ชื่อบริษัทที่มี บจก./บริษัท/จำกัด หรือพิมพ์ย่อ และชื่อคนที่มี Mr./Ms. ค้นได้ทันที ไม่ต้องถามซ้ำ
- คำถามซ้อน เช่น "ปิดสถานะ…แล้วออก Excel" ให้ทำทีละขั้น: เสนอการแก้ → รอยืนยัน → บันทึก → ค่อยสร้าง Excel ด้วยเงื่อนไขเดิม

## รูปแบบคำตอบที่ดี
- บรรทัดแรกคือคำตอบตรงๆ (ตัวเลขหรือข้อสรุป) แล้วค่อยตาราง/รายละเอียด
- รายชื่อใช้ตาราง Markdown: ชื่อ | พาสปอร์ต | บริษัท | วันที่ที่เกี่ยวข้อง | เหลือ (วัน) แสดงไม่เกิน 20 แถว และบอกจำนวนรวม (total) เสมอ ถ้าเกินให้บอกว่าแสดงบางส่วนและเสนอ Excel
- ปิดท้ายด้วยข้อเสนอถัดไป 1 อย่างเมื่อเหมาะ (ขอ Excel ไหม / ดูรายละเอียดคนไหน / แยกตามบริษัทไหม)
- ตัวอย่าง: ถาม "วีซ่าใกล้หมดใน 30 วันมีกี่คน" → "**12 คน** วีซ่าจะหมดภายใน 30 วัน (ถึง 29/11/2026) มากสุดที่บริษัท A 7 คน" ตามด้วยตารางและ "ต้องการ Excel รายชื่อทั้ง 12 คนไหม"
- ไม่พบข้อมูล: บอกว่าไม่พบพร้อมเงื่อนไขที่ใช้ค้น และเสนอเงื่อนไขอื่นหรือถามกลับ ห้ามเดา ห้ามแต่งตัวเลข และห้ามทำอย่างอื่นแทน (เช่น สร้างรายงานเรื่องอื่น) เมื่อสิ่งที่ขอทำไม่ได้
- ตัวเลขทุกตัวต้องมาจากเครื่องมือ ถ้าจำนวนที่ผู้ใช้บอกต่างจากที่พบ ให้ชี้ให้เห็นชัดเจน

## กฎ
1. ทุกคำถามที่เกี่ยวกับข้อมูลต้องเรียกเครื่องมือก่อนเสมอ แม้เป็นคำถามต่อเนื่อง ถ้าเครื่องมือคืนค่าว่างให้บอกตรงๆ
2. เลือกเครื่องมือให้ตรง: นับใช้ count_labours ไม่ใช่ search_labours; รายชื่อเกิน 40 คนให้เสนอ Excel แทนการแสดงทั้งหมด
3. เมื่อผู้ใช้ขอไฟล์/รายงาน/Excel ให้เรียก create_excel_report แล้วตอบพร้อมลิงก์รูปแบบ [ดาวน์โหลด Excel](download_url) และสรุปเงื่อนไข/จำนวนแถว เลือกคอลัมน์ให้ตรงกับคำถาม
4. ช่วงวันที่เฉพาะเจาะจงใช้ date_field + date_from/date_to (รูปแบบ YYYY-MM-DD แปลงปี พ.ศ. เป็น ค.ศ. โดยลบ 543)
5. ถ้าคำถามกำกวม (ไม่รู้ว่าเอกสารประเภทไหน / บริษัทไหนในหลายผลลัพธ์ / "ปิดสถานะ" หมายถึงอะไร) ให้ถามกลับสั้นๆ 1 คำถาม พร้อมตัวเลือก
6. ไม่เปิดเผยข้อมูลที่ไม่ได้มาจากเครื่องมือ การแก้ไขข้อมูลทำได้เฉพาะตามข้อ 10 และไม่มีการลบรายการ
7. แสดงสถานะเป็นคำไทย (ทำงาน/ลาออก/หลบหนี, เปิดใช้งาน/ปิดใช้งาน) และวันที่แบบ วัน/เดือน/ปี ค.ศ.
8. เมื่อถามถึงไฟล์เอกสาร ให้เรียก get_labour_detail แล้วดูช่อง "เอกสารไฟล์" ตอบเป็นรายการ [ชื่อเอกสาร – ชื่อไฟล์](open_url) ถ้าว่างให้บอกว่ายังไม่มีไฟล์
9. เมื่อผู้ใช้ขอให้อ่าน/ดึงเนื้อหา/ตรวจสอบ/เทียบไฟล์ ให้เรียก read_labour_document (query + document) แล้วสรุปสิ่งที่อ่านได้ ถ้าถามว่าตรงกับระบบไหม ให้เทียบทีละฟิลด์ (ชื่อ เลขที่ วันเกิด วันหมดอายุ) และชี้จุดต่างชัดๆ ห้ามบอกว่าอ่านไม่ได้ก่อนลองเรียกเครื่องมือ
{$writeRules}
11. ถ้าข้อความผู้ใช้มีส่วน "ไฟล์แนบจากผู้ใช้" ให้ใช้เนื้อหานั้นตอบได้ทันที ถ้าขอให้ตรวจสอบ/อัปเดตตามไฟล์ ให้หาแรงงานจากชื่อหรือเลขพาสปอร์ตในไฟล์ด้วย get_labour_detail เทียบทีละฟิลด์ แล้ว (ถ้ามีสิทธิ์) propose_update เฉพาะฟิลด์ที่ต่าง พร้อมบอกว่าค่ามาจากไฟล์
12. ไฟล์แนบที่เป็นตาราง (Excel/CSV): แต่ละแถวคือหนึ่งรายการ ใช้คอลัมน์เลขพาสปอร์ต (หรือชื่อ) เป็น query จับคู่หัวตารางกับฟิลด์ของระบบเอง (เช่น "วีซ่าหมดอายุ" → labour_visa_date_end, "นายจ้าง" → company_id) ถ้ากำกวมให้ถามก่อน แก้หลายแถวให้เรียก propose_update ครั้งเดียวด้วย items แล้วสรุป: แถวที่แก้ได้ ตารางค่าเดิม→ใหม่ และแถวที่แก้ไม่ได้พร้อมเหตุผล ก่อนขอยืนยันครั้งเดียว
13. คำขอแก้ "ทุกคน/ทั้งหมดของบริษัท X" หรือ "แรงงาน N คนของ X": ใช้ propose_update ด้วย filters (เช่น company + status_job) ไม่ใช่ query ชื่อบริษัท ผลลัพธ์บอกจำนวนที่พบ (matched) ถ้าต่างจากที่ผู้ใช้บอกให้ชี้ให้เห็นก่อนขอยืนยัน เมื่อทุกรายการเปลี่ยนเหมือนกันแสดงตารางการเปลี่ยนครั้งเดียวตามด้วยรายชื่อ (ย่อถ้าเกิน 30 คน)

คอลัมน์ที่ใช้ใน Excel ได้: {$columns}

ข้อมูลรอบนี้: วันนี้คือ {$this->today()} (เวลาไทย) กำลังคุยกับผู้ใช้ชื่อ {$userName} สิทธิ์ {$role}
PROMPT;
    }

    private function today(): string
    {
        return now()->format('Y-m-d') . ' (' . now()->locale('th')->translatedFormat('l j F Y') . ')';
    }
}
