<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Share links for a document folder (one worker or one company), like "share folder" in Google Drive.
 */
class CreateDocumentSharesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('document_shares')) {
            return;
        }

        Schema::create('document_shares', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('token', 64)->unique();
            $table->string('kind', 10)->comment('labour | company');
            $table->unsignedBigInteger('target_id')->comment('labour.labour_id or company.company_id');
            $table->string('label', 150)->nullable()->comment('note: who the link is for');
            $table->boolean('can_download')->default(true);
            $table->string('password')->nullable()->comment('bcrypt, null = no password');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->string('created_by', 150)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'target_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_shares');
    }
}
