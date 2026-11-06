<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New permission analytics.view (the Dashboard page): Admin gets it like the other report rights.
 * Custom profiles are left alone; grant it on the profile page.
 */
class GrantAnalyticsPermission extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('role_permissions')) {
            return;
        }
        if (! DB::table('role_permissions')->where('role_key', 'Admin')->where('permission', 'analytics.view')->exists()) {
            DB::table('role_permissions')->insert(['role_key' => 'Admin', 'permission' => 'analytics.view']);
        }
        Permissions::forget();
    }

    public function down()
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->where('permission', 'analytics.view')->delete();
        }
        Permissions::forget();
    }
}
