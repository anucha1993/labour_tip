<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLabourDocumentsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('labour_documents')) {
            return;
        }

        Schema::create('labour_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('labour_id')->index()->comment('labour.labour_id');
            $table->string('name', 100)->comment('ชื่อเอกสาร เช่น Passport, Visa');
            $table->string('disk', 30)->default('public')->comment('filesystem disk ที่เก็บไฟล์ (public / r2)');
            $table->string('path', 500)->comment('path ใน disk');
            $table->string('original_name', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime', 100)->nullable();
            $table->string('legacy_column', 40)->nullable()->comment('labour_file_passport / _visa / _work เมื่อ migrate มาจากคอลัมน์เดิม');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('uploaded_by', 150)->nullable();
            $table->timestamps();

            $table->unique(['labour_id', 'legacy_column']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('labour_documents');
    }
}
