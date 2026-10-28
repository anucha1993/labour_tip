<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every question asked to the AI assistant, with the answer it gave.
 * Viewed by MasterAdmin at /ai-chat/logs.
 */
class CreateAiChatLogsTable extends Migration
{
    public function up()
    {
        Schema::create('ai_chat_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable()->index()->comment('ผู้ใช้ที่ถาม');
            $table->string('conversation_id', 64)->nullable()->index()->comment('รหัสบทสนทนา (สุ่มจากหน้าแชท) ใช้จัดกลุ่มคำถาม-คำตอบต่อเนื่องกัน');
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->json('tools_used')->nullable()->comment('เครื่องมือที่ AI เรียก เช่น count_labours');
            $table->json('reports')->nullable()->comment('ไฟล์ Excel ที่สร้าง');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 10)->default('ok')->comment('ok | error');
            $table->text('error')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_chat_logs');
    }
}
