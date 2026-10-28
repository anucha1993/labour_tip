<?php

namespace Tests\Feature;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Models\User;
use App\Services\LabourPhotoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Labour profile photo: upload from the labour form, served through /labour/{id}/photo, removable, deleted with the labour.
 */
class LabourPhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite', 'filesystems.labour_documents_disk' => 'public']);
        DB::purge('testing_sqlite');
        LabourDocument::forgetTableReady();

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
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_status')->nullable(); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        Schema::create('settings', function (Blueprint $t) { $t->increments('set_id'); $t->string('set_name'); $t->integer('set_expire'); });
        Schema::create('action_logs', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action_type'); $t->string('model_type'); $t->unsignedBigInteger('model_id'); $t->text('old_values')->nullable(); $t->text('new_values')->nullable(); $t->timestamp('created_at')->nullable(); });
        DB::table('nationality')->insert([['code' => 'myanmar', 'name_th' => 'พม่า']]);

        Storage::fake('public');
    }

    private function admin(): User
    {
        $u = new User(['name' => 'Admin', 'email' => 'a@test.local', 'type' => 'MasterAdmin', 'status' => 'enable']);
        $u->id = 5;

        return $u;
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'labour_prefix' => 'Mr', 'labour_fullname' => 'AUNG MYO', 'labour_sex' => 'male', 'labour_nationality' => 'myanmar',
            'labour_passport_number' => 'MA1234567', 'labour_status_job' => 'job', 'labour_status' => 'enable',
        ];
    }

    public function test_photo_is_uploaded_resized_served_replaced_and_removed()
    {
        $this->actingAs($this->admin());

        // non-image rejected
        $this->post(route('labour.store'), $this->payload(['labour_photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]))
            ->assertSessionHasErrors('labour_photo');

        $this->post(route('labour.store'), $this->payload(['labour_photo' => UploadedFile::fake()->image('me.png', 800, 600)]))
            ->assertRedirect(route('labour.index'));

        $labour = LabourModel::first();
        $this->assertStringStartsWith('photos/1_', $labour->labour_photo);
        $this->assertSame('public', $labour->labour_photo_disk);
        Storage::disk('public')->assertExists($labour->labour_photo);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($labour->labour_photo));
        $this->assertSame([LabourPhotoService::SIZE, LabourPhotoService::SIZE], [$w, $h]);

        // served inline through the app; appears on show and list pages
        $this->get(route('labour.photo', $labour->labour_id))->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->get(route('labour.show', $labour->labour_id))->assertOk()->assertSee('/labour/1/photo');
        $this->get(route('labour.edit', $labour->labour_id))->assertOk()->assertSee('ลบรูปเดิม');

        // replacing deletes the old file
        $old = $labour->labour_photo;
        $this->put(route('labour.update', $labour->labour_id), $this->payload(['labour_photo' => UploadedFile::fake()->image('new.jpg', 300, 300)]))->assertSessionHasNoErrors();
        $labour->refresh();
        $this->assertNotSame($old, $labour->labour_photo);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($labour->labour_photo);

        // "remove" checkbox clears it
        $current = $labour->labour_photo;
        $this->put(route('labour.update', $labour->labour_id), $this->payload(['remove_photo' => 1]))->assertSessionHasNoErrors();
        $labour->refresh();
        $this->assertNull($labour->labour_photo);
        Storage::disk('public')->assertMissing($current);
        $this->get(route('labour.photo', $labour->labour_id))->assertNotFound();

        // deleting the labour removes the photo file too
        $this->put(route('labour.update', $labour->labour_id), $this->payload(['labour_photo' => UploadedFile::fake()->image('again.jpg', 300, 300)]));
        $path = $labour->fresh()->labour_photo;
        Storage::disk('public')->assertExists($path);
        $this->delete(route('labour.delete', $labour->labour_id))->assertRedirect();
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, LabourModel::count());
    }

    public function test_guest_cannot_fetch_a_photo()
    {
        LabourModel::create($this->payload(['labour_photo' => 'photos/1_abc.jpg', 'labour_photo_disk' => 'public']));
        $this->get('/labour/1/photo')->assertRedirect(route('login'));
    }
}
