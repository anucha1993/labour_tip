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
 * Exercises the dynamic document rows and the R2 migration command on an in-memory
 * SQLite database with fake disks, so it never touches the real database or files.
 */
class LabourDocumentsTest extends TestCase
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
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); });
        Schema::create('action_logs', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action_type'); $t->string('model_type');
            $t->unsignedBigInteger('model_id'); $t->text('old_values')->nullable(); $t->text('new_values')->nullable(); $t->timestamp('created_at')->nullable();
        });

        Storage::fake('public');
        Storage::fake('r2');
        config(['filesystems.labour_documents_disk' => 'public']);
    }

    protected function tearDown(): void
    {
        // The "table exists" flag is static; never leak it into tests that use the real database.
        LabourDocument::forgetTableReady();
        parent::tearDown();
    }

    private function admin(): User
    {
        $u = new User(['name' => 'Admin Tester', 'email' => 'admin@test.local', 'type' => 'MasterAdmin', 'status' => 'enable']);
        $u->id = 1;

        return $u;
    }

    private function migrateDocumentsTable(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_27_000001_create_labour_documents_table.php', '--force' => true]);
        LabourDocument::forgetTableReady();
    }

    private function basePayload(array $extra = []): array
    {
        return array_merge([
            'labour_prefix' => 'Mr', 'labour_fullname' => 'TEST WORKER', 'labour_sex' => 'male', 'labour_nationality' => 'myanmar',
            'labour_passport_number' => 'AB123456', 'labour_status_job' => 'job', 'labour_status' => 'enable',
        ], $extra);
    }

    public function test_legacy_files_still_show_before_migration_table_exists()
    {
        $labour = LabourModel::create($this->basePayload(['labour_file_passport' => 'AB123456_Passport.pdf']));

        $list = $labour->documentList();
        $this->assertCount(1, $list);
        $this->assertSame('Passport', $list[0]->name);
        $this->assertSame('AB123456/AB123456_Passport.pdf', $list[0]->path);
        $this->assertFalse($list[0]->exists);

        $this->actingAs($this->admin())->get('/labour/form-show/' . $labour->labour_id)->assertOk()->assertSee('AB123456_Passport.pdf');
        $this->actingAs($this->admin())->get('/labour/form-edit/' . $labour->labour_id)->assertOk()->assertSee('เพิ่มเอกสาร');
    }

    public function test_upload_without_table_gives_clear_error_instead_of_losing_data()
    {
        $this->actingAs($this->admin())
            ->from('/labour/form-add')
            ->post('/labour/store', $this->basePayload(['documents' => [['name' => 'Passport', 'file' => UploadedFile::fake()->create('p.pdf', 50, 'application/pdf')]]]))
            ->assertRedirect('/labour/form-add')
            ->assertSessionHas('error');
        $this->assertSame(0, LabourModel::count(), 'transaction must roll back');
    }

    public function test_unlimited_document_rows_are_stored_on_configured_disk()
    {
        $this->migrateDocumentsTable();

        $docs = [];
        foreach (['Passport', 'Visa', 'Work Permit', 'Employment Contract', 'Photo'] as $i => $name) {
            $docs[] = ['name' => $name, 'file' => UploadedFile::fake()->create("file$i.pdf", 20, 'application/pdf')];
        }
        $docs[] = ['name' => '', 'file' => null]; // empty row is ignored

        $this->actingAs($this->admin())->post('/labour/store', $this->basePayload(['documents' => $docs]))
            ->assertRedirect(route('labour.index'))->assertSessionHas('success');

        $labour = LabourModel::first();
        $this->assertSame(5, $labour->documents()->count());
        $first = $labour->documents()->first();
        $this->assertSame('public', $first->disk);
        $this->assertStringStartsWith('AB123456/', $first->path);
        Storage::disk('public')->assertExists($first->path);

        // Row with a file but no name is rejected by validation.
        $this->actingAs($this->admin())->from('/labour/form-add')
            ->post('/labour/store', $this->basePayload(['labour_passport_number' => 'ZZ1', 'documents' => [['name' => '', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]]]))
            ->assertSessionHasErrors(['documents.0.name']);

        // Open + delete routes
        $this->actingAs($this->admin())->get($first->url())->assertOk();
        $this->actingAs($this->admin())->delete(route('labour.document.delete', $first->id))->assertSessionHas('success');
        Storage::disk('public')->assertMissing($first->path);
        $this->assertSame(4, $labour->documents()->count());

        // New uploads go to R2 once the setting changes; old rows keep their disk.
        config(['filesystems.labour_documents_disk' => 'r2']);
        $this->actingAs($this->admin())->put('/labour/update/' . $labour->labour_id, $this->basePayload(['documents' => [['name' => 'TM.', 'file' => UploadedFile::fake()->create('tm.pdf', 10, 'application/pdf')]]]))
            ->assertSessionHas('success');
        $tm = $labour->documents()->where('name', 'TM.')->first();
        $this->assertSame('r2', $tm->disk);
        Storage::disk('r2')->assertExists($tm->path);
        $this->assertSame(4, $labour->documents()->where('disk', 'public')->count());
    }

    public function test_migrate_command_registers_legacy_files_and_copies_to_r2_without_deleting()
    {
        $this->migrateDocumentsTable();

        Storage::disk('public')->put('AB123456/AB123456_Passport.pdf', 'PDFDATA');
        Storage::disk('public')->put('AB123456/AB123456_Visa.pdf', 'VISADATA');
        $labour = LabourModel::create($this->basePayload(['labour_file_passport' => 'AB123456_Passport.pdf', 'labour_file_visa' => 'AB123456_Visa.pdf']));

        // dry run writes nothing
        Artisan::call('labour:migrate-documents', ['--to' => 'r2', '--dry-run' => true]);
        $this->assertSame(0, LabourDocument::count());

        Artisan::call('labour:migrate-documents', ['--to' => 'r2']);
        $out = Artisan::output();
        $this->assertStringContainsString('ลงทะเบียนใหม่ 2 รายการ', $out);
        $this->assertStringContainsString('คัดลอกสำเร็จ 2', $out);

        $this->assertSame(2, LabourDocument::where('disk', 'r2')->count());
        Storage::disk('r2')->assertExists('AB123456/AB123456_Passport.pdf');
        $this->assertSame('PDFDATA', Storage::disk('r2')->get('AB123456/AB123456_Passport.pdf'));
        // originals untouched, legacy columns untouched
        Storage::disk('public')->assertExists('AB123456/AB123456_Passport.pdf');
        $this->assertSame('AB123456_Passport.pdf', $labour->fresh()->labour_file_passport);

        // documentList no longer duplicates the legacy file
        $this->assertCount(2, $labour->fresh()->documentList());

        // re-running is a no-op
        Artisan::call('labour:migrate-documents', ['--to' => 'r2']);
        $this->assertSame(2, LabourDocument::count());
        $this->assertStringContainsString('รอคัดลอก 0 รายการ', Artisan::output());

        // deleting a migrated legacy document clears the legacy column so it cannot reappear
        $doc = LabourDocument::where('legacy_column', 'labour_file_visa')->first();
        $this->actingAs($this->admin())->delete(route('labour.document.delete', $doc->id))->assertSessionHas('success');
        $this->assertNull($labour->fresh()->labour_file_visa);
        Storage::disk('r2')->assertMissing('AB123456/AB123456_Visa.pdf');
    }
}
