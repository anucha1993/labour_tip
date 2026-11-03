<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The uploaded profile photo exactly as it came (full resolution, not cropped) for printing on official documents
 * and for AI retouching. Same disk as labour_photo. labour_photo stays the 512×512 thumbnail for the web / Worker App.
 */
class AddPhotoOriginalToLabourTable extends Migration
{
    public function up()
    {
        Schema::table('labour', function (Blueprint $table) {
            $table->string('labour_photo_original', 255)->nullable()->after('labour_photo_disk');
        });
    }

    public function down()
    {
        Schema::table('labour', function (Blueprint $table) {
            $table->dropColumn('labour_photo_original');
        });
    }
}
