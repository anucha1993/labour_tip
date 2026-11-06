<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สิทธิ์การใช้งาน: roles (users.type points at roles.key) and the permissions each role holds.
 * Seeds the three built-in roles with exactly the rights they had while permissions were hard-coded.
 */
class CreateRolesTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->string('key', 40)->primary();
                $table->string('name', 100);
                $table->string('description', 255)->nullable();
                $table->boolean('is_system')->default(false);
                $table->unsignedSmallInteger('sort')->default(100);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('role_key', 40)->index();
                $table->string('permission', 60);
                $table->unique(['role_key', 'permission']);
            });
        }

        $now = now();
        $sort = 0;
        foreach (Permissions::SYSTEM_ROLES as $key => [$name, $desc]) {
            $sort += 10;
            if (! DB::table('roles')->where('key', $key)->exists()) {
                DB::table('roles')->insert(['key' => $key, 'name' => $name, 'description' => $desc, 'is_system' => true, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now]);
            }
            if ($key !== Permissions::MASTER && ! DB::table('role_permissions')->where('role_key', $key)->exists()) {
                DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_key' => $key, 'permission' => $p], Permissions::defaults($key)));
            }
        }

        Permissions::forget();
    }

    public function down()
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Permissions::forget();
    }
}
