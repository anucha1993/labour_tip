<?php

namespace Tests\Feature;

use App\Models\labour\LabourModel;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Dashboard page (/dashboard) and the numbers behind it, on an in-memory SQLite database.
 */
class AnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite']);
        DB::purge('testing_sqlite');
        Cache::flush();
        Permissions::forget();
        Carbon::setTestNow('2026-11-06 09:00:00');

        Schema::create('labour', function (Blueprint $t) {
            $t->bigIncrements('labour_id');
            foreach ((new LabourModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        DB::table('nationality')->insert([['code' => 'myanmar', 'name_th' => 'พม่า'], ['code' => 'lao', 'name_th' => 'ลาว']]);
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_status')->default('enable'); });
        DB::table('company')->insert([['company_id' => 1, 'company_name' => 'บริษัท เอ จำกัด'], ['company_id' => 2, 'company_name' => 'บริษัท บี จำกัด']]);
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); });
        DB::table('agency')->insert([['agency_id' => 1, 'agency_name' => 'เอเจนซี่หนึ่ง'], ['agency_id' => 2, 'agency_name' => 'เอเจนซี่สอง']]);
        Schema::create('settings', function (Blueprint $t) { $t->increments('set_id'); $t->string('set_name'); $t->integer('set_expire'); });
        Schema::create('action_logs', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action_type'); $t->string('model_type');
            $t->unsignedBigInteger('model_id'); $t->text('old_values')->nullable(); $t->text('new_values')->nullable(); $t->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Permissions::forget();
        parent::tearDown();
    }

    private function user(string $type): User
    {
        $u = new User(['name' => $type . ' Tester', 'email' => strtolower($type) . '@test.local', 'type' => $type, 'status' => 'enable']);
        $u->id = 1;

        return $u;
    }

    private function labour(array $attrs): LabourModel
    {
        return LabourModel::create($attrs + [
            'labour_prefix' => 'Mr', 'labour_fullname' => 'WORKER', 'labour_sex' => 'male', 'labour_nationality' => 'myanmar',
            'labour_passport_number' => 'P' . random_int(100000, 999999), 'labour_status_job' => 'job', 'labour_status' => 'enable', 'company_id' => 1,
            'labour_agency' => 1, 'labour_jobdate_start' => '2024-01-15', 'labour_birthday' => '1996-05-01',
        ]);
    }

    public function test_only_profiles_with_analytics_view_can_open_the_dashboard()
    {
        $this->assertTrue(Permissions::has('Admin', 'analytics.view'));
        $this->assertFalse(Permissions::has('Member', 'analytics.view'));
        $this->actingAs($this->user('Member'))->get('/dashboard')->assertRedirect(route('dashboard'));
        $this->actingAs($this->user('Admin'))->get('/dashboard')->assertOk()->assertSee('Dashboard')->assertDontSee('การใช้งานระบบ 30 วัน');
        $this->actingAs($this->user('MasterAdmin'))->get('/dashboard')->assertOk()->assertSee('การใช้งานระบบ 30 วัน');
        // the sidebar link follows the permission
        $this->actingAs($this->user('Admin'))->get('/dashboard')->assertSee(route('analytics.index') . '" class="sb-link', false);
    }

    public function test_the_numbers_are_computed_from_the_workforce()
    {
        // A: visa due this month and work permit due in 2 months; passport already expired.
        $a = $this->labour(['labour_fullname' => 'A', 'labour_visa_date_end' => '2026-11-20', 'labour_workpremit_date_end' => '2027-01-05', 'labour_passport_date_end' => '2026-10-20', 'labour_photo' => 'a.jpg']);
        // B: visa next month, nothing overdue, Lao woman from agency 2, joined last month.
        $this->labour(['labour_fullname' => 'B', 'labour_sex' => 'female', 'labour_nationality' => 'lao', 'labour_agency' => 2, 'labour_visa_date_end' => '2026-12-02', 'labour_jobdate_start' => '2026-10-10', 'labour_birthday' => '1980-01-01']);
        // C: escaped in September (agency 1), company 2.
        $this->labour(['labour_fullname' => 'C', 'labour_status_job' => 'escape', 'labour_escape_date' => '2026-09-15', 'company_id' => 2, 'labour_visa_date_end' => '2026-11-25']);
        // D: resigned long ago; its expired visa must not count as overdue.
        $this->labour(['labour_fullname' => 'D', 'labour_status_job' => 'resign', 'labour_resing_date' => '2025-03-01', 'labour_visa_date_end' => '2025-01-01']);

        $data = app(AnalyticsService::class)->build(0, 12, true);

        $this->assertSame(['total' => 4, 'job' => 2, 'resign' => 1, 'escape' => 1], array_intersect_key($data['summary'], array_flip(['total', 'job', 'resign', 'escape'])));
        $this->assertSame(1, $data['summary']['joined_30']);

        // Timeline: Nov = 1 visa (A; C escaped so not counted), Dec = 1 visa (B), Jan = 1 work permit (A).
        $tl = $data['timeline'];
        $this->assertSame('2026-11', $tl['keys'][0]);
        $this->assertSame([1, 1, 0], array_slice($tl['series']['visa'], 0, 3));
        $this->assertSame([0, 0, 1], array_slice($tl['series']['work'], 0, 3));
        $this->assertSame('พ.ย. 69', $tl['labels'][0]);

        // Overdue: only A's passport (D resigned).
        $this->assertSame(1, $data['overdue']['passport']['count']);
        $this->assertSame(1, $data['overdue']['passport']['last_30']);
        $this->assertSame(0, $data['overdue']['visa']['count']);
        $this->assertSame(1, $data['overdue']['total']);

        // Companies: company 1 has 2 working, 1 overdue, 1 soon (A's visa within 90 days + B's), company 2 has none working so it is left out.
        $c1 = collect($data['companies'])->firstWhere('id', 1);
        $this->assertSame([2, 1, 2, 1], [$c1['job'], $c1['overdue'], $c1['soon'], $c1['no_photo']]);
        $this->assertNull(collect($data['companies'])->firstWhere('id', 2));

        // Completeness over the 2 working: photo 50%, visa end 100%.
        $this->assertSame(50.0, $data['completeness']['items']['photo']['pct']);
        $this->assertSame(100.0, $data['completeness']['items']['visa_end']['pct']);
        $this->assertSame('ninety_end', array_key_first($data['completeness']['items'])); // 0% comes first

        // Trend: B joined 2026-10, C escaped 2026-09.
        $tr = $data['trend'];
        $oct = array_search('2026-10', $tr['keys'], true);
        $sep = array_search('2026-09', $tr['keys'], true);
        $this->assertSame(1, $tr['series']['joined'][$oct]);
        $this->assertSame(1, $tr['series']['escaped'][$sep]);
        $this->assertSame(2, end($tr['series']['working']));

        // Demographics of the 2 working: 1 myanmar + 1 lao, 1 man + 1 woman, ages 30 and 46.
        $this->assertSame(['พม่า', 'ลาว'], array_column($data['demographics']['nationality'], 'label'));
        $this->assertSame([1, 1], array_column($data['demographics']['sex'], 'count'));
        $age = collect($data['demographics']['age'])->pluck('count', 'label');
        $this->assertSame([1, 1], [$age['25-34'], $age['45-54']]);

        // Agencies: agency 1 brought A, C, D → 1 escape of 3 = 33.3%; agency 2 → 0%.
        $ag = collect($data['agencies'])->keyBy('id');
        $this->assertSame(33.3, $ag[1]['rate']);
        $this->assertSame(0.0, $ag[2]['rate']);

        // System card present for MasterAdmin, absent otherwise; cached per filter set.
        $this->assertArrayHasKey('system', $data);
        $this->assertArrayNotHasKey('system', app(AnalyticsService::class)->build(0, 12, false));
        $this->labour(['labour_fullname' => 'E']);
        $this->assertSame(2, app(AnalyticsService::class)->build(0, 12, true)['summary']['job'], 'cached for 10 minutes');
        $this->assertSame(3, app(AnalyticsService::class)->build(0, 6, true)['summary']['job']);

        // Company filter narrows everything.
        $only2 = app(AnalyticsService::class)->build(2, 12, false);
        $this->assertSame(['total' => 1, 'job' => 0, 'escape' => 1], array_intersect_key($only2['summary'], array_flip(['total', 'job', 'escape'])));
        $this->assertSame([], $only2['companies']);
    }

    public function test_the_page_renders_the_cards_and_links_into_the_worker_list()
    {
        $this->labour(['labour_fullname' => 'A', 'labour_visa_date_end' => '2026-11-20', 'labour_passport_date_end' => '2026-10-20']);
        $res = $this->actingAs($this->user('MasterAdmin'))->get('/dashboard?company=1&months=6')->assertOk();
        $res->assertSee('บริษัท เอ จำกัด')
            ->assertSee('ปริมาณงานต่ออายุล่วงหน้า 6 เดือน')
            ->assertSee('เอกสารหมดอายุแล้วแต่ยังทำงานอยู่')
            ->assertSee('column_name_type=labour_passport_date_end', false)
            ->assertSee('company_id=1', false)
            ->assertSee('id="chTimeline"', false)
            ->assertDontSee('บริษัทที่ต้องตามงาน'); // hidden when one company is selected
        $this->actingAs($this->user('MasterAdmin'))->get('/dashboard?company=999&months=99')->assertOk()->assertSee('ทุกบริษัท')->assertSee('12 เดือน');
    }
}
