<?php

namespace Tests\Feature;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The drive-like document explorer (/documents) on an in-memory SQLite database with fake disks.
 */
class DocumentExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite']);
        DB::purge('testing_sqlite');
        LabourDocument::forgetTableReady();

        Schema::create('labour', function (Blueprint $t) {
            $t->bigIncrements('labour_id');
            foreach ((new LabourModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        DB::table('nationality')->insert(['code' => 'myanmar', 'name_th' => 'พม่า']);
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_status')->default('enable'); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); });
        Schema::create('action_logs', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action_type'); $t->string('model_type');
            $t->unsignedBigInteger('model_id'); $t->text('old_values')->nullable(); $t->text('new_values')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_27_000001_create_labour_documents_table.php', '--force' => true]);
        LabourDocument::forgetTableReady();

        Storage::fake('public');
        Storage::fake('r2');
        config(['filesystems.labour_documents_disk' => 'public']);

        DB::table('company')->insert([
            ['company_id' => 1, 'company_name' => 'บริษัท เอ จำกัด', 'company_status' => 'enable'],
            ['company_id' => 2, 'company_name' => 'บริษัท บี จำกัด', 'company_status' => 'enable'],
        ]);
    }

    protected function tearDown(): void
    {
        LabourDocument::forgetTableReady();
        parent::tearDown();
    }

    private function user(string $type): User
    {
        $u = new User(['name' => $type . ' Tester', 'email' => strtolower($type) . '@test.local', 'type' => $type, 'status' => 'enable']);
        $u->id = 1;

        return $u;
    }

    private function labour(string $name, string $passport, $companyId = 1, string $status = 'job'): LabourModel
    {
        return LabourModel::create([
            'labour_prefix' => 'Mr', 'labour_fullname' => $name, 'labour_sex' => 'male', 'labour_nationality' => 'myanmar',
            'labour_passport_number' => $passport, 'labour_status_job' => $status, 'labour_status' => 'enable', 'company_id' => $companyId,
        ]);
    }

    private function doc(LabourModel $labour, string $name, string $file = 'doc.pdf', string $disk = 'public'): LabourDocument
    {
        $path = $labour->labour_passport_number . '/' . $file;
        Storage::disk($disk)->put($path, str_contains($file, '.jpg') ? 'jpgdata' : '%PDF-1.4 test');

        return $labour->documents()->create([
            'name' => $name, 'disk' => $disk, 'path' => $path, 'original_name' => $file, 'size' => 12,
            'mime' => str_contains($file, '.jpg') ? 'image/jpeg' : 'application/pdf', 'uploaded_by' => 'Seeder',
        ]);
    }

    public function test_root_shows_companies_as_folders_with_counts_and_recent_files()
    {
        $a = $this->labour('AUNG KO', 'MA000001', 1);
        $b = $this->labour('MYO MIN', 'MB000002', 2);
        $this->labour('NO COMPANY', 'MC000003', null);
        $this->doc($a, 'Passport');
        $this->doc($a, 'Visa', 'visa.jpg');
        $this->doc($b, 'Passport');

        $res = $this->actingAs($this->user('Member'))->get('/documents')->assertOk();
        $res->assertSee('บริษัท เอ จำกัด')->assertSee('1 คน · 2 ไฟล์')->assertSee('1 คน · 1 ไฟล์');
        $res->assertSee('ไม่ระบุบริษัท')->assertSee('1 คน · 0 ไฟล์');
        $res->assertSee('อัปโหลดล่าสุด')->assertSee('ไฟล์ทั้งหมด <b>3</b>', false);
        // Type chips come from the document labels in use.
        $res->assertSee('type=Passport', false)->assertSee('type=Visa', false);
    }

    public function test_company_folder_lists_workers_and_worker_folder_lists_files_with_preview_data()
    {
        $a = $this->labour('AUNG KO', 'MA000001', 1);
        $this->labour('ZAW ZAW', 'MA000009', 1, 'resign');
        $passport = $this->doc($a, 'Passport');
        $this->doc($a, 'Photo', 'face.jpg');

        $this->actingAs($this->user('Member'))->get('/documents?company=1')->assertOk()
            ->assertSee('AUNG KO')->assertSee('ZAW ZAW')->assertSee('MA000001 · 2 ไฟล์');
        $this->actingAs($this->user('Member'))->get('/documents?company=1&w=ZAW')->assertOk()
            ->assertSee('ZAW ZAW')->assertDontSee('AUNG KO');
        $this->actingAs($this->user('Member'))->get('/documents?company=1&status=job')->assertOk()
            ->assertSee('AUNG KO')->assertDontSee('ZAW ZAW');
        $this->actingAs($this->user('Member'))->get('/documents?company=none')->assertOk()->assertSee('ไม่พบแรงงานตามเงื่อนไข');
        $this->actingAs($this->user('Member'))->get('/documents?company=999')->assertNotFound();

        $res = $this->actingAs($this->user('Member'))->get('/documents?labour=' . $a->labour_id)->assertOk();
        $res->assertSee('data-url="' . $passport->url() . '"', false)
            ->assertSee('data-dl="' . $passport->url() . '?download=1"', false)
            ->assertSee('data-kind="image"', false)
            ->assertSee('ดาวน์โหลดทั้งหมด (zip)');
        // Member: no upload button, no rename / delete links.
        $res->assertDontSee('อัปโหลดไฟล์')->assertDontSee('data-rename="http', false)->assertDontSee('data-delete="http', false);

        $res = $this->actingAs($this->user('MasterAdmin'))->get('/documents?labour=' . $a->labour_id)->assertOk();
        $res->assertSee('อัปโหลดไฟล์')
            ->assertSee('data-rename="' . route('documents.rename', $passport->id) . '"', false)
            ->assertSee('data-delete="' . route('labour.document.delete', $passport->id) . '"', false);
    }

    public function test_search_matches_passport_name_company_and_document_type()
    {
        $a = $this->labour('AUNG KO', 'MA000001', 1);
        $b = $this->labour('MYO MIN', 'MB000002', 2);
        $this->doc($a, 'Passport');
        $this->doc($b, 'Work Permit', 'work.pdf');

        $this->actingAs($this->user('Member'))->get('/documents?q=MB000002')->assertOk()->assertSee('พบ 1 ไฟล์')->assertSee('MYO MIN')->assertDontSee('AUNG KO');
        $this->actingAs($this->user('Member'))->get('/documents?q=' . urlencode('บี จำกัด'))->assertOk()->assertSee('พบ 1 ไฟล์')->assertSee('MYO MIN');
        $this->actingAs($this->user('Member'))->get('/documents?type=Passport')->assertOk()->assertSee('พบ 1 ไฟล์')->assertSee('AUNG KO');
        $this->actingAs($this->user('Member'))->get('/documents?q=nothing-here')->assertOk()->assertSee('ไม่พบไฟล์ที่ตรงกับคำค้น');
    }

    public function test_upload_rename_zip_and_delete_follow_roles()
    {
        $a = $this->labour('AUNG KO', 'MA000001', 1);

        // Member cannot upload (role middleware sends them back to the dashboard).
        $this->actingAs($this->user('Member'))
            ->post('/documents/upload', ['labour_id' => $a->labour_id, 'name' => 'Visa', 'files' => [UploadedFile::fake()->create('v.pdf', 10, 'application/pdf')]])
            ->assertRedirect(route('dashboard'));
        $this->assertSame(0, LabourDocument::count());

        // Admin uploads two files at once, both labelled "Visa".
        $this->actingAs($this->user('Admin'))
            ->post('/documents/upload', ['labour_id' => $a->labour_id, 'name' => 'Visa', 'files' => [
                UploadedFile::fake()->create('v1.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->image('v2.jpg'),
            ]])
            ->assertRedirect('/documents?labour=' . $a->labour_id)
            ->assertSessionHas('success');
        $this->assertSame(2, LabourDocument::count());
        $doc = LabourDocument::first();
        $this->assertSame('Visa', $doc->name);
        $this->assertSame('Admin Tester', $doc->uploaded_by);
        Storage::disk('public')->assertExists($doc->path);

        // Wrong file type is rejected with a Thai message.
        $this->actingAs($this->user('Admin'))->from('/documents?labour=' . $a->labour_id)
            ->post('/documents/upload', ['labour_id' => $a->labour_id, 'name' => 'Visa', 'files' => [UploadedFile::fake()->create('x.exe', 10)]])
            ->assertRedirect('/documents?labour=' . $a->labour_id)
            ->assertSessionHasErrors(['files.0']);

        // Rename (Admin) keeps the file, changes the label.
        $this->actingAs($this->user('Admin'))->patch(route('documents.rename', $doc->id), ['name' => 'Visa 2026'])
            ->assertRedirect('/documents?labour=' . $a->labour_id)->assertSessionHas('success');
        $this->assertSame('Visa 2026', $doc->fresh()->name);
        $this->actingAs($this->user('Member'))->patch(route('documents.rename', $doc->id), ['name' => 'Nope'])->assertRedirect(route('dashboard'));

        // Download param forces an attachment with a readable file name.
        $this->actingAs($this->user('Member'))->get($doc->url() . '?download=1')->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="Visa 2026 - MA000001.pdf"');
        $this->actingAs($this->user('Member'))->get($doc->url())->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="Visa 2026 - MA000001.pdf"');

        // Zip of the worker folder contains both files.
        $zipRes = $this->actingAs($this->user('Member'))->get(route('documents.zip', $a->labour_id))->assertOk();
        $this->assertStringContainsString('application/zip', $zipRes->headers->get('content-type'));
        $this->assertStringContainsString('MA000001 AUNG KO.zip', $zipRes->headers->get('content-disposition'));
        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($tmp, $zipRes->getFile()->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $this->assertSame(2, $zip->numFiles);
        $names = [$zip->getNameIndex(0), $zip->getNameIndex(1)];
        $zip->close();
        @unlink($tmp);
        $this->assertContains('Visa 2026 - MA000001.pdf', $names);
        $this->assertContains('Visa - MA000001.jpg', $names);

        // Delete is MasterAdmin only.
        $this->actingAs($this->user('Admin'))->delete(route('labour.document.delete', $doc->id))->assertRedirect(route('dashboard'));
        $this->actingAs($this->user('MasterAdmin'))->delete(route('labour.document.delete', $doc->id))->assertSessionHas('success');
        $this->assertSame(1, LabourDocument::count());
        Storage::disk('public')->assertMissing($doc->path);
    }

    public function test_labour_form_upload_still_works_through_shared_service()
    {
        $this->actingAs($this->user('Admin'))->post('/labour/store', [
            'labour_prefix' => 'Mr', 'labour_fullname' => 'FORM WORKER', 'labour_sex' => 'male', 'labour_nationality' => 'myanmar',
            'labour_passport_number' => 'MF000001', 'labour_status_job' => 'job', 'labour_status' => 'enable',
            'documents' => [['name' => 'Passport', 'file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')]],
        ])->assertSessionHas('success');

        $doc = LabourDocument::first();
        $this->assertNotNull($doc);
        $this->assertStringStartsWith('MF000001/', $doc->path);
        $this->assertSame('Admin Tester', $doc->uploaded_by);
        Storage::disk('public')->assertExists($doc->path);
    }
}
