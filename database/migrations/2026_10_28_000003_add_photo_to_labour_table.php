<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Labour profile photo: path on the documents disk (photos/<id>_<rand>.jpg) + which disk holds it. */
class AddPhotoToLabourTable extends Migration
{
    public function up()
    {
        Schema::table('labour', function (Blueprint $table) {
            $table->string('labour_photo', 255)->nullable()->after('labour_file_work');
            $table->string('labour_photo_disk', 20)->nullable()->after('labour_photo');
        });
    }

    public function down()
    {
        Schema::table('labour', function (Blueprint $table) {
            $table->dropColumn(['labour_photo', 'labour_photo_disk']);
        });
    }
}
