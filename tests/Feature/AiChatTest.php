<?php

namespace Tests\Feature;

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
        config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-test']);
        DB::purge('testing_sqlite');

        Schema::create('labour', function (Blueprint $t) {
            $t->bigIncrements('labour_id');
            foreach ((new LabourModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_tax')->nullable(); $t->string('company_contact_sale')->nullable(); $t->string('company_status')->nullable(); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); $t->string('agency_nationality')->nullable(); });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        Schema::create('settings', function (Blueprint $t) { $t->increments('set_id'); $t->string('set_name'); $t->integer('set_expire'); });
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
    }

    private function user(): User
    {
        $u = new User(['name' => 'Tester', 'email' => 't@test.local', 'type' => 'Member', 'status' => 'enable']);
        $u->id = 7;

        return $u;
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
