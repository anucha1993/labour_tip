<?php

namespace Tests\Feature;

use App\Models\company\CompanyModel;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * English company name/address for the Worker App: auto-filled by Google Translate on save
 * (only when left blank) and in bulk by company:translate. Google is faked.
 */
class CompanyTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite', 'services.google.translate_key' => 'g-test']);
        DB::purge('testing_sqlite');

        Schema::create('company', function (Blueprint $t) {
            $t->increments('company_id');
            foreach ((new CompanyModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('labour', function (Blueprint $t) { $t->increments('labour_id'); $t->integer('company_id')->nullable(); $t->string('labour_status')->nullable(); $t->string('labour_status_job')->nullable(); $t->string('labour_visa_date_end')->nullable(); $t->string('labour_passport_date_end')->nullable(); $t->string('labour_workpremit_date_end')->nullable(); $t->string('labour_day90_date_end')->nullable(); });
        Schema::create('settings', function (Blueprint $t) { $t->increments('set_id'); $t->string('set_name'); $t->integer('set_expire'); });
        Schema::create('provinces', function (Blueprint $t) { $t->increments('id'); $t->string('name_th'); $t->string('name_en'); });
        Schema::create('amphures', function (Blueprint $t) { $t->increments('id'); $t->string('name_th'); $t->string('name_en'); $t->integer('province_id'); });
        Schema::create('districts', function (Blueprint $t) { $t->increments('id'); $t->string('name_th'); $t->string('name_en'); $t->integer('amphure_id'); $t->string('zip_code')->nullable(); });
    }

    private function admin(): User
    {
        $u = new User(['name' => 'Admin', 'email' => 'a@test.local', 'type' => 'Admin', 'status' => 'enable']);
        $u->id = 3;

        return $u;
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'company_name' => 'บริษัท แลบ โฟร์ทีน จำกัด', 'company_tax' => '0105500000001', 'company_address' => '362/10-11 ถนนสี่พระยา',
            'company_province' => '1', 'company_amphur' => '10', 'company_district' => '100', 'company_zipcode' => '10500',
            'company_contact' => 'คุณเอ', 'company_phone' => '02-000-0000', 'company_type' => 'โรงงาน', 'company_contact_sale' => 'ตุ๊กกี้', 'company_status' => 'enable',
        ];
    }

    /** Google reply that "translates" by prefixing EN: so the test can see which text was sent. */
    private function fakeGoogle(): void
    {
        Http::fake(['translation.googleapis.com/*' => function ($request) {
            $q = (array) ($request->data()['q'] ?? []);

            return Http::response(['data' => ['translations' => array_map(fn ($t) => ['translatedText' => trim($t) === '' ? '' : 'EN:' . trim($t)], $q)]]);
        }]);
    }

    public function test_blank_english_fields_are_filled_by_google_on_save_and_kept_when_typed()
    {
        $this->fakeGoogle();
        $this->actingAs($this->admin());

        $this->post(route('company.store'), $this->payload())->assertRedirect(route('company.index'));
        $c = CompanyModel::first();
        $this->assertSame('EN:บริษัท แลบ โฟร์ทีน จำกัด', $c->company_name_en);
        $this->assertSame('EN:362/10-11 ถนนสี่พระยา', $c->company_address_en);
        Http::assertSentCount(1);

        // Hand-typed English wins and is not sent to Google.
        $this->put(route('company.update', $c->company_id), $this->payload(['company_name_en' => 'Lab Fourteen Co., Ltd.']))->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('Lab Fourteen Co., Ltd.', $c->company_name_en);
        $this->assertSame('EN:362/10-11 ถนนสี่พระยา', $c->company_address_en);   // unchanged Thai -> previous value kept, no call
        Http::assertSentCount(1);

        // Changing the Thai address with the English left blank re-translates only that field.
        $this->put(route('company.update', $c->company_id), $this->payload(['company_name_en' => 'Lab Fourteen Co., Ltd.', 'company_address' => '99 ถนนใหม่']))->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('EN:99 ถนนใหม่', $c->company_address_en);
        $this->assertSame('Lab Fourteen Co., Ltd.', $c->company_name_en);
        Http::assertSentCount(2);
    }

    public function test_google_failure_does_not_block_saving()
    {
        Http::fake(['translation.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 403)]);
        $this->actingAs($this->admin())->post(route('company.store'), $this->payload())->assertRedirect(route('company.index'));
        $this->assertNull(CompanyModel::first()->company_name_en);
    }

    public function test_bulk_command_fills_only_missing_values_unless_forced()
    {
        $this->fakeGoogle();
        CompanyModel::create(['company_name' => 'หนึ่ง', 'company_address' => 'ที่อยู่หนึ่ง']);
        CompanyModel::create(['company_name' => 'สอง', 'company_address' => 'ที่อยู่สอง', 'company_name_en' => 'Two', 'company_address_en' => 'Addr Two']);
        CompanyModel::create(['company_name' => 'สาม', 'company_address' => 'ที่อยู่สาม', 'company_name_en' => 'Three']);

        $this->artisan('company:translate')->assertExitCode(0);
        $rows = CompanyModel::orderBy('company_id')->get();
        $this->assertSame(['EN:หนึ่ง', 'Two', 'Three'], $rows->pluck('company_name_en')->all());
        $this->assertSame(['EN:ที่อยู่หนึ่ง', 'Addr Two', 'EN:ที่อยู่สาม'], $rows->pluck('company_address_en')->all());

        $this->artisan('company:translate --force')->assertExitCode(0);
        $this->assertSame('EN:สอง', CompanyModel::find(2)->company_name_en);
    }
}
