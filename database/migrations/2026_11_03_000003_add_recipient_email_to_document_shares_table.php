<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a share link was sent to (one or more e-mail addresses) and whether the link is
 * open to anyone who has it ("anyone") or only to those e-mails after verification ("email").
 */
class AddRecipientEmailToDocumentSharesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('document_shares')) {
            return;
        }

        Schema::table('document_shares', function (Blueprint $table) {
            if (! Schema::hasColumn('document_shares', 'recipient_email')) {
                $table->string('recipient_email', 500)->nullable()->after('label')->comment('comma separated e-mails the link was shared with');
            }
            if (! Schema::hasColumn('document_shares', 'access')) {
                $table->string('access', 10)->default('anyone')->after('recipient_email')->comment('anyone | email');
            }
        });
    }

    public function down()
    {
        Schema::table('document_shares', function (Blueprint $table) {
            foreach (['access', 'recipient_email'] as $col) {
                if (Schema::hasColumn('document_shares', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
