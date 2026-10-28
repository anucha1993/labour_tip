<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * My-profile page: name/password update and profile picture upload (in-memory SQLite, real GD resize).
 */
class ProfileTest extends TestCase
{
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite']);
        DB::purge('testing_sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->increments('id'); $t->string('name'); $t->string('email'); $t->string('password');
            $t->string('type')->nullable(); $t->string('status')->nullable(); $t->string('avatar')->nullable();
            $t->string('remember_token')->nullable(); $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) { $t->increments('set_id'); $t->string('set_name'); $t->integer('set_expire'); });
        Schema::create('labour', function (Blueprint $t) { $t->increments('labour_id'); $t->string('labour_status')->nullable(); $t->string('labour_status_job')->nullable(); $t->string('labour_visa_date_end')->nullable(); $t->string('labour_passport_date_end')->nullable(); $t->string('labour_workpremit_date_end')->nullable(); $t->string('labour_day90_date_end')->nullable(); });

        User::create(['name' => 'Somchai', 'email' => 's@test.local', 'password' => Hash::make('secret1'), 'type' => 'Member', 'status' => 'enable']);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_user_can_update_name_and_password()
    {
        $user = User::first();

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('โปรไฟล์ของฉัน')->assertSee('Somchai');

        // wrong current password → rejected, nothing changes
        $this->actingAs($user)->post('/profile', ['name' => 'X', 'current_password' => 'nope', 'password' => 'newpass1', 'password_confirmation' => 'newpass1'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('Somchai', $user->fresh()->name);

        $this->actingAs($user)->post('/profile', ['name' => 'Somchai J.', 'current_password' => 'secret1', 'password' => 'newpass1', 'password_confirmation' => 'newpass1'])
            ->assertRedirect(route('profile.edit'))->assertSessionHas('success');
        $fresh = $user->fresh();
        $this->assertSame('Somchai J.', $fresh->name);
        $this->assertTrue(Hash::check('newpass1', $fresh->password));

        // name only, no password fields
        $this->actingAs($user)->post('/profile', ['name' => 'Somchai K.'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('newpass1', $user->fresh()->password));
    }

    public function test_avatar_upload_resizes_to_square_and_can_be_removed()
    {
        $user = User::first();

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('avatar');

        $upload = UploadedFile::fake()->image('me.png', 640, 400);
        $this->actingAs($user)->post('/profile/avatar', ['avatar' => $upload])->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $user = $user->fresh();
        $this->assertNotEmpty($user->avatar);
        $path = AvatarService::directory() . DIRECTORY_SEPARATOR . $user->avatar;
        $this->written[] = $path;
        $this->assertFileExists($path);
        [$w, $h] = getimagesize($path);
        $this->assertSame([AvatarService::SIZE, AvatarService::SIZE], [$w, $h]);
        $this->assertStringContainsString('/uploads/avatars/' . $user->avatar, $user->avatarUrl());

        // sidebar / member list render the picture
        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('uploads/avatars/' . $user->avatar);

        // replacing removes the old file
        $old = $path;
        $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('new.jpg', 300, 300)])->assertSessionHas('success');
        $user = $user->fresh();
        $this->written[] = AvatarService::directory() . DIRECTORY_SEPARATOR . $user->avatar;
        $this->assertFileDoesNotExist($old);

        $this->actingAs($user)->delete('/profile/avatar')->assertRedirect(route('profile.edit'));
        $this->assertNull($user->fresh()->avatar);
        $this->assertFileDoesNotExist(end($this->written));
    }

    public function test_guest_cannot_open_profile()
    {
        $this->get('/profile')->assertRedirect(route('login'));
    }
}
