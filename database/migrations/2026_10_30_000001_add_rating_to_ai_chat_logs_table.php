<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Thumbs up/down (+ optional note) on an assistant answer, used to review and improve the prompt. */
class AddRatingToAiChatLogsTable extends Migration
{
    public function up()
    {
        Schema::table('ai_chat_logs', function (Blueprint $table) {
            $table->tinyInteger('rating')->nullable()->after('error')->comment('1 = ดี, -1 = ไม่ดี');
            $table->string('feedback', 500)->nullable()->after('rating');
        });
    }

    public function down()
    {
        Schema::table('ai_chat_logs', function (Blueprint $table) {
            $table->dropColumn(['rating', 'feedback']);
        });
    }
}
