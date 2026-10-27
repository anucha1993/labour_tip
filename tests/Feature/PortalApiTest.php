<?php

namespace Tests\Feature;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Worker portal API (consumed by labour.importer168.com) on an in-memory SQLite database.
 */
class PortalApiTest extends TestCase
{
    private const KEY = 'portal-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite']);
        config(['services.portal.key' => self::KEY, 'app.url' => 'http://admin.test']);
        DB::purge('testing_sqlite');
        LabourDocument::forgetTableReady();
        URL::forceRootUrl('http://admin.test');

        Schema::create('labour', function (Blueprint $t) {
            $t->bigIncrements('labour_id');
            foreach ((new LabourModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('labour_documents', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('labour_id'); $t->string('name'); $t->string('disk'); $t->string('path');
            $t->string('original_name')->nullable(); $t->unsignedBigInteger('size')->nullable(); $t->string('mime')->nullable();
            $t->string('legacy_column')->nullable(); $t->integer('sort_order')->default(0); $t->unsignedBigInteger('uploaded_by')->nullable(); $t->timestamps();
        });
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_address')->nullable(); $t->string('company_province')->nullable(); $t->string('company_amphur')->nullable(); $t->string('company_district')->nullable(); $t->string('company_zipcode')->nullable(); $t->string('company_phone')->nullable(); $t->string('company_contact')->nullable(); $t->string('company_type')->nullable(); $t->string('company_status')->nullable(); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        Schema::create('settings', function (Blueprint $t) { $t->increments('set_id'); $t->string('set_name'); $t->integer('set_expire'); });
        Schema::create('provinces', function (Blueprint $t) { $t->increments('id'); $t->string('name_th'); $t->string('name_en'); });
        Schema::create('amphures', function (Blueprint $t) { $t->increments('id'); $t->string('name_th'); $t->string('name_en'); $t->integer('province_id'); });
        Schema::create('districts', function (Blueprint $t) { $t->increments('id'); $t->string('name_th'); $t->string('name_en'); $t->integer('amphure_id'); });
        Schema::create('action_logs', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action_type'); $t->string('model_type'); $t->unsignedBigInteger('model_id'); $t->text('old_values')->nullable(); $t->text('new_values')->nullable(); $t->timestamp('created_at')->nullable(); });

        DB::table('nationality')->insert([['code' => 'myanmar', 'name_th' => 'พม่า']]);
        DB::table('provinces')->insert(['id' => 1, 'name_th' => 'กรุงเทพมหานคร', 'name_en' => 'Bangkok']);
        DB::table('amphures')->insert(['id' => 10, 'name_th' => 'คลองเตย', 'name_en' => 'Khlong Toei', 'province_id' => 1]);
        DB::table('districts')->insert(['id' => 100, 'name_th' => 'คลองเตย', 'name_en' => 'Khlong Toei', 'amphure_id' => 10]);
        DB::table('company')->insert(['company_id' => 1, 'company_name' => 'แสงลินฟู้ดส์', 'company_address' => '123/45 ถนนสุขุมวิท', 'company_province' => '1', 'company_amphur' => '10', 'company_district' => '100', 'company_zipcode' => '10110']);
        DB::table('agency')->insert(['agency_id' => 1, 'agency_name' => 'TOP MANPOWER']);
        DB::table('settings')->insert([['set_name' => 'visa', 'set_expire' => 90], ['set_name' => 'work', 'set_expire' => 30], ['set_name' => 'ninety', 'set_expire' => 15], ['set_name' => 'passport', 'set_expire' => 90]]);

        LabourModel::create([
            'labour_prefix' => 'Mr', 'labour_fullname' => 'MYO MIN AUNG', 'labour_sex' => 'male', 'labour_nationality' => 'myanmar',
            'labour_birthday' => '1997-12-06', 'company_id' => 1, 'labour_agency' => 1, 'labour_passport_number' => 'AA1234567',
            'labour_passport_date_end' => now()->addDays(450)->toDateString(),
            'labour_workpremit_number' => '1234-5678-90', 'labour_workpremit_date_start' => '2025-01-01', 'labour_workpremit_date_end' => now()->addDays(65)->toDateString(),
            'labour_day90_date_end' => now()->addDays(10)->toDateString(),
            'labour_status' => 'enable', 'labour_status_job' => 'job', 'labour_file_passport' => 'AA1234567_Passport.pdf',
        ]);
        LabourModel::create(['labour_fullname' => 'OLD GUY', 'labour_birthday' => '1980-01-02', 'labour_passport_number' => 'BB0000001', 'labour_status' => 'disable', 'labour_status_job' => 'resign']);

        Storage::fake('public');
        Storage::disk('public')->put('AA1234567/AA1234567_Passport.pdf', '%PDF-1.4 legacy');
        Storage::disk('public')->put('AA1234567/AA1234567_Visa.pdf', '%PDF-1.4 row');
        LabourDocument::create(['labour_id' => 1, 'name' => 'Visa', 'disk' => 'public', 'path' => 'AA1234567/AA1234567_Visa.pdf', 'original_name' => 'AA1234567_Visa.pdf', 'size' => 12]);
    }

    protected function tearDown(): void
    {
        LabourDocument::forgetTableReady();
        parent::tearDown();
    }

    private function api(): static
    {
        return $this->withHeader('X-Portal-Key', self::KEY);
    }

    public function test_requests_without_the_shared_key_are_rejected()
    {
        $this->postJson('/api/portal/login', ['passport' => 'AA1234567', 'password' => '06121997'])->assertStatus(401);
        $this->withHeader('X-Portal-Key', 'wrong')->getJson('/api/portal/labours/1')->assertStatus(401);

        config(['services.portal.key' => null]);
        $this->api()->getJson('/api/portal/labours/1')->assertStatus(503);
    }

    public function test_login_with_passport_and_birthday_returns_the_profile()
    {
        $res = $this->api()->postJson('/api/portal/login', ['passport' => ' aa1234567 ', 'password' => '06/12/1997']);
        $res->assertOk()
            ->assertJsonPath('labour_id', 1)
            ->assertJsonPath('profile.full_name', 'Mr MYO MIN AUNG')
            ->assertJsonPath('profile.nationality.name_th', 'พม่า')
            ->assertJsonPath('profile.nationality.name_en', 'Myanmar')
            ->assertJsonPath('profile.status.active', true)
            ->assertJsonPath('profile.status.label_th', 'ทำงาน')
            ->assertJsonPath('profile.employer.name', 'แสงลินฟู้ดส์')
            ->assertJsonPath('profile.employer.address.text_th', '123/45 ถนนสุขุมวิท แขวงคลองเตย เขตคลองเตย กรุงเทพมหานคร 10110')
            ->assertJsonPath('profile.employer.address.text_en', '123/45 ถนนสุขุมวิท, Khlong Toei, Khlong Toei, Bangkok, 10110')
            ->assertJsonPath('profile.passport.days_left', 450)
            ->assertJsonPath('profile.passport.level', 'ok')
            ->assertJsonPath('profile.work_permit.number', '1234-5678-90')
            ->assertJsonPath('profile.work_permit.days_left', 65)
            ->assertJsonPath('profile.work_permit.date_start_text', '01/01/2025')
            ->assertJsonPath('profile.ninety_day.days_left', 10)
            ->assertJsonPath('profile.ninety_day.level', 'warning')
            ->assertJsonPath('profile.visa.days_left', null)
            ->assertJsonPath('profile.visa.level', 'none');

        // Alerts: only the 90-day report (10 days left <= 15) qualifies; work permit 65 > 30, passport 450 > 90.
        $this->assertSame(['ninety_day'], array_column($res->json('profile.alerts'), 'type'));

        // Documents: the labour_documents row plus the legacy passport column.
        $docs = $res->json('profile.documents');
        $this->assertEqualsCanonicalizing(['1', 'legacy-labour_file_passport'], array_column($docs, 'key'));
        $this->assertEqualsCanonicalizing(['Visa', 'Passport'], array_column($docs, 'name'));
    }

    public function test_login_failures()
    {
        $this->api()->postJson('/api/portal/login', ['passport' => 'AA1234567', 'password' => '07121997'])
            ->assertStatus(422)->assertJsonPath('errors.passport.0', 'invalid_credentials');
        $this->api()->postJson('/api/portal/login', ['passport' => 'NOPE', 'password' => '06121997'])
            ->assertStatus(422)->assertJsonPath('errors.passport.0', 'invalid_credentials');
        $this->api()->postJson('/api/portal/login', ['passport' => 'BB0000001', 'password' => '02011980'])
            ->assertStatus(422)->assertJsonPath('errors.passport.0', 'account_disabled');
        $this->api()->postJson('/api/portal/login', ['passport' => '', 'password' => ''])->assertStatus(422);

        $this->api()->getJson('/api/portal/labours/2')->assertStatus(403);
        $this->api()->getJson('/api/portal/labours/999')->assertStatus(404);
    }

    public function test_document_links_are_signed_and_scoped_to_the_labour()
    {
        $link = $this->api()->getJson('/api/portal/labours/1/documents/legacy-labour_file_passport/link');
        $link->assertOk()->assertJsonPath('file_name', 'AA1234567_Passport.pdf');
        $url = $link->json('url');
        $this->assertStringStartsWith('http://admin.test/portal-file/1/legacy-labour_file_passport?', $url);
        $this->assertStringContainsString('signature=', $url);

        // The signed URL streams the file with no login at all.
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        // Tampered signature -> 403.
        $this->get($url . 'x')->assertStatus(403);
        // Unsigned direct access -> 403.
        $this->get('/portal-file/1/1')->assertStatus(403);

        // Row-based document and unknown key.
        $this->api()->getJson('/api/portal/labours/1/documents/1/link')->assertOk()->assertJsonPath('name', 'Visa');
        $this->api()->getJson('/api/portal/labours/1/documents/42/link')->assertStatus(404);
        $this->api()->getJson('/api/portal/labours/2/documents/1/link')->assertStatus(403);
    }
}
