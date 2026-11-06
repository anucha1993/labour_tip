<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * สิทธิ์การใช้งาน: editable roles × permissions (/roles) on an in-memory SQLite database.
 */
class RolePermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite']);
        DB::purge('testing_sqlite');
        Permissions::forget();

        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('name'); $t->string('email')->unique(); $t->string('password')->nullable();
            $t->string('type')->default('Member'); $t->string('status')->default('enable'); $t->string('avatar')->nullable();
            $t->rememberToken(); $t->timestamps();
        });
        Schema::create('labour', function (Blueprint $t) {
            $t->bigIncrements('labour_id');
            foreach ((new \App\Models\labour\LabourModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_status')->default('enable'); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); });
    }

    protected function tearDown(): void
    {
        Permissions::forget();
        parent::tearDown();
    }

    private function user(string $type, int $id = 1): User
    {
        $u = User::create(['name' => $type . ' Tester ' . $id, 'email' => strtolower($type) . $id . '@test.local', 'type' => $type, 'status' => 'enable', 'password' => 'x']);

        return $u;
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_06_000001_create_roles_tables.php', '--force' => true]);
        Permissions::forget();
    }

    public function test_without_the_tables_the_built_in_defaults_apply_and_the_page_explains()
    {
        $this->assertFalse(Permissions::tableReady());
        $this->assertTrue(Permissions::has('Admin', 'labour.edit'));
        $this->assertFalse(Permissions::has('Member', 'labour.edit'));
        $this->assertTrue(Permissions::has('MasterAdmin', 'admin.roles'));

        // A Member cannot add a worker; the menu does not show management links.
        $res = $this->actingAs($this->user('Member'))->get('/labour/form-add')->assertRedirect(route('dashboard'));
        $this->assertStringContainsString('เพิ่มแรงงานใหม่', session('error'));
        $this->actingAs($this->user('Member', 2))->get('/roles')->assertRedirect(route('dashboard'));
        $this->actingAs($this->user('Admin', 3))->get('/roles')->assertRedirect(route('dashboard'));

        // MasterAdmin sees the page, locked, with the migrate hint; saving is refused.
        $master = $this->user('MasterAdmin', 4);
        $this->actingAs($master)->get('/roles')->assertOk()->assertSee('ยังไม่ได้สร้างตารางสิทธิ์')->assertSee('labour.edit');
        $this->actingAs($master)->post('/roles/Member/permissions', ['perms' => ['labour.edit']])->assertRedirect();
        $this->assertStringContainsString('php artisan migrate', session('error'));
        $this->assertFalse(Permissions::has('Member', 'labour.edit'));
    }

    public function test_the_migration_seeds_the_old_behaviour_and_the_matrix_can_be_edited()
    {
        $this->migrate();
        $this->assertTrue(Permissions::tableReady());
        $this->assertSame(Permissions::defaults('Member'), Permissions::forRole('Member'));
        $this->assertSame(Permissions::defaults('Admin'), Permissions::forRole('Admin'));
        $this->assertCount(count(Permissions::all()), Permissions::forRole('MasterAdmin'));

        $master = $this->user('MasterAdmin');
        $member = $this->user('Member', 2);

        // The page opens on the first profile (MasterAdmin, locked) and on any profile by key.
        $res = $this->actingAs($master)->get('/roles')->assertOk();
        $res->assertSee('ทุกสิทธิ์ แก้ไม่ได้')->assertSee('value="labour.create"', false)->assertDontSee('บันทึกสิทธิ์');
        $res = $this->actingAs($master)->get('/roles/Member')->assertOk();
        $res->assertSee('name="perms[]" value="labour.create"', false)->assertSee('1 คน', false)->assertSee('ดู, ดาวน์โหลด zip')->assertSee('บันทึกสิทธิ์');
        $this->actingAs($master)->get('/roles/Nope')->assertNotFound();

        // Give Member "add worker", take away "view workers"; MasterAdmin cannot be changed.
        $this->actingAs($master)->post('/roles/Member/permissions', ['perms' => ['labour.create', 'company.view', 'ai.chat']])->assertRedirect(route('roles.index', 'Member'));
        $this->assertSame(['labour.create', 'company.view', 'ai.chat'], Permissions::forRole('Member'));
        $this->actingAs($master)->from('/roles/MasterAdmin')->post('/roles/MasterAdmin/permissions', ['perms' => ['labour.view']])->assertRedirect('/roles/MasterAdmin');
        $this->assertStringContainsString('แก้ไขไม่ได้', session('error'));
        $this->assertSame(0, DB::table('role_permissions')->where('role_key', 'MasterAdmin')->count());

        // ... and it takes effect on routes, Blade and the sidebar right away.
        $this->actingAs($member)->get('/labour')->assertRedirect(route('dashboard'));
        $res = $this->actingAs($member)->get('/company')->assertOk();
        $res->assertDontSee(route('labour.index') . '" class="sb-link', false)->assertSee(route('company.index') . '" class="sb-link', false)->assertDontSee('href="' . route('company.create') . '"', false);
        $this->assertTrue($member->fresh()->can('labour.create'));
        $this->assertFalse($member->fresh()->can('labour.view'));

        // Unknown permission keys are rejected.
        $this->actingAs($master)->post('/roles/Member/permissions', ['perms' => ['labour.hack']])->assertSessionHasErrors('perms.0');

        // Reset puts Member back to the shipped defaults.
        $this->actingAs($master)->post('/roles/Member/reset')->assertRedirect(route('roles.index', 'Member'));
        $this->assertSame(Permissions::defaults('Member'), Permissions::forRole('Member'));
        $this->actingAs($master)->post('/roles/MasterAdmin/reset')->assertNotFound();
    }

    public function test_custom_roles_can_be_added_renamed_assigned_and_deleted()
    {
        $this->migrate();
        $master = $this->user('MasterAdmin');

        // Bad key, then a good one copied from Admin (admin.roles is never copied).
        $this->actingAs($master)->from('/roles')->post('/roles/create', ['key' => 'hr staff', 'name' => 'HR'])->assertSessionHasErrors('key');
        $this->actingAs($master)->post('/roles/create', ['key' => 'Admin', 'name' => 'dup'])->assertSessionHasErrors('key');
        $this->actingAs($master)->post('/roles/create', ['key' => 'Head', 'name' => 'หัวหน้าฝ่าย', 'description' => 'ดูแลทีม', 'copy_from' => 'Admin'])->assertRedirect(route('roles.index', 'Head'));
        $this->assertSame(Permissions::defaults('Admin'), Permissions::forRole('Head'));
        $this->assertSame('หัวหน้าฝ่าย', role_label('Head'));

        // The new role is offered when adding / editing members and works like any other.
        $this->actingAs($master)->get('/register')->assertOk()->assertSee('Head — หัวหน้าฝ่าย');
        $this->actingAs($master)->post('/register', ['name' => 'Head One', 'email' => 'head@test.local', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'type' => 'Head', 'status' => 'enable'])->assertRedirect(route('member.index'));
        $head = User::where('email', 'head@test.local')->firstOrFail();
        $this->assertSame('Head', $head->type);
        $this->actingAs($head)->get('/labour/form-add')->assertOk();
        $this->actingAs($head)->get('/roles')->assertRedirect(route('dashboard'));
        $this->actingAs($master)->get('/member')->assertOk()->assertSee('Head One')->assertSee('<option value="Head">Head — หัวหน้าฝ่าย</option>', false);

        // Grant Head the roles page: it can save, but can never drop admin.roles from itself.
        $this->actingAs($master)->post('/roles/Head/permissions', ['perms' => ['admin.roles', 'labour.view']]);
        $this->actingAs($head)->get('/roles/Head')->assertOk();
        $this->actingAs($head)->post('/roles/Head/permissions', ['perms' => ['labour.view']])->assertRedirect(route('roles.index', 'Head'));
        $this->assertSame(['labour.view', 'admin.roles'], Permissions::forRole('Head'));
        $this->actingAs($head)->post('/roles/Admin/permissions', ['perms' => []])->assertRedirect(route('roles.index', 'Admin'));
        $this->assertSame([], Permissions::forRole('Admin'));

        // Rename, then delete: refused while someone has the role, refused for system roles, fine afterwards.
        $this->actingAs($master)->patch('/roles/Head', ['name' => 'หัวหน้า', 'description' => ''])->assertRedirect(route('roles.index', 'Head'));
        $this->assertSame('หัวหน้า', role_label('Head'));
        $this->actingAs($master)->from('/roles')->delete('/roles/Head')->assertRedirect('/roles');
        $this->assertStringContainsString('ยังมีผู้ใช้ 1 คน', session('error'));
        $this->actingAs($master)->from('/roles')->delete('/roles/Admin')->assertRedirect('/roles');
        $this->assertStringContainsString('โปรไฟล์พื้นฐาน', session('error'));
        $head->type = 'Member';
        $head->save();
        $this->actingAs($master)->delete('/roles/Head')->assertRedirect(route('roles.index'));
        $this->assertArrayNotHasKey('Head', Permissions::roles());
        $this->assertSame(0, DB::table('role_permissions')->where('role_key', 'Head')->count());
        $this->actingAs($master)->delete('/roles/Nope')->assertNotFound();
    }
}
