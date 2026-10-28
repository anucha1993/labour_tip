<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** English business type for the Worker App (filled by hand or by Google Translate). */
class AddTypeEnToCompanyTable extends Migration
{
    public function up()
    {
        Schema::table('company', function (Blueprint $table) {
            $table->string('company_type_en', 100)->nullable()->after('company_type');
        });
    }

    public function down()
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('company_type_en');
        });
    }
}
