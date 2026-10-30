<?php

namespace Tests\Feature;

use App\Models\AiChatLog;
use App\Models\labour\LabourModel;
use App\Models\User;
use App\Services\Ai\LabourQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AI assistant: tool-calling round trip with a faked OpenAI API on an in-memory SQLite database.
 */
class AiChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite']);
        config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-test', 'services.openai.base_url' => 'https://api.openai.com/v1', 'services.openai.datacorp' => false]);
        config(['services.google.vision_key' => '', 'services.google.translate_key' => '']); // no Google Vision unless a test enables it
        DB::purge('testing_sqlite');

        Schema::create('labour', function (Blueprint $t) {
            $t->bigIncrements('labour_id');
            foreach ((new LabourModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_tax')->nullable(); $t->string('company_phone')->nullable(); $t->string('company_contact_sale')->nullable(); $t->string('company_status')->nullable(); $t->string('updated_by')->nullable(); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); $t->string('agency_nationality')->nullable(); });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        Schema::create('settings', function (Blueprint $t) { $t->increments('set_id'); $t->string('set_name'); $t->integer('set_expire'); });
        Schema::create('ai_chat_logs', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('conversation_id')->nullable(); $t->text('question'); $t->text('answer')->nullable(); $t->text('tools_used')->nullable(); $t->text('reports')->nullable(); $t->integer('prompt_tokens')->default(0); $t->integer('completion_tokens')->default(0); $t->integer('duration_ms')->default(0); $t->string('status')->default('ok'); $t->text('error')->nullable(); $t->integer('rating')->nullable(); $t->string('feedback')->nullable(); $t->string('ip')->nullable(); $t->timestamp('created_at')->nullable(); });
        Schema::create('users', function (Blueprint $t) { $t->increments('id'); $t->string('name'); $t->string('email')->nullable(); $t->string('password')->nullable(); $t->string('type')->nullable(); $t->string('status')->nullable(); $t->timestamps(); });
        Schema::create('labour_documents', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('labour_id'); $t->string('name'); $t->string('disk'); $t->string('path'); $t->string('original_name')->nullable(); $t->unsignedBigInteger('size')->nullable(); $t->string('mime')->nullable(); $t->string('legacy_column')->nullable(); $t->integer('sort_order')->default(0); $t->unsignedBigInteger('uploaded_by')->nullable(); $t->timestamps(); });
        \App\Models\labour\LabourDocument::forgetTableReady();
        Schema::create('action_logs', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action_type'); $t->string('model_type'); $t->unsignedBigInteger('model_id'); $t->text('old_values')->nullable(); $t->text('new_values')->nullable(); $t->timestamp('created_at')->nullable(); });

        DB::table('nationality')->insert([['code' => 'myanmar', 'name_th' => 'พม่า'], ['code' => 'cambodia', 'name_th' => 'กัมพูชา']]);
        DB::table('company')->insert([['company_id' => 1, 'company_name' => 'เก่ง เอ็นจิเนียริ่ง', 'company_contact_sale' => 'ตุ๊กกี้'], ['company_id' => 2, 'company_name' => 'แสงลินฟู้ดส์', 'company_contact_sale' => 'ตุ๊กตา']]);
        DB::table('agency')->insert([['agency_id' => 1, 'agency_name' => 'TOP MANPOWER']]);

        $rows = [
            ['A1', 'AUNG MYO', 'myanmar', 1, 'job', now()->addDays(10)],
            ['A2', 'SAN WIN', 'myanmar', 1, 'job', now()->addDays(200)],
            ['A3', 'RATH CHHON', 'cambodia', 2, 'job', now()->subDays(5)],
            ['A4', 'DOB TUN', 'cambodia', 2, 'resign', now()->addDays(3)],
        ];
        foreach ($rows as [$pp, $name, $nat, $company, $status, $visaEnd]) {
            LabourModel::create(['labour_prefix' => 'Mr', 'labour_fullname' => $name, 'labour_sex' => 'male', 'labour_nationality' => $nat, 'company_id' => $company,
                'labour_agency' => 1, 'labour_passport_number' => $pp, 'labour_status_job' => $status, 'labour_status' => 'enable', 'labour_visa_date_end' => $visaEnd->toDateString()]);
        }
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_insight_tools_history_timeline_and_data_quality()
    {
        $svc = app(LabourQueryService::class);

        // timeline: A1 visa +10d, A2 +200d (job); A3 expired (job); A4 +3d but resigned -> excluded
        $t = $svc->expiryTimeline('visa', 7);
        $this->assertSame(1, $t['expired_already']);
        $this->assertSame(2, $t['total_upcoming']);
        $this->assertSame(1, collect($t['months'])->firstWhere('month', now()->addDays(10)->format('Y-m'))['total']);
        $this->assertSame(1, collect($t['months'])->firstWhere('month', now()->addDays(200)->format('Y-m'))['total']);
        $grouped = $svc->expiryTimeline('visa', 7, 'company');
        $this->assertSame('เก่ง เอ็นจิเนียริ่ง', collect($grouped['months'])->firstWhere('month', now()->addDays(10)->format('Y-m'))['groups'][0]['group']);
        $this->assertArrayHasKey('error', $svc->expiryTimeline('bogus'));

        // data quality: nobody has a birthday; A3 works with an expired visa; nobody has a passport expiry
        $q = $svc->dataQualityCheck(['company' => 'แสงลิน']);
        $this->assertSame(2, $q['checked']);
        $issues = collect($q['issues'])->keyBy('check');
        $this->assertSame(2, $issues['no_birthday']['count']);
        $this->assertSame(1, $issues['expired_but_working']['count']);
        $this->assertStringContainsString('RATH CHHON', $issues['expired_but_working']['examples'][0]);
        $this->assertSame(1, $issues['no_passport_end']['count']);   // only A3 is still working at this company

        // change history: an update is logged by the model observer
        $this->actingAs($this->user('MasterAdmin'));
        LabourModel::find(1)->update(['labour_visa_number' => 'V-NEW']);
        $h = $svc->changeHistory('A1');
        $this->assertSame(2, $h['found']);   // created (setUp) + updated, newest first
        $this->assertSame('แก้ไข', $h['rows'][0]['action']);
        $this->assertSame(' → V-NEW', $h['rows'][0]['changes']['เลขที่วีซ่า']);
        $this->assertStringContainsString('AUNG MYO', $h['rows'][0]['labour']);
        $this->assertSame(0, $svc->changeHistory('NOBODY')['found']);
    }

    public function test_answers_carry_follow_up_suggestions_and_can_be_rated()
    {
        DB::table('users')->insert(['id' => 7, 'name' => 'Tester', 'type' => 'Member']);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'd1', 'type' => 'function', 'function' => ['name' => 'get_labour_detail', 'arguments' => json_encode(['query' => 'A1'])]]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'AUNG MYO ทำงานที่ เก่ง เอ็นจิเนียริ่ง'])),
        ]);

        $res = $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'ข้อมูลของ A1']);
        $res->assertOk()->assertJsonPath('suggestions.0', 'อ่านไฟล์พาสปอร์ตของคนนี้');
        $logId = $res->json('log_id');
        $this->assertNotNull($logId);

        $this->actingAs($this->user())->postJson(route('ai.feedback', $logId), ['rating' => -1, 'feedback' => 'ไม่ได้บอกเลขพาสปอร์ต'])->assertOk();
        $log = AiChatLog::find($logId);
        $this->assertSame([-1, 'ไม่ได้บอกเลขพาสปอร์ต'], [$log->rating, $log->feedback]);
        $this->actingAs($this->user())->postJson(route('ai.feedback', $logId), ['rating' => 5])->assertStatus(422);

        // another member cannot rate someone else's answer; MasterAdmin can
        $other = new User(['name' => 'Other', 'email' => 'o@test.local', 'type' => 'Member', 'status' => 'enable']);
        $other->id = 8;
        $this->actingAs($other)->postJson(route('ai.feedback', $logId), ['rating' => 1])->assertForbidden();
        $this->actingAs($this->user('MasterAdmin'))->postJson(route('ai.feedback', $logId), ['rating' => 1])->assertOk();

        // and the log page can filter on it
        $this->actingAs($this->user('MasterAdmin'))->get(route('ai.logs', ['rating' => 'good']))->assertOk()->assertSee('ข้อมูลของ A1');
        $this->actingAs($this->user('MasterAdmin'))->get(route('ai.logs', ['rating' => 'bad']))->assertOk()->assertDontSee('ข้อมูลของ A1');
    }

    public function test_bulk_update_by_filter_covers_every_matching_worker_after_one_confirmation()
    {
        \Illuminate\Support\Facades\Cache::flush();
        $master = $this->user('MasterAdmin');
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'f1', 'type' => 'function', 'function' => ['name' => 'propose_update', 'arguments' => json_encode(['entity' => 'labour', 'filters' => ['company' => 'แสงลินฟู้ดส์ บจก.', 'status_job' => 'job'], 'changes' => ['status_job' => 'ลาออก', 'resign_date' => '28/10/2026', 'status' => 'ปิดใช้งาน']])]]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'พบ 1 คน พิมพ์ ยืนยัน']))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'f2', 'type' => 'function', 'function' => ['name' => 'apply_update', 'arguments' => '{}']],
                ['id' => 'f3', 'type' => 'function', 'function' => ['name' => 'create_excel_report', 'arguments' => json_encode(['title' => 'ลาออก', 'filters' => ['company' => 'แสงลิน', 'status_job' => 'resign']])]]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'บันทึกแล้ว [ดาวน์โหลด Excel](URL)'])),
        ]);

        // Company 2 has A3 (job) and A4 (already resigned): only A3 matches the filter.
        $this->actingAs($master)->postJson(route('ai.message'), ['message' => 'ปิดสถานะแรงงานทุกคนของ แสงลินฟู้ดส์ บจก. เป็นลาออก และปิดใช้งาน'])->assertOk();
        Http::assertSent(function ($r) {
            $t = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool');
            $j = $t ? json_decode($t['content'], true) : null;

            return $j && ($j['matched'] ?? 0) === 1 && $j['valid'] === 1 && str_contains($j['items'][0]['record'], 'RATH CHHON')
                && collect($j['items'][0]['preview'])->firstWhere('field', 'labour_resing_date')['new'] === '2026-10-28';
        });
        $this->assertSame('job', LabourModel::find(3)->labour_status_job);

        $res = $this->actingAs($master)->postJson(route('ai.message'), ['message' => 'ยืนยัน แล้วออก Excel ด้วย']);
        $res->assertOk()->assertJsonPath('tools_used', ['apply_update', 'create_excel_report'])->assertJsonPath('reports.0.rows', 2);
        $l = LabourModel::find(3);
        $this->assertSame(['resign', '2026-10-28', 'disable'], [$l->labour_status_job, $l->labour_resing_date, $l->labour_status]);
        $this->assertSame('resign', LabourModel::find(4)->labour_status_job);   // untouched, was resigned already

        // Rows from a list can name workers with a prefix / different word order and still be found.
        $svc = app(\App\Services\Ai\DataUpdateService::class);
        $out = $svc->proposeMany($master, 'labour', [
            ['query' => 'Ms. AUNG MYO', 'changes' => ['status' => 'disable']],
            ['query' => 'win san', 'changes' => ['status' => 'disable']],
            ['query' => 'Mr. NOBODY HERE', 'changes' => ['status' => 'disable']],
        ]);
        $this->assertSame(2, $out['valid']);
        $this->assertStringContainsString('AUNG MYO', $out['items'][0]['record']);
        $this->assertStringContainsString('SAN WIN', $out['items'][1]['record']);
        $this->assertSame('Mr. NOBODY HERE', $out['failed'][0]['query']);
        $svc->cancel($master, $out['token']);

        // No filters at all is refused (never "update everyone").
        $this->assertStringContainsString('ไม่อนุญาต', app(\App\Services\Ai\DataUpdateService::class)->proposeByFilter($master, [], ['status' => 'disable'])['error']);
    }

    public function test_excel_attachment_becomes_a_text_table_and_rows_can_be_updated_in_one_confirmation()
    {
        \Illuminate\Support\Facades\Cache::flush();
        $master = $this->user('MasterAdmin');

        // Build a small xlsx: passport | วีซ่าหมดอายุ | หมายเหตุ   (A1, A2 exist; ZZ9 does not)
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['passport', 'วีซ่าหมดอายุ', 'หมายเหตุ'], ['A1', '2031-02-02', 'จากไฟล์'], ['A2', '2032-03-03', ''], ['ZZ9', '2030-01-01', 'ไม่มีคนนี้']], null, 'A1');
        $sheet->getCell('B2')->setValue(\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2031-02-02')));
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $tmp = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp);

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'b1', 'type' => 'function', 'function' => ['name' => 'propose_update', 'arguments' => json_encode(['entity' => 'labour', 'items' => [
                    ['query' => 'A1', 'changes' => ['labour_visa_date_end' => '2031-02-02', 'labour_note' => 'จากไฟล์']],
                    ['query' => 'A2', 'changes' => ['labour_visa_date_end' => '2032-03-03']],
                    ['query' => 'ZZ9', 'changes' => ['labour_visa_date_end' => '2030-01-01']],
                ]])]]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'แก้ได้ 2 แถว พิมพ์ ยืนยัน']))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'b2', 'type' => 'function', 'function' => ['name' => 'apply_update', 'arguments' => '{}']]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'บันทึก 2 รายการแล้ว'])),
        ]);

        $res = $this->actingAs($master)->post(route('ai.message'), [
            'message' => 'อัปเดตวีซ่าตามไฟล์นี้',
            'attachment' => new \Illuminate\Http\UploadedFile($tmp, 'visa update.xlsx', null, null, true),
        ], ['Accept' => 'application/json']);
        $res->assertOk()->assertJsonPath('tools_used', ['read_attachment', 'propose_update']);

        // The sheet reached the model as text rows with the Excel date rendered as Y-m-d; no OpenAI OCR call was made.
        Http::assertSentCount(2);
        Http::assertSent(function ($r) {
            $u = $r->data()['messages'][1]['content'] ?? '';

            return is_string($u) && str_contains($u, 'ตาราง Excel/CSV') && str_contains($u, 'หัวตาราง: passport | วีซ่าหมดอายุ | หมายเหตุ') && str_contains($u, 'แถว 2: A1 | 2031-02-02 | จากไฟล์') && str_contains($u, '3 แถวข้อมูล');
        });
        // Proposal: 2 valid rows + 1 failed, nothing written yet.
        Http::assertSent(function ($r) {
            $t = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool');
            $j = $t ? json_decode($t['content'], true) : null;

            return $j && ($j['valid'] ?? 0) === 2 && count($j['failed']) === 1 && str_contains($j['failed'][0]['error'], 'ไม่พบ') && $j['items'][1]['preview'][0]['new'] === '2032-03-03';
        });
        $this->assertSame(now()->addDays(200)->toDateString(), LabourModel::find(2)->labour_visa_date_end);

        $this->actingAs($master)->postJson(route('ai.message'), ['message' => 'ยืนยัน'])->assertOk()->assertJsonPath('answer', 'บันทึก 2 รายการแล้ว');
        $this->assertSame(['2031-02-02', 'จากไฟล์', '2032-03-03'], [LabourModel::find(1)->labour_visa_date_end, LabourModel::find(1)->labour_note, LabourModel::find(2)->labour_visa_date_end]);
        $this->assertSame(2, DB::table('action_logs')->where('action_type', 'updated')->count());
        Http::assertSent(fn ($r) => ($t = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool')) && str_contains($t['content'], '"count":2'));
        @unlink($tmp);
    }

    public function test_file_attached_to_a_question_is_read_and_its_text_given_to_the_model()
    {
        \Illuminate\Support\Facades\Cache::flush();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->openAiReply(['role' => 'assistant', 'content' => "1) จำนวนหน้า: 1\n2) ชื่อ-สกุล: SAN WIN\nเลขที่เอกสาร: A2\nวันหมดอายุ: 2031-05-05"]))   // OCR of the attachment
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'ไฟล์เป็นพาสปอร์ตของ SAN WIN หมดอายุ 2031-05-05'])),
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('passport SAN WIN.pdf', '%PDF-1.4 attached');
        $res = $this->actingAs($this->user())->post(route('ai.message'), [
            'message' => 'ตรวจสอบข้อมูลตามไฟล์นี้', 'conversation' => 'att-1',
            'history' => json_encode([['role' => 'user', 'content' => 'สวัสดี'], ['role' => 'assistant', 'content' => 'สวัสดีครับ']]),
            'attachment' => $file,
        ], ['Accept' => 'application/json']);
        $res->assertOk()->assertJsonPath('tools_used', ['read_attachment']);
        $this->assertStringContainsString('SAN WIN', $res->json('answer'));

        // The OCR request carried the PDF bytes; the chat request got the extracted text appended to the question + the history.
        Http::assertSent(fn ($r) => (($r->data()['messages'][1]['content'][1]['file']['filename'] ?? '') !== '') && str_contains($r->data()['messages'][1]['content'][1]['file']['file_data'], base64_encode('%PDF-1.4 attached')));
        Http::assertSent(function ($r) {
            $m = $r->data()['messages'] ?? [];
            $last = end($m);

            return count($m) === 4 && $m[1]['content'] === 'สวัสดี' && is_string($last['content'])
                && str_starts_with($last['content'], 'ตรวจสอบข้อมูลตามไฟล์นี้') && str_contains($last['content'], 'ไฟล์แนบจากผู้ใช้') && str_contains($last['content'], 'SAN WIN');
        });

        // Stored under the user's folder, logged with the file name, and bad types are refused.
        $this->assertCount(1, Storage::disk('local')->files('ai-uploads/7'));
        $this->assertStringContainsString('passport-san-win.pdf', Storage::disk('local')->files('ai-uploads/7')[0]);
        $this->assertStringStartsWith('[ไฟล์แนบ: ', AiChatLog::latest('id')->value('question'));
        $this->actingAs($this->user())->post(route('ai.message'), ['message' => 'x', 'attachment' => \Illuminate\Http\UploadedFile::fake()->create('virus.exe', 5)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('attachment');
    }

    public function test_master_admin_edits_data_in_two_steps_and_members_cannot()
    {
        $master = $this->user('MasterAdmin');
        \Illuminate\Support\Facades\Cache::flush();

        // --- Member: write tools are not offered, and are refused even if the model calls one.
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'm1', 'type' => 'function', 'function' => ['name' => 'propose_update', 'arguments' => json_encode(['entity' => 'labour', 'query' => 'A1', 'changes' => ['labour_visa_date_end' => '2031-01-01']])]]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'แก้ไม่ได้']))
            // (Http::fake merges stubs, so every turn of this test shares one sequence)
            // --- MasterAdmin step 1: propose -> preview, nothing written yet.
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'p1', 'type' => 'function', 'function' => ['name' => 'propose_update', 'arguments' => json_encode(['entity' => 'labour', 'query' => 'A1', 'changes' => ['visa_date_end' => '15/01/2031', 'company' => 'แสงลิน', 'sex' => 'หญิง', 'status_job' => 'ลาออก', 'resign_date' => '1/11/2569']])]]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'พิมพ์ ยืนยัน เพื่อบันทึก']))
            // step 2: the user confirms -> apply
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'a1', 'type' => 'function', 'function' => ['name' => 'apply_update', 'arguments' => '{}']]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'บันทึกแล้ว']))
            // bad field / ambiguous labour
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'p2', 'type' => 'function', 'function' => ['name' => 'propose_update', 'arguments' => json_encode(['entity' => 'labour', 'query' => 'A1', 'changes' => ['labour_id' => '99', 'created_by' => 'x']])]],
                ['id' => 'p3', 'type' => 'function', 'function' => ['name' => 'propose_update', 'arguments' => json_encode(['entity' => 'company', 'query' => 'เก่ง', 'changes' => ['company_phone' => '02-999-9999']])]]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'ok'])),
        ]);

        $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'เปลี่ยนวันหมดอายุวีซ่า A1'])->assertOk();
        Http::assertSent(fn ($r) => ! in_array('propose_update', array_column(array_column($r->data()['tools'] ?? [], 'function'), 'name'), true));
        Http::assertSent(fn ($r) => ($t = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool')) && str_contains($t['content'], 'MasterAdmin'));
        $this->assertSame(now()->addDays(10)->toDateString(), LabourModel::find(1)->labour_visa_date_end);

        $this->actingAs($master)->postJson(route('ai.message'), ['message' => 'แก้ A1: วีซ่าหมด 15/01/2031 ย้ายไปบริษัทแสงลิน เพศหญิง สถานะลาออก 1/11/2569'])->assertOk();
        Http::assertSent(fn ($r) => in_array('propose_update', array_column(array_column($r->data()['tools'] ?? [], 'function'), 'name'), true));
        $proposal = null;
        Http::assertSent(function ($r) use (&$proposal) {
            $t = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool');
            if ($t && str_contains($t['content'], '"token"')) {
                $proposal = json_decode($t['content'], true);
            }

            return (bool) $t;
        });
        $this->assertNotNull($proposal, 'tool result with token');
        $preview = collect($proposal['preview'])->keyBy('field');
        $this->assertSame('2031-01-15', $preview['labour_visa_date_end']['new']);
        $this->assertSame('แสงลินฟู้ดส์ (#2)', $preview['company_id']['new']);
        $this->assertSame('เก่ง เอ็นจิเนียริ่ง (#1)', $preview['company_id']['old']);
        $this->assertSame('female', $preview['labour_sex']['new']);
        $this->assertSame('2026-11-01', $preview['labour_resing_date']['new']);   // Thai year converted
        $this->assertSame('resign', $preview['labour_status_job']['new']);
        $this->assertSame('male', LabourModel::find(1)->labour_sex);           // not written yet
        $this->assertSame(0, DB::table('action_logs')->where('action_type', 'updated')->count());

        $this->actingAs($master)->postJson(route('ai.message'), ['message' => 'ยืนยัน'])->assertOk()->assertJsonPath('answer', 'บันทึกแล้ว');
        $l = LabourModel::find(1);
        $this->assertSame(['2031-01-15', '2', 'female', 'resign', '2026-11-01', 'Tester (AI)'], [$l->labour_visa_date_end, (string) $l->company_id, $l->labour_sex, $l->labour_status_job, $l->labour_resing_date, $l->updated_by]);
        $this->assertSame(1, DB::table('action_logs')->where('action_type', 'updated')->where('model_id', 1)->count());
        Http::assertSent(fn ($r) => ($t = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool')) && str_contains($t['content'], '"updated":true'));

        // A second "ยืนยัน" with nothing pending does not write anything; unknown fields are rejected; company edit works.
        $this->actingAs($master)->postJson(route('ai.message'), ['message' => 'แก้อีก'])->assertOk();
        Http::assertSent(function ($r) {
            $tools = collect($r->data()['messages'] ?? [])->where('role', 'tool')->pluck('content');

            return $tools->count() === 2 && str_contains($tools[0], 'ไม่รู้จักฟิลด์') && str_contains($tools[1], '"token"') && str_contains($tools[1], '02-999-9999');
        });
        $this->assertNull(\App\Models\company\CompanyModel::find(1)->company_phone);
    }

    public function test_google_vision_reads_every_page_and_the_chat_model_structures_it()
    {
        config(['services.google.vision_key' => 'vision-test']);
        Storage::disk('public')->put('A1/A1_Passport.pdf', "%PDF-1.4\n1 0 obj<</Type/Page>>endobj 2 0 obj<</Type/Page>>endobj 3 0 obj<</Type/Page>>endobj");
        \App\Models\labour\LabourDocument::create(['labour_id' => 1, 'name' => 'Passport', 'disk' => 'public', 'path' => 'A1/A1_Passport.pdf', 'original_name' => 'A1_Passport.pdf', 'size' => 90]);
        \Illuminate\Support\Facades\Cache::flush();

        Http::fake([
            'vision.googleapis.com/*' => Http::response(['responses' => [['totalPages' => 3, 'responses' => [
                ['context' => ['pageNumber' => 1], 'fullTextAnnotation' => ['text' => "PASSPORT\nAUNG MYO\nA1\n01 JAN 2030"]],
                ['context' => ['pageNumber' => 2], 'fullTextAnnotation' => ['text' => 'VISA STAY PERMIT UNTIL 1 JUN 2026']],
                ['context' => ['pageNumber' => 3]],
            ]]]]),
            'api.openai.com/*' => Http::sequence()
                ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_labour_document', 'arguments' => json_encode(['query' => 'A1', 'document' => 'passport'])]]]]))
                ->push($this->openAiReply(['role' => 'assistant', 'content' => "1) จำนวนหน้า: 3\n2) ชื่อ-สกุล: AUNG MYO\n3) หน้า 1: พาสปอร์ต หน้า 2: วีซ่า หน้า 3: ว่าง"]))
                ->push($this->openAiReply(['role' => 'assistant', 'content' => 'ไฟล์มี 3 หน้า'])),
        ]);

        $res = $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'ไฟล์พาสปอร์ต A1 มีกี่หน้า']);
        $res->assertOk()->assertJsonPath('answer', 'ไฟล์มี 3 หน้า');

        // Vision got the PDF with pages 1-3 requested; the structuring call received the per-page OCR text, not the file.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'files:annotate') && $r->data()['requests'][0]['pages'] === [1, 2, 3]
            && $r->data()['requests'][0]['inputConfig']['mimeType'] === 'application/pdf');
        Http::assertSent(function ($r) {
            $u = $r->data()['messages'][1]['content'] ?? null;

            return is_string($u) && str_contains($u, '===== หน้า 2 =====') && str_contains($u, 'STAY PERMIT') && str_contains($u, 'ไม่พบข้อความที่อ่านได้ในหน้านี้');
        });
        Http::assertSent(function ($r) {
            $tool = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool');

            return $tool && str_contains($tool['content'], '"pages":3') && str_contains($tool['content'], '"engine":"google-vision"');
        });
    }

    public function test_assistant_can_read_a_document_file_through_the_vision_model()
    {
        Storage::disk('public')->put('A1/A1_Passport.pdf', '%PDF-1.4 fake passport');
        \App\Models\labour\LabourDocument::create(['labour_id' => 1, 'name' => 'Passport', 'disk' => 'public', 'path' => 'A1/A1_Passport.pdf', 'original_name' => 'A1_Passport.pdf', 'size' => 22]);
        \Illuminate\Support\Facades\Cache::flush();

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_labour_document', 'arguments' => json_encode(['query' => 'A1', 'document' => 'passport'])]]]]))
                ->push($this->openAiReply(['role' => 'assistant', 'content' => "ประเภทเอกสาร: หนังสือเดินทาง\nชื่อ-สกุล: AUNG MYO\nเลขที่เอกสาร: A1\nวันหมดอายุ: 2030-01-01"]))
                ->push($this->openAiReply(['role' => 'assistant', 'content' => 'ในไฟล์ระบุชื่อ **AUNG MYO** เลขที่ A1 หมดอายุ 2030-01-01 ตรงกับระบบ']))
                // second turn: same file -> served from cache, so only the two chat calls
                ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'read_labour_document', 'arguments' => json_encode(['query' => 'A1', 'document' => '1'])]]]]))
                ->push($this->openAiReply(['role' => 'assistant', 'content' => 'ตอบจาก cache']))
                // third turn: unknown document
                ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'c3', 'type' => 'function', 'function' => ['name' => 'read_labour_document', 'arguments' => json_encode(['query' => 'A1', 'document' => 'visa'])]]]]))
                ->push($this->openAiReply(['role' => 'assistant', 'content' => 'ไม่มีไฟล์วีซ่า'])),
        ]);

        $res = $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'ดึงเนื้อหาในไฟล์พาสปอร์ตของ A1 มาหน่อย']);
        $res->assertOk()->assertJsonPath('tools_used', ['read_labour_document']);
        $this->assertStringContainsString('AUNG MYO', $res->json('answer'));

        // The middle request carried the PDF as a base64 file part for the vision model.
        Http::assertSentCount(3);
        Http::assertSent(function ($request) {
            $parts = $request->data()['messages'][1]['content'] ?? null;
            if (! is_array($parts) || ($parts[1]['type'] ?? '') !== 'file') {
                return false;
            }
            $data = $parts[1]['file']['file_data'];

            return $parts[1]['file']['filename'] === 'A1_Passport.pdf'
                && str_starts_with($data, 'data:application/pdf;base64,')
                && base64_decode(substr($data, strlen('data:application/pdf;base64,'))) === '%PDF-1.4 fake passport';
        });

        // The chat model got the OCR text back as the tool result.
        Http::assertSent(function ($request) {
            $tool = collect($request->data()['messages'] ?? [])->firstWhere('role', 'tool');

            return $tool && str_contains($tool['content'], 'AUNG MYO') && str_contains($tool['content'], '"document":"Passport"');
        });

        // Second read of the same file is served from cache: only the two chat calls hit OpenAI (3 + 2).
        $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'อ่านอีกที'])->assertOk()->assertJsonPath('answer', 'ตอบจาก cache');
        Http::assertSentCount(5);

        // Unknown document -> the tool explains and lists what exists instead of failing.
        $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'อ่านวีซ่า'])->assertOk();
        Http::assertSentCount(7);
        Http::assertSent(fn ($r) => ($t = collect($r->data()['messages'] ?? [])->firstWhere('role', 'tool')) && str_contains($t['content'], 'document_key'));
    }

    private function user(string $type = 'Member'): User
    {
        $u = new User(['name' => 'Tester', 'email' => 't@test.local', 'type' => $type, 'status' => 'enable']);
        $u->id = 7;

        return $u;
    }

    public function test_every_exchange_is_logged_and_master_admin_can_browse_it()
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'count_labours', 'arguments' => '{}']]]]))
            ->push($this->openAiReply(['role' => 'assistant', 'content' => 'มีทั้งหมด **4 คน**']))
            ->push(['error' => ['message' => 'boom']], 500),
        ]);
        DB::table('users')->insert(['id' => 7, 'name' => 'Tester', 'type' => 'Member']);

        $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'มีแรงงานกี่คน', 'conversation' => 'abc-123'])->assertOk();
        $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'คำถามที่พัง', 'conversation' => 'abc-123'])->assertStatus(502);

        $this->assertDatabaseCount('ai_chat_logs', 2);
        $ok = AiChatLog::where('status', 'ok')->first();
        $this->assertSame(['user_id' => 7, 'conversation_id' => 'abc-123', 'question' => 'มีแรงงานกี่คน', 'tools' => ['count_labours'], 'tokens' => 30],
            ['user_id' => $ok->user_id, 'conversation_id' => $ok->conversation_id, 'question' => $ok->question, 'tools' => $ok->tools_used, 'tokens' => $ok->totalTokens()]);
        $this->assertStringContainsString('4 คน', $ok->answer);
        $this->assertSame('error', AiChatLog::where('question', 'คำถามที่พัง')->value('status'));

        // Members cannot see the log page; MasterAdmin can, with filters.
        $this->actingAs($this->user())->get(route('ai.logs'))->assertRedirect(route('dashboard'));
        $master = $this->user('MasterAdmin');
        $this->actingAs($master)->get(route('ai.logs'))->assertOk()->assertSee('มีแรงงานกี่คน')->assertSee('คำถามที่พัง');
        $this->actingAs($master)->get(route('ai.logs', ['status' => 'error']))->assertOk()->assertSee('คำถามที่พัง')->assertDontSee('มีแรงงานกี่คน');
        $this->actingAs($master)->get(route('ai.logs', ['tool' => 'count_labours']))->assertOk()->assertSee('มีแรงงานกี่คน');
        $this->actingAs($master)->get(route('ai.logs', ['search' => 'ไม่มีคำนี้']))->assertOk()->assertSee('ยังไม่มีประวัติการถาม AI');
    }

    private function openAiReply(array $message): array
    {
        return ['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
    }

    public function test_query_service_filters_are_whitelisted_and_correct()
    {
        $svc = app(LabourQueryService::class);

        $this->assertSame(4, $svc->count([])['total']);
        $this->assertSame(2, $svc->count(['nationality' => 'พม่า'])['total']);
        $this->assertSame(1, $svc->count(['company' => 'แสงลิน', 'status_job' => 'job'])['total']);

        // expiring within 30 days: A1 (10d) only; A4 is resigned, A3 already expired
        $this->assertSame(1, $svc->count(['expiring' => ['type' => 'visa', 'within_days' => 30]])['total']);
        $this->assertSame(2, $svc->count(['expiring' => ['type' => 'visa', 'within_days' => 30, 'include_expired' => true]])['total']);
        $this->assertSame(1, $svc->count(['expired' => ['type' => 'visa']])['total']);

        $grouped = $svc->count(['group_by' => 'company']);
        $this->assertEqualsCanonicalizing(['เก่ง เอ็นจิเนียริ่ง' => 2, 'แสงลินฟู้ดส์' => 2], array_column($grouped['groups'], 'total', 'group'));

        $search = $svc->search(['keyword' => 'RATH']);
        $this->assertSame(1, $search['total']);
        $this->assertSame('กัมพูชา', $search['rows'][0]['nationality']);

        // company names match without the legal-form words and in any order of the words
        foreach (['เก่ง เอ็นจิเนียริ่ง', 'บริษัท เก่ง เอ็นจิเนียริ่ง จำกัด', 'เก่ง เอ็นจิเนียริ่ง บจก.', 'เอ็นจิเนียริ่ง เก่ง'] as $name) {
            $this->assertSame(2, $svc->count(['company' => $name])['total'], $name);
        }
        $this->assertSame(0, $svc->count(['company' => 'บริษัท ไม่มีจริง จำกัด'])['total']);

        // worker names: prefix and word order do not matter
        foreach (['Ms. AUNG MYO', 'Mr AUNG MYO', 'นาย AUNG MYO', 'aung myo', 'MYO AUNG'] as $name) {
            $this->assertSame(1, $svc->search(['keyword' => $name])['total'], $name);
        }

        // unknown / malicious values are ignored, never interpolated
        $this->assertSame(4, $svc->count(['date_field' => 'labour_id) OR 1=1 --', 'date_from' => '2000-01-01', 'order_by' => 'DROP TABLE'])['total']);
    }

    public function test_chat_round_trip_with_tool_calls_and_excel_report()
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openAiReply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'count_labours', 'arguments' => json_encode(['filters' => ['expiring' => ['type' => 'visa', 'within_days' => 30]]])]],
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'create_excel_report', 'arguments' => json_encode(['title' => 'วีซ่าใกล้หมดอายุ', 'filters' => ['expiring' => ['type' => 'visa', 'within_days' => 30]], 'columns' => ['fullname', 'visa_date_end', 'visa_days_left']])]],
                ]]))
                ->push($this->openAiReply(['role' => 'assistant', 'content' => "มี **1 คน** ดาวน์โหลดได้ที่ [ดาวน์โหลด Excel](URL)"])),
        ]);

        $res = $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'วีซ่าใกล้หมดใน 30 วันมีกี่คน ขอ Excel ด้วย', 'history' => []]);
        $res->assertOk()->assertJsonPath('tools_used', ['count_labours', 'create_excel_report'])->assertJsonPath('reports.0.rows', 1);
        $this->assertStringContainsString('1 คน', $res->json('answer'));

        $file = $res->json('reports.0.file');
        Storage::disk('local')->assertExists('ai-reports/' . $file);

        // The tool results were sent back to the model in the second request.
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $toolMsgs = array_values(array_filter($body['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'tool'));
            if (! $toolMsgs) {
                return false;
            }
            $count = json_decode($toolMsgs[0]['content'], true);
            $report = json_decode($toolMsgs[1]['content'], true);

            return $count['total'] === 1 && $report['created'] === true && $report['rows'] === 1;
        });

        // Download route works and refuses path tricks.
        $this->actingAs($this->user())->get(route('ai.report', ['file' => $file]))->assertOk();
        $this->actingAs($this->user())->get('/ai-chat/report/..%2F..%2F.env')->assertNotFound();
    }

    public function test_page_and_validation()
    {
        $this->get('/ai-chat')->assertRedirect(route('login')); // guest first: actingAs() sticks for the rest of the test
        $this->actingAs($this->user())->get('/ai-chat')->assertOk()->assertSee('ผู้ช่วย AI');
        $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => ''])->assertStatus(422);
    }

    /** Separate test: the route's controller instance is cached per app, so the key must be unset before the first call. */
    public function test_missing_api_key_gives_a_clear_503()
    {
        config(['services.openai.key' => null]);
        $this->actingAs($this->user())->postJson(route('ai.message'), ['message' => 'สวัสดี'])->assertStatus(503)->assertJsonFragment(['error' => 'ยังไม่ได้ตั้งค่า OPENAI_API_KEY ใน .env']);
        $this->actingAs($this->user())->get('/ai-chat')->assertOk()->assertSee('OPENAI_API_KEY');
    }
}
